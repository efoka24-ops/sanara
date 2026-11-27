/**
 * T128 : mises à jour automatiques du poste. Téléchargées en arrière-plan depuis le serveur Sanara,
 * installées seulement quand aucune caisse n'est ouverte (au redémarrage demandé ou à la fermeture).
 * Retour arrière : republier l'ancienne version comme « dernière » ; la rétrogradation est permise.
 * Désactivées tant que le serveur n'est pas en HTTPS : un paquet ne doit pas arriver par un canal
 * modifiable en chemin.
 */

/** Ce que nous utilisons d'electron-updater ; remplaçable dans les tests. */
export interface AutoUpdaterLike {
  autoDownload: boolean;
  autoInstallOnAppQuit: boolean;
  allowDowngrade: boolean;
  setFeedURL(options: { provider: 'generic'; url: string }): void;
  checkForUpdates(): Promise<unknown>;
  quitAndInstall(isSilent?: boolean, isForceRunAfter?: boolean): void;
  on(event: string, listener: (...args: unknown[]) => void): unknown;
}

export type UpdateStatus = 'disabled' | 'idle' | 'checking' | 'downloading' | 'ready' | 'error';

export interface UpdateState {
  status: UpdateStatus;
  /** Version proposée ou prête à installer. */
  version: string | null;
  /** Pourquoi les mises à jour sont désactivées, ou la dernière erreur. */
  reason: string | null;
}

export class TillOpenError extends Error {
  constructor() {
    super('till_open');
  }
}

export const CHECK_EVERY_MS = 6 * 60 * 60 * 1000;

/** Adresse des paquets publiés à côté du serveur ; null si elle ne convient pas. */
export function updateFeed(serverUrl: string): string | null {
  try {
    const url = new URL(serverUrl);
    return url.protocol === 'https:' ? `${url.origin}/desktop-releases/` : null;
  } catch {
    return null;
  }
}

export class Updater {
  private current: UpdateState = { status: 'idle', version: null, reason: null };
  private timer: ReturnType<typeof setInterval> | null = null;

  constructor(
    private readonly options: {
      updater: AutoUpdaterLike | null;
      serverUrl: string;
      /** Application installée (pas lancée depuis les sources). */
      packaged: boolean;
      /** Une caisse est ouverte sur ce poste. */
      tillOpen: () => boolean;
      log?: (message: string) => void;
    },
  ) {}

  state(): UpdateState {
    return { ...this.current };
  }

  /** Branche les événements et lance la première vérification. */
  start(): void {
    const { updater, packaged, serverUrl } = this.options;
    const feed = updateFeed(serverUrl);
    if (!updater || !packaged || !feed) {
      this.current = {
        status: 'disabled',
        version: null,
        reason: !packaged ? 'not_packaged' : !feed ? 'https_required' : 'unavailable',
      };
      return;
    }
    updater.autoDownload = true;
    // Installée à la fermeture si personne ne l'a demandée avant.
    updater.autoInstallOnAppQuit = true;
    updater.allowDowngrade = true;
    updater.setFeedURL({ provider: 'generic', url: feed });
    updater.on('checking-for-update', () => this.set('checking'));
    updater.on('update-not-available', () => this.set('idle'));
    updater.on('update-available', (info) => this.set('downloading', versionOf(info)));
    updater.on('update-downloaded', (info) => this.set('ready', versionOf(info)));
    updater.on('error', (e) => {
      this.current = { status: 'error', version: this.current.version, reason: messageOf(e) };
      this.options.log?.(`mise à jour : ${messageOf(e)}`);
    });
    void this.check();
    this.timer = setInterval(() => void this.check(), CHECK_EVERY_MS);
  }

  stop(): void {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
  }

  async check(): Promise<void> {
    // Une mise à jour prête n'est pas retéléchargée.
    if (this.current.status === 'ready' || this.current.status === 'disabled') return;
    try {
      await this.options.updater?.checkForUpdates();
    } catch (e) {
      this.current = { status: 'error', version: this.current.version, reason: messageOf(e) };
    }
  }

  /** Redémarre sur la nouvelle version, jamais pendant qu'une caisse est ouverte. */
  install(): void {
    if (this.current.status !== 'ready') throw new Error('no_update');
    if (this.options.tillOpen()) throw new TillOpenError();
    this.options.updater?.quitAndInstall(true, true);
  }

  private set(status: UpdateStatus, version: string | null = this.current.version): void {
    this.current = { status, version, reason: null };
  }
}

const versionOf = (info: unknown): string | null =>
  typeof info === 'object' &&
  info !== null &&
  typeof (info as { version?: unknown }).version === 'string'
    ? (info as { version: string }).version
    : null;

const messageOf = (e: unknown): string => (e instanceof Error ? e.message : String(e));
