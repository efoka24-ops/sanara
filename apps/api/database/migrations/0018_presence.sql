CREATE TABLE presence (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  user_id CHAR(26) NOT NULL,
  available TINYINT NOT NULL,
  last_seen_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, user_id)
);
ALTER TABLE patient_requests ADD COLUMN phone_key VARCHAR(20);
ALTER TABLE patient_requests ADD COLUMN escalation_state VARCHAR(12);
ALTER TABLE patient_requests ADD COLUMN escalation_step SMALLINT;
ALTER TABLE patient_requests ADD COLUMN escalation_next_at DATETIME(3);
CREATE INDEX idx_requests_escalation ON patient_requests (escalation_state, escalation_next_at);
CREATE TABLE escalation_attempts (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  request_id CHAR(26) NOT NULL,
  step SMALLINT NOT NULL,
  channel VARCHAR(10) NOT NULL,
  user_id CHAR(26),
  result VARCHAR(20) NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_escalation_attempts ON escalation_attempts (tenant_id, request_id)
