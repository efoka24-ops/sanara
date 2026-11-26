/**
 * Passerelle du poste (T126, T135). L'interface web est chargée telle quelle : ses appels `/api` arrivent
 * ici. La caisse travaille toujours sur la base locale ; le reste est relayé au serveur quand il répond.
 * Les jetons du serveur ne sortent jamais de la passerelle : l'interface n'a que des jetons locaux.
 */
import { randomBytes } from 'node:crypto';
import { createHttpTransport, synchronise, type PushReport } from '@sanara/sync';
import { SqliteQueue, SqliteReference, SqliteState, type LocalDatabase } from '../local-db';
import { LocalPos, PosError, type SaleInput } from '../pos/local-pos';
import { OfflineAccounts, type Profile } from './accounts';
import { receipt, zReport } from './documents';

export interface GatewayOptions {
  db: LocalDatabase;
  deviceId: string;
  /** Adresse de l'API, par exemple http://sanara.trugroup.cm/api */
  serverUrl: string;
  fetch?: (url: string, init?: RequestInit) => Promise<Response>;
  now?: () => Date;
}

export interface GatewayStatus {
  online: boolean;
  /** Le référentiel a été téléchargé au moins une fois : on peut vendre. */
  ready: boolean;
  syncing: boolean;
  pending: number;
  rejected: { id: string; code: string }[];
  conflicts: number;
  lastSyncAt: string | null;
  clockSkewSeconds: number | null;
}

interface Session {
  email: string;
  profile: Profile;
  refreshToken: string;
  server: { access: string; refresh: string } | null;
  /** Gardé en mémoire seulement, pour joindre le serveur quand le réseau revient. */
  password: string | null;
}

class Offline extends Error {}

/** Champs lus dans les corps envoyés par l'interface (les règles sont vérifiées par la caisse). */
interface Body {
  email?: string;
  password?: string;
  mfa_token?: string;
  refresh?: string;
  pin?: string;
  station?: string;
  opening_float?: number;
  counts?: { denomination: number; qty: number }[];
  reason?: string;
  lines?: { product_id: string; qty: number; discount_bp?: number }[];
  payments?: SaleInput['payments'];
  customer_name?: string;
  prescription?: { ref: string; prescriber: string; patient_name: string };
  label?: string;
  cart?: unknown;
}

/** Réponse du serveur aux routes de connexion. */
interface Auth {
  access?: string;
  refresh?: string;
  user?: unknown;
  mfa_required?: boolean;
  mfa_token?: string;
}

const ERROR_STATUS: Record<string, number> = {
  cash_session_required: 409,
  cash_session_open: 409,
  cash_session_stale: 409,
  insufficient_stock: 409,
  reference_missing: 409,
  not_found: 404,
  forbidden: 403,
  discount_needs_approval: 403,
};

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' },
  });
const fail = (code: string, status: number, details: Record<string, unknown> = {}) =>
  json({ error: { code, details } }, status);
const html = (body: string) =>
  new Response(body, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
const token = (prefix: string) => `${prefix}.${randomBytes(24).toString('base64url')}`;

export class Gateway {
  private readonly queue: SqliteQueue;
  private readonly reference: SqliteReference;
  private readonly accounts: OfflineAccounts;
  private readonly state: SqliteState;
  private readonly sessions = new Map<string, Session>();
  /** Connexions en attente du second facteur : jeton intermédiaire → identifiants. */
  private readonly awaitingMfa = new Map<string, { email: string; password: string }>();
  private current: Session | null = null;
  private online = true;
  private running: Promise<void> | null = null;
  private again = false;
  private report: PushReport | null = null;

  constructor(private readonly options: GatewayOptions) {
    this.queue = new SqliteQueue(options.db);
    this.reference = new SqliteReference(options.db);
    this.state = new SqliteState(options.db);
    this.accounts = new OfflineAccounts(options.db, () => this.now());
  }

  status(): GatewayStatus {
    return {
      online: this.online,
      ready: this.reference.settings() !== null,
      syncing: this.running !== null,
      pending: this.queue.pendingCount(),
      rejected: this.queue.rejected(),
      conflicts: this.report?.conflicts.length ?? 0,
      lastSyncAt: this.state.get('last_sync_at'),
      clockSkewSeconds: this.report?.clockSkewSeconds ?? null,
    };
  }

  /** Attend la fin de la synchronisation en cours. */
  async idle(): Promise<void> {
    while (this.running) await this.running;
  }

  /** Envoie ce qui attend puis rafraîchit le référentiel. Sans effet si personne n'est connecté. */
  syncNow(): Promise<void> {
    if (this.running) {
      this.again = true;
      return this.running;
    }
    this.running = (async () => {
      try {
        do {
          this.again = false;
          await this.synchroniseOnce();
        } while (this.again);
      } finally {
        this.running = null;
      }
    })();
    return this.running;
  }

  async handle(request: Request): Promise<Response> {
    const url = new URL(request.url);
    const route = url.pathname.replace(/^\/api/, '');
    const override = request.headers.get('X-HTTP-Method-Override');
    const method = request.method === 'POST' && override ? override : request.method;
    const text = ['GET', 'HEAD'].includes(request.method) ? '' : await request.text();
    const body = (): Body => (text ? (JSON.parse(text) as Body) : {});
    try {
      if (route === '/_desktop/status') return json(this.status());
      if (route === '/_desktop/sync') {
        await this.syncNow();
        return json(this.status());
      }
      if (route === '/auth/login') return await this.login(body(), text);
      if (route === '/auth/mfa/verify') return await this.verifyMfa(body(), text);

      const bearer = (request.headers.get('Authorization') ?? '').replace(/^Bearer /, '');
      if (route === '/auth/refresh') {
        const wanted = body().refresh;
        for (const [access, s] of this.sessions) {
          if (s.refreshToken === wanted) return json({ access, refresh: s.refreshToken });
        }
        return fail('invalid_refresh_token', 401);
      }
      if (route === '/auth/logout') return await this.logout(String(body().refresh ?? ''));

      const session = this.sessions.get(bearer);
      // Pages publiques (inscription, annuaire, activation) : relayées sans identité.
      if (!session) {
        if (bearer || route.startsWith('/app') || route.startsWith('/admin')) {
          return fail('unauthorized', 401);
        }
        return await this.forward(route + url.search, request, text, null);
      }
      this.current = session;

      const local = await this.local(session, method, route, url.searchParams, body);
      if (local) return local;

      if (route === '/auth/me' && !session.server) return json(session.profile);
      let response: Response;
      try {
        response = await this.forward(route + url.search, request, text, session);
      } catch (e) {
        const spare = e instanceof Offline && this.fallback(session, route, url.searchParams, body);
        if (spare) return spare;
        throw e;
      }
      if (route === '/auth/pin' && response.ok)
        this.accounts.setPin(session.email, String(body().pin ?? ''));
      return response;
    } catch (e) {
      if (e instanceof Offline) return fail('offline', 503);
      if (e instanceof PosError) {
        const { productId, ...rest } = e.details;
        const details = productId === undefined ? rest : { ...rest, product_id: productId };
        return fail(e.code, ERROR_STATUS[e.code] ?? 422, details);
      }
      console.error('passerelle', route, e);
      return fail('server_error', 500);
    }
  }

  private now(): Date {
    return this.options.now?.() ?? new Date();
  }

  private async send(path: string, init: RequestInit): Promise<Response> {
    try {
      const response = await (this.options.fetch ?? fetch)(
        `${this.options.serverUrl}${path}`,
        init,
      );
      this.online = true;
      return response;
    } catch {
      this.online = false;
      throw new Offline();
    }
  }

  private post(path: string, body: unknown): Promise<Response> {
    return this.send(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    });
  }

  private async login(body: Body, text: string): Promise<Response> {
    const email = String(body.email ?? '');
    const password = String(body.password ?? '');
    let response: Response;
    try {
      response = await this.send('/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: text,
      });
    } catch (e) {
      if (!(e instanceof Offline)) throw e;
      const result = this.accounts.signIn(email, password);
      if (result === 'unknown') return fail('offline', 503);
      if (result === 'wrong') return fail('invalid_credentials', 401);
      if (result === 'locked') return fail('account_locked', 423);
      return this.start({ email, password, profile: result, server: null });
    }
    const data = (await response.json()) as Auth;
    if (!response.ok) {
      this.accounts.forget(email);
      return json(data, response.status);
    }
    if (data.mfa_required) {
      this.accounts.forget(email);
      this.awaitingMfa.set(String(data.mfa_token ?? ''), { email, password });
      return json(data);
    }
    return this.signedIn(email, password, data);
  }

  private async verifyMfa(body: Body, text: string): Promise<Response> {
    const challenge = String(body.mfa_token ?? '');
    const pending = this.awaitingMfa.get(challenge);
    const response = await this.send('/auth/mfa/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: text,
    });
    const data = (await response.json()) as Auth;
    if (!response.ok || !pending) return json(data, response.status);
    this.awaitingMfa.delete(challenge);
    // Avec la double vérification, le mot de passe seul ne rouvre rien : on ne le conserve pas.
    return this.signedIn(pending.email, null, data);
  }

  private async signedIn(email: string, password: string | null, data: Auth): Promise<Response> {
    const { access, refresh } = data;
    if (!access || !refresh) return fail('server_error', 502);
    const me = await this.send('/auth/me', {
      headers: { Authorization: `Bearer ${access}` },
    });
    const profile = (await me.json()) as Profile;
    if (password !== null) this.accounts.remember(email, password, profile);
    const response = this.start({
      email,
      password,
      profile,
      server: { access, refresh },
      user: data.user,
    });
    // Premier démarrage : on attend le référentiel pour que la caisse soit utilisable.
    const first = this.reference.settings() === null;
    const sync = this.syncNow();
    if (first) await sync;
    return response;
  }

  private start({
    user,
    ...session
  }: Omit<Session, 'refreshToken'> & { user?: unknown }): Response {
    const access = token('local');
    const refreshToken = token('localr');
    const full = { ...session, refreshToken };
    this.sessions.set(access, full);
    this.current = full;
    return json({
      access,
      refresh: refreshToken,
      user: user ?? { id: session.profile.id, role: session.profile.role },
    });
  }

  private async logout(refresh: string): Promise<Response> {
    for (const [access, s] of this.sessions) {
      if (s.refreshToken !== refresh) continue;
      this.sessions.delete(access);
      if (this.current === s) this.current = null;
      if (s.server) {
        await this.post('/auth/logout', { refresh: s.server.refresh }).catch(() => undefined);
      }
    }
    return new Response(null, { status: 204 });
  }

  /** Jetons du serveur de la session, obtenus ou renouvelés au besoin. */
  private async serverTokens(session: Session, renew = false): Promise<string | null> {
    if (session.server && !renew) return session.server.access;
    if (session.server) {
      const r = await this.post('/auth/refresh', { refresh: session.server.refresh });
      if (r.ok) {
        const t = (await r.json()) as { access: string; refresh: string };
        session.server = t;
        return t.access;
      }
      session.server = null;
    }
    if (session.password === null) return null;
    const r = await this.post('/auth/login', {
      email: session.email,
      password: session.password,
      device_label: 'Poste Sanara',
    });
    const data = (await r.json()) as Auth;
    if (!r.ok || !data.access || !data.refresh) {
      if (r.status === 401 || r.status === 403) this.accounts.forget(session.email);
      return null;
    }
    session.server = { access: data.access, refresh: data.refresh };
    return data.access;
  }

  private async authorized(session: Session, path: string, init: RequestInit): Promise<Response> {
    const attempt = async (access: string | null) => {
      const headers = new Headers(init.headers);
      if (access) headers.set('Authorization', `Bearer ${access}`);
      return this.send(path, { ...init, headers });
    };
    const response = await attempt(await this.serverTokens(session));
    if (response.status !== 401) return response;
    const renewed = await this.serverTokens(session, true);
    return renewed ? attempt(renewed) : response;
  }

  private async forward(
    path: string,
    request: Request,
    text: string,
    session: Session | null,
  ): Promise<Response> {
    const headers: Record<string, string> = {};
    for (const name of ['Content-Type', 'Accept', 'Accept-Language', 'X-HTTP-Method-Override']) {
      const value = request.headers.get(name);
      if (value) headers[name] = value;
    }
    const init: RequestInit = { method: request.method, headers, body: text || undefined };
    return session ? this.authorized(session, path, init) : this.send(path, init);
  }

  private async synchroniseOnce(): Promise<void> {
    const session = this.current;
    if (!session || session.profile.tenant_id === null) return;
    if (!session.profile.permissions.includes('sales.create')) return;
    const transport = createHttpTransport({
      baseUrl: '',
      token: () => '',
      fetch: async (path, init) => {
        const headers = new Headers(init?.headers);
        headers.delete('Authorization');
        return this.authorized(session, String(path), { ...init, headers });
      },
    });
    try {
      const report = await synchronise(this.queue, transport, this.reference, {
        deviceId: this.options.deviceId,
        userId: session.profile.id,
      });
      this.report = report;
      if (report.refreshed) this.state.set('last_sync_at', this.now().toISOString());
    } catch (e) {
      console.error('synchronisation', e);
    }
  }

  /** Routes traitées sur le poste. null : à relayer au serveur. */
  private async local(
    session: Session,
    method: string,
    route: string,
    query: URLSearchParams,
    body: () => Body,
  ): Promise<Response | null> {
    if (!route.startsWith('/app/') || session.profile.tenant_id === null) return null;

    const pos = new LocalPos(this.options.db, {
      deviceId: this.options.deviceId,
      user: { id: session.profile.id, permissions: session.profile.permissions },
      now: () => this.now(),
      online: () => this.online,
    });
    const sells = () => {
      if (!session.profile.permissions.includes('sales.create')) throw new PosError('forbidden');
    };
    const changed = (response: Response) => {
      void this.syncNow();
      return response;
    };
    const picker = query.get('picker') === '1' && this.reference.settings() !== null;
    let m: RegExpMatchArray | null;

    if (route === '/app/products' && method === 'GET' && picker) return this.search(query);
    if ((m = route.match(/^\/app\/stock\/products\/([^/]+)\/on-hand$/)) && picker) {
      return json({ product_id: m[1], on_hand: pos.available(m[1]!), lots: [] });
    }

    if (route === '/app/cash/current') {
      sells();
      const s = pos.currentSession();
      return json({
        session: s && {
          id: s.id,
          station: s.station,
          opening_float: s.openingFloat,
          stale: s.stale,
        },
      });
    }
    if (route === '/app/cash/open') {
      sells();
      const b = body();
      return changed(
        json({ id: pos.openCash(String(b.station ?? ''), Number(b.opening_float ?? 0)) }, 201),
      );
    }
    if ((m = route.match(/^\/app\/cash\/sessions\/([^/]+)\/(preview|close|z)$/))) {
      sells();
      const report = pos.report(m[1]!);
      if (!report) return null;
      if (m[2] === 'z') return html(zReport(report, this.header()));
      if (m[2] === 'close') {
        const b = body();
        pos.closeCash(b.counts ?? [], b.reason);
        return changed(json({ session: { id: m[1] } }));
      }
      return json({
        session: { id: report.id, station: report.station },
        totals: { expected_cash: report.expectedCash, opening_float: report.openingFloat },
      });
    }
    if (route === '/app/sales' && method === 'POST') {
      sells();
      const b = body();
      const done = pos.sell({
        lines: (b.lines ?? []).map((l) => ({
          productId: l.product_id,
          qty: l.qty,
          discountBp: l.discount_bp,
        })),
        payments: b.payments ?? [],
        customerName: b.customer_name,
        prescription: b.prescription && {
          ref: b.prescription.ref,
          prescriber: b.prescription.prescriber,
          patientName: b.prescription.patient_name,
        },
      });
      return changed(json({ ...done, id: done.saleId }, 201));
    }
    if ((m = route.match(/^\/app\/sales\/([^/]+)\/receipt$/))) {
      const printed = pos.print(m[1]!);
      return printed ? html(receipt(printed.sale, this.header(), printed.duplicate)) : null;
    }
    if (route === '/app/pos/held') {
      sells();
      if (method === 'GET') return json({ items: pos.held() });
      const b = body();
      return json({ id: pos.hold(String(b.label ?? ''), b.cart) }, 201);
    }
    if ((m = route.match(/^\/app\/pos\/held\/([^/]+)$/)) && method === 'DELETE') {
      sells();
      pos.resume(m[1]!);
      return new Response(null, { status: 204 });
    }
    return null;
  }

  private search(query: URLSearchParams): Response {
    const items = this.reference.search(query.get('q') ?? '', Number(query.get('limit') ?? 20));
    return json({ items: items.map(productView) });
  }

  /** Réponses de secours, quand le serveur ne répond pas à une lecture dont le poste a une copie. */
  private fallback(
    session: Session,
    route: string,
    query: URLSearchParams,
    body: () => Body,
  ): Response | null {
    if (route === '/auth/pin/verify') {
      return json({ valid: this.accounts.checkPin(session.email, String(body().pin ?? '')) });
    }
    if (session.profile.tenant_id === null) return null;
    if (route === '/app/products') return this.search(query);
    const product = route.match(/^\/app\/products\/([^/]+)$/);
    if (product) {
      const p = this.reference.product(product[1]!);
      return p ? json(productView(p)) : null;
    }
    if (route.startsWith('/app/sales/alternatives/') || route === '/app/approvers') {
      return json({ items: [] });
    }
    return null;
  }

  private header() {
    return (
      this.reference.settings()?.pharmacy ?? {
        name: '',
        address: '',
        phone: '',
        licenseNumber: '',
        currency: 'XAF',
        ticketFooter: '',
        ticketWidth: '80',
      }
    );
  }
}

const productView = (p: {
  id: string;
  name: string;
  dci: string | null;
  form: string | null;
  strength: string | null;
  salePrice: number;
  vatBp: number;
  regulatoryStatus: string;
}) => ({
  id: p.id,
  name: p.name,
  dci: p.dci,
  form: p.form,
  strength: p.strength,
  sale_price: p.salePrice,
  vat_bp: p.vatBp,
  regulatory_status: p.regulatoryStatus,
});
