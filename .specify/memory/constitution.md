# Sanara Constitution

Sanara est une plateforme SaaS de gestion de pharmacie d'officine : application web, application desktop installable (Windows), site public de recherche de pharmacies et assistant de traitement d'ordonnances. Cette constitution fixe les règles qui s'imposent à toutes les spécifications, tous les plans et toutes les tâches.

## Core Principles

### I. La sécurité du patient prime, l'humain décide (NON-NEGOTIABLE)
L'assistant (IA) lit, prépare, oriente et relaie. Il ne prend jamais de décision pharmaceutique sans validation d'un pharmacien, sauf dans le cadre strict d'un niveau d'autonomie explicitement accordé par le pharmacien principal.
- Aucun conseil médical, aucune posologie, aucune substitution proposée au patient par l'assistant.
- Stupéfiants, psychotropes, produits listés, ordonnances ambiguës ou illisibles : validation humaine TOUJOURS, quel que soit le niveau d'autonomie.
- Symptômes graves évoqués : message fixe orientant vers les urgences, sans analyse.
- Le niveau d'autonomie est réglé par pharmacie, révocable à tout moment, et chaque décision automatique est tracée et réversible.
- Toute facture ou tout devis émis porte l'identité du pharmacien responsable ou la mention explicite du niveau d'autonomie appliqué.

### II. Offline-first pour le comptoir (NON-NEGOTIABLE)
Une coupure internet ne doit jamais empêcher de vendre, d'encaisser, d'imprimer un ticket ni de consulter le stock. L'application desktop travaille sur une base locale SQLite et se synchronise dès que le réseau revient. Les fonctions qui exigent le réseau (assistant patient, site public, emails) dégradent proprement, avec un message clair.

### III. Données de santé : confidentialité par conception
Ordonnances, dossiers patients et historiques de délivrance sont des données sensibles.
- Chiffrement en transit (TLS) et au repos pour les documents et champs sensibles.
- Accès limité à la pharmacie concernée et aux rôles autorisés ; accès consigné.
- Consentement explicite du patient avant dépôt d'une ordonnance ; durée de conservation définie ; suppression sur demande quand la loi le permet.
- Aucune donnée d'une pharmacie n'est utilisée pour améliorer l'assistant d'une autre sans consentement et anonymisation.
- Aucune donnée personnelle dans les logs applicatifs ni dans les requêtes vers des services externes au-delà du strict nécessaire.

### IV. Multi-tenant et isolation stricte
Une pharmacie = un tenant. Toute table métier porte l'identifiant de la pharmacie et toute requête est filtrée par celui-ci. MySQL n'offrant pas de Row-Level Security, le filtre est imposé par une couche d'accès aux données centralisée (scope global du modèle, aucune requête métier écrite à la main sans ce scope) et vérifié par des tests. Un test automatisé d'isolation est obligatoire pour chaque nouvelle entité.

### V. Multi-pays par configuration
Le Cameroun est le premier marché, mais rien n'est codé en dur : devise, langue, taxes, formats d'adresse et de téléphone, fournisseurs de paiement/SMS, listes réglementaires et documents exigés à l'inscription sont des paramètres par pays. Langues : français et anglais dès la v1 (i18n obligatoire, aucun texte en dur dans le code).

### VI. Traçabilité et audit
Toute action sensible (vente, annulation, remise, mouvement de stock, délivrance, validation, changement de rôle, décision de l'assistant) produit une entrée d'audit immuable : qui, quoi, quand, depuis quel poste, avant/après. Le stock et la caisse sont des journaux de mouvements ; on ne modifie pas l'historique, on le corrige par écriture inverse.

### VII. Test-first sur les domaines critiques
TDD obligatoire (tests écrits et en échec avant l'implémentation) pour : calcul de prix/TVA/remises, stock par lot et FEFO, clôture de caisse, synchronisation et résolution de conflits, règles de l'assistant (niveaux d'autonomie, escalade), isolation multi-tenant, autorisations. Le reste est testé raisonnablement. Chaque user story possède un test d'acceptation de bout en bout.

### VIII. Simplicité et une seule base de code
TypeScript partout. L'interface React est écrite une seule fois et sert le web et le desktop (Electron). Pas d'abstraction spéculative : toute complexité ajoutée est justifiée par écrit dans le plan. Les intégrations externes (paiement, SMS, IA, carte) passent par des interfaces remplaçables.

### IX. Utilisable dans des conditions réelles
Cible : PC modestes (4 Go de RAM), réseaux lents ou instables, écrans variés, mobile pour le site public. Budgets : démarrage du desktop < 5 s, ajout d'un article en caisse < 200 ms, page publique < 3 s en 3G. Raccourcis clavier et lecteur de codes-barres de première classe dans la caisse. Accessibilité de base (contrastes, navigation clavier).

### X. Livraison par user story indépendante
Chaque user story est livrable, testable et démontrable seule. On livre par ordre de priorité (P1 d'abord). Une story n'est terminée que lorsque ses critères d'acceptation passent, que l'audit et l'i18n sont en place et que la documentation utilisateur est à jour.

## Contraintes techniques

| Couche | Choix |
|---|---|
| Interface web + desktop | React + Vite (SPA) + TypeScript, Tailwind CSS ; build statique servi par l'hébergeur |
| Desktop installable | Electron + electron-builder (installateur Windows `.exe`, mise à jour automatique) |
| Base locale desktop | SQLite (better-sqlite3) + file d'opérations de synchronisation |
| API | PHP 8.x (Laravel si la version de PHP de l'hébergeur le permet, sinon Slim), REST + OpenAPI |
| Base serveur | MySQL 8.0 (filtre tenant imposé par la couche d'accès) |
| Hébergement | Camoo (mutualisé) : sous-domaine `sanara.trugroup.cm`, MySQL, FTP ; pas de Node, pas de Redis, HTTPS indispensable |
| Carte | Leaflet + OpenStreetMap |
| Assistant | API Claude (vision) derrière une interface `AssistantProvider` |
| Notifications | Email transactionnel, SMS via passerelle abstraite, notifications push |
| Paiement en ligne | Interface `PaymentProvider` (désactivée en v1, paiement à la pharmacie) |
| Monorepo | pnpm workspaces : `apps/web`, `apps/desktop`, `apps/api`, `packages/*` |

Secrets hors dépôt (fichier `.env` ignoré par git, jamais committé ni collé dans une spec), dépendances à jour et auditées, aucun mot de passe en clair (lien d'activation à durée limitée, hachage Argon2id). Aucune donnée de santé ne transite en HTTP clair : la mise en production est conditionnée à un certificat HTTPS valide sur le sous-domaine. Les contraintes de l'hébergeur (pare-feu applicatif, tâches planifiées limitées, pas de processus permanents) sont traitées comme des exigences de conception : files de jobs en base déclenchées par cron, temps réel par polling court.

## Workflow de développement

1. Spec Kit : constitution → spécification → plan → tâches → implémentation, story par story.
2. Une branche par user story (`feat/US-xx-nom`), revue obligatoire, intégration continue (lint, types, tests, build desktop).
3. Migrations de base versionnées et réversibles ; synchronisation rétrocompatible sur au moins une version.
4. Une pharmacie pilote valide chaque jalon (MVP 1, MVP 2, V2) avant généralisation.
5. Aucune fonctionnalité de livraison à domicile ni de paiement en ligne n'est activée en v1 ; le modèle de données les prévoit sans les exposer.

## Governance

Cette constitution prime sur toute autre pratique. Tout amendement doit être documenté (motif, impact, plan de migration) et approuvé par le propriétaire du produit. Les revues vérifient la conformité aux principes I à X ; toute dérogation est consignée dans la section « Complexity Tracking » du plan concerné.

**Version**: 1.0.0 | **Ratified**: 2026-11-23 | **Last Amended**: 2026-11-23
