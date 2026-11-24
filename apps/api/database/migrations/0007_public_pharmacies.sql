CREATE TABLE public_pharmacies (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL UNIQUE,
  pharmacy_name VARCHAR(160) NOT NULL,
  region VARCHAR(80) NOT NULL,
  city VARCHAR(80) NOT NULL,
  district VARCHAR(80),
  address VARCHAR(255),
  phone VARCHAR(32),
  visible TINYINT NOT NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_public_pharmacies_visible_region_city ON public_pharmacies (visible, region, city);
CREATE INDEX idx_public_pharmacies_name ON public_pharmacies (pharmacy_name);
CREATE INDEX idx_public_pharmacies_city ON public_pharmacies (city);
