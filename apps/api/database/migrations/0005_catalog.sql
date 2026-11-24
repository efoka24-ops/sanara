CREATE TABLE product_families (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  name VARCHAR(120) NOT NULL,
  UNIQUE (tenant_id, name)
);
CREATE TABLE generic_groups (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  name VARCHAR(160) NOT NULL,
  UNIQUE (tenant_id, name)
);
CREATE TABLE products (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  name VARCHAR(190) NOT NULL,
  dci VARCHAR(190),
  form VARCHAR(60),
  strength VARCHAR(60),
  pack VARCHAR(60),
  lab VARCHAR(120),
  family_id CHAR(26),
  generic_group_id CHAR(26),
  is_generic TINYINT NOT NULL,
  regulatory_status VARCHAR(20) NOT NULL,
  vat_bp INT NOT NULL,
  purchase_price BIGINT NOT NULL,
  sale_price BIGINT NOT NULL,
  min_stock INT NOT NULL,
  max_stock INT,
  active TINYINT NOT NULL,
  created_by CHAR(26),
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_products_tenant_name ON products (tenant_id, name);
CREATE INDEX idx_products_tenant_dci ON products (tenant_id, dci);
CREATE INDEX idx_products_group ON products (tenant_id, generic_group_id);
CREATE TABLE product_barcodes (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  product_id CHAR(26) NOT NULL,
  code VARCHAR(64) NOT NULL,
  UNIQUE (tenant_id, code)
);
CREATE INDEX idx_barcodes_product ON product_barcodes (product_id);
CREATE TABLE price_history (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  product_id CHAR(26) NOT NULL,
  kind VARCHAR(12) NOT NULL,
  old_price BIGINT,
  new_price BIGINT NOT NULL,
  reason VARCHAR(190),
  user_id CHAR(26),
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_pricehist_product ON price_history (tenant_id, product_id, created_at);
CREATE TABLE reference_products (
  id CHAR(26) NOT NULL PRIMARY KEY,
  country_code CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  dci VARCHAR(190),
  form VARCHAR(60),
  strength VARCHAR(60),
  pack VARCHAR(60),
  family VARCHAR(120),
  regulatory_status VARCHAR(20) NOT NULL,
  is_generic TINYINT NOT NULL,
  UNIQUE (country_code, name, form, strength)
)
