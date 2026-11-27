ALTER TABLE plans ADD COLUMN monthly_readings INT;
CREATE TABLE assistant_runs (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  request_id CHAR(26) NOT NULL,
  user_id CHAR(26) NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_assistant_runs ON assistant_runs (tenant_id, created_at)
