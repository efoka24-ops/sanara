/** Comptes utilisables sans réseau : empreintes scrypt, verrouillage, validité limitée. */
import { randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';
import type { LocalDatabase } from '../local-db';

export interface Profile {
  id: string;
  role: string;
  tenant_id: string | null;
  permissions: string[];
  mfa_setup_required: boolean;
  mfa_enabled: boolean;
}

const VALID_DAYS = 30;
const MAX_FAILURES = 5;
const LOCK_MINUTES = 15;
const COST = { N: 1 << 15, r: 8, p: 1, maxmem: 64 * 1024 * 1024 };

function hash(secret: string, salt = randomBytes(16)): string {
  return `${salt.toString('hex')}:${scryptSync(secret, salt, 32, COST).toString('hex')}`;
}

function matches(secret: string, stored: string): boolean {
  const [salt, expected] = stored.split(':');
  const actual = scryptSync(secret, Buffer.from(salt!, 'hex'), 32, COST);
  return timingSafeEqual(actual, Buffer.from(expected!, 'hex'));
}

interface Row {
  profile_json: string;
  password_hash: string;
  pin_hash: string | null;
  last_online_at: string;
  failures: number;
  locked_until: string | null;
}

export class OfflineAccounts {
  constructor(
    private readonly db: LocalDatabase,
    private readonly now: () => Date,
  ) {}

  /** Après une connexion en ligne réussie. La double vérification interdit le hors-ligne. */
  remember(email: string, password: string, profile: Profile): void {
    if (profile.tenant_id === null || profile.mfa_enabled || profile.mfa_setup_required) {
      this.forget(email);
      return;
    }
    this.db
      .prepare(
        `INSERT INTO offline_accounts (email, profile_json, password_hash, last_online_at)
         VALUES (?, ?, ?, ?)
         ON CONFLICT(email) DO UPDATE SET profile_json = excluded.profile_json,
           password_hash = excluded.password_hash, last_online_at = excluded.last_online_at,
           failures = 0, locked_until = NULL`,
      )
      .run(key(email), JSON.stringify(profile), hash(password), this.now().toISOString());
  }

  forget(email: string): void {
    this.db.prepare('DELETE FROM offline_accounts WHERE email = ?').run(key(email));
  }

  signIn(email: string, password: string): Profile | 'unknown' | 'wrong' | 'locked' {
    const row = this.row(email);
    const limit = this.now().getTime() - VALID_DAYS * 86_400_000;
    if (!row || Date.parse(row.last_online_at) < limit) return 'unknown';
    if (row.locked_until && row.locked_until > this.now().toISOString()) return 'locked';
    if (!matches(password, row.password_hash)) {
      const failures = row.failures + 1;
      const until =
        failures >= MAX_FAILURES
          ? new Date(this.now().getTime() + LOCK_MINUTES * 60_000).toISOString()
          : null;
      this.db
        .prepare('UPDATE offline_accounts SET failures = ?, locked_until = ? WHERE email = ?')
        .run(until ? 0 : failures, until, key(email));
      return 'wrong';
    }
    this.db
      .prepare('UPDATE offline_accounts SET failures = 0, locked_until = NULL WHERE email = ?')
      .run(key(email));
    return JSON.parse(row.profile_json) as Profile;
  }

  setPin(email: string, pin: string): void {
    this.db
      .prepare('UPDATE offline_accounts SET pin_hash = ? WHERE email = ?')
      .run(hash(pin), key(email));
  }

  checkPin(email: string, pin: string): boolean {
    const stored = this.row(email)?.pin_hash;
    return stored ? matches(pin, stored) : false;
  }

  private row(email: string): Row | undefined {
    return this.db.prepare('SELECT * FROM offline_accounts WHERE email = ?').get(key(email)) as
      Row | undefined;
  }
}

const key = (email: string) => email.trim().toLowerCase();
