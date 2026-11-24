CREATE TABLE document_sequences (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  scope VARCHAR(60) NOT NULL,
  last_value INT NOT NULL,
  UNIQUE (tenant_id, scope)
);
CREATE TABLE cash_sessions (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  station VARCHAR(12) NOT NULL,
  opened_by CHAR(26) NOT NULL,
  opened_at DATETIME(3) NOT NULL,
  opening_float BIGINT NOT NULL,
  status VARCHAR(10) NOT NULL,
  closed_by CHAR(26),
  closed_at DATETIME(3),
  expected_cash BIGINT,
  counted_cash BIGINT,
  gap BIGINT,
  gap_reason VARCHAR(190),
  z_number VARCHAR(40)
);
CREATE INDEX idx_cash_sessions_user ON cash_sessions (tenant_id, opened_by, status);
CREATE TABLE cash_counts (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  session_id CHAR(26) NOT NULL,
  denomination INT NOT NULL,
  qty INT NOT NULL
);
CREATE TABLE sales (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  number VARCHAR(40) NOT NULL,
  invoice_number VARCHAR(30),
  station VARCHAR(12) NOT NULL,
  session_id CHAR(26) NOT NULL,
  cashier_id CHAR(26) NOT NULL,
  status VARCHAR(12) NOT NULL,
  gross_total BIGINT NOT NULL,
  discount_total BIGINT NOT NULL,
  total BIGINT NOT NULL,
  vat_total BIGINT NOT NULL,
  customer_name VARCHAR(120),
  prescription_ref VARCHAR(60),
  prescriber VARCHAR(120),
  patient_name VARCHAR(120),
  pharmacist_id CHAR(26),
  discount_approved_by CHAR(26),
  printed_count INT NOT NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, number)
);
CREATE INDEX idx_sales_session ON sales (tenant_id, session_id);
CREATE INDEX idx_sales_date ON sales (tenant_id, created_at);
CREATE TABLE sale_lines (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  sale_id CHAR(26) NOT NULL,
  product_id CHAR(26) NOT NULL,
  name VARCHAR(190) NOT NULL,
  regulatory_status VARCHAR(20) NOT NULL,
  qty INT NOT NULL,
  unit_price BIGINT NOT NULL,
  discount_bp INT NOT NULL,
  vat_bp INT NOT NULL,
  line_total BIGINT NOT NULL,
  vat_amount BIGINT NOT NULL
);
CREATE INDEX idx_sale_lines_sale ON sale_lines (tenant_id, sale_id);
CREATE TABLE payments (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  sale_id CHAR(26) NOT NULL,
  session_id CHAR(26) NOT NULL,
  method VARCHAR(16) NOT NULL,
  amount BIGINT NOT NULL,
  tendered BIGINT NOT NULL,
  reference VARCHAR(80),
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_payments_session ON payments (tenant_id, session_id);
CREATE TABLE held_carts (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  user_id CHAR(26) NOT NULL,
  label VARCHAR(60) NOT NULL,
  payload TEXT NOT NULL,
  created_at DATETIME(3) NOT NULL
)
;
CREATE TABLE cash_movements (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  session_id CHAR(26) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  amount BIGINT NOT NULL,
  ref_id CHAR(26),
  reason VARCHAR(190),
  created_at DATETIME(3) NOT NULL
)
