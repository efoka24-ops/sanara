# Implementation Plan: Sanara, plateforme de gestion de pharmacie

**Branch**: `001-sanara-platform` | **Date**: 2026-11-23 | **Spec**: [spec.md](spec.md)

## Summary

Plateforme SaaS multi-tenant hébergée sur Camoo (`sanara.trugroup.cm`) : une API PHP (Slim 4) + MySQL, une interface React (Vite) partagée entre le web et le desktop (Electron), un site public avec carte, et un assistant IA à autonomie progressive. Le desktop travaille hors-ligne sur SQLite et se synchronise par journal d'opérations idempotent.

> **Révision du 2026-11-23 (hébergement)** : l'hébergement fourni est un mutualisé Camoo (PHP, MySQL 8.0, FTP). Le plan initial NestJS + PostgreSQL + Redis n'y est pas exécutable. Le backend devient PHP/MySQL, le front un build statique React/Vite. Les chemins `apps/web/src/app/...` des tâches désignent des routes React Router rangées dans des dossiers équivalents (`apps/web/src/routes/...`) ; les chemins `apps/api/src/modules/...` désignent des modules PHP (`apps/api/app/Modules/...`). Phase 0 réalisée : voir research.md (audit Camoo) et data-model.md. Les tâches dont le contenu change sont corrigées dans tasks.md.

## Technical Context

**Language/Version**: TypeScript 5.x (front, desktop, packages), PHP 8.1 (API, Camoo : 8.1.34 constaté), Node.js 22+ en local pour les builds uniquement
**Primary Dependencies**: React 19, Vite, React Router, Tailwind, Slim 4 + Illuminate Database côté API, better-sqlite3 + Kysely côté desktop, Electron + electron-builder + electron-updater, Leaflet, Zod, TanStack Query, i18next, PHPUnit/Pest, Playwright, Vitest
**Storage**: MySQL 8.0 (serveur, base `trugro9159_sanara`), SQLite (desktop), documents sensibles chiffrés sur disque hors racine web (ou stockage objet externe si le quota Camoo est insuffisant)
**Testing**: Vitest (unitaires front/packages), Pest/PHPUnit (API), Playwright (E2E web), tests de synchro en simulation réseau, tests d'isolation multi-tenant
**Target Platform**: navigateurs modernes (mobile inclus), Windows 10/11 (desktop), hébergement mutualisé Camoo
**Project Type**: monorepo web + API + desktop
**Performance Goals**: ajout caisse < 200 ms, recherche produit < 200 ms sur 20 000 références, page publique < 3 s en 3G, démarrage desktop < 5 s
**Constraints**: offline-first pour la caisse, 4 Go de RAM, données de santé chiffrées, FR/EN
**Scale/Scope**: 100 pharmacies à 12 mois, 5 postes par pharmacie, 20 000 produits par catalogue, 35 user stories

## Constitution Check

| Principe               | Conformité du plan                                                                                                                        |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| I. Humain décide       | `packages/assistant` expose uniquement `propose()` ; les décisions passent par `AutonomyPolicy` ; liste de produits réglementés bloquante |
| II. Offline-first      | `packages/sync` + SQLite local, ids ULID générés côté client, opérations idempotentes                                                     |
| III. Données de santé  | Chiffrement enveloppe pour documents, accès journalisé, consentement stocké                                                               |
| IV. Multi-tenant       | `tenant_id` sur toutes les tables, scope global imposé par la couche d'accès (pas de RLS en MySQL), test d'isolation générique            |
| V. Multi-pays          | `CountryConfig` en base, i18n, aucune constante pays dans le code                                                                         |
| VI. Audit              | Intercepteur NestJS d'audit + table append-only                                                                                           |
| VII. Test-first        | Tâches de tests avant implémentation pour les domaines critiques                                                                          |
| VIII. Simplicité       | Monorepo TypeScript, une UI pour web et desktop                                                                                           |
| IX. Conditions réelles | Budgets de perf vérifiés en CI, tests sur matériel modeste                                                                                |
| X. Story par story     | tasks.md organisé par user story, checkpoint après chacune                                                                                |

## Project Structure

### Documentation

```text
specs/001-sanara-platform/
├── spec.md
├── plan.md
├── tasks.md
├── research.md        # à produire en phase 0 (choix Electron, sync, OCR/vision, carte)
├── data-model.md      # à produire en phase 0
└── contracts/         # OpenAPI par module
```

### Source Code

```text
Sanara/
├── apps/
│   ├── api/                 # PHP (Slim 4) : app/Modules/... (nommé src/modules/... dans les tâches)
│   │   └── src/
│   │       ├── common/      # auth, tenancy (scope global), audit, i18n, errors, rate-limit
│   │       ├── modules/
│   │       │   ├── applications/   # demandes d'accès pharmacie (US1-2)
│   │       │   ├── tenants/        # pharmacies, config, abonnements (US3, US32)
│   │       │   ├── users/          # équipe, rôles, devices (US4-5)
│   │       │   ├── catalog/        # produits (US6)
│   │       │   ├── stock/          # lots, mouvements, inventaire (US7-9)
│   │       │   ├── sales/          # ventes, caisse, retours (US10-12)
│   │       │   ├── customers/      # clients, crédit, tiers payant (US13-14)
│   │       │   ├── purchasing/     # fournisseurs, commandes (US15-16)
│   │       │   ├── patients/       # dossiers, ordonnances, registre (US17)
│   │       │   ├── public/         # recherche, fiches, gardes (US18-20)
│   │       │   ├── requests/       # demandes patient, devis, retrait (US21-23, US26)
│   │       │   ├── presence/       # présence, escalade (US24)
│   │       │   ├── assistant/      # orchestration IA, autonomie (US21, US25)
│   │       │   ├── sync/           # endpoints push/pull (US28)
│   │       │   ├── reports/        # tableaux de bord, finances (US30-31)
│   │       │   ├── notifications/  # email, SMS, push (US35)
│   │       │   └── superadmin/     # supervision, audit, modération (US33)
│   │       └── prisma/
│   ├── web/                 # React + Vite (SPA statique) : site public + espace pharmacie + super admin
│   │   └── src/app/{(public),(pharmacy),(admin)}/
│   └── desktop/             # Electron : main, preload, updater, impression, matériel
│       └── src/{main,preload,hardware,updater,local-db}/
├── packages/
│   ├── shared/              # types, schémas Zod, constantes, règles métier pures
│   ├── ui/                  # composants React partagés web + desktop
│   ├── sync/                # moteur de synchronisation (client + serveur)
│   ├── assistant/           # AssistantProvider, prompts, garde-fous, évaluation
│   ├── i18n/                # fr.json, en.json
│   └── config/              # tsconfig, eslint, prettier
├── infra/                   # Docker, CI, scripts de déploiement
└── tests/                   # e2e, isolation, synchro
```

**Structure Decision**: monorepo pnpm. La logique métier pure (prix, TVA, FEFO, règles d'autonomie) vit dans `packages/shared` pour être testée une fois et utilisée par le serveur et le desktop hors-ligne.

## Décisions clés

1. **Synchronisation** : journal d'opérations (`SyncOperation`) avec id client ULID, version de schéma, idempotence. Référentiels (catalogue, prix) : serveur gagnant. Mouvements (ventes, stock) : cumul d'événements, jamais d'écrasement d'état. Conflits journalisés (`SyncConflict`).
2. **Numérotation** : préfixe poste + séquence locale (ex. `P2-000123`), puis numéro légal attribué à la synchronisation si la loi l'exige.
3. **Assistant** : interface `AssistantProvider` implémentée par notre moteur (OCR local puis analyse et rapprochement catalogue, décision du 2026-11-27), sortie structurée validée par Zod (lignes, confiance, anomalies), garde-fous déterministes (liste de produits réglementés, détection de symptômes graves, interdiction de conseil), évaluation sur jeu d’ordonnances annoté. Limite connue : l’écriture manuscrite est mal lue par l’OCR ; le pharmacien lit alors l’image et saisit les lignes.
4. **Autonomie** : `AutonomyPolicy` par tenant (N1/N2/N3, seuils), évaluée par une fonction pure testée ; toute décision automatique produit un `AuditLog` et peut être annulée.
5. **Présence** : pas de processus permanent sur le mutualisé. Heartbeat par polling court (10 à 15 s) mis à jour en base ; chaîne d'escalade configurable (titulaire → adjoint) exécutée par un cron chaque minute lisant une table de jobs ; canaux push/SMS/appel via passerelles abstraites.
6. **Carte publique** : Leaflet + tuiles OSM ; recherche géographique MySQL (colonnes POINT avec index spatial, ou boîte englobante + Haversine), cache des fiches publiques.
7. **Sécurité des documents** : fichiers chiffrés hors racine web, accès uniquement via l'API avec URL signée à courte durée, contrôle de type à l'upload, suppression selon rétention.
8. **Desktop** : processus principal Electron isolé (contextIsolation, pas de nodeIntegration), IPC typé, impression ESC/POS, auto-update signé (les binaires de mise à jour sont déposés sur le sous-domaine par FTP).
9. **Hébergement et déploiement** : Camoo, déploiement par FTP (script de publication du build statique et de l'API, migrations MySQL exécutées par une commande protégée), `.env` hors dépôt, sauvegardes MySQL planifiées. **Prérequis bloquants à vérifier en phase 0 :** HTTPS sur `sanara.trugroup.cm` (sinon pas de données de santé), version de PHP et extensions (Sodium/OpenSSL, GD, Zip), quotas disque et base, nombre de tâches cron, comportement du pare-feu applicatif (cas connu : blocage des apostrophes sur un autre projet Camoo), limite de taille des uploads, envoi d'emails (SMTP/SPF/DKIM).

## Complexity Tracking

| Écart                                                    | Pourquoi nécessaire                                 | Alternative plus simple rejetée                                |
| -------------------------------------------------------- | --------------------------------------------------- | -------------------------------------------------------------- |
| Deux bases (PostgreSQL + SQLite)                         | Exigence offline-first                              | Cache navigateur : trop fragile pour la caisse                 |
| Moteur de synchronisation maison                         | Besoin de règles métier par type d'objet            | Outils génériques (CRDT) : sémantique de stock inadaptée       |
| File de jobs en base (table `jobs` + cron chaque minute) | Emails, SMS, relances d'escalade fiables sans Redis | Cron direct sans file : pas de reprise ni de relances précises |
| Polling court à la place du temps réel                   | Pas de WebSocket/SSE long sur mutualisé             | Serveur dédié : hors budget actuel                             |
