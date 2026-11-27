# Contrôle d'installation sur un PC Windows vierge (T131, T132)

À exécuter par une personne sur une machine virtuelle ou un PC sans Sanara. Rien n'est coché d'avance.

Construire l'installateur : `corepack pnpm --filter @sanara/desktop run dist` (résultat dans `apps/desktop/release/`).

## Installation

- [ ] L'installateur n'est pas signé (T130) : Windows affiche un avertissement ; noter son texte exact.
- [ ] L'installation se fait sans demander de droits d'administrateur.
- [ ] Le bandeau vert Sanara et l'icône s'affichent.
- [ ] Le dossier proposé est dans le profil de l'utilisateur et peut être changé.
- [ ] Case « Lancer Sanara au démarrage de Windows » décochée : rien au redémarrage ; cochée : Sanara se lance.
- [ ] Raccourcis présents sur le bureau et dans le menu Démarrer.

## Premier lancement

- [ ] Démarrage en moins de 5 secondes sur un PC avec 4 Go de mémoire (T132) : temps mesuré :
- [ ] Écran « Connectez ce poste » ; activation avec un compte de la pharmacie et un nom de poste.
- [ ] Vente en ligne, coupure du réseau, vente hors ligne, reprise et envoi.
- [ ] Ticket imprimé sur l'imprimante thermique (T204).

## Désinstallation

- [ ] L'application et les raccourcis disparaissent ; l'entrée de démarrage aussi.
- [ ] Les données du poste (`%APPDATA%\Sanara`) sont conservées ; une réinstallation les retrouve.
