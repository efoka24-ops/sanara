CREATE TABLE subscription_notices (
  id CHAR(26) NOT NULL PRIMARY KEY,
  pharmacy_id CHAR(26) NOT NULL,
  kind VARCHAR(30) NOT NULL,
  period_end DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE (pharmacy_id, kind, period_end)
)
