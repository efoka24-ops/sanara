# Data Model : Sanara (MySQL 8.0, utf8mb4)

**Conventions**
- Clés primaires : `id CHAR(26)` en **ULID** (générées côté client ou serveur, triables dans le temps).
- Toute table métier d'une pharmacie porte `tenant_id CHAR(26) NOT NULL` en première colonne des index composites. Le scope global de la couche d'accès l'ajoute à chaque lecture et écriture.
- Colonnes communes : `created_at`, `updated_at` (UTC, `DATETIME(3)`), `created_by`, `deleted_at` (suppression logique) ; tables de journal (mouvements, audit, paiements) **append-only** : jamais de UPDATE/DELETE, corrections par écriture inverse.
- Montants en **entiers** dans la plus petite unité de la devise (XAF n'a pas de décimales ; on stocke aussi `currency` et `minor_unit` du pays). Quantités en `DECIMAL(12,3)` si fractionnables, sinon entier.
- Synchronisation : tables synchronisées ont `version BIGINT`, `device_id`, `client_op_id` (idempotence).
- Mode SQL strict forcé à la connexion.

## 1. Plateforme

| Table | Champs principaux |
|---|---|
| `countries` | code ISO, nom fr/en, devise, unité mineure, fuseau, langue par défaut |
| `country_configs` | country_code, clé, valeur JSON : taxes, documents d'inscription exigés, listes réglementaires, formats téléphone/adresse, textes légaux, référentiel géographique |
| `geo_units` | pays, niveau (région, département, ville, quartier), parent_id, nom, latitude, longitude |
| `plans` | code, nom, prix, durée, quotas (utilisateurs, postes, demandes assistant/mois) |
| `subscriptions` | tenant_id, plan_id, statut (trial, active, grace, readonly, suspended), dates |
| `platform_invoices` | tenant_id, période, montant, statut, référence de paiement |
| `pharmacy_applications` | pays, identité du titulaire, officine, adresse, GPS, n° agrément, n° ordre, email, téléphone, statut (pending, approved, rejected), motif, code de suivi, reviewed_by, reviewed_at |
| `application_documents` | application_id, type, nom, chemin chiffré, taille, hash |
| `jobs` | id, file, charge JSON, tentatives, disponible_à, verrouillé_jusqu'à, statut |
| `audit_logs` | tenant_id (nullable), user_id, device_id, action, entité, entité_id, avant JSON, après JSON, ip, created_at (append-only) |
| `notifications` | destinataire, canal, gabarit, charge, statut, envoyé_à |

## 2. Pharmacie et accès

| Table | Champs principaux |
|---|---|
| `tenants` | pays, nom, slug, statut, adresse, latitude, longitude, téléphone, email, n° agrément, logo, `public_visible`, devise, TVA par défaut, niveau d'autonomie courant |
| `tenant_settings` | tenant_id, clé, valeur JSON (ticket, seuils, délais de retrait, escalade, remises) |
| `opening_hours`, `duty_schedules`, `holidays` | horaires hebdomadaires, gardes, jours fériés |
| `users` | tenant_id (null pour super admin), email, téléphone, hash Argon2id, statut, 2FA (secret chiffré, codes de secours hachés), verrouillage, langue |
| `roles`, `permissions`, `role_permissions`, `user_permission_overrides` | RBAC et surcharges (remise max, annulation, stupéfiants, finances) |
| `invitations` | tenant_id, email, rôle, jeton haché, expiration, statut |
| `devices` | tenant_id, user_id, nom, empreinte, dernier vu, révoqué |
| `refresh_tokens` | user_id, device_id, jeton haché, expiration, rotation |
| `onboarding_progress` | tenant_id, étape, données partielles |

## 3. Catalogue et stock

| Table | Champs principaux |
|---|---|
| `products` | tenant_id, nom, DCI, forme, dosage, conditionnement, laboratoire, famille, statut réglementaire (free, list_i, list_ii, narcotic, prescription_only), taux TVA, prix d'achat, prix de vente, seuil mini/maxi, actif, `generic_group_id` |
| `product_barcodes` | product_id, code (unique par tenant) |
| `price_history` | product_id, ancien/nouveau prix, motif, user_id |
| `product_families`, `generic_groups`, `reference_products` | familles ; groupes d'équivalence ; catalogue de référence partagé (sans tenant) |
| `lots` | tenant_id, product_id, numéro de lot, péremption, statut (ok, quarantine, expired) |
| `stock_movements` | tenant_id, product_id, lot_id, type (receipt, sale, return, adjustment, loss, transfer_out, transfer_in, destruction, inventory), quantité signée, coût unitaire, référence document, motif, user_id, device_id (append-only) |
| `stock_levels` | tenant_id, product_id, lot_id, quantité : vue/cache recalculable depuis `stock_movements` |
| `inventories`, `inventory_lines` | portée, mode aveugle, comptages horodatés, écarts, validation |
| `quarantines`, `destructions` | lots isolés, procès-verbaux |
| `transfers`, `transfer_lines` | site source/destination, statut (sent, in_transit, received) |

## 4. Ventes et caisse

| Table | Champs principaux |
|---|---|
| `document_sequences` | tenant_id, device_id, type, préfixe, dernier numéro |
| `sales` | tenant_id, numéro, device_id, caissier, client_id, prescription_id, statut, totaux (HT, TVA, remise, TTC), `request_id` (si issu d'une demande patient) |
| `sale_lines` | sale_id, product_id, lot_id, quantité, prix unitaire, remise, TVA |
| `payments` | sale_id, moyen (cash, mobile_money, card, credit), montant, référence, cash_session_id (append-only) |
| `cash_sessions`, `cash_counts` | ouverture (fond de caisse), clôture, écart, motif |
| `held_carts` | paniers en attente par poste |
| `sale_returns`, `credit_notes` | retours, motif, remise en stock oui/non, approbation |
| `customers`, `credit_accounts`, `loyalty_points` | clients, plafond, solde, points |
| `insurers`, `coverages`, `claim_batches` | tiers payant (V3) |

## 5. Achats et finances

| Table | Champs principaux |
|---|---|
| `suppliers`, `purchase_orders`, `purchase_order_lines`, `receipts`, `receipt_lines`, `supplier_invoices` | cycle d'achat avec lot et péremption à la réception |
| `supplier_returns`, `supplier_credits`, `payment_schedules` | retours, avoirs, échéances |
| `expenses`, `ledger_entries`, `period_locks` | dépenses, écritures, verrouillage de période |

## 6. Patients et ordonnances (officine)

| Table | Champs principaux |
|---|---|
| `patients` | tenant_id, identité, naissance, téléphone, allergies, antécédents (champs sensibles chiffrés), consentement |
| `prescriptions`, `prescription_lines` | prescripteur, date, lignes, délivré/restant, scan chiffré |
| `controlled_register` | tenant_id, produit, lot, quantité, patient, prescripteur, pharmacien (append-only) |

## 7. Vitrine publique et assistant

| Table | Champs principaux |
|---|---|
| `public_pharmacies` | tenant_id, slug, nom, région/département/ville/quartier, latitude, longitude, horaires résumés, garde, services, assurances, visibilité : table de lecture alimentée à chaque changement, index sur (latitude, longitude) et sur le référentiel géographique |
| `pharmacy_services`, `pharmacy_photos`, `public_stock_flags` | services, photos, disponibilité opt-in (V3) |
| `patient_requests` | tenant_id, code de suivi, prénom, téléphone, mode (pickup), statut (received, reading, processing, quoted, accepted, ready, picked_up, cancelled, expired), urgence, niveau d'autonomie appliqué, assigned_to, verrou |
| `request_documents` | request_id, chemin chiffré, type, pages, hash (détection de doublons) |
| `request_lines` | request_id, texte lu, produit proposé, confiance, produit final, statut de correction |
| `quotes`, `quote_lines` | prix, total, validité, stock réservé, décision patient |
| `pickups` | request_id, code de retrait, prête_à, retirée_à, vente liée |
| `consents` | sujet (patient/demande), version du texte, date, IP/poste |
| `message_threads`, `messages` | échanges patient ↔ pharmacie/assistant |
| `assistant_runs` | request_id, fournisseur/modèle, durée, coût estimé, sortie JSON, garde-fous déclenchés |
| `assistant_corrections` | request_line_id, valeur lue, valeur corrigée, auteur, catégorie d'erreur |
| `autonomy_policies` | tenant_id, niveau (1/2/3), seuil de confiance, échantillonnage, historique des changements |
| `reliability_stats` | agrégats par période : lectures, acceptées sans correction, erreurs par catégorie |
| `sampling_reviews` | devis automatiques à contrôler, résultat |
| `presence_sessions` | user_id, tenant_id, dernier heartbeat, statut |
| `escalation_policies`, `escalation_attempts` | ordre, délais, canaux ; tentatives et réponses |

## 8. Synchronisation

| Table | Champs principaux |
|---|---|
| `sync_operations` | tenant_id, device_id, client_op_id (unique), type, charge JSON, version de schéma, reçu_à, appliqué_à, statut |
| `sync_conflicts` | opération, règle appliquée, valeurs en conflit, résolution |
| `sync_cursors` | device_id, curseur de lecture |

## 9. Règles d'intégrité clés

1. `UNIQUE (tenant_id, barcode)` ; `UNIQUE (tenant_id, device_id, numéro)` pour les ventes ; `UNIQUE (device_id, client_op_id)` pour la synchro.
2. Le stock courant = somme de `stock_movements` ; `stock_levels` est reconstruit en cas de doute (commande de réconciliation).
3. Une vente n'est jamais supprimée : annulation et retour créent des écritures inverses.
4. Un produit `narcotic` ou `list_i/list_ii` ne peut jamais être inclus dans un devis automatique (contrôlé en base par la couche applicative et par un test).
5. Les données patients et documents sont chiffrés au niveau applicatif (sodium) ; les clés ne sont jamais stockées dans la base.
6. Index obligatoires : `(tenant_id, …)` sur toutes les tables ; FULLTEXT sur `products(nom, dci)` ; `(latitude, longitude)` sur `public_pharmacies`.
