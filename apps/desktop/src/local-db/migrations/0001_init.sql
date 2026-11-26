-- Opérations produites par ce poste, conservées après acquittement pour ne jamais les rejouer.
CREATE TABLE operations (
  id TEXT PRIMARY KEY,
  device_id TEXT NOT NULL,
  type TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  schema_version INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  acked_at TEXT
);

CREATE INDEX idx_operations_pending ON operations (created_at, id) WHERE acked_at IS NULL;

-- Opérations reçues du serveur et déjà appliquées.
CREATE TABLE applied_operations (
  id TEXT PRIMARY KEY,
  applied_at TEXT NOT NULL
);

-- État de la synchronisation (curseur de lecture, identité du poste).
CREATE TABLE sync_state (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
