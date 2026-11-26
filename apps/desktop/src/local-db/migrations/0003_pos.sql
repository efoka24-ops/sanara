-- Sessions de caisse tenues sur le poste.
CREATE TABLE pos_sessions (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  station TEXT NOT NULL,
  opening_float INTEGER NOT NULL,
  cash_total INTEGER NOT NULL DEFAULT 0,
  closed_at TEXT
);

CREATE UNIQUE INDEX idx_pos_sessions_open ON pos_sessions (user_id) WHERE closed_at IS NULL;

-- Quantités vendues que la copie locale du stock ne reflète pas encore.
CREATE TABLE pos_consumption (
  operation_id TEXT NOT NULL,
  product_id TEXT NOT NULL,
  qty INTEGER NOT NULL
);

CREATE INDEX idx_pos_consumption_product ON pos_consumption (product_id);

-- Dernier numéro de ticket par poste et par année.
CREATE TABLE pos_counters (
  scope TEXT PRIMARY KEY,
  value INTEGER NOT NULL
);
