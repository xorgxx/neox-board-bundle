# Traitement asynchrone des favicons

Les favicons sont ajoutés au transport Messenger `async` de l’application hôte. Le worker télécharge l’image, la valide, produit une miniature WebP de 25 × 25 pixels avec GD, puis stocke son contenu encodé dans la colonne `neox_board_link.favicon_data`. Les SVG assainis restent des SVG avec une taille de 25 × 25.

## Mise en service

1. Installer l’extension PHP GD avec la prise en charge WebP (`ext-gd`).
2. Exécuter les migrations Doctrine afin d’ajouter `favicon_data`.
3. Vérifier que `FetchFavicon` est routé vers le transport `async` (le bundle le configure) et que le consommateur `async` de l’application est lancé :

   ```sh
   php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
   ```

En production, le consommateur `async` doit tourner sous Supervisor, systemd ou comme service de worker Docker afin qu’il redémarre automatiquement.

Pour les domaines internes et les domaines locaux en `.wip`, le worker doit être
connecté au réseau du reverse proxy et disposer de `NEOX_BOARD_INTERNAL_PROXY_HOST`
(par exemple `caddy`). Le worker résout alors les adresses privées, conserve la
résolution pendant le téléchargement de la page et de sa favicon, et ne suit que
les redirections qui restent sur le même hôte.

Pour lancer ou relancer les favicons de tous les liens déjà présents :

```sh
php bin/console neox-board:favicons:enqueue-missing
```

Les icônes sont servies avec un ETag et `Cache-Control: private, max-age=31536000, immutable`. L’URL inclut l’empreinte de l’image : si elle change, le navigateur demande la nouvelle miniature.
