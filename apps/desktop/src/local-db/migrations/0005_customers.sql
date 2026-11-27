-- Comptes clients copiés du serveur, et crédit accordé que cette copie ne reflète pas encore.
CREATE TABLE ref_customers (
  id TEXT PRIMARY KEY,
  search_text TEXT NOT NULL,
  data_json TEXT NOT NULL
);

CREATE TABLE pos_credit (
  operation_id TEXT NOT NULL,
  customer_id TEXT NOT NULL,
  amount INTEGER NOT NULL
);

CREATE INDEX idx_pos_credit_customer ON pos_credit (customer_id);
