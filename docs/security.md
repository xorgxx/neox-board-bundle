# Sécurité et données

- Les routes de gestion doivent refuser l’accès anonyme par défaut ; l’hôte peut configurer le rôle requis.
- Chaque lecture et écriture doit vérifier l’accès au board et à son propriétaire/tenant, pas seulement l’authentification.
- Les mutations utilisent POST/PATCH/DELETE selon le contrat retenu, avec CSRF pour les formulaires navigateur.
- Valider les URL, titres, identifiants et tailles d’import côté serveur ; échapper les sorties dans Twig.
- Ne jamais supposer une classe User particulière : l’intégration du propriétaire doit être configurable via un contrat ou un adaptateur.
- Les topics Mercure ne doivent divulguer que les changements accessibles à leurs abonnés.
- Les handlers Messenger doivent être idempotents et ne traiter que des messages validés.
- Si une récupération distante d’icône est ajoutée, appliquer une politique anti-SSRF robuste, sans accès aux réseaux privés/réservés ni redirections non contrôlées.
