# Sources techniques consultées — 29 octobre 2026

Les versions installées sont celles des lockfiles, vérifiées auprès des registres npm et Packagist. Les documentations de la version installée prévalent sur les exemples anciens.

| Composant | Version verrouillée | Source |
| --- | --- | --- |
| Node projet | 22.23.4 | Paquet npm node, runtime isolé au dépôt ; machine inchangée (22.20.0) |
| Astro | 7.4.4 | https://docs.astro.build/en/reference/configuration-reference/ |
| Astro React / Node | 7.1.2 / 11.1.9 | https://docs.astro.build/en/guides/integrations-guide/react/ |
| Next.js | 16.4.3 | https://nextjs.org/docs/app/getting-started/installation et documentation livrée node_modules/next/dist/docs |
| React / React DOM | 19.3.1 | Métadonnées npm et peer dependencies des frameworks |
| TypeScript | 5.9.3 | Version stable compatible avec les deux applications ; tsc et astro check exécutés |
| Laravel | 13.39.0 | https://laravel.com/docs/13.x/releases |
| Sanctum / Fortify | 4.3.3 / 1.41.0 | https://laravel.com/docs/13.x/sanctum et https://laravel.com/docs/13.x/fortify |
| PHP / PHPUnit | 8.3.16 / 12.5.42 | Exécutables locaux et composer.lock |
| PostgreSQL | 17 | Exécutable local dans C:/Program Files/PostgreSQL/17/bin ; cluster propre au projet |
| openapi-typescript | 7.13.0 | Générateur de schema.ts depuis docs/openapi.json |
| Young Serif / Hanken Grotesk | @fontsource 5.3.0 | Paquets locaux, aucune requête Google Fonts à l’exécution |

Next.js a créé son AGENTS.md lors du démarrage. Les guides fournis sur layouts, internationalisation, headers, proxy et CSP ont été consultés ; les paramètres et headers sont asynchrones dans cette version.

CSP Astro activée dans le build, avec empreinte du script de préférence ajoutée via Astro.csp. Documentation officielle : https://docs.astro.build/en/reference/api-reference/#csp. Ce mécanisme n’est pas activé par Astro en mode développement : contrôle HTTP complémentaire du build requis.

Les sources juridiques, comptables et Monetbil du prompt devront être reconsultées au moment de leurs modules, avant implémentation et validation réelles. Aucune règle fiscale ou intégration Monetbil n’est déclarée livrée par cette première étape.

## Incrément catalogue/médias/accords

Documentation officielle Laravel 13 consultée pour les transactions/verrous, la validation des fichiers et le stockage : https://laravel.com/docs/13.x/database ; https://laravel.com/docs/13.x/validation ; https://laravel.com/docs/13.x/filesystem. Adaptateur verrouillé league/flysystem-aws-s3-v3 3.35.3 et AWS SDK PHP 3.402.3 ; versions confirmées dans composer.lock. Aucun accès à un service AWS réel. GD/EXIF/fileinfo vérifiés sur le PHP local et déclarés comme dépendances de plateforme.

Guides Next installés server/client components et data security relus pour les interfaces de gestion. Les scénarios de fichiers et filtres sont testés sur PostgreSQL et SQLite, sans les présenter comme une validation du stockage distant ou des interactions navigateur.


## Stock, transactions et pièces privées

Laravel 13 : [transactions/verrous](https://laravel.com/framework/docs/13.x/database) et [stockage privé](https://laravel.com/framework/docs/13.x/filesystem). Sources installées du gestionnaire d’exceptions relues : callback de report false interrompt le rapport SQL par défaut ; vérifié dans PrivateDatabaseLogTest.

PostgreSQL 17 : [verrous explicites](https://www.postgresql.org/docs/17/explicit-locking.html), [statistiques d’activité](https://www.postgresql.org/docs/17/monitoring-stats.html). Le test invalide le snapshot des statistiques avant observation des attentes de verrou.

ClamAV : [manuel officiel clamscan/codes de retour](https://github.com/Cisco-Talos/clamav/blob/main/docs/man/clamscan.1.in), [signatures](https://docs.clamav.net/manual/Usage/SignatureManagement.html). Code 0 traité clean, 1 infected, erreur/absence pending. Aucune installation/analyse réelle ClamAV revendiquée, aucun envoi à un antivirus externe.


## Snapshots et traitements internes

PostgreSQL 17 : [SET TRANSACTION](https://www.postgresql.org/docs/17/sql-set-transaction.html), modes REPEATABLE READ et READ ONLY consultés pour les rapports ; comportement vérifié avec deux connexions distinctes sur kora_test. [Laravel 13 Query Builder](https://laravel.com/framework/docs/13.x/queries) pour verrous, sous-requêtes et transactions. Les noms/expressions SQL sont fixés au serveur et les valeurs utilisateur liées en paramètres.

Le consommateur actuel ne fait que des écritures DB locales atomiques et ne suppose pas qu'un transport distant offre exactement une remise. Tout futur handler de paiement/envoi devra vérifier son contrat prestataire et sa déduplication propres.
