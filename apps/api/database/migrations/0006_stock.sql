CREATE TABLE lots (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  product_id CHAR(26) NOT NULL,
  lot_number VARCHAR(60) NOT NULL,
  expiry VARCHAR(10) NOT NULL,
  unit_cost BIGINT NOT NULL,
  status VARCHAR(12) NOT NULL,
  status_reason VARCHAR(190),
  received_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, product_id, lot_number)
);
CREATE INDEX idx_lots_fefo ON lots (tenant_id, product_id, status, expiry);
CREATE INDEX idx_lots_expiry ON lots (tenant_id, expiry);
CREATE TABLE stock_movements (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  product_id CHAR(26) NOT NULL,
  lot_id CHAR(26),
  qty INT NOT NULL,
  kind VARCHAR(16) NOT NULL,
  ref_type VARCHAR(24),
  ref_id CHAR(26),
  reversal_of CHAR(26),
  reason VARCHAR(190),
  user_id CHAR(26),
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_mov_lot ON stock_movements (tenant_id, lot_id, created_at);
CREATE INDEX idx_mov_product ON stock_movements (tenant_id, product_id, created_at);
CREATE TABLE stock_alerts (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  dedupe_key VARCHAR(100) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  product_id CHAR(26) NOT NULL,
  lot_id CHAR(26),
  message VARCHAR(190) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  read_at DATETIME(3),
  UNIQUE (tenant_id, dedupe_key)
);
CREATE TABLE destructions (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  number VARCHAR(30) NOT NULL,
  product_id CHAR(26) NOT NULL,
  lot_id CHAR(26) NOT NULL,
  qty INT NOT NULL,
  reason VARCHAR(190) NOT NULL,
  method VARCHAR(120),
  witness VARCHAR(120),
  user_id CHAR(26) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, number)
);
CREATE TABLE inventories (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  number VARCHAR(30) NOT NULL,
  kind VARCHAR(10) NOT NULL,
  blind TINYINT NOT NULL,
  family_id CHAR(26),
  status VARCHAR(12) NOT NULL,
  created_by CHAR(26) NOT NULL,
  validated_by CHAR(26),
  created_at DATETIME(3) NOT NULL,
  validated_at DATETIME(3),
  gap_value BIGINT,
  UNIQUE (tenant_id, number)
);
CREATE TABLE inventory_lines (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  inventory_id CHAR(26) NOT NULL,
  product_id CHAR(26) NOT NULL,
  lot_id CHAR(26) NOT NULL,
  expected_qty INT,
  counted_qty INT,
  counted_at DATETIME(3),
  unit_cost BIGINT NOT NULL,
  UNIQUE (tenant_id, inventory_id, lot_id)
);
CREATE TABLE inventory_counts (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  inventory_id CHAR(26) NOT NULL,
  lot_id CHAR(26) NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  qty INT NOT NULL,
  counted_at DATETIME(3) NOT NULL,
  user_id CHAR(26),
  UNIQUE (tenant_id, inventory_id, lot_id, device_id)
)
