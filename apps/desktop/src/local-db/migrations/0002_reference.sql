-- Copie locale du référentiel, remplacée en entier à chaque synchronisation.
CREATE TABLE ref_products (
  id TEXT PRIMARY KEY,
  search_text TEXT NOT NULL,
  active INTEGER NOT NULL,
  data_json TEXT NOT NULL
);

CREATE TABLE ref_barcodes (
  code TEXT PRIMARY KEY,
  product_id TEXT NOT NULL
);

CREATE TABLE ref_lots (
  id TEXT PRIMARY KEY,
  product_id TEXT NOT NULL,
  expiry TEXT NOT NULL,
  received_at TEXT NOT NULL,
  data_json TEXT NOT NULL
);

CREATE INDEX idx_ref_lots_product ON ref_lots (product_id, expiry, received_at);
