CREATE TABLE opening_hours (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  weekday SMALLINT NOT NULL,
  is_open TINYINT NOT NULL,
  opens VARCHAR(5),
  closes VARCHAR(5),
  UNIQUE (tenant_id, weekday)
);
CREATE TABLE onboarding_progress (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  step_key VARCHAR(32) NOT NULL,
  status VARCHAR(12) NOT NULL,
  data JSON,
  updated_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, step_key)
)
