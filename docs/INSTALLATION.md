# Installation locale reproductible

Vérifié sous Windows PowerShell, 29 octobre 2026. Racine : le dossier Site Web.

Prérequis : PHP 8.3 avec PDO PostgreSQL/SQLite, mbstring, OpenSSL, curl, DOM/XML, fileinfo, EXIF et GD compilé avec JPEG/PNG/WebP ; Composer 2.8 ; npm 11 ; Node 22.23.4 pour exécuter les applications ; PostgreSQL 17. Les fichiers package-lock.json et services/api/composer.lock sont conservés.

Sur cette machine, Node système est 22.20.0. Le paquet de développement node installe 22.23.4 dans node_modules ; npm run utilise ce runtime local. Aucun changement global de Node ni de stratégie PowerShell n’est nécessaire. Utiliser npm.cmd sous PowerShell (npm.ps1 est bloqué par la politique locale).

```powershell
npm.cmd ci
Set-Location services/api
composer install --no-interaction
Set-Location ../..
npm.cmd run db:start
npm.cmd run api:setup
```

Le cache Composer peut être placé dans le dépôt sans secret : COMPOSER_CACHE_DIR=<racine>/.cache/composer. Ne pas copier de configuration sensible dans les commandes ou journaux.

db:start initialise au besoin .local/postgres, écoute exclusivement 127.0.0.1:55432, crée kora et kora_test. Il ne se connecte pas au service PostgreSQL existant sur un autre port. Définir PG_BIN si les binaires PostgreSQL sont ailleurs. Les droits de lancement du processus peuvent être nécessaires dans un bac à sable Windows. Le mode trust ne sert qu’à ce cluster local sans données réelles ; il est interdit pour une base distante/production. Aucun effacement automatique de données n’est effectué.

api:setup copie .env.example seulement si .env est absent, génère une clé locale puis applique les migrations. Il conserve un .env existant. MAIL_MAILER=array empêche les envois et évite d’inscrire des liens de récupération dans des logs. Aucun secret de service réel n’est nécessaire à cette étape.

Dans trois terminaux depuis la racine :

```powershell
npm.cmd run api:serve
npm.cmd run dev:storefront
npm.cmd run dev:operations
```

Adresses : boutique http://localhost:4321/fr et /en ; gestion http://localhost:3000/fr et /en ; API http://localhost:8000/api/v1/health. Garder localhost pour les trois surfaces (éviter de mélanger avec 127.0.0.1 pour les cookies). Les exemples .env des applications décrivent les URLs personnalisables.

La base créée est volontairement vide, sans partenaire, offre, avis ou mot de passe commercial inventé. Créer le premier propriétaire dans un terminal interactif :

```powershell
Set-Location services/api
php artisan kora:create-admin
```

Le mot de passe choisi est masqué et n’est pas passé en argument. Ouvrir la gestion, configurer le TOTP, conserver les codes de récupération puis se reconnecter avec le second facteur. Aucun compte administrateur de démonstration partagé.

Vérifications depuis Site Web :

```powershell
npm.cmd run check
npm.cmd run build
npm.cmd test
npm.cmd run api:contract-check
npm.cmd run api:test
npm.cmd run api:test:pgsql
npm.cmd run test:http
```

test:http exige les trois serveurs lancés et un catalogue de développement vide pour les assertions d’états vides. Ce sont des contrôles HTTP/SSR, sans exécution de JavaScript dans un navigateur. api:test:pgsql ne peut utiliser que kora_test sur 127.0.0.1:55432 ; le garde-fou dans Tests/TestCase refuse une autre base. Ne jamais pointer des tests sur une base commerciale.

Les vérifications des builds ont aussi été exécutées sur boutique :4322 et gestion :3001. Pour les reproduire, démarrer ces builds avec ces ports, définir KORA_STOREFRONT_URL et KORA_OPERATIONS_URL, puis lancer test:http et test:csp. test:csp attend par défaut les builds sur ces deux ports, et vérifie les hashes/nonces sans exécuter les interactions d'un navigateur. Pour un usage authentifié sur d'autres ports, ajouter explicitement leurs origines à la configuration Laravel.

api:generate régénère les types après une modification d’OpenAPI. inventory.py vérifie les copies des références et ne réinitialise pas une matrice existante.

Pour arrêter les serveurs, Ctrl+C dans leurs terminaux ; puis npm.cmd run db:stop. Les données restent dans .local/. Ne pas supprimer le cluster pour une simple reprise.

Builds locaux de production : boutique via node apps/storefront/dist/server/entry.mjs (HOST/PORT configurables) ; opérations via npm.cmd exec -w @kora/operations -- next start --hostname localhost --port 3000. Configurer API_INTERNAL_URL et les origines/cookies adéquats. Aucun déploiement réel n’est effectué ici.

## Catalogue et médias

Voir UTILISATION_CATALOGUE.md pour la saisie/publication. Les liens de gestion acceptent #suppliers, #categories, #products, #offers, #media, #agreements ou #content après connexion autorisée et MFA. NEXT_PUBLIC_STOREFRONT_URL configure le lien de la gestion vers la boutique ; le définir avant le build Next.

Exécuter les suites PHP SQLite et PostgreSQL séquentiellement : Storage::fake utilise un répertoire de test partagé. npm test vérifie aussi le HTML serveur des formulaires/filtres et les conversions de saisie ; ce test ne remplace pas les interactions navigateur.

KORA_MEDIA_DISK=local est la configuration de développement. Les fichiers normalisés sont sous services/api/storage/app/private/catalogue, jamais dans les répertoires publics Astro/Next. Ne pas créer de lien public vers ce répertoire. Limites d’entrée : 2 Mo, 4 096 px par côté, 8 millions de pixels ; sortie WebP jusqu’à 1 600 px, orientation corrigée, métadonnées supprimées. Régler les limites PHP/reverse proxy pour accepter le formulaire multipart tout en conservant ces limites applicatives.

Pour S3, l’adaptateur Composer est installé. Configurer côté serveur KORA_MEDIA_DISK=s3, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION, AWS_BUCKET et, pour un service compatible, AWS_ENDPOINT/AWS_USE_PATH_STYLE_ENDPOINT. Garder le bucket privé ; aucun secret AWS dans les variables publiques des frontends. L’API sert les objets sous contrôle d’accès, y compris les médias explicitement publiés. Chaque ligne mémorise son disque ; changer le disque ne migre pas les objets existants.

Aucun bucket distant consulté ni testé. Avant exploitation, vérifier les droits compte/bucket, upload/lecture/retrait, erreurs réseau, sauvegarde/restauration et conservation. Les documents privés ont leur propre registre, distinct de celui des images.


## Pièces privées et stock/achats

Dix migrations appliquées au cluster local. Après récupération des sources, php artisan migrate depuis services/api avec la base appropriée ; pas de migrate:fresh sur la base consultée.

KORA_DOCUMENT_DISK=local conserve les pièces sous storage/app/private/documents. S3 reste serveur/bucket privé ; modifier le disque ne migre pas les fichiers existants.

CLAMSCAN_BINARY doit désigner un exécutable clamscan réel. Installer/configurer moteur et signatures à jour avant usage documentaire réel. Ici ce paramètre est vide : import pending, interdit comme contrat/facture/justificatif accepté. Scanner contrôlé uniquement dans les tests, jamais une configuration de production.

- php artisan inventory:expire traite les réservations expirées sans effet doublé.
- php artisan schedule:run lance les tâches dues ; tâche système à installer/superviser en exploitation.
- api:test et api:test:pgsql successifs : disque Storage::fake partagé. PostgreSQL de test exclusivement kora_test et sans DB_URL alternatif.

Usage API : UTILISATION_APPROVISIONNEMENT.md. OpenAPI précise corps, montants, pièces, permissions et clés d’idempotence. Écrans stock/achats encore à construire.


## Mise à jour des rappels

Une dixième migration, 2026_10_29_000007_create_internal_payable_alerts, ajoute alertes, reçus et notifications ; appliquer php artisan migrate, sans réinitialisation de la base consultée.

Vérifier ensuite php artisan schedule:list ; lancer manuellement php artisan payables:scan-alerts puis php artisan outbox:process --limit=100. Aucun rappel ne sera mis en file sans politique configurée par le propriétaire. Ne pas utiliser des coordonnées de production pour des tests ; le handler actuel ne contacte aucun canal externe.

Le prévisionnel est sous payables/forecast et les notifications internes sous notifications. Configuration, droits et limites : INCREMENT_RAPPELS_FOURNISSEURS.md. Ces nouvelles fonctions sont des API ; leurs écrans restent à construire.

## Promotions de livraison

La migration `2026_10_31_000017_create_delivery_offers` est appliquée à la base locale `kora` (vérifié le 4 novembre 2026). Sur une nouvelle installation, exécuter `php artisan migrate --no-interaction` depuis `services/api`, sans réinitialiser la base de développement.

Configurer les campagnes via l'API de gestion avec les zones, couverture et budget réels. Aucune campagne n'est créée par le démarrage ou les migrations. `payments:reconcile`, déjà planifié, libère aussi les réservations de promotion expirées. Détails et limites : INCREMENT_PROMOTIONS_LIVRAISON.md. Les suites SQLite et PostgreSQL doivent toujours être exécutées successivement.

## Confirmations fournisseur liées aux commandes

Migration `2026_11_04_000018_link_order_supply` appliquée à la base locale `kora` le 4 novembre, sans réinitialisation. Sur une nouvelle installation, exécuter `php artisan migrate --no-interaction` depuis `services/api`.

`php artisan orders:expire-supply --limit=100` traite les confirmations échues et ouvre les choix client ; elle apparaît chaque minute dans `schedule:list`. Le service de planification système reste à installer et superviser en exploitation. Aucun contact fournisseur ni versement n'est envoyé par cette commande. Droits, flux de remboursement et limites : INCREMENT_CONFIRMATIONS_COMMANDES.md.

## Affectations achats et réceptions

Migration `2026_11_04_000019_allocate_order_purchases` appliquée à `kora` sans réinitialisation. Elle conserve les réservations actives déjà liées aux commandes payées, puis crée les affectations d'achats et de réceptions. Pour une nouvelle installation : `php artisan migrate --no-interaction` depuis `services/api`.

`inventory:expire` exclut désormais les réservations retenues pour des commandes payées ; la présence d'un ancien timestamp dans la base ne doit pas être utilisée pour les libérer. Le DTO expose `expires_at: null` et le lien de commande. Aucune nouvelle clé prestataire, tâche externe ou dépendance requise. Usage et limites : INCREMENT_AFFECTATIONS_COMMANDES.md.

## Profils et devis professionnels

Migration `2026_11_04_000020_create_professional_quotes` appliquée à `kora` le 4 novembre, sans réinitialisation. Pour une nouvelle installation, exécuter `php artisan migrate --no-interaction` depuis `services/api`.

`php artisan professional:expire-quotes --limit=100` expire les propositions échues ; tâche constatée chaque minute dans `schedule:list`. Elle ne réserve aucun stock et ne contacte personne. Le planificateur système reste à installer/superviser. Les permissions `professional.read` et `professional.manage` sont distinctes des rôles owner/buyer/viewer du profil client. Aucun profil d'entreprise ni tarif commercial n'est prérempli. Flux, sécurité et limites : INCREMENT_DEVIS_PROFESSIONNELS.md.

## Carte et registre de fidélité

Migration `2026_11_04_000021_create_loyalty_ledger` appliquée à `kora` le 4 novembre sans réinitialisation : programme désactivé sans barème, comptes/cartes, révisions, gains, mouvements et attribution des retours, plus instantané de règles et date de livraison sur les commandes. Sur une nouvelle installation, appliquer avec `php artisan migrate --no-interaction` depuis `services/api`. Une carte se crée explicitement par son titulaire ; les migrations ne créent aucun client ni points fictifs.

`php artisan loyalty:reconcile --limit=100` valide les délais, expire les points et traite uniquement les commandes payées avec livraison enregistrée. La tâche doit apparaître chaque minute dans `schedule:list`. Le workflow de remise vérifiée n'est pas encore livré : aucun endpoint de fidélité n'écrit `delivered_at`, et aucun paiement seul ne génère de gain de livraison. Ne pas renseigner manuellement ce champ pour simuler une livraison réelle.

`loyalty.settings` et `loyalty.adjust` exigent MFA et confirmation récente ; seul le propriétaire possède initialement ces droits. Support, gestion et comptabilité disposent de `loyalty.read`. Le budget est en points engagés, distinct du futur budget monétaire des avantages. La clé d'application Laravel protège la copie chiffrée du jeton QR ; conserver cette clé avec les sauvegardes existantes, sans la publier. Règles, retours, routes et limites : INCREMENT_FIDELITE.md.

## Points au paiement

Migration `2026_11_04_000022_create_loyalty_redemptions` appliquée à `kora` le 4 novembre, sans réinitialisation. Sur une nouvelle installation, utiliser `php artisan migrate --no-interaction` depuis `services/api`. La politique d'échange démarre désactivée, règles nulles, budget consommé nul ; aucun devis, point ou client fictif créé.

Configurer les valeurs réelles avec `loyalty.settings`, MFA et confirmation récente. La lecture des budgets XAF utilise `loyalty.budget.read` (propriétaire et comptable), distincte de la consultation support. Une autorisation de perte demande `pricing.loss_override` et un motif ; le simple droit de consulter un compte ne permet pas de financer une remise.

`loyalty:reconcile --limit=100`, constatée chaque minute dans `schedule:list`, libère aussi les points réservés échus. Le planificateur système reste à installer/superviser. Aucun nouveau prestataire, secret ou paquet requis. Protocole des devis, valeurs nettes de retour et limites : INCREMENT_CHECKOUT_POINTS.md.

## Comptabilité : premier socle

La migration `2026_11_04_000023_create_accounting_ledger` ajoute les tables comptables et leurs protections SQL. Appliquée le 4 novembre à la base locale `kora` après vérification de la cible, sans réinitialisation : zéro livre, compte, exercice, schéma, source ou écriture créé. Sur une nouvelle installation, exécuter `php artisan migrate --no-interaction` depuis `services/api`. Aucun mouvement fictif n’est créé par migration.

Le propriétaire configure explicitement livre, plan, journaux, exercices/périodes et schémas. Le comptable examine la version et l’empreinte du plan et des schémas avant comptabilisation réelle ; une réimportation du plan exige de nouveaux schémas. Le propriétaire seul ne possède pas le droit d’examen comptable. Le livre réel exige l’identité exploitant et le régime fiscal renseignés. Les pièces manuelles ont le type privé `accounting_evidence`, sans fournisseur, avec les droits comptables et le contrôle antivirus existant.

Aucune nouvelle tâche automatique ni prestation externe n’est activée. Les sources sont capturées et préparées explicitement par l’API, puis validées. Les clôtures ne certifient pas l’exhaustivité du recensement métier. Accès, séquence complète et limites : INCREMENT_COMPTABILITE.md.

## Capture des sources comptables

Migration `2026_11_04_000024_create_accounting_capture` appliquée le 4 novembre à la base locale `kora`, cible vérifiée, sans réinitialisation. Livres, sources, écritures, reçus et tentatives restent à zéro. Aucun livre de capture activé. Le job exécuté localement a retourné tous les compteurs à zéro, sans événement financier fictif.

`accounting:sync-sources --limit=100` est présent chaque minute dans `schedule:list`. Il ne traite que les livres activés explicitement par le propriétaire avec date de début et motif. Option `--book=<UUID>` pour un livre précis ; limite comprise entre 1 et 1 000. Ne pas confondre la présence de la tâche Laravel et la supervision du planificateur système, qui reste à installer.

La capture crée des sources et leurs reçus, jamais des écritures validées. Les révisions du plan et des schémas, les pièces et les étapes de préparation/validation restent obligatoires. Réception des événements, reprise historique, indicateurs et anomalies : INCREMENT_CAPTURE_COMPTABLE.md. Le rollback de la migration conserve l’auteur nullable des sources système existantes : il ne leur attribue pas un créateur humain fictif.

## Rapprochements de trésorerie

Migration additive `2026_11_04_000025_create_treasury_reconciliations` appliquée à la base locale `kora`, cible vérifiée, sans réinitialisation. Correspondances, relevés, lignes et groupes restent à zéro. Aucun compte/solde fictif créé ni rapprochement activé. Sur une autre installation : `php artisan migrate --no-interaction` depuis `services/api`.

Aucune dépendance, tâche planifiée ou connexion externe supplémentaire. Configuration propriétaire et examen comptable avant import réel, puis preuve privée `accounting_evidence` et étapes manuelles. Routes, contraintes et limites dans INCREMENT_RAPPROCHEMENTS_TRESORERIE.md. Le rollback supprime les seules tables de cet incrément ; ne pas l’utiliser pour corriger un rapprochement existant.

## Reports de trésorerie et clôtures historiques

Migration additive `2026_11_04_000026_create_treasury_outstanding` appliquée à la base locale `kora`, cible vérifiée, sans réinitialisation. Comptabilité, trésorerie, décisions et preuves de clôture restent à zéro. La migration renforce aussi le garde-fou PostgreSQL commun aux historiques sans argument. Sur une autre installation : `php artisan migrate --no-interaction` depuis `services/api`. Aucun nouveau paquet, job ou raccordement externe.

Les reports exigent une preuve privée propre et un examen comptable, avec auteur distinct de l’approbateur en réel. Le rollback laisse en place la correction de la fonction SQL commune ; il ne constitue pas une correction de report métier. Protocole, nouveaux droits et limites : INCREMENT_REPORTS_TRESORERIE.md.

## Couverture de trésorerie et ouvertures rapprochées

Migration additive `2026_11_04_000027_create_treasury_coverage` appliquée à la base locale `kora`, cible contrôlée, sans réinitialisation. Livres, écritures, trésorerie et politiques restent à zéro. Aucun paquet, compte, solde ou raccordement externe ajouté ; aucune obligation activée par la migration. Sur une autre installation : `php artisan migrate --no-interaction` depuis `services/api`.

Le propriétaire prépare la couverture avec un relevé d'ancrage et une pièce comptable propre ; le comptable examine l'empreinte et l'ouverture exacte, avec auteur distinct en réel. Les clôtures concernées doivent être rouvertes avant activation/révision. La date de début reste fixe après approbation, aucune désactivation via cette API. Le rollback n'est pas une correction métier et détruirait la configuration de cet incrément ; utiliser la procédure de réouverture/révision décrite dans INCREMENT_COUVERTURE_TRESORERIE.md.

## Lettrage comptable partiel et total

Migration additive `2026_11_04_000028_create_accounting_lettering` appliquée à la base locale `kora`, cible vérifiée, sans réinitialisation. Livres, écritures, trésorerie et lettrages restent à zéro. Aucun compte ni opération fictive ajouté. Sur une autre installation : `php artisan migrate --no-interaction` depuis `services/api`. Aucun paquet ou prestataire supplémentaire.

Les fonctions PostgreSQL utilisent CREATE OR REPLACE pour supporter les reconstructions de la base de test ; les membres restent immuables et les capacités sont protégées. Le rollback détruirait les groupes de cet incrément : il ne constitue pas une correction métier. Utiliser l’annulation datée puis un nouveau lettrage explicite. Droits, dates et limites : INCREMENT_LETTRAGE_COMPTABLE.md.

## Budgets de dépenses et règlements documentés

Migration additive `2026_11_04_000029_create_expense_plans` appliquée le 5 novembre à la base locale `kora`, cible vérifiée, sans réinitialisation. Livres, écritures, trésorerie, budgets et règlements de dépenses restent à zéro. Aucun paquet ni prestataire supplémentaire. Sur une autre installation : `php artisan migrate --no-interaction` depuis `services/api`.

Configurer et examiner explicitement plan, calendrier et correspondances de trésorerie avant usage réel. Aucune taxe ou donnée commerciale par défaut. Le rollback détruirait les historiques de cet incrément ; utiliser les annulations et contrepassations liées pour les corrections métier. Droits, pièces et limites : INCREMENT_DEPENSES_ENGAGEMENTS.md.

## Prévisionnel consolidé de trésorerie

Aucune migration ou dépendance supplémentaire pour cet incrément. Le contrôle local en lecture seule confirme la base kora vide de données métier, dernière migration 000029. Le cluster PostgreSQL existant a été redémarré et sa reprise automatique terminée avant les tests ; aucune réinitialisation de kora. Les reconstructions de test restent réservées à kora_test.

Consulter le prévisionnel via le livre réel explicitement créé et configuré ; aucun livre ou solde n’est initialisé automatiquement. Un livre de démonstration exclut les sources métier globales. Droits, dates, montants inconnus et limites : INCREMENT_PREVISIONNEL_TRESORERIE.md.

## Retraits manuels

Migration additive 2026_11_05_000030_create_withdrawals appliquée à la seule base locale kora après vérification de la cible PostgreSQL 127.0.0.1:55432 ; aucune réinitialisation. Livres, écritures, trésorerie, dépenses, retraits, reçus, fournisseurs, commandes et remboursements restent vides. Aucun nouveau paquet ni configuration prestataire. Les clés autoréférentes sont ajoutées après création des tables sur PostgreSQL.

Configurer explicitement les comptes/correspondances et leurs examens avant tout usage réel ; aucune demande de retrait ou somme initiale créée automatiquement. Les tests reconstruisent uniquement kora_test. Voir INCREMENT_RETRAITS_MANUELS.md pour les preuves, droits et limites.

## Constats datés des soldes externes

Migration additive 2026_11_05_000031_create_balance_observations appliquée à la seule base locale kora après contrôle PostgreSQL 127.0.0.1:55432 ; aucune réinitialisation. Les comptes, livres, écritures, dépenses, retraits, constats et autres données financières contrôlées restent vides. Aucun paquet ni configuration prestataire ajouté. Les reconstructions de test restent réservées à kora_test.

Configurer explicitement les comptes et correspondances examinées, puis enregistrer les justificatifs réellement obtenus. Choisir explicitement observation et expiration ; aucune somme ni durée par défaut créée. Le résumé est une preuve datée examinée, sans connexion bancaire/Monetbil ou certification du solde actuel. Voir INCREMENT_CONSTATS_SOLDES.md.

## Financements monétaires

Migration additive 2026_11_05_000032_create_financings appliquée à la seule base locale kora après contrôle de cible PostgreSQL 127.0.0.1:55432 ; aucune réinitialisation. Les données financières contrôlées, dont accords/mouvements de financement, restent vides. Aucun paquet, compte, taux, somme initiale ou configuration prestataire ajouté. Les reconstructions de test restent réservées à kora_test.

Configurer explicitement un compte de contrepartie de dette/capitaux propres et une trésorerie sur actif avec entrées au débit, puis faire examiner plan et correspondances avant usage réel. Les versements/remboursements utilisent un journal existant de principal seul, sans second posting. Le scope du prévisionnel de démonstration devient book_commitments_only avec le contrat 0.29.0. Voir INCREMENT_FINANCEMENTS.md.
