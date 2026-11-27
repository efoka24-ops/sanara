CREATE TABLE pharmacy_photos (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  storage_id CHAR(26) NOT NULL,
  mime VARCHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_pharmacy_photos ON pharmacy_photos (tenant_id, created_at);
ALTER TABLE public_pharmacies ADD COLUMN photos TEXT
