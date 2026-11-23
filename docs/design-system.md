# Design Sanara : référence visuelle

**Source de vérité** : la maquette « Sanara Parcours complet hors ligne » (fichier HTML fourni par le propriétaire). Le web et le desktop utilisent **exactement** ce design : mêmes tokens, mêmes composants, même logo. Aucune variante graphique pour le desktop.

Les tokens vivent dans [packages/ui/src/tokens.css](../packages/ui/src/tokens.css), le logo dans [packages/ui/src/Logo.tsx](../packages/ui/src/Logo.tsx). Toute interface importe `@sanara/ui/tokens.css`. Aucune couleur n'est écrite en dur ailleurs.

## Identité

| Élément  | Valeur                                                                                                                                                                                                                                              |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Logo     | Quatre pétales : haut `#0b5d3b`, droite `#14804f`, bas `#3fae7a`, gauche `#e8a33d` ; mot-symbole « sanara » en minuscules, Bricolage Grotesque 800, interlettrage −0,04 em. Version claire sur fond vert : `#fff`, `#bfe5d0`, `#7fcaa3`, `#e8a33d`. |
| Titres   | Bricolage Grotesque 800 (titres de page 28 à 42 px), 700 pour les sous-titres, 500 en secours                                                                                                                                                       |
| Texte    | Instrument Sans 400, 500, 600, 700                                                                                                                                                                                                                  |
| Accroche | « Votre pharmacie, même quand le réseau coupe. »                                                                                                                                                                                                    |

## Couleurs

| Rôle                                        | Token                             | Valeur                |
| ------------------------------------------- | --------------------------------- | --------------------- |
| Primaire (boutons, logo, panneau de marque) | `--sa-green-900`                  | `#0b5d3b`             |
| Succès, étapes faites, interrupteurs        | `--sa-green-700`                  | `#14804f`             |
| Fond de sélection, badges succès            | `--sa-green-100`                  | `#e8f4ed`             |
| Texte clair sur fond vert                   | `--sa-green-50`                   | `#d3eadc`             |
| Accent : étape en cours, compteurs          | `--sa-amber`                      | `#e8a33d`             |
| Alerte douce                                | `--sa-amber-bg` / `--sa-amber-fg` | `#fdf1dc` / `#8a5a0a` |
| Danger                                      | `--sa-red` / `--sa-red-bg`        | `#a63a22` / `#fbe6e0` |
| Texte / console super admin                 | `--sa-ink`                        | `#14201a`             |
| Texte secondaire                            | `--sa-muted`                      | `#5b6b62`             |
| Bordures                                    | `--sa-line`                       | `#dfe8e2`             |
| Fond d'application et champs                | `--sa-bg`                         | `#f5f8f6`             |

La **console super admin** est reconnaissable : barre latérale `#14201a` avec pastille « ADMIN » ambre. L'**espace pharmacie** a une barre latérale blanche, entrée active en vert clair. Le **site public** est blanc avec panneaux verts.

## Formes et composants

- Rayons : champs 10 px, boutons 12 px, cartes 14 px, fenêtres modales 20 px, pastilles 99 px.
- Champ : fond `--sa-bg`, étiquette en capitales 11 px interlettrée, contour vert 2 px au focus.
- Bouton principal : vert, texte blanc, 15 px, gras. Secondaire : contour 1,5 px `--sa-line`. Danger : rouge. Avertissement : ambre.
- Assistant en étapes : barre de progression à quatre segments (fait vert, en cours ambre, à faire gris).
- Interrupteur : 42 × 24 px, vert quand actif.
- Tableaux : en-tête sur fond `--sa-bg`, capitales 11 px, séparateurs `--sa-line-2`.
- Badges de statut : vert (ok), ambre (attente), rouge (urgent ou impayé), gris (neutre).

## Écrans de la maquette et user stories

| Écran    | Contenu                                                                                                                                            | User story                |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------- |
| A1 à A3  | Formulaire d'accès en 4 étapes : Officine, Localisation (carte, repère glissable, « utiliser ma position »), Documents, Responsable, récapitulatif | US1                       |
| A4       | Demande envoyée (n° `PH-2026-0087`) et suivi avec complément demandé                                                                               | US1, US2                  |
| A5       | E-mail d'activation (lien valable 48 h)                                                                                                            | US2                       |
| A6       | Choix du mot de passe, puis vérification du numéro par SMS à 6 chiffres                                                                            | US3, US5                  |
| A7       | Connexion avec onglets « E-mail » et « Code PIN caisse »                                                                                           | US5, US10                 |
| A8 à A11 | Assistant de configuration : Horaires et garde, Équipe, Catalogue et stock, Visibilité et assistant                                                | US3, US4, US6, US19, US25 |
| A12      | Tableau de bord du premier jour (liste d'actions de démarrage)                                                                                     | US3                       |
| B0       | Connexion super admin avec double vérification                                                                                                     | US5, US33                 |
| B1, B2   | Liste des demandes ; trois décisions : valider, demander un complément, rejeter                                                                    | US2                       |
| B3, B4   | Pharmacies ; fiche, suspension                                                                                                                     | US32                      |
| B5, B6   | Formules d'abonnement ; facturation                                                                                                                | US32                      |
| B7       | Signalements et modération                                                                                                                         | US33                      |
| B8       | Modèles d'e-mails et de messages (FR/EN, variables, aperçu)                                                                                        | US33                      |
| B9, B10  | Journal d'audit ; paramètres globaux                                                                                                               | US33                      |
| B11      | Pays et zones                                                                                                                                      | US34                      |
| C1       | Produits : catalogue et fiche médicament                                                                                                           | US6, US7                  |
| C2       | Patients et dossier (allergies, alerte à la délivrance, historique)                                                                                | US17                      |
| C3       | Crédit clients et tiers payant                                                                                                                     | US13, US14                |
| C4       | Finance : journal de caisse et clôture                                                                                                             | US11, US31                |
| C5       | Rapports et analyses                                                                                                                               | US30                      |
| C6       | Paramètres de l'officine : pays, langue, devise, paiements, registres                                                                              | US34, US3                 |
| P9       | Site public : pharmacies de garde, choix du pays et de la langue                                                                                   | US19, US34                |

Les écrans P1 à P8, de la caisse, du stock et des achats figurent dans la maquette « Écrans » (section en fin de document).

## Menu de l'espace pharmacie

Tableau de bord · Demandes patients · Caisse · Produits · Stock · Achats · Patients · Crédit et tiers payant · Finance · Rapports · Assistant · Équipe et droits · Paramètres.

## Menu de la console super admin

Tableau de bord · Demandes d'accès · Pharmacies · Abonnements · Facturation · Signalements · Modèles d'e-mails · Journal d'audit · Pays et zones · Paramètres.

## Précisions fonctionnelles tirées de la maquette

Ces points complètent la spécification et sont intégrés à [spec.md](../specs/001-sanara-platform/spec.md) :

1. **Demande d'accès** : champs supplémentaires (date d'agrément, nombre de postes de caisse, mode de gestion actuel, participation aux gardes, dépôt de médicaments en livraison) ; numéro de demande `PH-AAAA-NNNN`.
2. **Troisième décision du super admin** : « Demander un complément ». Il choisit les documents concernés et écrit un message ; le demandeur renvoie depuis son lien de suivi. Le rejet propose des motifs prédéfinis et l'option « autoriser une nouvelle demande ».
3. **Validation** : le super admin choisit la formule (essai 30 jours par défaut) et le nombre de postes autorisés.
4. **Activation** : mot de passe d'au moins 10 caractères avec majuscule, chiffre et caractère spécial, puis **code SMS à 6 chiffres** ; le code est redemandé sur chaque nouveau poste (appareils de confiance).
5. **Connexion caissier** par code PIN en plus de l'e-mail.
6. **Super admin** : double vérification obligatoire par application d'authentification.
7. **Assistant de démarrage de l'assistant IA** : niveau « Guidé » (équivalent de N1) par défaut.
8. **Formules** : Essai (gratuit, 30 jours, 1 poste, 3 utilisateurs, 30 lectures d'ordonnance), Mensuel, Annuel ; prix à définir par le propriétaire.
9. **Site public** : sélecteur de pays et de langue ; gardes filtrables « Ce soir », « Cette semaine », « Ce week-end » ; boutons Appeler et Itinéraire.
10. **Pays** : statuts Actif, Préparation, Fermé ; chaque pays définit devise, indicatif, niveaux de zones, autorité de référence, documents exigés, moyens de paiement, règles de conservation.

## Maquette « Sanara Écrans » (reçue le 2026-11-24)

Elle complète la maquette Parcours. Mêmes tokens ; aucune couleur nouvelle.

| Écran        | Contenu                                                                                                                                                                                                                                       | User story |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| 00           | Identité : logo (quatre feuilles à 90°, arcs de rayon 47, vide central de 10 u), icônes 128/84/48/24 px (monochrome blanc à 24 px et moins), zone de protection x, tailles mini (logo 120 px, symbole 16 px)                                  | transverse |
| P1           | Accueil public : ville, pays·région, département, « Ma position », 3 étapes Trouvez, Envoyez, Retirez, appel aux pharmaciens                                                                                                                  | US19       |
| P2           | Recherche : liste + carte, filtres Ouvert maintenant, De garde, Assurances, Plus proche                                                                                                                                                       | US19       |
| P3           | Fiche pharmacie : photo, badges (ouverte, de garde, vérifiée), services, horaires, contact, équipe de garde                                                                                                                                   | US19       |
| P4           | Assistant patient : dépôt PDF/photo, Retrait en officine ou Je gère ma livraison, états de présence, escalade 0 min notification, 3 min SMS, 6 min appel de l'adjoint ; illisible : nouvelle photo ; symptômes graves : message fixe urgences | US21, US22 |
| P5, P8       | Suivi SAN-2411 et validation du devis (Valider, Retirer des produits, Refuser) ; coulisse en 4 temps                                                                                                                                          | US23       |
| P6           | Demande d'accès, étape 3 : documents (agrément, diplôme, carte de l'Ordre, pièce d'identité), 5 Mo max                                                                                                                                        | US1        |
| P7           | Code de retrait sur le téléphone du patient (ex. K7429, QR code), valable le jour même, validé après paiement                                                                                                                                 | US24       |
| S1, S2       | Super admin : tableau de bord, file de validation, examen de la demande, e-mail généré (lien 48 h)                                                                                                                                            | US2        |
| H1 à H3, H3b | Tableau de bord pharmacien, file des demandes, lecture proposée avec confiance, composition du devis depuis le stock                                                                                                                          | US22, US23 |
| H4           | Caisse : F2 attente, F4 remise, F6 retour, F8 client, F10 clôture ; règlements espèces, MTN MoMo, Orange Money, carte, crédit, tiers payant                                                                                                   | US7, US11  |
| H5, H8       | Stock et péremptions (FEFO), journal des mouvements                                                                                                                                                                                           | US9        |
| H6           | Assistant : niveaux N1 Guidé, N2 Supervisé, N3 Autonome partiel ; règles du niveau 2 ; performance 30 jours                                                                                                                                   | US25       |
| H7           | Création d'un compte caissier, droits, PIN à 4 chiffres choisi par le caissier                                                                                                                                                                | US10       |
| H9           | Achats : suggestions, réception avec écart et reliquat                                                                                                                                                                                        | US12       |
| K1           | Vue caissier : retrait par code ou QR (C1 reste « Produits »)                                                                                                                                                                                 | US24       |
| R1, R2       | Reçu thermique 80 mm et facture A4                                                                                                                                                                                                            | US11       |
| D1, D2       | Desktop : caisse hors ligne, installation, activation, synchronisation, conflit de stock                                                                                                                                                      | US27, US28 |

### Règles fonctionnelles nouvelles

1. **Réservation** : le stock est réservé à la validation du devis par le patient ; sans réponse sous **24 h**, la réservation est libérée (mouvement « Libération »).
2. **Sortie de stock** à la validation du paiement en caisse, pas avant.
3. **Cycle du stock** : achat, disponible, réservé, sortie, alerte, commande.
4. **Retrait par code** : code court + QR, valable le jour même, validé seulement après paiement ; le caissier peut le valider selon ses droits.
5. **Caissier** : lien d'activation par SMS ou e-mail, PIN à 4 chiffres choisi par lui ; remise plafonnée (5 % dans l'exemple).
6. **Desktop hors ligne** : MoMo et demandes patients exigent une connexion ; un conflit de stock (vente du même lot sur deux postes) crée une alerte à corriger.
7. **Documents imprimés** : reçu 80 mm numéroté, facture `F-AAAA-NNNNNN` avec vérificateur, cachet et signature.
8. **Données d'exemple de la maquette** (Pharmacie du Marché, Awa Bello, etc.) : fictives, à ne jamais reprendre comme données réelles.

## Atlas des écrans par rôle (reçu le 2026-11-24, 57 écrans)

L'atlas regroupe tous les écrans par rôle ; il fait foi pour la répartition des droits et remplace les libellés précédents en cas de divergence (le retrait par code est **K1**, `C1` est « Produits »).

| Rôle                                                | Écrans                                                        |
| --------------------------------------------------- | ------------------------------------------------------------- |
| Marque (1)                                          | 00 identité                                                   |
| Visiteur et patient (8)                             | P1, P9, P2, P3, P4, P8, P5, P7                                |
| Pharmacien fondateur, inscription et démarrage (13) | A1, A2, P6 (documents, étape 3), A3, A4, A5, A6, A7, A8 à A12 |
| Pharmacien titulaire (7)                            | H1, H6, C3, C4, C5, H7, C6                                    |
| Pharmacien adjoint (5)                              | H2, H3, H3b, C1, C2                                           |
| Magasinier (3)                                      | H5, H8, H9                                                    |
| Caissier (6)                                        | H4, K1, R1, R2, D1, D2                                        |
| Super admin (14)                                    | B0, B1, S1, S2, B2 à B11                                      |

Précisions nouvelles : menu super admin avec « Pays et zones » ; signalements d'abus (fausse urgence répétée, limitation d'envoi d'urgences par numéro) ; paramètres globaux (lien 48 h, 2FA obligatoire, limite 5 demandes par patient et par jour, libération 24 h, mode maintenance, version desktop) ; convention de tiers payant avec part assureur (ex. 80 %) ; clôture avec écart et Z de caisse ; prévision de la demande dans les rapports ; journal d'audit non modifiable avec IP ; allergie du patient comparée à l'ordonnance (alerte à la délivrance).
