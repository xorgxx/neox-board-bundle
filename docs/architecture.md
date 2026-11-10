# Architecture et périmètre

## Produit

Le dashboard est principalement un organisateur visuel de liens vers des sites et outils. L’interface est exposée comme composant Twig réutilisable dans une application Symfony, et ses données sont fournies par des services du bundle.

## Modèle fonctionnel proposé

- **Board** : tableau appartenant à un utilisateur ou à un tenant.
- **Space** : groupe ordonné de liens, par exemple Développement ou Documentation.
- **Link** : URL, titre, icône facultative, ordre et état favori.
- **Activity** : journal optionnel des changements pertinents.

Les widgets restent une extension optionnelle ; ils ne sont pas le cœur du produit.

## Couches

1. Composant Twig et templates surchargeables.
2. Services d’application pour les cas d’usage CRUD et le réordonnancement.
3. Contrats de stockage afin de ne pas imposer les entités User de l’hôte.
4. Adaptateur Doctrine optionnel pour l’installation classique.
5. Adaptateurs Messenger/RabbitMQ et Mercure optionnels pour l’asynchronisme et les mises à jour partagées.

## Frontend

Tailwind et Preline habillent l’interface ; Turbo améliore les interactions serveur. Les assets devront être distribuables sans imposer une chaîne de compilation particulière au projet hôte. Le choix final entre AssetMapper et un build frontend dédié sera documenté avant l’implémentation des assets.
