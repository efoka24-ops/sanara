ALTER TABLE public_pharmacies ADD COLUMN country_code CHAR(2);
ALTER TABLE public_pharmacies ADD COLUMN department VARCHAR(80);
ALTER TABLE public_pharmacies ADD COLUMN latitude DECIMAL(9,6);
ALTER TABLE public_pharmacies ADD COLUMN longitude DECIMAL(9,6);
ALTER TABLE public_pharmacies ADD COLUMN hours TEXT
