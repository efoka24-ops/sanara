# Prompt pour GitHub Copilot : implémenter un lot de Sanara

Mise à jour : 2026-11-26. Remplace les versions précédentes.

**Mode d'emploi** : copier tout ce qui suit la ligne de séparation dans Copilot (mode agent), après avoir choisi le numéro du lot à la première ligne. Un lot par session. Copilot rend un rapport dans un fichier ; Claude le vérifie, corrige, fusionne, déploie et teste ; puis on lance le lot suivant.

---

**LOT À TRAITER : 1**

Tu implémentes **Sanara**, un SaaS de gestion de pharmacie pour le Cameroun (multi-pays par configuration). Traite **uniquement** le lot indiqué ci-dessus, écris ton rapport (section 7), puis arrête-toi. Un autre agent (Claude, le mainteneur) relira ton travail à partir de ce rapport, le corrigera, le fusionnera et le déploiera.

## 0. Contrôle avant de commencer

1. `git status` doit être propre et `main` à jour avec `origin/main`. Seule exception tolérée : le dossier non suivi `specs/002-chasseur-de-tetes/` (ne l'ajoute à aucun commit, pas de `git add -A`).
2. Le fichier `apps/web/src/pages/AdminConsole.tsx` doit exister sur `main`.
3. Lance les vérifications de la section 5 et note les résultats de départ.

Si le point 1 ou 2 échoue, **arrête-toi et signale-le**. Ne supprime, n'écrase et ne mets de côté aucune modification existante.

## 1. À lire d'abord

1. `.specify/memory/constitution.md` (v1.0.2) : règles non négociables.
2. `specs/001-sanara-platform/spec.md`, `plan.md`, `data-model.md`, `research.md`, `docs/design-system.md`.
3. `specs/001-sanara-platform/tasks.md` : source de vérité des tâches. Lis aussi les remarques entre parenthèses après les cases cochées : elles signalent des restes.

Ignore `specs/002-chasseur-de-tetes/` : sujet distinct.

## 2. État au 2026-11-26

- **Fait** : fondations, inscription et validation des pharmacies, activation, configuration initiale, équipe et droits, sécurité du compte, catalogue, stock par lot (FEFO), inventaire, caisse, paiements, retours (API et écrans web), console super admin (API et écrans).
- **Ébauche** : application desktop (`apps/desktop/src` : `main.ts`, `preload.ts`, `local-db/`) et synchronisation (`packages/sync`).
- **Presque vide** : `packages/assistant`. Côté public, seule la recherche de pharmacies de base existe (`PublicPharmacyService`). Aucun module API pour la synchro, les demandes patients ou la présence.
- **Vérifications de référence** : 181 tests PHP, 47 tests unitaires, 16 tests Playwright, tous verts ; typage vert ; lint à 0 erreur et 18 avertissements.

## 3. Les lots

| Lot   | Contenu                                                                                  | Tâches de `tasks.md`                                                                                                                                   |
| ----- | ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **1** | Fin du MVP 1 : desktop, hors-ligne, synchro, performance caisse                          | T111, T125 à T143, T280, T308, T309                                                                                                                    |
| **2** | Restes des phases 0 à 8, console super admin, maquette officine                          | T003, T006 (brouillons), T019, T030, T041, T251 à T260, T279, T281 à T289, T292, T301, T302, T307, T310 à T312, T316, restes de T045, T047, T061, T073 |
| **3** | MVP 2 : site public, assistant d'ordonnances, devis, retrait, escalade, matériel         | T144 à T204, T290, T293 à T300, T303                                                                                                                   |
| **4** | V2 : autonomie, mouvements, clients, achats, patients, rapports, finances, notifications | T205 à T250, T261 à T266, T304 à T306, T314, T315                                                                                                      |
| **5** | V3 et finitions                                                                          | T267 à T277, T313                                                                                                                                      |

Précisions :

- **Lot 1** : commence par la coque desktop et la base locale (T125, T126, T129, T133), puis la synchro (T135 à T142, avec les tests T134 et T143), puis l'installateur et les mises à jour (T127, T128). Le hors-ligne exige les miroirs TypeScript encore absents dans `packages/shared` (inventaire, caisse, retours : voir T098, T113, T121) ; ils doivent donner les mêmes résultats que le PHP, avec des jeux de cas JSON communs dans `packages/shared/fixtures/`. Pour T126, « build Next.js » signifie le build Vite de `apps/web`.
- **Lot 2** : la console super admin existe déjà. Compare-la à la spécification, complète ce qui manque, ajoute les tests, et ne coche que ce que tu as vérifié.
- **Lot 3** : procède dans l'ordre US18-US19, puis US21-US22, puis US23-US24-US26-US29.
- **Bloqué sans humain** (ne pas simuler, ne pas cocher) : T001a (certificat HTTPS), T001b (rotation des identifiants), T004 et T005 (pharmacien et ordre), T006 (tu peux rédiger des brouillons marqués « à valider »), T130 (certificat de signature), T131 (machine virtuelle vierge), T132 (PC de 4 Go), T204 (imprimantes et scanners réels), T278 (pilote réel). Prépare ce qui peut l'être (checklists, pipeline) et liste le reste dans le rapport.
- **Clés externes** (IA, SMS, cartes) : code derrière une interface, avec un faux fournisseur pour les tests. Déclare les variables dans `.env.example` seulement.

## 4. Règles non négociables

1. **TDD** pour chaque tâche marquée `[TDD]` : le test d'abord, en échec, puis le code.
2. **Produits réglementés** (`prescription_only`, `list_i`, `list_ii`, `narcotic`) : jamais délivrés ni mis dans un devis sans validation d'un pharmacien, à aucun niveau d'autonomie. `ProductPolicy` est la règle partagée.
3. **Stock = journal de mouvements** : une correction est une écriture inverse, jamais une modification ni une suppression. On désactive les produits et les lots, on ne les supprime pas.
4. **Multi-tenant** : toute table métier porte `tenant_id`, figure dans `TenantTables::SCOPED` et n'est lue ou écrite que par `TenantDb`. Ne contourne jamais `NoRawSqlTest` ni `TenantIsolationTest`.
5. **API** : PHP 8.1 + Slim 4, SQL écrit à la main via `Db` (PDO), pas d'ORM. Le même SQL tourne sur MySQL 8 et SQLite ; le propre à un moteur va dans des fichiers `*.mysql.sql` ou `*.sqlite.sql`. Les chemins `prisma/` et NestJS de `tasks.md` sont d'anciens noms. Attention aux mots réservés de MySQL 8 (ex. `last_value`).
6. **Migrations** : la dernière est `0010_admin.sql`. Numérote à partir de `0011`, sans modifier une migration existante.
7. Identifiants ULID, dates UTC `DATETIME(3)`, argent en **entiers** (XAF), taux en points de base.
8. Droits via `RequirePermission`, journal via `AuditLogger`, tâches de fond via `JobQueue` (pas de BullMQ ni Redis), e-mails via `MailJobHandler` et `EmailTemplates`, erreurs via `ApiException` et `ErrorCatalog` (fr et en).
9. **Web** : jeton en mémoire (jamais dans `localStorage`), client `apps/web/src/api/client.ts`, textes dans `packages/i18n/src/{fr,en}.json` à parité, composants de `packages/ui`, couleurs et tailles issues des seuls tokens. N'ajoute pas de nouveaux avertissements de lint.
10. **Menu** : une entrée de `apps/web/src/app/layouts.tsx` n'est ajoutée qu'avec sa route et sa page. Les entrées sans page ont été retirées : remets chacune quand tu livres son écran.
11. **Secrets** : jamais d'identifiant ni de clé dans le dépôt, les tests, les journaux ou le rapport. Ne lis pas `.env`. Données de test uniquement.
12. Logique métier pure écrite aussi en TypeScript dans `packages/shared` quand le desktop en a besoin hors-ligne.
13. Accessibilité : vrais libellés, focus visible, navigation au clavier.
14. **Hébergement** : mutualisé PHP + MySQL, FTP seulement, pas de binaire système, tâches de fond déclenchées chaque minute par URL. Les envois de fichiers sont limités à 2 Mo sur la préproduction.

## 5. Vérifications avant chaque push

```
cd apps/api && php vendor/bin/phpunit        # composer n'est pas dans le PATH
cd ../..
corepack pnpm -r --if-present typecheck
corepack pnpm lint
corepack pnpm -r --if-present test
corepack pnpm exec playwright test           # API PHP en SQLite (8081) et Vite (5173)
```

Chaque fonctionnalité apporte ses tests PHP, ses tests Vitest pour la logique partagée et un test Playwright pour le parcours principal. Ne désactive, n'affaiblis et ne saute aucun test existant ; si un test doit changer parce que le comportement voulu a changé, explique-le dans le rapport.

## 6. Git

- Branche `copilot/lot-<N>` créée depuis `main`. Ne pousse jamais sur `main`, ne fusionne pas, pas de `push --force`.
- Un commit par modification cohérente, poussé aussitôt. Conventional Commits : `type(scope): sujet`, en-tête ≤ 100 caractères, sujet commençant par une minuscule, lignes du corps ≤ 100.
- Fin de message : `Co-Authored-By: GitHub Copilot <noreply@github.com>`.
- Hooks Husky actifs (`--no-verify` interdit).
- Coche une case de `tasks.md` dans le même commit que son code, et seulement si la tâche est complète et testée. Une tâche partielle reste décochée, avec une remarque.
- **Hors périmètre** : déploiement, `infra/deploy/`, `.env`, identifiants FTP et MySQL.

## 7. Rapport obligatoire

Écris `specs/001-sanara-platform/reports/copilot-lot-<N>.md`, mets-le à jour après chaque groupe de tâches (il doit rester utilisable si la session s'interrompt), commite-le et pousse-le sur la branche. Structure :

1. **Résumé** : lot, branche, premier et dernier commit, nombre de tâches faites, partielles, bloquées et non commencées.
2. **Tâches** : un tableau avec une ligne pour **chaque** tâche du lot : identifiant, statut (fait, partiel, bloqué, non commencé), fichiers principaux, tests qui la prouvent, ce qui manque.
3. **Vérifications** : pour chaque commande de la section 5, la commande, le résultat réel (nombre de tests réussis et échoués) et le commit testé. Écris « non exécuté » avec la raison si c'est le cas.
4. **Pour le déploiement** : nouvelles migrations, nouvelles variables d'environnement (noms seulement), nouvelles tâches de fond, nouvelles dépendances, extensions PHP nécessaires.
5. **Pour le test manuel** : parcours à rejouer sur la préproduction, comptes de démonstration utilisés, résultat attendu.
6. **Écarts et risques** : choix qui s'écartent de la spécification, code non testé, simulations et faux fournisseurs, points de sécurité à relire.
7. **Questions** pour le mainteneur ou le propriétaire.

N'indique jamais qu'une chose fonctionne si tu ne l'as pas exécutée. Une tâche honnêtement déclarée partielle vaut mieux qu'une case cochée à tort.
