# Sanara - Points bloquants par agent

Date: 2026-11-24
Objet: Synthèse des blocages à transmettre au chef de projet

## Résumé exécutif

- Chemin critique MVP1: Agent 1 doit livrer stock + FEFO + API stock (T088-T091) puis vente/caisse (T103-T119).
- Agents 2 et 3 peuvent avancer en parallèle sur des lots non dépendants.
- Blocages humains non délégables: HTTPS production, validation légale/pharmacien, pilote réel.
- Blocages techniques actuels: contrats API inter-agents à figer pour éviter dérives d’intégration.

## Agent 1 - Coeur officine (feat/core-pos)

Statut:

- Démarrage en cours sur T089-T091 (TDD FEFO + service stock + routes API).

Blocages:

- Dépendance de qualité: finaliser conventions de mouvements de stock (nomenclature des kinds et règles de correction inverse).
- Dépendance produit: valider règles métiers FEFO sur lots expirés/quarantaine avant extension ventes.
- Dépendance inter-agents: sans API stock stable, Agent 2 (sync) et Agent 3 (devis avec réservation) ne peuvent pas finaliser leurs flux critiques.

Décisions attendues chef de projet:

- Validation explicite des conventions stock journalier (types de mouvements et sens des quantités).
- Priorité stricte maintenue sur T088-T119 avant extensions V2/V3.

## Agent 2 - Desktop et synchronisation (feat/desktop-sync)

Statut:

- Peut avancer immédiatement sur coque Electron, packaging, base locale, moteur sync client, conflits, indicateurs offline.

Blocages:

- Dépendance API: endpoints sync serveur T137 non disponibles tant que les contrats push/pull ne sont pas figés.
- Dépendance métier: parité stricte TS/PHP (fixtures communs) requiert une base stable des règles FEFO/vente/caisse livrées par Agent 1.
- Dépendance produit: sémantique définitive de device/workstation (T076) à aligner avec Agent 4.

Décisions attendues chef de projet:

- Imposer un contrat unique des fixtures partagés sous packages/shared/fixtures.
- Valider schéma final de l’identité poste pour éviter refactor tardif desktop + sécurité.

## Agent 3 - Public et assistant (feat/public-assistant)

Statut:

- Peut avancer sur US18/US19 backend-first (recherche pharmacies, lecture publique, filtres, distance), puis US21/US24/US26 non bloquées par caisse.

Blocages:

- Dépendance sécurité: captcha global public à finaliser (travail partagé avec Agent 4).
- Dépendance stock/vente: US22/US23 (devis, réservation, retrait caisse T184) partiellement bloqués tant que flux POS/caisse Agent 1 non livrés.
- Contrainte réglementaire: impossibilité de finaliser autonomie sans verrou produit réglementé testé en CI (T215).

Décisions attendues chef de projet:

- Validation immédiate du principe no regulated auto-quote comme règle de release bloquante.
- Arbitrage priorité: finir recherche publique + dépôt ordonnance avant fonctionnalités de confort UI.

## Agent 4 - Plateforme, admin, qualité (feat/platform-final)

Statut:

- Peut traiter immédiatement les restes phase 0-8 et bugs transverses.

Blocages:

- Blocages humains non délégables:
  - T001a: certificat HTTPS valide pour production.
  - T004-T006: corpus ordonnances annotées et validations légales avec pharmacien/ordre.
  - T278: pilote réel en pharmacie.
- Dépendance inter-agents:
  - T076 (devices) doit rester synchronisé avec Agent 2.
  - T045/T047 partagés avec Agent 3 (carte + captcha publics).
- Dette fonctionnelle confirmée:
  - Bug écran équipe: overrides non renvoyés par API et non restaurés correctement au rechargement.

Décisions attendues chef de projet:

- Planifier une session humaine dédiée conformité/légal (T004-T006).
- Nommer un owner sécurité infra pour T001a avant toute donnée réelle.

## Blocages transverses projet

- Infra/production:
  - HTTPS absent = interdiction de données réelles.
- Gouvernance:
  - Contrats API inter-agents à figer maintenant dans specs/001-sanara-platform/contracts.
- Intégration continue:
  - Maintenir vert: NoRawSqlTest + TenantIsolationTest + suites ciblées par lot.

## Message prêt à envoyer au chef de projet

Bonjour,

Voici les blocages actuels par agent sur Sanara.

1. Chemin critique MVP1: Agent 1 doit terminer stock/FEFO/API stock puis vente/caisse pour débloquer le reste.
2. Agent 2 peut avancer sur desktop/sync local, mais l’intégration finale dépend des contrats sync et des règles stock stabilisées.
3. Agent 3 avance sur public/assistant, mais les flux devis-retrait complets attendent les APIs POS/caisse.
4. Agent 4 peut corriger les restes phase 0-8, mais plusieurs items sont bloqués par des actions humaines (HTTPS, validations légales, pilote réel).

Actions de pilotage demandées:

- Valider immédiatement les conventions de mouvements de stock et les contrats API partagés.
- Prioriser et planifier les tâches humaines bloquantes: T001a, T004-T006, T278.
- Maintenir l’ordre de livraison MVP1 sans diversion vers V2/V3.

Merci.
