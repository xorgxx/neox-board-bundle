# Installation et intégration

Neox Board vise exclusivement **PHP 8.4** et **Symfony 8**. L’adaptateur Doctrine utilise ORM 3 et DoctrineBundle 3.

```sh
composer require xorgxx/neox-board-bundle
php bin/console assets:install
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

Activer le bundle avec Symfony Flex ou dans `config/bundles.php`. Le bundle importe ses routes d’attributs sous `/neox-board` et publie ses templates sous `@NeoxBoard`. La protection CSRF de Symfony doit être activée. Les routes exigent un utilisateur authentifié ; l’identité stockée est le `getUserIdentifier()` Symfony, sans association à une classe User concrète.

```yaml
# config/packages/security.yaml
security:
  access_control:
    - { path: ^/neox-board, roles: ROLE_USER }
```

Chaque propriétaire obtient automatiquement son tableau à la première visite. Toute sélection d’espace/lien vérifie le même propriétaire et renvoie 404 si l’objet est hors périmètre. Une installation multi-tenant doit fournir une extension de résolution de propriétaire avant usage partagé : le modèle livré est actuellement centré sur `UserInterface::getUserIdentifier()`.

Les assets distribués sont `dashboard.css`, `neox-board.css` (Tailwind compilé) et `neox-board.js` (Turbo 8, Stimulus et Preline). Dans le dépôt du bundle, reconstruisez-les avec `npm ci && npm run build`; le projet hôte n’a besoin ni de Node ni d’une chaîne de compilation. Lancez `php bin/console assets:install` puis surchargez les templates Twig `@NeoxBoard/dashboard/*`. Les formulaires CRUD s’ouvrent dans une modale native via Turbo Frames, et les mutations répondent en Turbo Streams.

Les raccourcis tentent de récupérer `/favicon.ico` à la création et lors d’un changement d’URL. La requête est limitée à HTTP(S), sans redirection, protégée contre les adresses IP privées/réservées, limitée en durée et en taille, et n’accepte que des formats d’image raster. Une icône valide est enregistrée sous `var/neox-board/favicons` et servie par une route qui vérifie l’accès au lien. L’échec de récupération n’empêche pas l’enregistrement du raccourci. Après la mise à jour du bundle, appliquez la migration de l’application hôte pour ajouter `favicon_filename`.

La prévision utilise l’API Open-Meteo pour aujourd’hui et les quatre jours suivants, puis met le résultat en cache 30 minutes dans `var/neox-board`. Lyon est la localisation par défaut. Configurez `neox_board.weather.latitude`, `longitude`, `timezone` et `location` dans l’application hôte pour une autre ville, ou désactivez avec `enabled: false`. Le dashboard affiche un lien d’attribution Open-Meteo. La licence des données est CC BY 4.0; les conditions du point d’accès gratuit limitent son usage aux projets non commerciaux. Pour un usage commercial, utilisez le point d’accès payant : configurez `api_url: https://customer-api.open-meteo.com/v1/forecast` et `api_key: '%env(OPEN_METEO_API_KEY)%'`, puis définissez le secret `OPEN_METEO_API_KEY` dans l’application hôte. Ne commitez jamais sa valeur.

Sous la date, le calendrier affiche le saint du jour, les jours fériés et les vacances scolaires lorsqu’ils s’appliquent. Par défaut, les vacances utilisent Lyon (zone A); configurez `neox_board.calendar.city`, `school_zone` ou désactivez ce bloc avec `enabled: false`. Les résultats sont mis en cache six heures sous `var/neox-board`. Les liens de source sont affichés à côté des données : [Nominis](https://nominis.cef.fr/), le calendrier des jours fériés et le calendrier scolaire du Ministère de l’Éducation nationale.

Chaque utilisateur peut remplacer la localisation météo par défaut depuis **Réglages → Localisation météo**. Le nom de ville est résolu par l’API de géocodage Open-Meteo et la sélection (coordonnées, fuseau et nom) est enregistrée sur son tableau. Les grandes villes françaises reconnues utilisent aussi leur zone scolaire; pour les autres lieux, les jours fériés nationaux restent disponibles mais aucun calendrier scolaire local n’est supposé. La colonne `neox_board.weather_location` est ajoutée par migration, sans altérer automatiquement le schéma au chargement.

## Messagerie et temps réel

Mercure peut diffuser un Turbo Stream `refresh` aux autres clients du même tableau après chaque mutation réussie. Activez le publisher avec les secrets fournis par l’application hôte :

```yaml
neox_board:
  realtime:
    mercure_enabled: true
    mercure_hub_url: '%env(resolve:NEOX_BOARD_MERCURE_URL)%'
    mercure_public_url: '%env(resolve:NEOX_BOARD_MERCURE_PUBLIC_URL)%'
    mercure_jwt_secret: '%env(NEOX_BOARD_MERCURE_JWT_SECRET)%'
```

`NEOX_BOARD_MERCURE_URL` est l’URL complète du endpoint de publication interne du hub ; `NEOX_BOARD_MERCURE_PUBLIC_URL` est son endpoint accessible au navigateur. Le JWT doit utiliser le même secret HS256 que le hub. Les jetons de publication restent côté serveur ; un jeton de lecture limité au topic UUID du tableau est généré pour le flux navigateur. Sans configuration, le publisher reste inactif. La publication est synchrone après le flush Doctrine ; pour garantir la livraison après panne, l’hôte doit utiliser une outbox.

Messenger/AMQP reste optionnel et n’est pas requis pour ces mises à jour. Aucun secret JWT ni URL de hub ne doit être committé.

## Recherche et date d’ouverture

La recherche accepte un texte libre ou un domaine, avec un filtre temporel optionnel : `neonatis.example @days:1`, `@hours:23` ou `@weeks:2`. Les alias sont `@d` (jours), `@h` (heures), `@w` (semaines), `@m` (mois), `@y` (années) et `@min` (minutes), par exemple `@d:1`, `@h:23`, `@w:2` ou `@m:1`. Les filtres portent sur la dernière ouverture réelle d’un lien. Une ouverture via le dashboard passe par une route appartenant au propriétaire, enregistre `last_opened_at`, puis redirige vers l’URL du lien. Les liens jamais ouverts ne correspondent pas à un filtre de date.

Après mise à jour du bundle, générez et appliquez la migration Doctrine dans l’application hôte (`make:migration`, puis `doctrine:migrations:migrate`). Le bundle ne modifie jamais le schéma automatiquement.

## Ordre des liens et sections

Les liens et les séparateurs de section partagent un ordre d’insertion dans chaque espace. Leur déplacement au glisser-déposer sauvegarde l’ordre côté serveur ; déposer un lien sur un séparateur le place au début de cette section. Le déplacement d’un séparateur vers un autre espace emporte son groupe de liens jusqu’au séparateur suivant. Une URL externe (par exemple glissée depuis la barre d’adresse) peut être déposée sur une carte. Un aperçu affiche son titre et son URL ; le serveur vérifie le format et les doublons avant d’autoriser l’ajout. Une URL déjà présente dans l’espace est refusée, y compris si son hôte ou sa barre finale diffère seulement par la casse ou un slash final. Les déplacements au clavier sont possibles avec `Alt` + flèche haut/bas. Les nouvelles entrées sont ajoutées à la fin.


### Fond personnalisé

Dans **Réglages → Arrière-plan du dashboard**, chaque propriétaire peut choisir une couleur ou téléverser une image JPEG, PNG ou WebP (5 Mo maximum). Les fichiers sont conservés dans `var/neox-board/backgrounds`, hors du répertoire public, avec un nom généré et une route qui vérifie le propriétaire. Les SVG et les URL d’image distantes ne sont pas acceptés. Une migration Doctrine ajoute `neox_board.background_settings`; exécutez les migrations de l’application hôte avant d’utiliser cette option.

Les espaces sont répartis dans deux colonnes persistantes indépendantes (`neox_board_space.column_index`). La migration `Version20261110220000` initialise la colonne à partir de l’ordre existant; `Version20261110221000` aligne son type sur le mapping Doctrine. Le déplacement d’un espace entre colonnes enregistre sa nouvelle colonne et son ordre local.
