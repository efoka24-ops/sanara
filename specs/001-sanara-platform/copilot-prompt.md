# Prompt pour GitHub Copilot : implémenter les tâches restantes de Sanara

Copie le bloc ci-dessous dans Copilot (mode agent). Il lui demande de répartir le travail entre 4 agents.

---

Tu es le coordinateur d'une équipe de **4 agents** qui terminent l'implémentation de **Sanara**, un SaaS de gestion de pharmacie pour le Cameroun (multi-pays par configuration). Répartis le travail entre 4 agents selon la section « Répartition » et fais-les travailler en parallèle, chacun sur sa propre branche.

## 1. Documents à lire d'abord (dans l'ordre)

1. `.specify/memory/constitution.md` (v1.0.2) : règles non négociables.
2. `specs/001-sanara-platform/spec.md` (35 user stories), `plan.md`, `data-model.md`, `research.md`, `docs/design-system.md`.
3. `specs/001-sanara-platform/tasks.md` : la liste des tâches (~316). Les cases cochées sont faites. **Fais-en la source de vérité** : coche chaque tâche terminée dans le même commit que son code.
4. Maquettes « Parcours complet », « Écrans » et « Atlas des écrans » : elles font autorité pour le design, identique sur web et desktop. Les tokens vivent uniquement dans `packages/ui/src/tokens.css`.

## 2. État actuel

- **Terminé et déployé** : phases 1 à 8 (fondations, inscription et validation des pharmacies, activation, configuration initiale, équipe et droits, sécurité du compte avec 2FA, catalogue produits). 131 tests PHP et 10 tests Playwright passent.
- **Phase 9 (stock par lot, FEFO) commencée, non commitée** : la migration `apps/api/database/migrations/0006_stock.sql` est écrite (lots, mouvements, alertes, destructions, inventaires, lignes et comptages d'inventaire). `TenantTables::SCOPED`, `DefaultRoles` (permissions `sales.discount_override`, `stock.destroy`, `inventory.validate`) et les clés de paramètres de `TenantSetupService` ont été mis à jour. **Aucun service, route, test ou écran n'existe encore.** Reprends à partir de là.
- Tâches partielles connues à reprendre quand tu passes dessus : T030 (client OpenAPI), T019 (catalogue de référence au-delà de 38 produits), T045 (carte Leaflet), T047 (captcha), T061 (logo, imprimante, import dans l'assistant), T067 (rôle préparateur), T073 (2FA par SMS), T076 (postes par appareil), et l'écran Équipe qui n'affiche pas les droits individuels après rechargement (l'API ne renvoie pas les overrides).

## 3. Stack et conventions

- Monorepo pnpm (`corepack pnpm`). Paquets : `config`, `shared`, `ui`, `i18n`, `sync`, `assistant`. Apps : `web` (React 19 + Vite + react-router), `desktop` (Electron), `api`.
- **API : PHP 8.1 + Slim 4**, accès SQL écrit à la main via `Db` (PDO). Pas d'ORM. Les chemins `apps/api/prisma/...` et `.ts` de `tasks.md` sont des noms d'origine : l'équivalent réel est PHP + migrations SQL portables dans `apps/api/database/migrations/` (fichiers `*.mysql.sql` / `*.sqlite.sql` pour ce qui est propre à un moteur). Adapte les chemins de `tasks.md` à cette réalité.
- Le même SQL doit tourner sur **MySQL 8 (production)** et **SQLite (tests)**.
- Identifiants ULID, dates UTC `DATETIME(3)`, argent en **entiers** (XAF, sans décimale), taux en points de base.
- **Multi-tenant** : toute table métier porte `tenant_id`, est listée dans `TenantTables::SCOPED` et n'est accédée que par `TenantDb`. `NoRawSqlTest` et `TenantIsolationTest` échouent sinon : ne les contourne jamais.
- Droits via `RequirePermission`, journal via `AuditLogger`, tâches de fond via `JobQueue`, e-mails via `MailJobHandler` + `EmailTemplates`, erreurs via `ApiException` + `ErrorCatalog` (fr/en).
- Web : session en mémoire (jamais de token dans `localStorage`), client `apps/web/src/api/client.ts`, textes dans `packages/i18n/src/{fr,en}.json` (un test vérifie la parité fr/en), composants dans `packages/ui`. Aucune couleur ou taille en dur : uniquement les tokens.
- Logique métier pure (FEFO, prix, remises, numérotation, inventaire, caisse) : écrite **aussi** dans `packages/shared` en TypeScript pour le mode hors-ligne du desktop, avec les mêmes cas de test que côté PHP.

## 4. Règles non négociables

1. **TDD** : pour chaque tâche marquée `[TDD]`, écris d'abord le test qui échoue, puis le code.
2. **Constitution I** : les produits réglementés (`prescription_only`, `list_i`, `list_ii`, `narcotic`) ne sont jamais délivrés ni mis dans un devis sans validation humaine par un pharmacien. L'assistant (niveaux N1/N2/N3) ne contourne jamais cette règle. `ProductPolicy` est la règle partagée.
3. **Stock = journal de mouvements** : le stock courant est la somme des mouvements. Une correction est une écriture inverse, jamais une modification ni une suppression. Pas de suppression de produit ni de lot : on désactive.
4. **Secrets** : n'écris jamais d'identifiant, mot de passe ni clé dans le dépôt, les tests, les logs ou les prompts. Ils vivent uniquement dans `.env` (ignoré par git). Ne lis pas `.env` pour le recopier ailleurs.
5. **Données de test uniquement** tant que le site de préproduction est en HTTP (`http://sanara.trugroup.cm`).
6. **Aucune donnée personnelle de l'équipe envoyée à un service externe.**
7. Texte utilisateur en français d'abord, anglais en parité. Montants formatés par `packages/shared/src/format`.
8. Accessibilité : labels réels, focus visible, navigation clavier (la caisse doit se faire sans souris).

## 5. Commits et GitHub

- **Un commit par modification cohérente, poussé immédiatement** sur la branche de l'agent, avec un message explicite.
- Format Conventional Commits imposé par commitlint : `type(scope): sujet`, en-tête **≤ 100 caractères**, sujet **commençant par une minuscule** (donc pas « SMS … » ni « FEFO … » en tête de sujet).
- Fin de message : `Co-Authored-By: GitHub Copilot <noreply@github.com>`.
- Husky + lint-staged + prettier tournent au commit : ne les désactive pas (`--no-verify` interdit).
- Ouvre une PR par lot de phases vers `main` ; le mainteneur corrige et fusionne.

## 6. Vérifications avant chaque push

```
cd apps/api && composer test            # PHPUnit (SQLite en mémoire)
corepack pnpm -r test                    # Vitest (shared, sync, assistant, web, desktop)
corepack pnpm -r lint && corepack pnpm -r typecheck
corepack pnpm exec playwright test       # e2e (API PHP en SQLite sur 8081 + vite sur 5173)
```

Chaque nouvelle fonctionnalité apporte ses tests PHP, ses tests Vitest pour la logique partagée, et un test Playwright pour le parcours principal. Le budget de performance (ajout d'un article en caisse < 200 ms, recherche produit < 200 ms) est testé automatiquement.

## 7. Répartition entre les 4 agents (par identifiants de `tasks.md`)

Chaque agent possède des dossiers distincts et travaille sur sa branche. Les fichiers partagés (`Services.php`, `Routes.php`, `TenantTables.php`, `DefaultRoles.php`, `fr.json`, `en.json`, `tasks.md`) ne reçoivent que de petits ajouts en fin de section, rebasés souvent. **Plages de migrations** : agent 1 `0006`–`0019`, agent 2 `0020`–`0039`, agent 3 `0040`–`0059`, agent 4 `0060`–`0079`.

**Ordre imposé par les dépendances** (section « Dépendances et ordre » de `tasks.md`) : l'agent 1 livre d'abord le stock (T088–T096) puis la vente (T103–T119), car les agents 2 et 3 en dépendent (US28 dépend de US6, US7, US10, US11 ; US23 dépend de US11). Tant que ces API n'existent pas, les agents 2 et 3 avancent sur ce qui ne dépend pas d'elles (voir ci-dessous) et utilisent des contrats d'API convenus d'avance, écrits dans `specs/001-sanara-platform/contracts/`.

### Agent 1 : cœur officine (branche `feat/core-pos`)

Dossiers : `apps/api/app/Modules/{Stock,Sales,Cash,Purchases,Customers,Patients,Finance,Reports}`, `apps/web/src/pages/{Stock,Pos,Purchases,Customers,Patients,Reports}`.

- **MVP 1** : T088–T096 (US7), T097–T102 (US8), T103–T111 (US10), T112–T119 (US11), T120–T124 (US12).
- **Annexe maquette** : T301 (caisse H4), T302 (compte caissier H7, PIN 4 chiffres), T304 (stock H5/H8), T305 (achats H9), T307 (reçu 80 mm R1 et facture A4 R2).
- **V2** : T216–T219 (US9), T220–T224 (US13), T225–T229 (US15), T230–T233 (US16), T234–T239 (US17), T240–T245 (US30, avec rapports planifiés par `JobQueue` et non BullMQ), T246–T250 (US31), T314, T315.
- **V3** : T267, T268, T313 (US14 tiers payant).
- Les documents imprimables (tickets, factures, procès-verbaux, rapport Z) sont des pages HTML avec feuille de style d'impression ; un rendu PDF serveur n'est ajouté que s'il ne requiert aucun binaire système sur l'hébergement.

### Agent 2 : desktop, synchronisation hors-ligne, matériel (branche `feat/desktop-sync`)

Dossiers : `apps/desktop`, `packages/sync`, `packages/shared`, `packages/ui/src/hardware`, `apps/api/app/Modules/Sync`.

- **MVP 1** : T125–T132 (US27), T133–T143 (US28).
- **MVP 2** : T200–T204 (US29 matériel : ESC/POS, tiroir-caisse, scanner, page de test, matrice de compatibilité).
- **Annexe maquette** : T308 (desktop D1), T309 (desktop D2, écran de conflit de stock), T280 (test visuel web contre Electron).
- **V2** : T265 (sauvegarde locale chiffrée du desktop et restauration).
- Dès le départ (sans attendre l'agent 1) : T125–T132 et T133. La logique pure TypeScript de `packages/shared` (FEFO T089/T090, inventaire T098, vente T104/T105, numérotation T110, caisse T113, retours T121, documents T116) est **écrite par l'agent 2 en TS avec les mêmes jeux de cas de test que les tests PHP de l'agent 1** ; les deux implémentations doivent donner des résultats identiques. Les deux agents se mettent d'accord sur ces jeux de cas dans `packages/shared/fixtures/*.json`, lus par les tests PHP et TS.
- T134 et T143 (scénarios hors-ligne sur 2 postes, coupure de 8 h) sont bloquants pour le jalon MVP 1.

### Agent 3 : site public et assistant d'ordonnances (branche `feat/public-assistant`)

Dossiers : `apps/web/src/public`, `packages/assistant`, `apps/api/app/Modules/{Public,Requests,Assistant,Presence}`, `tests/assistant-eval`.

- **MVP 2** : T144–T153 (US18), T154–T159 (US19), T160–T170 (US21), T171–T178 (US22), T179–T185 (US23, la partie retrait en caisse T184 attend l'agent 1), T186–T194 (US24), T195–T199 (US26).
- **Annexe maquette** : T290, T293, T294, T295, T296, T297, T298, T299, T300, T303, T306 (réglages de l'assistant H6).
- **V2** : T205–T215 (US25 autonomie N1/N2/N3 et fiabilité).
- **V3** : T269, T270 (US20 disponibilité publique des produits).
- Carte : **Leaflet + OpenStreetMap**, tuiles PMTiles hébergées par nous en production, itinéraire par lien vers openstreetmap.org. Le captcha de T047 est à ajouter ici pour tous les formulaires publics.
- Règle d'or : **aucun produit réglementé (`prescription_only`, `list_i`, `list_ii`, `narcotic`) n'est jamais dans un devis automatique**, à aucun niveau d'autonomie. Test en CI (T215/SC-007). Escalade urgente : notification à 0 min, SMS à 3 min, appel de l'adjoint à 6 min, délais réglables ; le cron ne passe qu'une fois par minute, à documenter.
- Sans attendre l'agent 1 : T144–T159, T160–T170, T186–T194, T195–T199 (aucune dépendance sur la vente).

### Agent 4 : plateforme, administration, qualité (branche `feat/platform-final`)

Dossiers : `apps/api/app/Common`, `apps/web/src/pages/Admin*`, `packages/ui`, `infra`, `tests/e2e`, `.github`, `docs`.

- **Restes des phases 0 à 8** : T001a, T001b (rotation des identifiants à la bascule), T003 (contrats OpenAPI), T004 à T006 (jeux et textes légaux, à préparer avec le pharmacien pilote), T019 (catalogue de référence au-delà de 38 produits), T030 (client OpenAPI typé), T041 (schémas de validation du formulaire), T045 (carte Leaflet de l'inscription, en partage avec l'agent 3), T047 (captcha), T061 (logo, imprimante, import dans l'assistant), T067 (rôle préparateur), T073 (2FA par SMS), T076 (table `devices` par poste, en lien avec l'agent 2), et l'écran Équipe qui ne renvoie pas les droits individuels après rechargement.
- **Annexe maquette** : T279 (composants `@sanara/ui`), T281 à T286 (inscription en 4 étapes, complément, formule et postes, activation en deux temps, connexion PIN, assistant de configuration en 4 étapes), T287 à T289 (écrans admin pharmacies, formules, facturation, signalements, modèles d'e-mails, audit, paramètres, pays et zones), T292 (identité visuelle), T310 (console super admin S1/S2), T311, T312, T316 (matrice rôle → écrans avec tests d'accès).
- **V2** : T251–T255 (US32 abonnements), T256–T260 (US33 supervision, audit, modération), T261–T264 et T266 (US35 notifications, export, consentements).
- **V3** : T271 (second pays par configuration seule), T272 (interface `PaymentProvider`, Mobile Money, envisager apisungku/pawaPay), T273 (livraison désactivée en v1).
- **Phase finale** : T274 (OWASP ASVS), T275 (accessibilité et performance), T276 (documentation FR/EN), T277 (exploitation, sauvegardes, reprise), T278 (pilote réel, après validation humaine).
- **CI** : ne la modifie que pour y ajouter des jobs de tes propres tâches. La correction de la CI GitHub, le déploiement et `infra/deploy/deploy.ps1` sont **hors périmètre des agents** (voir section 8).

### Jalons communs

1. **MVP 1** : agents 1 et 2 (T088–T143). Démonstration et validation par le pharmacien pilote avant de continuer.
2. **MVP 2** : agent 3 et T200–T204. Site public et assistant au niveau N1 validés par la pharmacie pilote.
3. **V2 puis V3**, une story à la fois, chacune validée avant la suivante.
4. **Phase finale** : agent 4, avec revue croisée des 4 branches.
5. T001a (HTTPS), T004–T006 et T278 (pilote réel) ne peuvent pas être faites par un agent seul : les signaler comme bloquées en attendant l'humain, ne pas les simuler.

## 8. Correction et déploiement : hors périmètre des agents

**Les agents ne déploient pas et ne touchent pas à `infra/deploy/`, au `.env` ni aux identifiants FTP/MySQL.** Claude (le mainteneur) s'occupe, après chaque jalon : de la relecture et de la correction des PR, de la CI GitHub, des fusions dans `main` et du déploiement FTP sur la préproduction. Les agents signalent dans la PR toute migration à appliquer, toute variable d'environnement nouvelle (ajoutée à `.env.example` seulement) et toute tâche de fond à brancher sur le cron.

- Hébergement mutualisé PHP + MySQL 8, **déploiement FTP uniquement**, HTTP seulement pour l'instant. Le site est à `http://sanara.trugroup.cm`, API sous `/api`, code sous `/api-core`.
- Les identifiants sont dans le `.env` local (ignoré par git) : ne les affiche pas, ne les copie pas dans un fichier suivi.
- Pas de cron fiable côté Camoo : les tâches de fond sont déclenchées par l'URL `/api/internal/run-jobs?key=…` appelée chaque minute, avec un vidage opportuniste après certaines requêtes. Tout nouveau job (péremption, alertes, synchronisation) doit passer par `JobQueue` et ce déclencheur.
- Le WAF de l'hébergeur bloque certains caractères (apostrophes dans des corps de requête non JSON) ; les formats JSON passent. Voir `research.md` §6.
- Avant toute donnée réelle : **HTTPS obligatoire** (T001a). D'ici là, uniquement des comptes et données jetables.

## 9. Définition de « terminé » pour une tâche

La case est cochée dans `tasks.md`, les tests (PHP, Vitest, Playwright) passent, `NoRawSqlTest` et `TenantIsolationTest` restent verts, les textes sont en fr et en, le code est commité et poussé avec un message conforme, et les limites ou écarts éventuels sont notés dans la description de la PR.

## 10. Livrables attendus à la fin

- Toutes les cases de `tasks.md` cochées (ou, pour chaque case non cochée, une ligne expliquant pourquoi).
- Branches poussées, tests locaux verts. La CI sur `main` et la préproduction sont gérées par le mainteneur.
- Un court rapport par agent : ce qui est fait, ce qui reste, les risques.
