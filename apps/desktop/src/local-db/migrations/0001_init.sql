CREATE TABLE IF NOT EXISTS operations (
  id TEXT PRIMARY KEY,
  op_type TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  created_at TEXT NOT NULL,
  acked_at TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_operations_pending
  ON operations (acked_at, created_at);
