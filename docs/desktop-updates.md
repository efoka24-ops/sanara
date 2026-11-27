# Mises à jour du poste Sanara (T128)

Le poste vérifie au démarrage puis toutes les 6 heures s'il existe une nouvelle version, la télécharge
en arrière-plan et l'installe :

- quand l'utilisateur clique « Redémarrer pour passer à la version … » dans la barre d'état, **seulement
  si aucune caisse n'est ouverte** sur le poste ;
- sinon à la fermeture de Sanara.

Les ventes en attente d'envoi restent dans la base locale : une mise à jour ne les touche pas.

## Conditions

- **HTTPS obligatoire.** Les mises à jour sont désactivées tant que l'adresse du serveur du poste
  (`serverUrl` dans `config.json` du dossier de données) n'est pas en `https://`. Aujourd'hui la
  préproduction est en HTTP : les postes ne cherchent donc aucune mise à jour.
- **Signature.** Sans certificat de signature de code (T130), Windows affiche un avertissement à
  l'installation. La mise à jour automatique fonctionne quand même, mais rien ne garantit l'origine
  du paquet au-delà du HTTPS : à corriger avant la production.

## Publier une version

1. Augmenter `version` dans `apps/desktop/package.json` (ex. `0.2.0`).
2. Construire : `corepack pnpm --filter @sanara/desktop dist`.
3. Déposer dans le dossier `/desktop-releases/` du serveur (FTP) les fichiers de `apps/desktop/release/` :
   - `Sanara-Setup-0.2.0.exe`
   - `Sanara-Setup-0.2.0.exe.blockmap`
   - `latest.yml` **en dernier** (c'est lui qui annonce la version aux postes).
4. Vérifier `https://<serveur>/desktop-releases/latest.yml` dans un navigateur.

## Revenir à la version précédente

Les postes acceptent une version plus ancienne (`allowDowngrade`). Pour revenir en arrière :

1. Garder les fichiers de la version précédente (`.exe`, `.blockmap`, `latest.yml`).
2. Remettre son `latest.yml` dans `/desktop-releases/`.
3. Les postes la téléchargent à leur prochaine vérification et l'installent comme une mise à jour.

Si la base locale a changé de schéma entre les deux versions, ne pas revenir en arrière sans avoir vérifié
que l'ancienne version sait lire la nouvelle base : préférer publier une version corrective.
