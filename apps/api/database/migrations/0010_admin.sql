CREATE TABLE plans (
  id CHAR(26) NOT NULL PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  price_month BIGINT NOT NULL,
  max_stations INT NOT NULL,
  features VARCHAR(500),
  active TINYINT NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE TABLE subscriptions (
  id CHAR(26) NOT NULL PRIMARY KEY,
  pharmacy_id CHAR(26) NOT NULL UNIQUE,
  plan_id CHAR(26) NOT NULL,
  status VARCHAR(12) NOT NULL,
  stations INT NOT NULL,
  trial_ends_at DATETIME(3),
  period_end DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL
);
CREATE TABLE platform_invoices (
  id CHAR(26) NOT NULL PRIMARY KEY,
  pharmacy_id CHAR(26) NOT NULL,
  number VARCHAR(30) NOT NULL UNIQUE,
  amount BIGINT NOT NULL,
  status VARCHAR(10) NOT NULL,
  due_at DATETIME(3) NOT NULL,
  paid_at DATETIME(3),
  method VARCHAR(20),
  reference VARCHAR(80),
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_platform_invoices_pharmacy ON platform_invoices (pharmacy_id, created_at);
CREATE TABLE reports (
  id CHAR(26) NOT NULL PRIMARY KEY,
  kind VARCHAR(24) NOT NULL,
  pharmacy_id CHAR(26),
  subject VARCHAR(160) NOT NULL,
  details TEXT,
  status VARCHAR(12) NOT NULL,
  note VARCHAR(190),
  created_at DATETIME(3) NOT NULL,
  handled_by CHAR(26),
  handled_at DATETIME(3)
);
CREATE TABLE message_templates (
  id CHAR(26) NOT NULL PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  locale CHAR(2) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  updated_by CHAR(26),
  updated_at DATETIME(3) NOT NULL,
  UNIQUE (code, locale)
);
CREATE TABLE platform_settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  svalue VARCHAR(255) NOT NULL,
  updated_at DATETIME(3) NOT NULL
)
