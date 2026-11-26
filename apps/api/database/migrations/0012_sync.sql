CREATE TABLE sync_operations (
  id CHAR(26) NOT NULL,
  tenant_id CHAR(26) NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  user_id CHAR(26) NOT NULL,
  type VARCHAR(40) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  received_at DATETIME(3) NOT NULL,
  PRIMARY KEY (tenant_id, id)
);
CREATE TABLE sync_conflicts (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  operation_id CHAR(26) NOT NULL,
  kind VARCHAR(32) NOT NULL,
  details TEXT NOT NULL,
  created_at DATETIME(3) NOT NULL,
  resolved_at DATETIME(3),
  resolved_by CHAR(26)
);
CREATE INDEX idx_sync_conflicts_open ON sync_conflicts (tenant_id, resolved_at)
