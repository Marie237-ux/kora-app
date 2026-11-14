# Recherche texte FR/EN — backend

La recherche du catalogue utilise un index SQL de tokens normalisés, construit à partir des données publiées : titres et descriptions FR/EN, SKU, boutique, catégories héritées et attributs connus, dont les choix traduits et unités. Les accents et la casse sont neutralisés. Tous les mots demandés doivent correspondre à une même offre.

Les synonymes sont administrables sous les droits catalogue et MFA, versionnés et désactivables sans réindexation. Chaque groupe contient deux à seize mots, deux cents groupes au maximum ; l’expansion n’est pas récursive. Les suggestions proposent au maximum huit offres existantes et publiques.

La recherche accepte huit mots utiles au maximum. Les préfixes commencent à trois caractères. En absence de correspondance exacte/préfixe/synonyme, la tolérance utilise une distance d’édition de un (deux à partir de huit caractères), sur au maximum deux mille mots candidats et douze termes retenus. Cette borne peut écarter des corrections sur un vocabulaire important ; les mesures de performance et de pertinence à grande échelle restent ouvertes.

Les filtres typés, catégorie descendante, boutique, tri et pagination existants continuent de s’appliquer. Les garde-fous de publication sont relus dans la requête finale : un index périmé ne rend pas achetable une offre archivée.

## Indexation et données minimisées

Une publication/archivage catalogue actualise l’index local dans la même transaction. Aucun appel IA ni réseau n’intervient. La commande `php artisan search:reindex-text` répare/reconstruit l’index offre par offre sous verrou, sans effacer globalement l’index des pages clientes. Les changements de catégorie rafraîchissent actuellement tous les documents ; cette stratégie doit être mesurée avant de gros volumes.

Les recherches sans résultat sont seulement agrégées par jour Douala/langue/empreinte HMAC et nombre de mots. Aucun texte brut, IP, session, identifiant client ou URL externe n’est conservé. `search:cleanup-misses` retire les agrégats de plus de KORA_SEARCH_MISS_DAYS (30 jours par défaut). Les tableaux d’agrégats sont privés sous catalogue.read.

## Vérifications

Sept tests fonctionnels couvrent bilinguisme, accents, description/catégorie/boutique/SKU, faute, préfixe, suggestions, synonymes et conflits de version, filtres combinés, agrégats minimisés, purge, reconstruction, publication atomique, archivage et attributs traduits. Les suites complètes SQLite/PostgreSQL et leurs compteurs courants figurent dans VALIDATION.md et preuves/.

Les marques structurées, le réglage fin de pertinence, l’interface d’administration des synonymes et les parcours navigateur restent ouverts. La recherche photo, les embeddings et les assistants IA restent des exigences de lancement ; aucun prestataire n’est simulé comme fonctionnel.
