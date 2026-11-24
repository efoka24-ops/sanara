---
description: 'Liste de tâches Sanara, organisée user story par user story'
---

# Tasks: Sanara, plateforme de gestion de pharmacie

**Input**: `specs/001-sanara-platform/` (spec.md, plan.md)
**Prerequisites**: spec.md et plan.md validés. research.md et data-model.md produits en phase 0.
**Tests**: obligatoires (TDD) pour les domaines critiques de la constitution VII : prix/TVA, stock FEFO, caisse, synchro, autonomie, escalade, isolation tenant, autorisations. Marqués **[TDD]**. Écrire le test, le voir échouer, puis implémenter.

## Révision hébergement (2026-11-23)

Hébergement imposé : **Camoo mutualisé** (`sanara.trugroup.cm`, MySQL 8.0, FTP). Conséquences sur les tâches ci-dessous : API **PHP** au lieu de NestJS, **MySQL** au lieu de PostgreSQL (pas de RLS ni PostGIS), **React/Vite statique** au lieu de Next.js, **jobs en base + cron** au lieu de BullMQ/Redis, **polling** au lieu de SSE/WebSocket. Quand une tâche cite « Prisma », « NestJS », « RLS », « BullMQ », « SSE », « Next.js » ou « PostGIS », lire l'équivalent correspondant ; les tâches T001, T009, T011, T012, T015 à T018, T026, T029, T030, T082, T137, T144, T188, T191 sont précisées ci-dessous. Les identifiants d'accès (base, FTP) ne figurent jamais dans le dépôt : ils vont dans `.env` (ignoré par git).

## Format : `[ID] [P?] [Story] Description (chemin)`

- **[P]** : parallélisable (fichiers différents, pas de dépendance)
- **[USx]** : user story concernée
- Après chaque story : **Checkpoint** = critères d'acceptation de la story validés, audit et i18n en place, doc utilisateur mise à jour.

## Ordre de livraison

MVP 1 : US1→US8, US10→US12, US27, US28 · MVP 2 : US18, US19, US21→US24, US26, US29 · V2 : US9, US13, US15→US17, US25, US30→US33, US35 · V3 : US14, US20, US34 étendu.

---

## Phase 0 : Recherche et conception (avant tout code)

- [x] T001 Rédiger `specs/001-sanara-platform/research.md` : Electron vs Tauri (décision : Electron), moteur de sync, modèle de vision pour ordonnances, tuiles de carte, passerelle SMS (Cameroun), **audit de l'hébergement Camoo** : HTTPS sur `sanara.trugroup.cm`, version PHP et extensions, quotas disque/base, cron disponibles, taille d'upload, SMTP, comportement du pare-feu (apostrophes), choix Laravel vs Slim (décidé : Slim 4)
- [ ] T001a **Prérequis bloquant pour la production** : obtenir un certificat HTTPS valide sur `sanara.trugroup.cm` ; en attendant, développement en HTTP avec données de test uniquement (dérogation constitution v1.0.1). `APP_URL` pilote le basculement sans changement de code
- [ ] T001b Identifiants Camoo (MySQL, FTP) rangés dans `.env` hors dépôt et utilisés pour le développement et les déploiements au fil des stories ; **les remplacer par de nouveaux identifiants avant la mise en production** (décision du propriétaire : rotation à la bascule en production)
- [x] T002 [P] Rédiger `specs/001-sanara-platform/data-model.md` : entités, relations, clés (ULID), colonnes `tenant_id`, champs d'audit, stratégie de suppression logique
- [ ] T003 [P] Rédiger `specs/001-sanara-platform/contracts/` : squelettes OpenAPI par module (auth, applications, catalog, stock, sales, public, requests, sync)
- [ ] T004 [P] Constituer avec un pharmacien pilote une liste de 100 ordonnances anonymisées annotées (jeu d'évaluation de l'assistant) dans `tests/assistant-eval/fixtures/` (hors dépôt public si données réelles)
- [ ] T005 [P] Valider avec le pharmacien pilote et l'ordre des pharmaciens les exigences locales : registre des stupéfiants, conservation des ordonnances, vente à distance, mentions sur facture
- [ ] T006 [P] Rédiger la liste des documents d'inscription exigés pour le Cameroun (`CountryConfig` initial) et les textes légaux (CGU, consentement patient FR/EN)

## Phase 1 : Setup

- [x] T007 Initialiser le monorepo pnpm : `package.json`, `pnpm-workspace.yaml`, `.gitignore`, `.editorconfig`, `.nvmrc`
- [x] T008 [P] Créer `packages/config` (tsconfig de base, ESLint, Prettier) et l'appliquer à tous les workspaces
- [x] T009 [P] Créer `apps/api` (PHP 8.1 : Slim 4, composer), `apps/web` (React + Vite), `apps/desktop` (Electron) avec scripts `dev`, `build`, `test`
- [x] T010 [P] Créer `packages/shared`, `packages/ui`, `packages/sync`, `packages/assistant`, `packages/i18n` (squelettes exportant un index typé)
- [x] T011 [P] Docker Compose de dev reproduisant Camoo : PHP 8.x (version alignée sur l'hébergeur) + MySQL 8.0 + MailHog dans `infra/docker-compose.dev.yml` (pas de Redis ni PostgreSQL) (écrit, non exécuté : Docker absent de ce poste)
- [x] T012 [P] CI (GitHub Actions) dans `.github/workflows/ci.yml` : lint, typecheck, tests front et PHP, build web, build desktop Windows (écrit, pas encore exécuté sur GitHub)
- [x] T012a [P] Script de déploiement FTP (`infra/deploy/deploy.ps1`) : publie le build web et l'API, exécute les migrations via une commande protégée, lit les identifiants dans l'environnement, jamais dans le dépôt
- [x] T013 [P] Husky + lint-staged + commitlint (messages conventionnels)
- [x] T014 [P] Fichiers `.env.example` par application, validation des variables avec Zod au démarrage (API : Config.php existant ; validation Zod web/desktop à brancher avec la première variable utilisée)

## Phase 2 : Fondations (bloquantes pour toutes les stories)

**⚠️ Aucune user story ne démarre avant la fin de cette phase.**

### Base de données et multi-tenant

- [x] T015 Migrations MySQL initiales (`apps/api/database/migrations/`) : countries, country_configs, tenants, users, roles, permissions, audit_logs, notifications, jobs (les « modèles Prisma » des tâches suivantes deviennent des migrations + modèles Eloquent/PDO)
- [x] T016 **[TDD]** Test d'isolation générique `apps/api/tests/Isolation/TenantIsolationTest.php` : pour chaque modèle portant `tenant_id`, un utilisateur du tenant A ne lit/écrit rien du tenant B
- [x] T017 Couche d'accès tenant-aware : trait/scope global qui ajoute `tenant_id` à toute lecture et écriture, interdiction par test statique des requêtes brutes sans scope (`apps/api/app/Common/Tenancy/`) ; MySQL n'a pas de RLS
- [x] T018 Middleware de contexte tenant : résout le tenant depuis le jeton et le place dans le contexte de requête (`apps/api/app/Common/Tenancy/TenantContext.php`) (TenantContext fait ; middleware lié au jeton à écrire avec les routes)
- [ ] T019 Script de seed : pays Cameroun, rôles par défaut, super admin initial, catalogue de référence (jeu minimal) (`apps/api/prisma/seed.ts`) (Seeder rôles + Cameroun fait ; super admin initial et catalogue de référence à faire)

### Sécurité et transverse API

- [x] T020 Module d'authentification : JWT court + refresh rotatif, hachage Argon2id (`apps/api/src/common/auth/`)
- [x] T021 **[TDD]** Garde d'autorisation RBAC + permissions fines (décorateur `@RequirePermission`) avec tests par rôle (`apps/api/src/common/rbac/`)
- [x] T022 Intercepteur d'audit : écrit dans `AuditLog` append-only (qui, quoi, quand, poste, avant/après) (`apps/api/src/common/audit/`)
- [x] T023 [P] Filtre d'erreurs uniforme et codes d'erreur i18n (`apps/api/src/common/errors/`)
- [x] T024 [P] Rate limiting par IP et par identifiant (`apps/api/src/common/rate-limit/`)
- [x] T025 [P] Journalisation structurée sans donnée personnelle (pino + masquage) (`apps/api/src/common/logging/`)
- [x] T026 [P] Service de stockage de fichiers chiffrés hors racine web, accès via l'API avec URL signée courte, contrôle de type/taille (et scan antivirus si disponible) à l'upload (`apps/api/src/common/storage/`)
- [x] T027 [P] Service d'email transactionnel + gabarits FR/EN (`apps/api/src/modules/notifications/email/`) (SMTP noreply@trugroup.cm vérifié sur 587/TLS et 465/SSL ; gabarit « accès validé » ; autres gabarits avec leurs stories)
- [x] T028 [P] Interface `SmsProvider` + implémentation « console » de dev (`apps/api/src/modules/notifications/sms/`) (+ fournisseur teeeotp, clé de test)
- [x] T029 [P] File de jobs en base (table `jobs`, verrou, reprise, délais) exécutée par un cron chaque minute pour relances, emails, rapports, escalades (`apps/api/src/common/jobs/`) ; tester la reprise après arrêt
- [ ] T030 [P] Génération OpenAPI et client typé partagé dans `packages/shared/src/api-client/`

### Interface partagée

- [x] T031 [P] Design tokens (fait : `packages/ui/src/tokens.css`, `Logo`) puis composants de base conformes à la maquette (Button, Field, Table, Modal, Toast, Stepper, Switch, Badge) dans `packages/ui/` ; référence : `docs/design-system.md`
- [x] T032 [P] i18n : `packages/i18n/fr.json`, `en.json`, hook `useT`, règle ESLint interdisant les textes en dur
- [x] T033 [P] Layouts web : `(public)`, `(pharmacy)`, `(admin)` avec gardes d'accès (`apps/web/src/app/`)
- [x] T034 [P] Composant de formatage monnaie/date/téléphone piloté par `CountryConfig` (`packages/shared/src/format/`)
- [x] T035 **[TDD]** Moteur de prix/TVA/remises/arrondis pur dans `packages/shared/src/pricing/` avec tests (arrondis XAF, TVA multiple, remises plafonnées)

### Base de la synchronisation

- [x] T036 [P] Génération d'identifiants ULID côté client et serveur (`packages/shared/src/ids/`)
- [x] T037 **[TDD]** Spécification et tests du contrat `SyncOperation` (idempotence, ordre, versions de schéma) (`packages/sync/tests/`)
- [x] T038 Squelette du moteur de sync : file locale, push/pull, curseurs (`packages/sync/src/`)

**Checkpoint fondations** : un utilisateur de test peut s'authentifier, une action produit une ligne d'audit, le test d'isolation passe.

---

## Phase 3 : US1 - Demande d'accès d'une pharmacie (P1) 🎯 MVP 1

**Goal**: un visiteur soumet une demande complète et reçoit un accusé et un lien de suivi.
**Independent Test**: soumettre une demande, la voir « En attente » dans la base et recevoir l'email.

- [x] T039 [P] [US1] Modèles Prisma `PharmacyApplication`, `ApplicationDocument` (statuts : pending, approved, rejected) (`apps/api/prisma/schema.prisma`)
- [x] T040 **[TDD]** [US1] Tests de contrat `POST /public/applications` (champs obligatoires, doublon email/agrément, types/tailles de fichiers) (`apps/api/test/applications.e2e-spec.ts`) (testé au niveau service ; contrat HTTP avec les routes)
- [ ] T041 [US1] Schémas Zod du formulaire et règles par pays dans `packages/shared/src/applications/`
- [x] T042 [US1] Endpoint de soumission avec upload sécurisé des documents (`apps/api/src/modules/applications/applications.controller.ts`)
- [x] T043 [US1] Génération du code de suivi et endpoint `GET /public/applications/:code/status` (`apps/api/src/modules/applications/`)
- [x] T044 [P] [US1] Email d'accusé de réception FR/EN (`apps/api/src/modules/notifications/templates/application-received.*`)
- [x] T045 [US1] Page « Digitaliser ma pharmacie » multi-étapes avec carte pour la position (`apps/web/src/app/(public)/inscription/`) (carte Leaflet : à brancher ; position par géolocalisation ou saisie)
- [x] T046 [P] [US1] Page de suivi de demande (`apps/web/src/app/(public)/inscription/suivi/[code]/page.tsx`)
- [x] T047 [US1] Anti-spam : limite de débit et captcha sur la soumission (limitation de débit faite ; captcha à ajouter)
- [x] T048 [US1] Test E2E Playwright du parcours complet (`tests/e2e/us1-application.spec.ts`)

**Checkpoint US1** ✅

## Phase 4 : US2 - Validation super admin et envoi des accès (P1) 🎯 MVP 1

**Goal**: valider/rejeter une demande ; création du tenant et email d'activation.

- [x] T049 **[TDD]** [US2] Tests : validation crée tenant + propriétaire, rejet exige un motif, pas de double traitement (transaction + verrou) (`apps/api/test/application-review.e2e-spec.ts`)
- [x] T050 [US2] Service de validation transactionnelle (création Tenant, User propriétaire, jeton d'activation à durée limitée) (`apps/api/src/modules/applications/review.service.ts`)
- [x] T051 [P] [US2] Emails d'activation et de rejet avec motif FR/EN (`apps/api/src/modules/notifications/templates/`)
- [x] T052 [US2] Console super admin : liste des demandes avec filtres et statuts (`apps/web/src/app/(admin)/applications/page.tsx`)
- [x] T053 [US2] Vue détail : prévisualisation sécurisée des documents, carte, historique, boutons valider/rejeter (`apps/web/src/app/(admin)/applications/[id]/page.tsx`)
- [x] T054 [P] [US2] Renvoi d'un lien d'activation expiré (`apps/api/src/modules/applications/resend-activation.ts`)
- [x] T055 [US2] Audit de toutes les décisions et notification interne aux super admins pour les nouvelles demandes
- [x] T056 [US2] Test E2E : soumission → validation → réception de l'email (MailHog) (`tests/e2e/us2-review.spec.ts`) (courrier fichier en remplacement de MailHog ; base SQLite)

**Checkpoint US2** ✅

## Phase 5 : US3 - Activation et configuration initiale (P1) 🎯 MVP 1

- [x] T057 [P] [US3] Modèles `TenantSettings`, `OpeningHours`, `OnboardingProgress` (`apps/api/prisma/schema.prisma`)
- [x] T058 **[TDD]** [US3] Tests : jeton valide/expiré/déjà utilisé, politique de mot de passe (`apps/api/test/activation.e2e-spec.ts`)
- [x] T059 [US3] Endpoint d'activation (définition du mot de passe, proposition 2FA) (`apps/api/src/modules/users/activation.controller.ts`)
- [x] T060 [US3] Page d'activation (`apps/web/src/app/(public)/activer/[token]/page.tsx`)
- [x] T061 [US3] Assistant de configuration en étapes sauvegardées : infos, logo, horaires, devise/TVA, imprimante, visibilité publique, import catalogue (`apps/web/src/app/(pharmacy)/configuration/`)
- [x] T062 [P] [US3] Endpoints de lecture/écriture des paramètres de l'officine (`apps/api/src/modules/tenants/settings.controller.ts`)
- [x] T063 [US3] Reprise de l'assistant à l'étape interrompue ; indicateur de progression sur le tableau de bord
- [x] T064 [US3] Publication/dépublication de la fiche publique (indicateur `public_visible`) (`apps/api/src/modules/tenants/visibility.service.ts`)

**Checkpoint US3** ✅

## Phase 6 : US4 - Équipe, rôles et permissions (P1) 🎯 MVP 1

- [x] T065 [P] [US4] Modèles `Invitation`, `RolePermission`, `UserPermissionOverride` (`apps/api/prisma/schema.prisma`)
- [x] T066 **[TDD]** [US4] Tests : invitation, rôles par défaut, refus hors droits consigné, dernier titulaire non désactivable, effet immédiat d'un changement de rôle (`apps/api/test/team.e2e-spec.ts`)
- [x] T067 [US4] Matrice de permissions par défaut (adjoint, préparateur, caissier, magasinier, comptable) dans `packages/shared/src/permissions/`
- [x] T068 [US4] API d'invitation, acceptation, changement de rôle, désactivation (`apps/api/src/modules/users/team.controller.ts`)
- [x] T069 [US4] Page « Équipe » : liste, invitation, rôle, permissions fines (remise max, annulation, stupéfiants, finances) (`apps/web/src/app/(pharmacy)/equipe/`)
- [x] T070 [US4] Masquage des menus et actions selon permissions côté interface (hook `useCan`) dans `packages/ui/src/auth/`
- [x] T071 [P] [US4] Email d'invitation FR/EN

**Checkpoint US4** ✅

## Phase 7 : US5 - Authentification et sécurité du compte (P1) 🎯 MVP 1

- [x] T072 **[TDD]** [US5] Tests : verrouillage après 5 échecs, 2FA obligatoire si activée, jeton de reset à usage unique et révocation des sessions (`apps/api/test/auth.e2e-spec.ts`)
- [x] T073 [US5] 2FA TOTP (secret chiffré, codes de secours) et option SMS (`apps/api/src/modules/users/mfa/`) (TOTP fait ; option SMS non faite)
- [x] T074 [US5] Réinitialisation de mot de passe, politique et historique (`apps/api/src/modules/users/password-reset.ts`)
- [x] T075 [US5] Verrouillage temporaire, notification au titulaire (`apps/api/src/common/auth/lockout.ts`)
- [x] T076 [US5] Gestion des sessions et des postes autorisés (`Device`), révocation à distance (`apps/api/src/modules/users/devices.controller.ts`) (sessions par appareil faites ; table devices par poste avec le desktop)
- [ ] T077 [US5] Pages connexion, 2FA, reset, sessions/postes (`apps/web/src/app/(auth)/`)
- [ ] T078 [US5] Verrouillage d'écran par inactivité avec PIN (`packages/ui/src/auth/idle-lock.tsx`)

**Checkpoint US5** ✅

## Phase 8 : US6 - Catalogue produits (P1) 🎯 MVP 1

- [ ] T079 [P] [US6] Modèles `Product`, `ProductBarcode`, `PriceHistory`, `GenericGroup`, `ProductFamily`, `RegulatoryStatus` (`apps/api/prisma/schema.prisma`)
- [ ] T080 **[TDD]** [US6] Tests : statut réglementaire obligatoire, historique de prix, désactivation vs suppression, recherche < 200 ms sur 20 000 produits (`apps/api/test/catalog.e2e-spec.ts`)
- [ ] T081 [US6] CRUD produits et codes-barres multiples (`apps/api/src/modules/catalog/`)
- [ ] T082 [US6] Recherche (nom, DCI, code-barres) avec index FULLTEXT MySQL + préfixe sur code-barres ; vérifier le budget de 200 ms sur 20 000 produits
- [ ] T083 [US6] Import CSV/Excel : validation, rapport créés/mis à jour/rejetés par ligne (`apps/api/src/modules/catalog/import.service.ts`)
- [ ] T084 [P] [US6] Export CSV/Excel du catalogue
- [ ] T085 [P] [US6] Catalogue de référence pré-chargé (DCI, formes, familles) et import à l'inscription (`apps/api/prisma/reference/`)
- [ ] T086 [US6] Pages catalogue : liste, fiche produit, génériques équivalents, historique de prix (`apps/web/src/app/(pharmacy)/catalogue/`)
- [ ] T087 [P] [US6] Gestion des familles, laboratoires, taux de TVA

**Checkpoint US6** ✅

## Phase 9 : US7 - Stock par lot, FEFO et alertes (P1) 🎯 MVP 1

- [ ] T088 [P] [US7] Modèles `Lot`, `StockMovement`, `StockAlert`, `Quarantine`, `Destruction` (`apps/api/prisma/schema.prisma`)
- [ ] T089 **[TDD]** [US7] Tests de la logique pure : FEFO multi-lots, stock insuffisant, stock négatif désactivé, corrections par écriture inverse (`packages/shared/src/stock/fefo.spec.ts`)
- [ ] T090 [US7] Implémentation FEFO et calcul du stock courant par journal de mouvements (`packages/shared/src/stock/`)
- [ ] T091 [US7] API de réception de stock (lot, péremption, quantité) et de consultation (`apps/api/src/modules/stock/`)
- [ ] T092 [US7] Job quotidien : alertes péremption 30/60/90 jours et blocage des lots périmés (`apps/api/src/modules/stock/jobs/expiry.job.ts`)
- [ ] T093 [US7] Alertes de stock minimum/rupture et centre de notifications (`apps/api/src/modules/notifications/stock-alerts.ts`)
- [ ] T094 [US7] Quarantaine et destruction avec procès-verbal PDF (`apps/api/src/modules/stock/destruction.service.ts`)
- [ ] T095 [US7] Pages stock : vue par produit/lot, alertes, quarantaine, destruction (`apps/web/src/app/(pharmacy)/stock/`)
- [ ] T096 [P] [US7] Paramètres de seuils par produit et alerte de péremption courte à la réception

**Checkpoint US7** ✅

## Phase 10 : US8 - Inventaire (P1) 🎯 MVP 1

- [ ] T097 [P] [US8] Modèles `Inventory`, `InventoryLine` (`apps/api/prisma/schema.prisma`)
- [ ] T098 **[TDD]** [US8] Tests : écarts calculés à l'instant du comptage, fusion de comptages hors-ligne, validation par pharmacien (`packages/shared/src/stock/inventory.spec.ts`)
- [ ] T099 [US8] Service d'inventaire complet/tournant, comptage à l'aveugle, ajustements valorisés (`apps/api/src/modules/stock/inventory.service.ts`)
- [ ] T100 [US8] Interface de comptage par scan (clavier/scanner) avec mode aveugle (`apps/web/src/app/(pharmacy)/stock/inventaire/`)
- [ ] T101 [P] [US8] Rapport d'écarts valorisé, export PDF/Excel
- [ ] T102 [US8] Validation par un pharmacien avec audit

**Checkpoint US8** ✅

## Phase 11 : US10 - Caisse rapide (P1) 🎯 MVP 1

- [ ] T103 [P] [US10] Modèles `Sale`, `SaleLine`, `HeldCart`, `DocumentSequence` (`apps/api/prisma/schema.prisma`)
- [ ] T104 **[TDD]** [US10] Tests : total avec remises plafonnées, validation responsable au-delà du plafond, produit sous ordonnance, FEFO à la vente (`packages/shared/src/sales/sale.spec.ts`)
- [ ] T105 [US10] Logique de vente pure partagée (lignes, remises, TVA, statuts réglementés) (`packages/shared/src/sales/`)
- [ ] T106 [US10] API de création de vente et sortie de stock transactionnelle (`apps/api/src/modules/sales/`)
- [ ] T107 [US10] Écran de caisse : scan, recherche DCI/nom, quantités, raccourcis clavier, paniers en attente (`apps/web/src/app/(pharmacy)/caisse/`)
- [ ] T108 [US10] Contrôle des produits réglementés : saisie/rattachement d'ordonnance et pharmacien délivrant
- [ ] T109 [US10] Suggestions de génériques en rupture
- [ ] T110 [US10] Numérotation par poste sans collision (`packages/shared/src/sales/numbering.ts`) avec tests de collision
- [ ] T111 [US10] Budget de performance : test automatique « ajout article < 200 ms » (`tests/perf/pos.spec.ts`)

**Checkpoint US10** ✅

## Phase 12 : US11 - Paiements, tickets, clôture (P1) 🎯 MVP 1

- [ ] T112 [P] [US11] Modèles `Payment`, `CashSession`, `CashCount` (`apps/api/prisma/schema.prisma`)
- [ ] T113 **[TDD]** [US11] Tests : paiements mixtes, rendu monnaie, clôture avec écart, rapport Z, ré-impression « DUPLICATA » (`packages/shared/src/cash/cash.spec.ts`)
- [ ] T114 [US11] Paiements multiples (espèces, Mobile Money par référence, carte, crédit) (`apps/api/src/modules/sales/payments.service.ts`)
- [ ] T115 [US11] Ouverture avec fond de caisse, clôture avec comptage et écart motivé (`apps/api/src/modules/sales/cash-session.service.ts`)
- [ ] T116 [US11] Gabarits ticket 58/80 mm et facture A4 (PDF) pilotés par `TenantSettings` (`packages/shared/src/documents/`)
- [ ] T117 [US11] UI paiement mixte et rendu monnaie dans la caisse
- [ ] T118 [US11] Rapport Z et obligation de clôturer la caisse précédente à la connexion
- [ ] T119 [US11] Gestion de l'imprimante absente : vente enregistrée, ré-impression ultérieure

**Checkpoint US11** ✅

## Phase 13 : US12 - Retours, annulations, avoirs (P1) 🎯 MVP 1

- [ ] T120 [P] [US12] Modèles `SaleReturn`, `CreditNote` (`apps/api/prisma/schema.prisma`)
- [ ] T121 **[TDD]** [US12] Tests : retour partiel, remise en stock conditionnelle, délai de retour, approbation (`packages/shared/src/sales/returns.spec.ts`)
- [ ] T122 [US12] API retours/annulations, motif obligatoire, écritures inverses de stock et de caisse (`apps/api/src/modules/sales/returns.service.ts`)
- [ ] T123 [US12] Écran de retour depuis l'historique des ventes, avoir imprimable
- [ ] T124 [US12] Règle de décision de remise en stock par le pharmacien (défaut : non)

**Checkpoint US12** ✅

## Phase 14 : US27 - Application desktop installable (P1) 🎯 MVP 1

- [ ] T125 [US27] Coque Electron sécurisée (contextIsolation, sandbox, CSP, IPC typé) (`apps/desktop/src/main/`)
- [ ] T126 [US27] Chargement de l'interface partagée (build Next.js export ou serveur local) et fenêtre de connexion
- [ ] T127 [US27] Configuration electron-builder : installateur NSIS `Sanara-Setup.exe` sans droits admin, raccourcis, lancement au démarrage optionnel (`apps/desktop/electron-builder.yml`)
- [ ] T128 [US27] Mises à jour automatiques signées (electron-updater), installation différée, retour arrière (`apps/desktop/src/updater/`)
- [ ] T129 [US27] Enregistrement du poste (`Device`) au premier lancement et initialisation de la base locale (`apps/desktop/src/local-db/`)
- [ ] T130 [US27] Signature de code Windows et pipeline de publication des versions (`.github/workflows/release-desktop.yml`)
- [ ] T131 [US27] Test d'installation/désinstallation propre sur machine virtuelle Windows vierge (checklist `tests/desktop/install-checklist.md`)
- [ ] T132 [US27] Mesure du démarrage (< 5 s) sur PC 4 Go de RAM

**Checkpoint US27** ✅

## Phase 15 : US28 - Mode hors-ligne et synchronisation (P1) 🎯 MVP 1

- [ ] T133 [P] [US28] Schéma SQLite local et migrations (`apps/desktop/src/local-db/migrations/`)
- [ ] T134 **[TDD]** [US28] Tests de synchro : deux postes hors-ligne vendent le même produit, somme correcte ; synchro interrompue sans doublon ; serveur gagnant sur référentiels (`packages/sync/tests/offline-scenarios.spec.ts`)
- [ ] T135 [US28] Couche d'accès aux données double (API en ligne / SQLite hors-ligne) derrière une même interface (`packages/shared/src/data-access/`)
- [ ] T136 [US28] File d'opérations locale, push par lots, accusés, reprise (`packages/sync/src/client/`)
- [ ] T137 [US28] Endpoints serveur `POST /sync/push`, `GET /sync/pull?cursor=` avec idempotence (`apps/api/src/modules/sync/`)
- [ ] T138 [US28] Résolution des conflits et journal `SyncConflict` (`packages/sync/src/conflicts/`)
- [ ] T139 [US28] Synchro initiale (téléchargement du catalogue, stock, clients) et synchro incrémentale
- [ ] T140 [US28] Vérification d'intégrité d'un poste hors-ligne trop longtemps (checksums de stock) avant acceptation
- [ ] T141 [US28] Indicateur de synchro dans l'interface (état, nombre d'opérations en attente, erreurs)
- [ ] T142 [US28] Détection de décalage d'horloge et horodatage serveur de référence
- [ ] T143 [US28] Test de bout en bout : coupure de 8 h simulée sur 2 postes (`tests/sync/offline-8h.spec.ts`)

**Checkpoint US28 = fin du MVP 1** : une pharmacie pilote s'inscrit, s'installe, vend hors-ligne et synchronise. **Démonstration et validation par le pharmacien pilote avant de continuer.**

---

## Phase 16 : US18 - Recherche de pharmacies et carte (P1) 🎯 MVP 2

- [ ] T144 [P] [US18] Table de lecture publique `public_pharmacies` (nom, point GPS, horaires, garde, visibilité) alimentée à chaque changement, avec index spatial MySQL ou boîte englobante + Haversine (`apps/api/database/migrations/`)
- [ ] T145 **[TDD]** [US18] Tests : pharmacies suspendues/non publiques absentes, tri par distance, recherche par région/département/ville/nom (`apps/api/test/public-search.e2e-spec.ts`)
- [ ] T146 [US18] Endpoints `GET /public/pharmacies` (filtres, bbox, distance) avec cache et pagination (`apps/api/src/modules/public/`)
- [ ] T147 [US18] Référentiel géographique (régions, départements, villes, quartiers) dans `CountryConfig` + sélecteurs en cascade
- [ ] T148 [US18] Page de recherche : liste + carte Leaflet, regroupement de marqueurs, chargement progressif (liste avant carte) (`apps/web/src/app/(public)/pharmacies/`)
- [ ] T149 [US18] Fiche résumée au survol/appui : nom, distance, ouvert/fermé, de garde
- [ ] T150 [US18] Géolocalisation avec consentement et repli sur saisie du quartier
- [ ] T151 [US18] Double-clic/double appui sur marqueur : ouverture de l'assistant (point d'entrée US21)
- [ ] T152 [US18] Filtres : ouvert maintenant, de garde, assurances acceptées
- [ ] T153 [US18] SEO, performance 3G (images optimisées, tuiles légères), test Lighthouse en CI

**Checkpoint US18** ✅

## Phase 17 : US19 - Fiche pharmacie, gardes, itinéraire (P1) 🎯 MVP 2

- [ ] T154 [P] [US19] Modèles `DutySchedule`, `PharmacyService`, `PharmacyPhoto` (`apps/api/prisma/schema.prisma`)
- [ ] T155 **[TDD]** [US19] Tests : statut ouvert/fermé selon fuseau et jours fériés, calcul de garde (`packages/shared/src/hours/hours.spec.ts`)
- [ ] T156 [US19] Gestion des horaires, jours fériés et gardes côté officine (`apps/web/src/app/(pharmacy)/vitrine/`)
- [ ] T157 [US19] Page publique de la pharmacie (adresse, téléphone, horaires, services, assurances, photos) (`apps/web/src/app/(public)/pharmacie/[slug]/page.tsx`)
- [ ] T158 [US19] Page « pharmacies de garde » par zone et date (`apps/web/src/app/(public)/gardes/`)
- [ ] T159 [US19] Lien itinéraire vers application de navigation

**Checkpoint US19** ✅

## Phase 18 : US21 - Dépôt d'ordonnance et analyse (P1) 🎯 MVP 2

- [ ] T160 [P] [US21] Modèles `PatientRequest`, `RequestDocument`, `RequestLine`, `Consent`, `AssistantRun` (`apps/api/prisma/schema.prisma`)
- [ ] T161 **[TDD]** [US21] Tests des garde-fous : détection de symptômes graves, refus de conseil médical, formats/taille de fichiers, limite de débit (`packages/assistant/tests/guardrails.spec.ts`)
- [ ] T162 [US21] Interface `AssistantProvider` et sortie structurée Zod (lignes, confiance, anomalies) (`packages/assistant/src/provider.ts`)
- [ ] T163 [US21] Implémentation Claude vision de `AssistantProvider` avec prompt système et sanitisation (`packages/assistant/src/providers/claude.ts`)
- [ ] T164 [US21] Rapprochement des lignes lues avec le catalogue de la pharmacie (similarité nom/DCI, confiance) (`packages/assistant/src/matching.ts`)
- [ ] T165 [US21] Garde-fous déterministes avant/après le modèle : produits réglementés, symptômes graves, interdiction de conseil (`packages/assistant/src/guardrails.ts`)
- [ ] T166 [US21] Endpoint public de dépôt (consentement explicite, upload, antivirus, conversion HEIC, plusieurs pages) (`apps/api/src/modules/requests/public.controller.ts`)
- [ ] T167 [US21] Widget assistant : consentement, prénom/téléphone, envoi, état d'analyse (`apps/web/src/app/(public)/assistant/`)
- [ ] T168 [US21] Cas d'échec : document illisible, demande d'une meilleure photo, transmission brute au pharmacien
- [ ] T169 [US21] Quotas par pharmacie selon abonnement et suivi du coût IA par demande
- [ ] T170 [US21] Jeu d'évaluation : script `tests/assistant-eval/run.ts` mesurant précision de lecture et taux de corrections

**Checkpoint US21** ✅

## Phase 19 : US22 - File de traitement et devis (P1) 🎯 MVP 2

- [ ] T171 [P] [US22] Modèles `Quote`, `QuoteLine`, `AssistantCorrection`, verrou de prise en charge (`apps/api/prisma/schema.prisma`)
- [ ] T172 **[TDD]** [US22] Tests : verrouillage entre pharmaciens, expiration du devis et libération du stock réservé, enregistrement des corrections (`apps/api/test/requests-queue.e2e-spec.ts`)
- [ ] T173 [US22] File des demandes avec priorité, urgence, ancienneté (rafraîchissement par polling toutes les 10 s) (`apps/web/src/app/(pharmacy)/demandes/`)
- [ ] T174 [US22] Écran de traitement : visionneuse d'ordonnance, lecture proposée, correction ligne par ligne, rattachement au catalogue
- [ ] T175 [US22] Vérification de stock et réservation temporaire, produit indisponible ou remplacé par générique (avec accord patient)
- [ ] T176 [US22] Génération du devis (produits, quantités, prix, total, validité) et envoi au patient (`apps/api/src/modules/requests/quote.service.ts`)
- [ ] T177 [US22] Enregistrement de chaque correction pour mesure de fiabilité (original lu vs final)
- [ ] T178 [US22] Détection de doublons : même ordonnance envoyée à plusieurs pharmacies, ordonnance expirée ou déjà délivrée

**Checkpoint US22** ✅

## Phase 20 : US23 - Validation patient et retrait (P1) 🎯 MVP 2

- [ ] T179 [P] [US23] Modèles `Pickup`, statuts de commande (`apps/api/prisma/schema.prisma`)
- [ ] T180 **[TDD]** [US23] Tests : validation partielle, code de retrait, expiration d'une commande non retirée (`apps/api/test/pickup.e2e-spec.ts`)
- [ ] T181 [US23] Page patient du devis : accepter tout/partie, refuser avec motif, choisir le retrait (`apps/web/src/app/(public)/demande/[code]/devis/`)
- [ ] T182 [US23] Génération du code de retrait et confirmation par l'assistant (+ SMS)
- [ ] T183 [US23] Préparation et statut « prête » côté pharmacie, notification au patient
- [ ] T184 [US23] Retrait en caisse : recherche par code, création de la vente sans ressaisie, encaissement à la pharmacie
- [ ] T185 [US23] Job d'expiration des commandes non retirées et libération du stock

**Checkpoint US23** ✅

## Phase 21 : US24 - Présence et escalade urgente (P1) 🎯 MVP 2

- [ ] T186 [P] [US24] Modèles `PresenceSession`, `EscalationPolicy`, `EscalationAttempt` (`apps/api/prisma/schema.prisma`)
- [ ] T187 **[TDD]** [US24] Tests de la chaîne d'escalade : titulaire puis adjoint, délais, arrêt à la prise en charge, message de repli, hors horaires, limitation d'abus (`apps/api/test/escalation.spec.ts`)
- [ ] T188 [US24] Présence par heartbeat HTTP toutes les 10 à 15 s (mise à jour en base, expiration au-delà de 45 s) et statut « en ligne » par pharmacien (`apps/api/src/modules/presence/`)
- [ ] T189 [US24] Messages d'état adaptés à la présence (en traitement / en attente d'un pharmacien / hors horaires) (`packages/assistant/src/status-messages.ts`)
- [ ] T190 [US24] Déclaration d'urgence par le patient avec confirmation anti-abus
- [ ] T191 [US24] Orchestrateur d'escalade (job en base traité par le cron chaque minute) : notification, SMS, appel/WhatsApp via `NotificationChannel`, relances, repli final (`apps/api/src/modules/presence/EscalationProcessor.php`) ; documenter la granularité de 1 minute du cron
- [ ] T192 [US24] Réglages d'escalade par pharmacie (ordre, délais, canaux, numéros)
- [ ] T193 [US24] Indicateur de présence et réglage de disponibilité dans l'interface pharmacie
- [ ] T194 [US24] Suivi des temps de prise en charge d'urgence pour le super admin

**Checkpoint US24** ✅

## Phase 22 : US26 - Suivi de demande et messagerie patient (P1) 🎯 MVP 2

- [ ] T195 [P] [US26] Modèle `MessageThread`, `Message` (`apps/api/prisma/schema.prisma`)
- [ ] T196 **[TDD]** [US26] Tests : accès par code + second facteur léger, expiration à la clôture, pas d'exposition de documents sans vérification (`apps/api/test/tracking.e2e-spec.ts`)
- [ ] T197 [US26] Page de suivi : statuts et historique (`apps/web/src/app/(public)/demande/[code]/`)
- [ ] T198 [US26] Messagerie patient ↔ pharmacie/assistant en temps réel
- [ ] T199 [US26] Notifications au patient par SMS/email selon coordonnées et consentement

**Checkpoint US26** ✅

## Phase 23 : US29 - Matériel d'officine (P2) 🎯 MVP 2

- [ ] T200 [P] [US29] Pilote d'impression ESC/POS (USB/réseau) avec accents et logo (`apps/desktop/src/hardware/printer.ts`)
- [ ] T201 [P] [US29] Ouverture du tiroir-caisse sur vente en espèces (`apps/desktop/src/hardware/cash-drawer.ts`)
- [ ] T202 [P] [US29] Prise en charge scanner en mode clavier avec détection de rafale de frappe hors champ (`packages/ui/src/hardware/scanner.ts`)
- [ ] T203 [US29] Assistant de configuration du matériel et page de test d'impression (`apps/web/src/app/(pharmacy)/parametres/materiel/`)
- [ ] T204 [US29] Matrice de compatibilité testée sur 3 modèles d'imprimantes et 2 scanners (`docs/materiel.md`)

**Checkpoint US29 = fin du MVP 2** : site public + assistant en niveau N1 validés par la pharmacie pilote.

---

## Phase 24 : V2 - US25 Autonomie progressive et fiabilité (P2)

- [ ] T205 [P] [US25] Modèles `AutonomyPolicy`, `ReliabilityStat`, `SamplingReview` (`apps/api/prisma/schema.prisma`)
- [ ] T206 **[TDD]** [US25] Fonction pure `decideAutonomy()` : N1/N2/N3, seuils, produits réglementés toujours humains, retour arrière (`packages/assistant/src/autonomy.spec.ts`)
- [ ] T207 [US25] Implémentation de `decideAutonomy()` et intégration au pipeline de demande (`packages/assistant/src/autonomy.ts`)
- [ ] T208 [US25] Calcul des statistiques de fiabilité : taux sans correction, erreurs par catégorie, tendance (`apps/api/src/modules/assistant/reliability.service.ts`)
- [ ] T209 [US25] Tableau de bord de fiabilité pour le titulaire (`apps/web/src/app/(pharmacy)/assistant/fiabilite/`)
- [ ] T210 [US25] Changement de niveau avec confirmation explicite et audit ; retour arrière immédiat
- [ ] T211 [US25] Alerte si le taux d'acceptation chute sous le seuil, proposition de rétrogradation
- [ ] T212 [US25] Échantillonnage : file de contrôles a posteriori des devis automatiques
- [ ] T213 [US25] Mention du niveau d'autonomie sur chaque devis automatique
- [ ] T214 [US25] Consentement et anonymisation pour toute utilisation inter-pharmacies des corrections
- [ ] T215 [US25] Vérification automatique en CI : aucun devis automatique ne contient de produit réglementé (SC-007)

## Phase 25 : V2 - US9 Mouvements, pertes, transferts (P2)

- [ ] T216 [P] [US9] Modèles `Transfer`, `TransferLine` et motifs de mouvement
- [ ] T217 **[TDD]** [US9] Tests : casse, transfert « en transit », accusé de réception, droits
- [ ] T218 [US9] API et écrans de mouvements motivés et de transferts inter-sites (`apps/web/src/app/(pharmacy)/stock/mouvements/`)
- [ ] T219 [US9] Historique complet par produit

## Phase 26 : V2 - US13 Clients et crédit (P2)

- [ ] T220 [P] [US13] Modèles `Customer`, `CreditAccount`, `LoyaltyPoints`
- [ ] T221 **[TDD]** [US13] Tests : plafond de crédit, règlements partiels, relances avec consentement
- [ ] T222 [US13] CRUD clients, comptes à crédit, relevés, reçus (`apps/api/src/modules/customers/`)
- [ ] T223 [US13] Relances automatiques SMS/email et programme de fidélité
- [ ] T224 [US13] Pages clients et créances (`apps/web/src/app/(pharmacy)/clients/`)

## Phase 27 : V2 - US15 Fournisseurs, commandes, réceptions (P2)

- [ ] T225 [P] [US15] Modèles `Supplier`, `PurchaseOrder`, `Receipt`, `SupplierInvoice`
- [ ] T226 **[TDD]** [US15] Tests : réception partielle, reliquat, écart facture/réception, PMP
- [ ] T227 [US15] API et écrans : fournisseurs, bons de commande, réception avec lot et péremption obligatoires (`apps/web/src/app/(pharmacy)/achats/`)
- [ ] T228 [US15] Rapprochement facture fournisseur et mise à jour du prix d'achat/PMP
- [ ] T229 [US15] Avertissement de péremption courte à la réception

## Phase 28 : V2 - US16 Suggestions, retours fournisseurs, dettes (P2)

- [ ] T230 [P] [US16] Modèles `SupplierReturn`, `SupplierCredit`, `PaymentSchedule`
- [ ] T231 **[TDD]** [US16] Tests : calcul des quantités suggérées (seuil, historique, saisonnalité simple)
- [ ] T232 [US16] Génération de brouillons de commande par fournisseur
- [ ] T233 [US16] Retours fournisseurs, avoirs attendus, échéancier de dettes et alertes

## Phase 29 : V2 - US17 Patients, ordonnances, registre (P2)

- [ ] T234 [P] [US17] Modèles `Patient`, `Prescription`, `PrescriptionLine`, `ControlledRegisterEntry`
- [ ] T235 **[TDD]** [US17] Tests : registre stupéfiants, délivrance partielle, alerte allergie bloquante, anonymisation sur effacement
- [ ] T236 [US17] CRUD patients et saisie d'ordonnance avec scan joint (`apps/web/src/app/(pharmacy)/patients/`)
- [ ] T237 [US17] Registre des stupéfiants/psychotropes consultable et exportable
- [ ] T238 [US17] Contrôle des allergies à la délivrance et historique de délivrance
- [ ] T239 [US17] Rétention et anonymisation selon la politique du pays

## Phase 30 : V2 - US30 Tableaux de bord et rapports (P2)

- [ ] T240 [P] [US30] Vues SQL/agrégats : ventes par période, vendeur, famille ; marge ; rotation ; dormants
- [ ] T241 **[TDD]** [US30] Tests : totaux concordants avec les clôtures de caisse, restriction des caissiers à leurs ventes
- [ ] T242 [US30] Tableau de bord du jour et rapports filtrables (`apps/web/src/app/(pharmacy)/rapports/`)
- [ ] T243 [US30] Exports PDF/Excel
- [ ] T244 [US30] Rapports planifiés par email (BullMQ)
- [ ] T245 [US30] Valorisation du stock et prévision simple de la demande

## Phase 31 : V2 - US31 Dépenses, finances, export comptable (P2)

- [ ] T246 [P] [US31] Modèles `Expense`, `LedgerEntry`, `PeriodLock`
- [ ] T247 **[TDD]** [US31] Tests : verrouillage de période, TVA collectée/déductible, export au plan comptable configuré
- [ ] T248 [US31] Dépenses, journaux de caisse/banque, TVA
- [ ] T249 [US31] Clôtures mensuelles/annuelles verrouillées
- [ ] T250 [US31] Export comptable (format OHADA) paramétrable

## Phase 32 : V2 - US32 Pharmacies et abonnements (P2)

- [ ] T251 [P] [US32] Modèles `Plan`, `Subscription`, `PlatformInvoice`
- [ ] T252 **[TDD]** [US32] Tests : essai 30 jours, délai de grâce, lecture seule, retrait du site public à la suspension
- [ ] T253 [US32] Console : liste des pharmacies, suspension/réactivation, gestion des plans (`apps/web/src/app/(admin)/pharmacies/`)
- [ ] T254 [US32] Facturation d'abonnement et suivi des impayés (saisie manuelle de paiement en v1)
- [ ] T255 [US32] Mode lecture seule/export après expiration et notifications de relance

## Phase 33 : V2 - US33 Supervision, audit, modération (P2)

- [ ] T256 [P] [US33] Modèles `Report` (signalements), `Review` (avis), gabarits de messages éditables
- [ ] T257 [US33] Statistiques globales (pharmacies actives, demandes, taux d'escalade) (`apps/web/src/app/(admin)/statistiques/`)
- [ ] T258 [US33] Consultation filtrable du journal d'audit, lecture seule
- [ ] T259 [US33] File de modération des signalements et avis
- [ ] T260 [US33] Éditeur de gabarits d'emails/SMS FR/EN

## Phase 34 : V2 - US35 Notifications, sauvegarde, export, consentements (P2)

- [ ] T261 [P] [US35] Préférences de notification par utilisateur et par canal
- [ ] T262 **[TDD]** [US35] Tests : respect des préférences, export complet, restauration d'une sauvegarde locale
- [ ] T263 [US35] Centre de notifications (in-app, email, SMS, push desktop)
- [ ] T264 [US35] Export complet des données de la pharmacie (archive)
- [ ] T265 [US35] Sauvegarde locale chiffrée du desktop et restauration
- [ ] T266 [US35] Journal des consentements patients et politique de conservation

---

## Phase 35 : V3 et extensions

- [ ] T267 [P] [US14] Modèles `Insurer`, `Coverage`, `ClaimBatch` ; **[TDD]** calcul part patient/organisme, bordereaux, rejets
- [ ] T268 [US14] Conventions, calcul à la vente, facturation groupée, suivi des paiements et rejets
- [ ] T269 [P] [US20] Publication opt-in de la disponibilité des produits, mention « ordonnance nécessaire » ; test de retrait automatique à rupture
- [ ] T270 [US20] Recherche publique de médicament avec liste de pharmacies
- [ ] T271 [US34] Ajout d'un second pays par configuration seule (devise, taxes, documents, régions) comme test de non-régression de la conception multi-pays
- [ ] T272 [P] Interface `PaymentProvider` et intégration Mobile Money (envisager apisungku/pawaPay) activables par pays
- [ ] T273 [P] Interface de livraison désactivée en v1, activable ensuite (statuts, frais, suivi)

## Phase finale : Finitions transverses

- [ ] T274 [P] Revue de sécurité complète (OWASP ASVS), test d'intrusion, revue du chiffrement et de la rétention
- [ ] T275 [P] Audit d'accessibilité et de performance (budgets constitution IX) sur PC modeste et réseau 3G simulé
- [ ] T276 [P] Documentation utilisateur FR/EN (guides officine, patient, super admin) et vidéos de prise en main
- [ ] T277 [P] Procédures d'exploitation : sauvegardes, restauration, supervision, astreinte, plan de reprise
- [ ] T278 Pilote réel dans une pharmacie, collecte de retours, correction, puis ouverture aux inscriptions publiques

---

## Dépendances et ordre

- Phase 0 → 1 → 2 → stories. Phase 2 bloque tout.
- US1 → US2 → US3 → US4/US5 (enchaînement strict).
- US6 avant US7, US7 avant US8 et US10, US10 avant US11 et US12.
- US27 peut démarrer en parallèle dès la fin de la phase 2 ; US28 dépend de US6, US7, US10, US11.
- US18/US19 dépendent de US3 ; US21 dépend de US6 et US18 ; US22 dépend de US21 ; US23 dépend de US22 et US11 ; US24 dépend de US21 et US4 ; US26 dépend de US21.
- US25 dépend de US22 et US24 ; V2 et V3 selon le tableau de priorités.

## Stratégie de livraison

1. Phases 0 à 2 puis US1→US5 : onboarding complet.
2. US6→US12 : l'officine vend (web).
3. US27 + US28 : desktop hors-ligne → **MVP 1** validé par la pharmacie pilote.
4. US18, US19, US21→US24, US26, US29 → **MVP 2** : visibilité publique et assistant guidé (N1).
5. V2 puis V3, en une story à la fois, chacune validée avant la suivante.

---

## Annexe : tâches issues de la maquette (design de référence)

Le design de la maquette s'applique **à l'identique** au web et au desktop (voir `docs/design-system.md`).

- [ ] T279 [P] Composants `@sanara/ui` fidèles à la maquette : `Button` (primaire, secondaire, danger, ambre), `Field` (étiquette en capitales, focus vert), `Stepper` (4 segments), `Switch`, `Badge` (ok, attente, danger, neutre), `Table`, `Modal` (rayon 20 px), `Sidebar` pharmacie (blanche) et `Sidebar` admin (sombre avec pastille ADMIN)
- [ ] T280 [P] Test visuel de non-régression (captures Playwright comparées) des écrans A1, A7, B1 et C1 sur web et dans la fenêtre Electron : le rendu desktop doit être identique au web
- [ ] T281 [US1] Formulaire d'accès en 4 étapes fidèle à A1 à A3 (stepper, carte avec repère glissable, récapitulatif) et écrans A4 (confirmation `PH-AAAA-NNNN`, suivi avec complément)
- [ ] T282 [US2] Décision « Demander un complément » (B2) : documents concernés, message au demandeur, renvoi depuis le lien de suivi ; rejet avec motifs prédéfinis et option « autoriser une nouvelle demande »
- [ ] T283 [US2] Choix de la formule et des postes autorisés à la validation (B2)
- [ ] T284 [US3] Activation en deux temps (A6) : mot de passe (10 caractères, majuscule, chiffre, spécial) puis code SMS à 6 chiffres, appareils de confiance
- [ ] T285 [US5] Connexion à deux modes (A7) : e-mail et code PIN de caisse ; double vérification TOTP obligatoire pour le super admin (B0)
- [ ] T286 [US3] Assistant de configuration en 4 étapes (A8 à A11) et tableau de bord du premier jour (A12) avec liste d'actions de démarrage
- [ ] T287 [US32] Écrans Pharmacies, fiche et suspension (B3, B4), Formules (B5), Facturation (B6) conformes à la maquette
- [ ] T288 [US33] Écrans Signalements (B7), Modèles d'e-mails avec variables et aperçu FR/EN (B8), Journal d'audit (B9), Paramètres globaux (B10)
- [ ] T289 [US34] Écran Pays et zones (B11) : statuts Actif, Préparation, Fermé ; autorité de référence, documents exigés, règles de conservation
- [ ] T290 [US19] Site public : sélecteur de pays et de langue, gardes « Ce soir / Cette semaine / Ce week-end » avec Appeler et Itinéraire (P9)
- [x] T291 Maquette « Sanara Écrans » obtenue et intégrée à `docs/design-system.md` (2026-11-24)

### Tâches issues de la maquette « Sanara Écrans » (2026-11-24)

- [ ] T292 [P] Identité : logo et icônes d'application (128, 84, 48, 24 px ; version monochrome blanche à 24 px et moins), zone de protection (x = hauteur de la feuille supérieure), tailles mini (logo 120 px, symbole 16 px) dans `packages/ui`
- [ ] T293 [US19] Site public P1 (accueil : ville, pays·région, département, « Ma position », trois étapes) et P2 (liste + carte, filtres Ouvert maintenant, De garde, Assurances, Plus proche)
- [ ] T294 [US19] Fiche pharmacie P3 (photo, badges, services, horaires, contact, équipe de garde, Envoyer mon ordonnance, Itinéraire, Appeler)
- [ ] T295 [US21] Assistant patient P4 : dépôt photo/PDF, choix Retrait en officine / Je gère ma livraison, états de présence (connecté, hors ligne, urgence), « C'est urgent », ajout de pages ; message fixe d'urgence médicale
- [ ] T296 [US22] Escalade urgente : notification à 0 min, SMS à 3 min, appel de l'adjoint à 6 min (délai réglable, voir H6) avec tests de la machine d'états
- [ ] T297 [US23] Suivi de demande P5 et P8 (Reçue, Lue, Vérifiée, Devis prêt, Prête à retirer) ; validation, modification ou refus du devis par le patient
- [ ] T298 [US24] Réservation du stock à la validation du devis ; **libération automatique après 24 h sans réponse** (job cron) ; mouvement « Libération » tracé
- [ ] T299 [US24] Code de retrait (ex. K7429) valable le jour même, QR code, écran P7 ; validé seulement après paiement au comptoir
- [ ] T300 [US22] Console pharmacien H1 (tableau de bord), H2 (file des demandes avec filtres et alerte d'escalade), H3 (lecture proposée, confiance par ligne, scan zoomable) et H3b (composer le devis depuis le stock, lot FEFO proposé, alternatives jamais substituées sans choix)
- [ ] T301 [US7] Caisse H4 : recherche/scan, F2 attente, F4 remise, F6 retour, F8 client, F10 clôture, modes de règlement (espèces, MTN MoMo, Orange Money, carte, crédit, tiers payant), rendu de monnaie, rattachement à une demande patient
- [ ] T302 [US10] Compte caissier H7 : rôle, droits (vendre, valider un retrait par code, remise plafonnée, annulation, accès stock/finance), lien d'activation par SMS ou e-mail, **PIN à 4 chiffres choisi par le caissier**
- [ ] T303 [US24] Vue caissier K1 (appelée C1 par erreur dans la maquette précédente) : retrait par code (pavé numérique ou scan QR), commande trouvée, encaissement puis remise des produits
- [ ] T304 [US9] Stock H5 (références, valeur, péremptions sous 90 jours, ruptures, filtres, quarantaine, import CSV, FEFO) et H8 (journal des mouvements : achat, disponible, réservé, sortie, alerte, commande ; types Casse, Libération, Entrée, Sortie)
- [ ] T305 [US12] Achats H9 : suggestions (stock, minimum, ventes 30 j), bon de commande, réception BL avec écart et reliquat, lot, péremption, prix d'achat
- [ ] T306 [US25] Réglages de l'assistant H6 : niveaux N1/N2/N3, règles du niveau 2 (confiance minimale, familles autorisées, stupéfiants toujours validés, délai d'escalade), indicateurs 30 jours, corrections propres à la pharmacie, partage anonymisé sur accord
- [ ] T307 [US11] Reçu thermique 80 mm R1 (agrément, n° de ticket, caissier, caisse, client, code de retrait, rendu) et facture A4 R2 (n° `F-AAAA-NNNNNN`, ordonnance, vérificateur, cachet) ; mentions légales par pays
- [ ] T308 [US27] Desktop D1 : bandeau « Mode hors ligne », compteur d'opérations en attente, MoMo et demandes patients désactivés hors ligne, barre d'état (imprimante, scanner, version)
- [ ] T309 [US28] Desktop D2 : installateur (dossier, raccourci, démarrage Windows), activation du poste (nom du poste), progression de synchronisation, **écran de conflit de stock** (« corriger » ou « plus tard »)
- [ ] T310 [US2] Console super admin S1 (indicateurs, file de validation) et S2 (examen : vérifications cochées, aperçu des documents, formule, e-mail généré avec lien de 48 h)

### Compléments issus de l'atlas des écrans (2026-11-24)

- [ ] T311 [US33] Signalements B7 : fausse urgence répétée, limitation d'envoi d'urgences par numéro, classement sans suite
- [ ] T312 [US33] Paramètres globaux B10 : validité du lien, 2FA, quota de demandes par patient et par jour (5), délai de libération (24 h), mode maintenance, version desktop
- [ ] T313 [US14] Conventions de tiers payant : part assureur en pourcentage, facturation groupée de fin de mois
- [ ] T314 [US30] Rapports C5 : prévision saisonnière de la demande, produits dormants (90 jours), export PDF/Excel, envoi planifié par e-mail
- [ ] T315 [US17] Alerte à la délivrance : comparaison des allergies du patient avec les produits de l'ordonnance (C2)
- [ ] T316 [P] Matrice rôle → écrans de l'atlas appliquée aux autorisations (titulaire, adjoint, magasinier, caissier, super admin) avec tests d'accès
