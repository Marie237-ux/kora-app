# API KORA

Laravel 13 / PHP 8.3 / PostgreSQL. Le seul rendu public de ce service est l'API ; les interfaces sont dans apps/storefront (Astro) et apps/operations (Next.js).

Lire ../../docs/INSTALLATION.md et ../../docs/AVANCEMENT.md. Contrat du sous-ensemble implémenté : ../../docs/openapi.json.

Aucune identité commerciale ni clé réelle fournie. Les migrations ne créent pas de compte à mot de passe connu. Utiliser php artisan kora:create-admin dans un terminal interactif puis configurer le second facteur.

Tests : depuis la racine Site Web, npm.cmd run api:test ou npm.cmd run api:test:pgsql. Le second exige le cluster isolé et la base kora_test ; il refuse une autre destination. Aucun test ne doit viser une base commerciale.

Les anciens scripts frontend du squelette Laravel ont été retirés. Le démarrage canonique et la création du .env sont gérés par les scripts racine, qui conservent les clés existantes.
