# Recherche et favoris

[← Index du guide utilisateur](README.md)

## Recherche Neox

Dans le champ de recherche en haut du dashboard, sélectionnez **Neox**, saisissez un titre, un domaine ou une partie d’adresse, puis validez. La recherche trouve les raccourcis correspondants et peut aussi faire apparaître un espace dont le nom ou la description correspond.

Le raccourci clavier **Ctrl + K** (ou **⌘ + K** sur Mac) place le curseur dans le champ. Le champ peut aussi être utilisé depuis les Réglages avec **Rechercher un lien**.

### Filtrer par date d’ouverture

Ajoutez un filtre temporel à la requête. Les alias courts sont :

| Filtre | Signification | Exemple |
| --- | --- | --- |
| `@d:n` | Ouvert au cours des *n* derniers jours | `mozilla @d:1` |
| `@h:n` | Ouvert au cours des *n* dernières heures | `@h:23` |
| `@w:n` | Ouvert au cours des *n* dernières semaines | `@w:2` |
| `@m:n` | Ouvert au cours des *n* derniers mois | `@m:1` |
| `@y:n` | Ouvert au cours des *n* dernières années | `@y:1` |
| `@min:n` | Ouvert au cours des *n* dernières minutes | `@min:30` |

Les formes longues sont également reconnues, par exemple `@days:1`, `@hours:23` et `@weeks:2`. Combinez un terme et un filtre, par exemple `neonatis @d:7`. Le filtre porte sur les ouvertures enregistrées depuis Neox ; les liens jamais ouverts via le dashboard ne remontent pas avec un filtre temporel.

## Recherche Google

Sélectionnez **Google** dans le sélecteur du champ, écrivez votre requête et validez. Les résultats s’ouvrent dans un nouvel onglet. Revenez à **Neox** pour chercher dans vos espaces.

## Favoris

L’étoile d’un espace ou d’un lien l’ajoute à la page **Favoris**. Cette page distingue les espaces favoris des liens favoris et indique l’espace où se trouve chaque lien. Cliquez sur un espace pour l’ouvrir dans la zone principale ; cliquez sur un lien pour ouvrir le site.

Suite : [Réglages](reglages.md) · [Démarrage](demarrage.md)
