ALTER TABLE pos_sessions ADD COLUMN opened_at TEXT NOT NULL DEFAULT '';
ALTER TABLE pos_sessions ADD COLUMN counted INTEGER;
ALTER TABLE pos_sessions ADD COLUMN gap_reason TEXT;

-- Ventes faites sur ce poste : réimpression du ticket et rapport Z sans réseau.
CREATE TABLE pos_sales (
  id TEXT PRIMARY KEY,
  session_id TEXT NOT NULL,
  data_json TEXT NOT NULL,
  printed_count INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_pos_sales_session ON pos_sales (session_id);

CREATE TABLE pos_held (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  label TEXT NOT NULL,
  cart_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

-- Empreintes (scrypt) permettant la connexion sans réseau. Jamais de mot de passe en clair.
CREATE TABLE offline_accounts (
  email TEXT PRIMARY KEY,
  profile_json TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  pin_hash TEXT,
  last_online_at TEXT NOT NULL,
  failures INTEGER NOT NULL DEFAULT 0,
  locked_until TEXT
);
