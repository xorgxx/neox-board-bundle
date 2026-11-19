# Traitement asynchrone des favicons

Les favicons sont ajoutés à la file Doctrine Messenger `neox_board_favicon`. Le worker télécharge l’image, la valide, produit une miniature WebP de 25 × 25 pixels avec GD, puis stocke son contenu encodé dans la colonne `neox_board_link.favicon_data`. Les SVG assainis restent des SVG avec une taille de 25 × 25.

## Mise en service

1. Installer l’extension PHP GD avec la prise en charge WebP (`ext-gd`).
2. Exécuter les migrations Doctrine afin d’ajouter `favicon_data`.
3. Démarrer un consommateur dans l’application hôte :

   ```sh
   php bin/console messenger:consume neox_board_favicon --time-limit=3600 --memory-limit=128M
   ```

Le bundle configure un transport Doctrine avec création automatique de sa table de file. En production, lancer le consommateur sous Supervisor, systemd ou comme service de worker Docker afin qu’il redémarre automatiquement.

Les icônes sont servies avec un ETag et `Cache-Control: private, max-age=31536000, immutable`. L’URL inclut l’empreinte de l’image : si elle change, le navigateur demande la nouvelle miniature.
