CREATE TABLE storefront_profiles (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL UNIQUE,
  region VARCHAR(80) NOT NULL,
  department VARCHAR(80) NOT NULL,
  city VARCHAR(80) NOT NULL,
  district VARCHAR(80) NOT NULL,
  latitude DECIMAL(9,6),
  longitude DECIMAL(9,6),
  public_email VARCHAR(190),
  services TEXT NOT NULL,
  insurances TEXT NOT NULL,
  updated_at DATETIME(3) NOT NULL
);
CREATE TABLE duty_periods (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  starts_at DATETIME(3) NOT NULL,
  ends_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_duty_tenant ON duty_periods (tenant_id, ends_at);
ALTER TABLE public_pharmacies ADD COLUMN duties TEXT;
ALTER TABLE public_pharmacies ADD COLUMN services TEXT;
ALTER TABLE public_pharmacies ADD COLUMN insurances TEXT;
ALTER TABLE public_pharmacies ADD COLUMN email VARCHAR(190)
