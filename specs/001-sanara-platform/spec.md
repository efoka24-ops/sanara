# Feature Specification: Sanara, plateforme de gestion de pharmacie

**Feature Branch**: `001-sanara-platform`
**Created**: 2026-11-23
**Status**: Draft
**Input**: Plateforme SaaS (web + desktop installable) permettant à un pharmacien de digitaliser son officine, avec site public de recherche de pharmacies et assistant de traitement d'ordonnances. Cameroun d'abord, conçu multi-pays.

## Acteurs

| Acteur | Description |
|---|---|
| Visiteur / Patient | Utilisateur public, sans compte obligatoire. Cherche une pharmacie, dépose une ordonnance. |
| Pharmacien fondateur (Titulaire) | Demande l'accès, administre l'officine, règle l'autonomie de l'assistant. |
| Pharmacien adjoint | Valide les ordonnances, délivre, gère stock et équipe selon ses droits. |
| Préparateur | Prépare, délivre sous contrôle, gère le stock. |
| Caissier | Encaisse, imprime, clôture sa caisse. |
| Magasinier | Réceptions, inventaires, mouvements de stock. |
| Comptable | Lecture des finances, exports, dépenses. |
| Super admin | Valide les pharmacies, abonnements, supervision de la plateforme. |
| Assistant | Agent IA : premier contact avec le patient, lecture des ordonnances, relais et escalade. |

## Parcours d'ensemble

1. Le pharmacien demande l'accès sur le site public, le super admin valide, un email d'activation est envoyé.
2. Le pharmacien configure l'officine, crée son équipe, charge son catalogue et son stock.
3. L'officine vend au comptoir (web ou desktop, même hors-ligne) et se synchronise.
4. Le patient trouve la pharmacie sur la carte, dépose son ordonnance via l'assistant, reçoit un devis validé par un pharmacien, confirme et retire en officine.
5. Le pharmacien principal suit la fiabilité de l'assistant et, s'il le décide, élargit son autonomie par paliers.

## Priorités et jalons

| Jalon | Stories |
|---|---|
| **MVP 1** (officine utilisable) | US1 à US8, US10, US11, US12, US27, US28 |
| **MVP 2** (visibilité publique + assistant guidé) | US18, US19, US21, US22, US23, US24, US26, US29 |
| **V2** | US9, US13, US15, US16, US17, US25, US30, US31, US32, US33, US35 |
| **V3** | US14, US20, US34 étendu, paiement en ligne, livraison |

---

## User Scenarios & Testing *(mandatory)*

### Épopée A : Onboarding et administration

#### User Story 1 - Demande d'accès d'une pharmacie (Priority: P1)
Le pharmacien fondateur arrive sur le site public, clique sur « Digitaliser ma pharmacie » et remplit une demande : identité du titulaire, nom de l'officine, pays, région, département, ville, adresse, position GPS (carte ou saisie), téléphone, email, numéro d'agrément, n° d'inscription à l'ordre, documents (diplôme, agrément, pièce d'identité).

**Why this priority**: point d'entrée de toute la plateforme ; sans pharmacie, rien d'autre n'existe.
**Independent Test**: soumettre une demande complète et la voir apparaître « En attente » côté super admin, avec accusé de réception par email.

**Acceptance Scenarios**:
1. **Given** un visiteur sur la page d'inscription, **When** il remplit tous les champs obligatoires et joint les documents exigés pour son pays, **Then** une demande « En attente » est créée et un email d'accusé de réception est envoyé.
2. **Given** un formulaire incomplet ou un document de type/taille invalide, **When** il soumet, **Then** les erreurs sont affichées champ par champ et rien n'est créé.
3. **Given** un email ou un n° d'agrément déjà utilisé, **When** il soumet, **Then** le système refuse avec un message clair sans révéler de données d'une autre pharmacie.
4. **Given** une demande soumise, **When** le visiteur utilise le lien de suivi reçu, **Then** il voit le statut (en attente, validée, rejetée avec motif).

#### User Story 2 - Validation par le super admin et envoi des accès (Priority: P1)
Le super admin consulte la file des demandes, examine les documents, valide ou rejette avec motif. À la validation, le tenant est créé et un email contenant un lien d'activation à durée limitée est envoyé au titulaire.

**Why this priority**: protège la plateforme contre les fausses pharmacies.
**Independent Test**: valider une demande et recevoir l'email d'activation ; rejeter une demande et recevoir le motif.

**Acceptance Scenarios**:
1. **Given** une demande en attente, **When** le super admin la valide, **Then** une pharmacie (tenant) et un compte propriétaire sont créés, l'email d'activation part, l'action est auditée.
2. **Given** une demande, **When** le super admin la rejette sans motif, **Then** le système exige un motif.
3. **Given** une demande rejetée, **When** le demandeur consulte le suivi, **Then** il voit le motif et peut soumettre une nouvelle demande corrigée.
4. **Given** un lien d'activation expiré, **When** le titulaire l'ouvre, **Then** il peut demander un nouveau lien.
5. **Given** une demande déjà traitée, **When** un autre super admin tente de la retraiter, **Then** l'action est refusée (pas de double validation).

#### User Story 3 - Activation du compte et configuration initiale (Priority: P1)
Le titulaire active son compte (choix du mot de passe, 2FA proposée) puis suit un assistant de configuration : informations de l'officine, logo, horaires, devise/TVA par défaut, imprimante, visibilité publique, premier import de catalogue.

**Why this priority**: transforme un compte créé en officine prête à vendre.
**Independent Test**: depuis le lien d'activation, atteindre un tableau de bord d'officine configuré.

**Acceptance Scenarios**:
1. **Given** un lien valide, **When** le titulaire définit un mot de passe conforme, **Then** le compte est actif et l'assistant de configuration démarre.
2. **Given** l'assistant de configuration, **When** il quitte avant la fin, **Then** il reprend à l'étape où il s'était arrêté.
3. **Given** la configuration terminée, **When** il active la visibilité publique, **Then** la pharmacie apparaît sur la carte publique (US18) avec ses horaires.
4. **Given** un lien déjà utilisé, **When** il est rouvert, **Then** il est refusé.

#### User Story 4 - Équipe, rôles et permissions (Priority: P1)
Le titulaire invite des collaborateurs par email, leur attribue un rôle (adjoint, préparateur, caissier, magasinier, comptable) et ajuste des permissions fines (remises max, annulations, accès stupéfiants, accès finances).

**Independent Test**: inviter un caissier, vérifier qu'il vend mais ne peut ni modifier les prix ni voir les finances.

**Acceptance Scenarios**:
1. **Given** le titulaire, **When** il invite un utilisateur avec un rôle, **Then** un email d'invitation est envoyé et l'invité active son compte.
2. **Given** un caissier connecté, **When** il tente une action hors de ses droits, **Then** l'action est refusée et consignée.
3. **Given** un utilisateur désactivé, **When** il tente de se connecter ou de synchroniser un poste desktop, **Then** l'accès est refusé.
4. **Given** le titulaire, **When** il change un rôle, **Then** le changement s'applique à la prochaine requête et est audité.
5. **Given** la dernière personne titulaire, **When** on tente de la désactiver, **Then** l'action est refusée.

#### User Story 5 - Authentification et sécurité du compte (Priority: P1)
Connexion email/mot de passe, 2FA (application ou SMS), réinitialisation du mot de passe, verrouillage après échecs répétés, gestion des sessions et des postes desktop autorisés, verrouillage d'écran à l'inactivité.

**Independent Test**: se connecter avec et sans 2FA, déclencher un verrouillage, réinitialiser un mot de passe.

**Acceptance Scenarios**:
1. **Given** 5 échecs consécutifs, **When** une 6e tentative a lieu, **Then** le compte est temporairement verrouillé et le titulaire est notifié.
2. **Given** la 2FA activée, **When** l'utilisateur se connecte, **Then** un second facteur est exigé.
3. **Given** une demande de réinitialisation, **When** le lien est utilisé une fois, **Then** il devient invalide et toutes les sessions sont révoquées.
4. **Given** un poste desktop perdu, **When** le titulaire le révoque, **Then** ce poste ne peut plus se synchroniser.
5. **Given** 10 minutes d'inactivité (réglable), **When** l'utilisateur revient, **Then** il doit saisir son code PIN ou mot de passe.

---

### Épopée B : Catalogue et stock

#### User Story 6 - Catalogue produits (Priority: P1)
Créer, modifier, désactiver des produits : DCI, nom commercial, forme, dosage, conditionnement, laboratoire, codes-barres multiples, famille, statut réglementaire (libre, liste I/II, stupéfiant, sous ordonnance), taux de TVA, prix d'achat/vente, marge, seuil mini/maxi, génériques équivalents. Import/export Excel/CSV et catalogue de référence pré-chargé.

**Independent Test**: importer 500 produits, en rechercher un par code-barres, DCI ou nom.

**Acceptance Scenarios**:
1. **Given** un fichier CSV valide, **When** l'import est lancé, **Then** un rapport indique créés, mis à jour et rejetés (avec ligne et cause).
2. **Given** un produit existant, **When** on change le prix de vente, **Then** l'historique des prix est conservé et l'audit enregistré.
3. **Given** un produit sous statut réglementé, **When** on le crée, **Then** le statut est obligatoire.
4. **Given** une recherche, **When** on tape 3 caractères, **Then** les résultats apparaissent en moins de 200 ms sur 20 000 produits.
5. **Given** un produit, **When** on consulte sa fiche, **Then** on voit stock, lots, génériques équivalents et historique de prix.
6. **Given** un produit déjà vendu, **When** on le supprime, **Then** il est seulement désactivé (jamais effacé).

#### User Story 7 - Stock par lot, péremptions et alertes (Priority: P1)
Suivi du stock par lot (numéro, péremption, quantité), sortie automatique FEFO, alertes de stock minimum, de rupture et de péremption proche (30/60/90 jours), quarantaine et destruction tracée des produits périmés.

**Independent Test**: réceptionner 2 lots de péremptions différentes, vendre et vérifier que le plus proche de la péremption sort en premier.

**Acceptance Scenarios**:
1. **Given** deux lots d'un produit, **When** on vend, **Then** le lot à péremption la plus proche est décrémenté en premier.
2. **Given** un stock sous le minimum, **When** il est franchi, **Then** une alerte apparaît au tableau de bord et dans les notifications.
3. **Given** un lot périmé, **When** la date passe, **Then** il est bloqué à la vente et proposé à la mise en quarantaine.
4. **Given** une destruction, **When** le pharmacien la valide, **Then** un procès-verbal est généré et le stock est ajusté par écriture inverse.
5. **Given** une vente dépassant le stock disponible, **When** on valide, **Then** le système refuse sauf permission explicite de stock négatif (désactivée par défaut).

#### User Story 8 - Inventaire (Priority: P1)
Inventaire complet ou tournant par zone/famille, saisie ou scan, comptage à l'aveugle, écarts valorisés, validation par un pharmacien, ajustements tracés.

**Independent Test**: lancer un inventaire sur une famille, saisir des écarts et valider.

**Acceptance Scenarios**:
1. **Given** un inventaire ouvert, **When** on scanne des produits, **Then** les quantités comptées s'accumulent sans afficher le stock théorique si le mode aveugle est activé.
2. **Given** des écarts, **When** le pharmacien valide, **Then** les ajustements sont créés et valorisés.
3. **Given** un inventaire en cours, **When** une vente a lieu, **Then** elle est prise en compte dans le calcul des écarts (date/heure du comptage).
4. **Given** un inventaire démarré hors-ligne, **When** la synchronisation a lieu, **Then** les comptages sont fusionnés sans perte.

#### User Story 9 - Mouvements, pertes et transferts (Priority: P2)
Entrées/sorties manuelles motivées (casse, don, perte, échantillon), transferts entre sites d'une même chaîne avec accusé de réception, historique complet par produit.

**Independent Test**: déclarer une casse et un transfert, vérifier le journal de mouvements.

**Acceptance Scenarios**:
1. **Given** un produit, **When** on déclare une casse avec motif, **Then** le stock baisse et le mouvement est valorisé.
2. **Given** un transfert envoyé, **When** le site destinataire accuse réception, **Then** le stock est ajouté côté destinataire ; avant accusé, il est « en transit ».
3. **Given** un utilisateur sans droit, **When** il tente un mouvement, **Then** il est refusé.

---

### Épopée C : Ventes

#### User Story 10 - Caisse rapide (POS) (Priority: P1)
Vente au comptoir : scan code-barres, recherche par nom/DCI, quantités, remises (selon droits), mise en attente de paniers, client optionnel, contrôle des produits réglementés (ordonnance requise), raccourcis clavier, fonctionnement hors-ligne.

**Independent Test**: vendre 3 articles par scan, appliquer une remise autorisée, valider et imprimer.

**Acceptance Scenarios**:
1. **Given** la caisse, **When** on scanne un code-barres, **Then** la ligne est ajoutée en moins de 200 ms avec le lot FEFO sélectionné.
2. **Given** un produit sous ordonnance, **When** on l'ajoute, **Then** le système exige l'ordonnance (saisie ou rattachement) et l'identité du pharmacien délivrant.
3. **Given** un caissier avec remise max 5 %, **When** il saisit 10 %, **Then** une validation par un responsable est demandée.
4. **Given** un panier en cours, **When** on le met en attente, **Then** on peut le reprendre plus tard sur le même poste.
5. **Given** aucune connexion, **When** on vend, **Then** la vente est enregistrée localement avec numérotation sans collision et synchronisée plus tard.
6. **Given** un produit en rupture, **When** il est cherché, **Then** les génériques équivalents en stock sont suggérés.

#### User Story 11 - Paiements, tickets et clôture de caisse (Priority: P1)
Paiement en espèces, Mobile Money (saisie de référence en v1), carte, crédit client, paiements mixtes avec rendu de monnaie ; ticket (58/80 mm) et facture A4 ; ouverture avec fond de caisse ; clôture avec écart ; rapport Z.

**Independent Test**: ouvrir une caisse, vendre en paiements mixtes, clôturer et obtenir un Z sans écart.

**Acceptance Scenarios**:
1. **Given** une vente de 7 500 XAF, **When** 5 000 en espèces et 2 500 en Mobile Money sont saisis, **Then** la vente est soldée et le ticket indique les deux moyens.
2. **Given** une caisse ouverte, **When** on la clôture, **Then** l'écart entre théorique et compté est calculé et motivé s'il dépasse le seuil.
3. **Given** un ticket, **When** on le réimprime, **Then** il porte la mention « DUPLICATA ».
4. **Given** une caisse non clôturée la veille, **When** l'utilisateur se connecte, **Then** on lui demande de la clôturer avant d'en ouvrir une nouvelle.
5. **Given** l'imprimante absente, **When** la vente se valide, **Then** la vente est enregistrée et le ticket peut être réimprimé plus tard.

#### User Story 12 - Retours, annulations et avoirs (Priority: P1)
Annulation d'une vente, retour partiel ou total, remboursement ou avoir, remise en stock conditionnée (produit commercialisable ou non), motif obligatoire, approbation selon droits.

**Independent Test**: retourner un article d'une vente de la veille et vérifier stock, caisse et audit.

**Acceptance Scenarios**:
1. **Given** une vente soldée, **When** un retour partiel est approuvé, **Then** un avoir est créé et le stock est ajusté (ou mis en quarantaine).
2. **Given** un médicament délivré, **When** un retour est demandé, **Then** le pharmacien décide de la remise en stock (par défaut non).
3. **Given** un retour hors délai paramétré, **When** il est saisi, **Then** une approbation du titulaire est requise.

#### User Story 13 - Clients et crédit (Priority: P2)
Fiches clients, comptes à crédit avec plafond, relevés, relances, encaissement des soldes, fidélité (points).

**Acceptance Scenarios**:
1. **Given** un client à crédit, **When** son plafond serait dépassé, **Then** la vente à crédit est bloquée.
2. **Given** un solde dû, **When** un règlement partiel est saisi, **Then** le solde est mis à jour et un reçu est émis.
3. **Given** une relance programmée, **When** l'échéance arrive, **Then** un SMS/email de rappel est envoyé si le client y a consenti.

#### User Story 14 - Tiers payant et assurances (Priority: P3)
Conventions avec assurances/mutuelles/entreprises : part patient et part organisme, facturation groupée, suivi des paiements et rejets.

**Acceptance Scenarios**:
1. **Given** un assuré à 80 %, **When** la vente est saisie, **Then** la part patient (20 %) et la part organisme sont calculées.
2. **Given** une facturation mensuelle, **When** elle est générée, **Then** un bordereau par organisme est produit.
3. **Given** un rejet, **When** il est saisi, **Then** la créance est rouverte avec motif.

---

### Épopée D : Achats et fournisseurs

#### User Story 15 - Fournisseurs, commandes et réceptions (Priority: P2)
Fiches fournisseurs, bons de commande, réception avec contrôle des lots et péremptions, rapprochement avec la facture fournisseur, mise à jour du prix d'achat et du PMP.

**Acceptance Scenarios**:
1. **Given** un bon de commande envoyé, **When** la livraison arrive, **Then** la réception se fait ligne par ligne avec lot et péremption obligatoires.
2. **Given** une livraison partielle, **When** elle est réceptionnée, **Then** le reliquat reste ouvert.
3. **Given** une facture fournisseur, **When** son total diffère de la réception, **Then** l'écart est signalé.
4. **Given** un lot reçu avec péremption < 6 mois (paramétrable), **When** il est saisi, **Then** un avertissement est affiché.

#### User Story 16 - Suggestions de commande, retours fournisseurs et dettes (Priority: P2)
Suggestion de commande (stock mini, historique de ventes, saisonnalité simple), retours fournisseurs et avoirs, échéancier des dettes.

**Acceptance Scenarios**:
1. **Given** des produits sous leur seuil, **When** on génère les suggestions, **Then** un brouillon de commande par fournisseur est proposé avec quantités calculées.
2. **Given** un retour fournisseur validé, **When** il est enregistré, **Then** le stock baisse et un avoir attendu est créé.
3. **Given** des échéances, **When** elles approchent, **Then** une alerte est émise.

---

### Épopée E : Patients et ordonnances (officine)

#### User Story 17 - Dossier patient, ordonnances et registre (Priority: P2)
Fiche patient (identité, allergies, antécédents, traitements chroniques), saisie d'ordonnance (prescripteur, date, lignes), délivrance partielle et renouvellement, scan joint, registre des stupéfiants/psychotropes, historique de délivrance.

**Acceptance Scenarios**:
1. **Given** une ordonnance avec stupéfiant, **When** elle est délivrée, **Then** une ligne est ajoutée au registre avec pharmacien, patient, lot, quantité.
2. **Given** une ordonnance partiellement délivrée, **When** le patient revient, **Then** le reste à délivrer est proposé.
3. **Given** un patient avec allergie renseignée, **When** un produit correspondant est ajouté, **Then** une alerte bloquante à acquitter s'affiche.
4. **Given** une demande d'effacement, **When** la loi le permet, **Then** les données sont anonymisées sans casser les écritures comptables.

---

### Épopée F : Site public

#### User Story 18 - Recherche de pharmacies et carte (Priority: P1)
Le visiteur cherche par région, département, ville, quartier ou nom. Carte interactive : marqueurs regroupés, au survol/appui une fiche résumée (nom, distance depuis sa position, ouverte/fermée, de garde). Géolocalisation avec consentement ou saisie manuelle.

**Independent Test**: depuis un mobile, trouver les pharmacies d'une ville et voir la distance de chacune.

**Acceptance Scenarios**:
1. **Given** une ville sélectionnée, **When** la recherche est lancée, **Then** la liste et la carte affichent les pharmacies visibles, triées par distance si la position est connue.
2. **Given** la position refusée, **When** le visiteur saisit un quartier, **Then** les distances sont calculées depuis ce point.
3. **Given** le survol d'un marqueur (appui sur mobile), **When** il s'affiche, **Then** nom, distance et statut ouvert/fermé apparaissent.
4. **Given** une pharmacie suspendue ou non publique, **When** la recherche est lancée, **Then** elle n'apparaît pas.
5. **Given** un réseau lent, **When** la page se charge, **Then** la liste s'affiche avant la carte.
6. **Given** un double-clic (double appui) sur un marqueur, **When** il est déclenché, **Then** l'assistant s'ouvre pour cette pharmacie (US21).

#### User Story 19 - Fiche pharmacie, gardes et itinéraire (Priority: P1)
Fiche publique : adresse, téléphone, horaires, services, assurances acceptées, statut de garde, itinéraire (lien vers app de navigation), photos. Page « pharmacies de garde » par zone et par date.

**Acceptance Scenarios**:
1. **Given** une pharmacie de garde ce soir, **When** un visiteur consulte la page de garde, **Then** elle est listée avec ses horaires de garde.
2. **Given** des horaires modifiés par le titulaire, **When** la fiche est rechargée, **Then** le statut ouvert/fermé est correct pour le fuseau du pays.
3. **Given** une fiche, **When** on clique sur « Itinéraire », **Then** l'application de navigation s'ouvre avec la destination.

#### User Story 20 - Disponibilité d'un médicament (Priority: P3)
Le visiteur cherche un médicament et voit les pharmacies qui indiquent l'avoir en stock (opt-in de la pharmacie, information indicative sans quantité exacte).

**Acceptance Scenarios**:
1. **Given** une pharmacie ayant activé la publication, **When** le produit passe à zéro, **Then** elle cesse d'être listée pour ce produit.
2. **Given** un produit réglementé, **When** il est recherché, **Then** le résultat rappelle qu'une ordonnance est nécessaire.

---

### Épopée G : Assistant

#### User Story 21 - Dépôt d'ordonnance et analyse (Priority: P1)
Depuis une fiche pharmacie, le patient ouvre l'assistant, donne son consentement, envoie une ordonnance (photo, PDF, plusieurs pages), indique prénom/téléphone et le mode « retrait en officine ». L'assistant analyse et produit une liste de produits probables avec un niveau de confiance.

**Independent Test**: envoyer une ordonnance lisible, voir la demande arriver côté pharmacie avec la lecture proposée.

**Acceptance Scenarios**:
1. **Given** une ordonnance lisible, **When** elle est envoyée, **Then** la demande est créée avec lecture proposée et confiance par ligne.
2. **Given** un document illisible ou hors sujet, **When** l'analyse échoue, **Then** l'assistant demande une meilleure photo ou transmet tel quel au pharmacien.
3. **Given** un fichier trop lourd ou d'un type interdit, **When** il est envoyé, **Then** il est refusé avec explication.
4. **Given** le patient qui décrit des symptômes graves, **When** l'assistant le détecte, **Then** il affiche le message d'orientation vers les urgences et ne donne aucun avis.
5. **Given** des envois répétés abusifs, **When** la limite est dépassée, **Then** le service est temporairement limité (anti-spam).
6. **Given** le patient qui refuse le consentement, **When** il continue, **Then** aucun document n'est enregistré.

#### User Story 22 - File de traitement et devis par le pharmacien (Priority: P1)
Le pharmacien voit la file des demandes (priorité, urgence, ancienneté), ouvre le document avec la lecture proposée, corrige, rattache chaque ligne au catalogue, vérifie le stock et génère le devis (produits, quantités, prix, total, validité). Chaque correction est mémorisée pour l'amélioration.

**Independent Test**: traiter une demande de bout en bout jusqu'à l'envoi du devis.

**Acceptance Scenarios**:
1. **Given** une demande, **When** le pharmacien corrige une ligne, **Then** la correction est enregistrée avec l'original lu.
2. **Given** un produit hors stock, **When** il est traité, **Then** il peut être marqué indisponible ou remplacé par un générique avec accord du patient.
3. **Given** un devis validé, **When** il est envoyé, **Then** le patient le reçoit par l'assistant (et SMS si configuré).
4. **Given** deux pharmaciens ouvrent la même demande, **When** l'un la prend, **Then** elle est verrouillée pour l'autre.
5. **Given** un devis, **When** sa durée de validité expire, **Then** il passe « expiré » et le stock réservé est libéré.

#### User Story 23 - Validation patient et retrait (Priority: P1)
Le patient accepte tout ou partie du devis, choisit le retrait en officine, reçoit une confirmation avec code de retrait. La pharmacie prépare la commande et la marque « prête » ; le patient est notifié. Au retrait, le code est vérifié et la vente est enregistrée en caisse. Paiement à la pharmacie.

**Acceptance Scenarios**:
1. **Given** un devis, **When** le patient valide une partie, **Then** seules les lignes acceptées sont préparées.
2. **Given** une commande prête non retirée après X jours, **When** le délai expire, **Then** elle est annulée et le stock libéré.
3. **Given** un patient au comptoir, **When** il donne son code, **Then** la caissière retrouve la commande et l'encaisse sans ressaisie.
4. **Given** un devis refusé, **When** le patient refuse, **Then** la demande est clôturée et le motif facultatif consigné.

#### User Story 24 - Présence des pharmaciens et escalade urgente (Priority: P1)
L'assistant connaît la présence des pharmaciens. Pharmacien connecté : « demande en cours de traitement ». Personne connecté : « traitée à l'arrivée du pharmacien ». Si le patient déclare une urgence : escalade vers le titulaire puis l'adjoint (notification, SMS, appel/WhatsApp selon configuration) avec relances jusqu'à prise en charge ou épuisement, puis message honnête au patient.

**Acceptance Scenarios**:
1. **Given** un pharmacien connecté, **When** une demande normale arrive, **Then** le patient voit « en cours de traitement ».
2. **Given** personne connecté et demande normale, **When** elle arrive, **Then** le patient est informé qu'elle sera traitée au retour d'un pharmacien et la pharmacie reçoit une notification.
3. **Given** une urgence déclarée et personne connecté, **When** elle est confirmée par le patient, **Then** l'escalade démarre : titulaire, puis adjoint, avec délai entre relances paramétrable.
4. **Given** aucune réponse après épuisement de la chaîne, **When** le délai max est atteint, **Then** le patient est invité à appeler la pharmacie ou une autre pharmacie de garde.
5. **Given** une pharmacie hors horaires, **When** une demande arrive, **Then** le message affiche les horaires et la prochaine ouverture.
6. **Given** un abus de la fonction urgence, **When** il est détecté, **Then** la fonction est limitée pour ce numéro.

#### User Story 25 - Autonomie progressive et fiabilité de l'assistant (Priority: P2)
Le pharmacien principal règle le niveau d'autonomie : N1 (tout validé), N2 (réponses simples seules : disponibilité, horaires, statut), N3 (devis seul au-dessus d'un seuil de confiance, produits non réglementés, contrôle par échantillonnage). Tableau de bord de fiabilité, retour arrière immédiat.

**Acceptance Scenarios**:
1. **Given** le niveau N1, **When** une demande arrive, **Then** aucune réponse métier n'est envoyée sans validation.
2. **Given** N3 et confiance ≥ seuil sur des produits libres, **When** la demande arrive, **Then** le devis est émis automatiquement avec mention du niveau.
3. **Given** N3 et un produit réglementé dans la demande, **When** elle arrive, **Then** elle bascule en validation humaine.
4. **Given** le tableau de fiabilité, **When** le titulaire consulte, **Then** il voit le taux de lectures acceptées sans correction, les erreurs par catégorie, l'historique par période.
5. **Given** un niveau supérieur, **When** le taux d'acceptation chute sous un seuil défini, **Then** une alerte propose de revenir au niveau inférieur.
6. **Given** un passage de niveau, **When** il est demandé, **Then** il exige une confirmation explicite du titulaire et est audité.
7. **Given** l'échantillonnage, **When** un contrôle est dû, **Then** le pharmacien reçoit des cas à revoir.

#### User Story 26 - Suivi de demande et messagerie patient (Priority: P1)
Le patient suit sa demande via un lien ou un code (sans compte) : statuts (reçue, en lecture, en traitement, devis envoyé, confirmée, prête, retirée, annulée) et échange de messages avec la pharmacie et l'assistant.

**Acceptance Scenarios**:
1. **Given** un code de suivi, **When** le patient l'ouvre, **Then** il voit le statut courant et l'historique.
2. **Given** un message du pharmacien, **When** il est envoyé, **Then** le patient est notifié par SMS/email selon ses coordonnées.
3. **Given** un lien de suivi, **When** il est partagé, **Then** il n'expose aucun document sans second facteur léger (code SMS) et expire après la clôture.

---

### Épopée H : Desktop et synchronisation

#### User Story 27 - Application desktop installable (Priority: P1)
Installateur Windows `Sanara-Setup.exe` : installation en quelques clics, raccourci, lancement au démarrage optionnel, connexion à la pharmacie, mises à jour automatiques en arrière-plan avec redémarrage planifié, désinstallation propre.

**Independent Test**: installer sur un PC vierge, se connecter, vendre.

**Acceptance Scenarios**:
1. **Given** un PC Windows 10/11, **When** on lance l'installateur, **Then** l'application s'installe sans droits administrateur et se lance.
2. **Given** une nouvelle version, **When** elle est publiée, **Then** le poste la télécharge et propose l'installation à un moment choisi.
3. **Given** une mise à jour échouée, **When** elle ne s'applique pas, **Then** l'ancienne version reste fonctionnelle.
4. **Given** le premier lancement, **When** l'utilisateur se connecte, **Then** le poste est enregistré et la base locale est initialisée.

#### User Story 28 - Mode hors-ligne et synchronisation (Priority: P1)
Les ventes, mouvements de stock et inventaires fonctionnent hors-ligne sur le poste et se synchronisent au retour du réseau. Numérotation sans collision, résolution des conflits, indicateur d'état de synchro, reprise sur coupure.

**Independent Test**: couper le réseau, vendre 20 articles sur deux postes, rétablir et vérifier la cohérence du stock.

**Acceptance Scenarios**:
1. **Given** le réseau coupé, **When** deux postes vendent le même produit, **Then** après synchro le stock est la somme correcte des deux ventes.
2. **Given** une modification de prix concurrente, **When** la synchro a lieu, **Then** la règle « serveur gagnant pour référentiels, cumul pour mouvements » s'applique et le conflit est journalisé.
3. **Given** une synchro interrompue, **When** elle reprend, **Then** aucune opération n'est dupliquée (idempotence).
4. **Given** un poste hors-ligne depuis plus de N jours, **When** il se reconnecte, **Then** une vérification d'intégrité est exécutée avant l'acceptation.
5. **Given** l'indicateur de synchro, **When** des opérations sont en attente, **Then** leur nombre est visible.

#### User Story 29 - Matériel d'officine (Priority: P2)
Imprimantes thermiques (ESC/POS), lecteur de codes-barres USB/Bluetooth, tiroir-caisse, afficheur client ; configuration guidée et test d'impression.

**Acceptance Scenarios**:
1. **Given** une imprimante 80 mm connectée, **When** on lance le test, **Then** un ticket de test s'imprime avec accents et logo.
2. **Given** un tiroir-caisse, **When** une vente espèces est validée, **Then** il s'ouvre.
3. **Given** un scanner configuré en clavier, **When** on scanne hors champ de recherche, **Then** le produit est ajouté à la vente en cours.

---

### Épopée I : Pilotage et finances

#### User Story 30 - Tableau de bord et rapports (Priority: P2)
Tableau de bord du jour (CA, marge, panier moyen, alertes), rapports : ventes par période/vendeur/famille, top produits, produits dormants, rotation, marge, valorisation du stock ; exports PDF/Excel ; rapports planifiés par email.

**Acceptance Scenarios**:
1. **Given** une période, **When** on génère le rapport des ventes, **Then** les totaux concordent avec les clôtures de caisse.
2. **Given** un rapport planifié, **When** l'heure arrive, **Then** il est envoyé aux destinataires.
3. **Given** un caissier, **When** il ouvre les rapports, **Then** il ne voit que ses propres ventes.

#### User Story 31 - Dépenses, finances et export comptable (Priority: P2)
Dépenses et charges, encaissements/décaissements, TVA collectée/déductible, journaux de caisse/banque, clôtures mensuelles et annuelles verrouillées, export comptable (format OHADA).

**Acceptance Scenarios**:
1. **Given** une clôture mensuelle, **When** elle est validée, **Then** les écritures de la période sont verrouillées.
2. **Given** une période verrouillée, **When** on tente de modifier une écriture, **Then** l'action est refusée.
3. **Given** un export, **When** il est généré, **Then** il respecte le plan comptable configuré.

---

### Épopée J : Super admin et transverse

#### User Story 32 - Gestion des pharmacies et abonnements (Priority: P2)
Le super admin liste les pharmacies, voit leur statut, suspend/réactive, gère les plans d'abonnement (essai, mensuel, annuel), émet les factures d'abonnement, suit les impayés ; un tenant suspendu garde un accès en lecture/export pendant un délai.

**Acceptance Scenarios**:
1. **Given** un abonnement impayé après le délai de grâce, **When** il expire, **Then** l'officine passe en lecture seule et le titulaire est notifié.
2. **Given** une suspension, **When** elle est décidée, **Then** la pharmacie disparaît du site public.
3. **Given** un essai de 30 jours, **When** il se termine, **Then** le titulaire est invité à choisir un plan.

#### User Story 33 - Supervision, statistiques globales, audit et modération (Priority: P2)
Statistiques globales (pharmacies actives, demandes, volumes, taux d'escalade), santé de la plateforme, journal d'audit consultable, signalements et avis modérés, gabarits d'emails et de SMS.

**Acceptance Scenarios**:
1. **Given** un signalement sur une pharmacie, **When** il est reçu, **Then** il apparaît dans la file de modération.
2. **Given** le journal d'audit, **When** le super admin filtre par pharmacie et action, **Then** les entrées sont affichées, non modifiables.

#### User Story 34 - Multi-pays et langues (Priority: P2, étendu en V3)
Configuration par pays : devise, taxes, formats, documents d'inscription exigés, listes réglementaires, langues (FR/EN), fuseaux horaires. Ajouter un pays ne demande aucun changement de code.

**Acceptance Scenarios**:
1. **Given** un pays configuré avec une autre devise, **When** une pharmacie y est créée, **Then** prix, tickets et rapports utilisent cette devise.
2. **Given** un changement de langue, **When** il est appliqué, **Then** toutes les interfaces et notifications suivent.

#### User Story 35 - Notifications, sauvegarde, export et consentements (Priority: P2)
Centre de notifications (in-app, email, SMS), préférences par utilisateur, sauvegarde et restauration, export complet des données de la pharmacie, journal des consentements patients, politique de conservation.

**Acceptance Scenarios**:
1. **Given** une alerte de péremption, **When** elle est émise, **Then** elle suit les canaux choisis par l'utilisateur.
2. **Given** une demande d'export complet, **When** le titulaire la lance, **Then** il reçoit une archive de ses données.
3. **Given** un poste desktop, **When** la sauvegarde locale est lancée, **Then** un fichier chiffré est créé et restaurable.

---

## Edge Cases

- Deux caissiers vendent le dernier exemplaire en même temps (hors-ligne puis synchro) : un stock négatif est signalé, jamais perdu silencieusement.
- Horloge du poste décalée : horodatage serveur de référence, décalage détecté et signalé.
- Ordonnance écrite à la main, abréviations locales, noms de marques différents de la DCI.
- Patient qui envoie la même ordonnance à plusieurs pharmacies : détecté et signalé au pharmacien.
- Ordonnance déjà délivrée ailleurs ou expirée.
- Coupure pendant l'impression ou pendant une synchronisation.
- Pharmacie qui change d'adresse ou de GPS : fiche publique à jour, ancienne position conservée en historique.
- Fausse demande d'inscription avec documents falsifiés : validation humaine, rejet motivé, blocage de récidive.
- Un pharmacien quitte l'officine : désactivation, transfert de ses dossiers en cours, conservation de l'audit.
- Fuseau et jours fériés pour horaires et gardes.
- Taille/format de fichiers (HEIC, photo floue, PDF protégé).
- Limites réglementaires locales sur la vente à distance : message de précaution configurable par pays.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Le système MUST permettre à un visiteur de soumettre une demande d'accès pharmacie avec pièces justificatives configurables par pays.
- **FR-002**: Le système MUST exiger la validation d'un super admin avant la création d'un tenant, et envoyer un lien d'activation à durée limitée.
- **FR-003**: Le système MUST isoler strictement les données par pharmacie.
- **FR-004**: Le système MUST gérer rôles et permissions fines avec audit de toute action sensible.
- **FR-005**: Le système MUST gérer le stock par lot avec sortie FEFO et alertes de seuil et de péremption.
- **FR-006**: Le système MUST permettre la vente, l'encaissement, l'impression et la clôture de caisse hors-ligne sur le desktop, avec synchronisation idempotente.
- **FR-007**: Le système MUST contrôler la délivrance des produits réglementés et tenir le registre associé.
- **FR-008**: Le système MUST publier des fiches de pharmacies recherchables par zone et par carte, avec distance, statut d'ouverture et de garde.
- **FR-009**: Le système MUST permettre au patient de déposer une ordonnance via l'assistant avec consentement explicite.
- **FR-010**: L'assistant MUST analyser le document et proposer une lecture avec niveau de confiance, sans jamais émettre de conseil médical.
- **FR-011**: Le système MUST faire valider chaque demande par un pharmacien tant que l'autonomie n'a pas été explicitement accordée, et ne JAMAIS automatiser les produits réglementés.
- **FR-012**: Le système MUST escalader les urgences vers titulaire puis adjoint avec relances et message de repli au patient.
- **FR-013**: Le système MUST générer devis, confirmation, code de retrait, et lier la commande retirée à une vente.
- **FR-014**: Le système MUST mémoriser les corrections du pharmacien pour mesurer et améliorer la fiabilité, avec consentement et anonymisation inter-pharmacies.
- **FR-015**: Le système MUST fournir un installateur desktop Windows avec mise à jour automatique.
- **FR-016**: Le système MUST proposer français et anglais et configurer devise, taxes et réglementation par pays.
- **FR-017**: Le système MUST permettre abonnements, suspension, réactivation et export des données par pharmacie.
- **FR-018**: Le système MUST offrir rapports, exports PDF/Excel et clôtures verrouillées.
- **FR-019**: Le système MUST limiter les abus (débit, taille de fichier, faux appels d'urgence).
- **FR-020**: Le système MUST laisser la livraison à la charge du patient en v1 ; paiement à la pharmacie en v1.

### Key Entities

- **Country / CountryConfig** : devise, taxes, langues, documents d'inscription, listes réglementaires.
- **Tenant (Pharmacy)** : identité, statut, GPS, horaires, gardes, visibilité publique, plan, niveau d'autonomie.
- **PharmacyApplication** : demande d'accès, documents, statut, motif de rejet, décideur.
- **User / Role / Permission / Device** : comptes, droits, postes desktop autorisés.
- **Product / ProductBarcode / PriceHistory / GenericGroup** : catalogue.
- **Lot / StockMovement / Inventory / InventoryLine / Quarantine / Destruction** : stock.
- **Sale / SaleLine / Payment / CashSession / Return / CreditNote** : ventes et caisse.
- **Customer / CreditAccount / Insurer / Coverage** : clients et tiers payant.
- **Supplier / PurchaseOrder / Receipt / SupplierInvoice / SupplierReturn** : achats.
- **Patient / Prescription / PrescriptionLine / ControlledRegister** : ordonnances en officine.
- **PatientRequest / RequestDocument / RequestLine / Quote / Pickup** : demandes via l'assistant.
- **AssistantRun / AssistantCorrection / AutonomyPolicy / ReliabilityStat** : traces et fiabilité de l'assistant.
- **PresenceSession / EscalationChain / EscalationAttempt** : présence et urgences.
- **Notification / MessageThread / Consent** : communication et consentements.
- **Subscription / Invoice(platform)** : abonnements.
- **AuditLog / SyncOperation / SyncConflict** : traçabilité et synchronisation.

## Success Criteria *(mandatory)*

- **SC-001**: Un pharmacien passe de la demande d'accès à sa première vente en moins d'1 jour ouvré (hors délai de validation), et en moins de 30 minutes de configuration.
- **SC-002**: 95 % des ventes au comptoir se font en moins de 60 secondes de la première ligne au ticket.
- **SC-003**: 0 vente perdue lors d'une coupure réseau de 8 h sur 2 postes (test de synchronisation).
- **SC-004**: Aucune fuite inter-tenant détectée par la suite de tests d'isolation.
- **SC-005**: 90 % des ordonnances lisibles proposent une lecture acceptée avec au plus une correction par le pharmacien (mesuré sur la pharmacie pilote).
- **SC-006**: Temps de réponse initial de l'assistant au patient < 5 s ; prise en charge d'une urgence escaladée mesurée et affichée au super admin.
- **SC-007**: 0 décision automatique sur produit réglementé dans les journaux de l'assistant.
- **SC-008**: Installation du desktop sur un PC vierge en moins de 5 minutes.
- **SC-009**: Page publique de recherche utilisable en moins de 3 s en 3G.
- **SC-010**: 100 % des actions sensibles présentes dans le journal d'audit.

## Assumptions

- Cameroun d'abord : XAF, FR/EN, Mobile Money (saisie de référence en v1, pas d'intégration de paiement).
- Livraison à la charge du patient ; paiement à la pharmacie en v1.
- Le desktop cible Windows 10/11 en v1 ; macOS/Linux plus tard.
- L'hébergement serveur est un VPS ou cloud, pas Camoo.
- Les exigences réglementaires locales (vente à distance, registres, conservation) seront validées avec un pharmacien pilote et l'ordre des pharmaciens avant le MVP 2.
- La base d'interactions médicamenteuses n'est pas incluse (licence externe) ; seules les allergies saisies sont contrôlées.
