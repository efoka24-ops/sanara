/** Journal d'opérations idempotent (ULID côté client). Implémenté en Phase 2 (T034+). */
export interface SyncOperation {
  id: string;
  type: string;
  payload: unknown;
  createdAt: string;
}
export const SYNC_PROTOCOL_VERSION = 1;
