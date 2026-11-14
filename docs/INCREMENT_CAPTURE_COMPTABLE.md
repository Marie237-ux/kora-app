# Capture comptable des événements métier

Suite du socle décrit dans INCREMENT_COMPTABILITE.md, le 4 novembre 2026. Capture automatique configurable des sept familles déjà admises, reprise des faits antérieurs et suivi des anomalies. Aucune écriture n’est préparée ou validée automatiquement.

## Activation et périmètre

Chaque livre commence avec capture désactivée, date de début nulle et version 1. Le propriétaire configure une date explicite avec MFA, confirmation récente du mot de passe, version attendue et motif. Le début du périmètre peut ensuite seulement être déplacé vers le passé. Une pause arrête les captures mais conserve les obligations de contrôle à la clôture.

Les livres de démonstration et réel conservent des reçus distincts. Un traitement système ne prend pas l’identité d’un utilisateur : `created_by` est nul et la source conserve `capture_event_id`. Une capture manuelle existante est réutilisée sans réécrire son auteur, sa pièce ou son empreinte.

Le périmètre couvre les sept familles de faits, sans prétendre couvrir les domaines encore absents :

| Source | Événement |
| --- | --- |
| Facture client émise | `CustomerInvoiceIssued` |
| Avoir client émis | `CustomerCreditNoteIssued` |
| Encaissement client confirmé | `CustomerPaymentConfirmed` |
| Remboursement client vérifié payé | `CustomerRefundPaid` |
| Dette fournisseur reconnue | `SupplierObligationRecognized` |
| Paiement fournisseur vérifié | `SupplierPaymentVerified` |
| Réception physique enregistrée | `GoodsReceived` |

Les quatre notifications client sont publiées dans la transaction de confirmation/émission/vérification, une seule fois par ressource. Les trois notifications fournisseurs existaient déjà. Le contenu de l’événement sert à retrouver la ressource ; montants, statut, pièces et dates sont relus dans les tables métier vérifiées, jamais acceptés directement depuis le payload.

## Consommateur et reprise historique

`accounting:sync-sources --limit=100` est planifiée chaque minute, sans chevauchement. Option `--book=<UUID>` pour un livre précis ; limite comprise entre 1 et 1 000, borne globale des captures et des événements historiques ajoutés par exécution. Aucun appel réseau, encaissement, remboursement, envoi de message ou prestation externe.

Le job recherche aussi les faits dans les tables métier et insère les notifications historiques manquantes par clé unique type/ressource. Cela récupère les faits antérieurs à cet incrément et les notifications absentes sans créer de facture, paiement, dette ou mouvement physique fictif. Une exécution bornée peut nécessiter plusieurs passages ; le contrôle de couverture reste visible entre les passages.

Les reçus sont propres au couple livre/événement. Le consommateur comptable ne modifie ni `processed_at`, ni tentatives, ni état du traitement générique de l’outbox : un autre consommateur doit pouvoir traiter le même événement indépendamment. L’état `unsupported` du panneau générique signifie toujours « aucun handler générique », et ne décrit pas l’avancement du consommateur comptable ; consulter les reçus du livre pour celui-ci.

La capture et le reçu final s’exécutent sous le verrou du livre, partagé avec capture manuelle, écritures, paramétrage et clôtures. Les écritures du sous-traitement sont annulées par savepoint si la capture échoue ; le reçu et sa tentative conservent alors l’échec. Deux workers ou une capture manuelle concurrente ne créent pas deux sources pour le même livre/type/ressource.

## États et anomalies

| État | Comportement |
| --- | --- |
| `captured` | Source figée liée au reçu, à préparer puis valider explicitement dans le journal |
| `excluded` | Motif visible : avant la date de début, document de démonstration dans livre réel, paiement de test, réception consignée ou sans valeur achetée disponible |
| `review_required` | Payload invalide, ressource absente/non admissible ou empreinte changée : aucune correction financière implicite |
| `retry_pending` | Échec local transitoire ; reprise avec délai croissant |
| `failed` | Cinq échecs consécutifs ; reprise explicite requise |

Les tentatives sont conservées après reprise. Le propriétaire peut demander une nouvelle tentative avec un motif et une confirmation récente. Un reçu déjà capturé n’est pas rejoué par cette route. Les exclusions « avant début » sont réexaminées automatiquement si le périmètre est étendu vers le passé. Un changement matériel de fait ou une anomalie ne réécrit jamais la source déjà figée ; investigation et traitement explicite restent nécessaires.

Les diagnostics exposent des codes stables et des identifiants nécessaires, sans payload brut ni exception SQL/donnée sensible. La résolution complète des événements invalides ou de faits modifiés nécessite encore les procédures métier de correction correspondantes ; cet incrément propose une reprise contrôlée, pas une route permettant de masquer librement une dette ou un encaissement.

## Couverture et clôture

`GET books/{book}/coverage?from=YYYY-MM-DD&to=YYYY-MM-DD` recense directement les sept familles, y compris les faits sans événement outbox. Un candidat n’est pas nécessairement une source admissible. Par type : candidats, capturés, exclus avec motif et candidats non traités. Les incidents de capture et les événements dont la date est inconnue restent des compteurs distincts ; ils peuvent concerner une source déjà capturée.

Les requêtes utilisent le snapshot de lecture existant. Le calendrier refuse la clôture du périmètre configuré si des candidats restent non traités ou si des captures sont en anomalie, y compris quand le worker a été mis en pause. Après capture, les sources connues non comptabilisées et les brouillons restent eux aussi bloquants selon les règles du premier socle.

Balance et grand livre indiquent désormais la couverture de capture du périmètre configuré. Ce contrôle n’est ni un rapprochement bancaire/Monetbil, ni une preuve d’exhaustivité des domaines non encore intégrés, ni une certification. Un événement métier tardif peut être découvert après une clôture : sa capture ne rouvre pas la période, ne modifie pas une écriture passée et ne déplace pas automatiquement sa date ; la comptabilisation exige une réouverture autorisée ou un décalage motivé vers une période ouverte.

Les émissions comptables restent soumises au plan/schéma courant examinés et aux pièces contrôlées avant usage réel. Aucun traitement automatique des frais prestataire, avances, coût des ventes, réintégrations de stock, amortissements, fidélité ou parrainage n’est inventé par ce recensement.

## API et installation

Préfixe `/api/v1/operations/accounting/books/{book}` :

- `GET/PUT capture-settings` : politique versionnée, modification réservée à `accounting.settings`.
- `GET capture-events`, filtre `status`, et `GET capture-events/{id}/attempts` : reçus et historique paginés, `accounting.read`.
- `POST capture-events/{id}/retry` : reprise motivée, `accounting.settings`, confirmation récente et idempotence.
- `GET coverage` : diagnostic sur une plage, `accounting.read`.

Session et MFA requis ; aucune modification de politique ni lecture de reçu d’un autre livre via un identifiant enfant. OpenAPI 0.20.0, 289 chemins au total ; types générés dans le client API existant.

La migration `2026_11_04_000024_create_accounting_capture` ajoute politique, reçus/tentatives et provenance système. Aucun livre ni politique active n’est créé. Sur une installation, appliquer `php artisan migrate --no-interaction` puis vérifier `schedule:list`. Le lancement et la supervision réels du planificateur système restent à mettre en place.

R22/V35 restent partiels : frais, rapprochements, valorisation complète, états réglementaires et interfaces sont toujours à livrer.

## Vérifications finales

PostgreSQL : 306 tests, 7 353 assertions, aucun échec ni test ignoré. SQLite : 306 recensés, 282 exécutés, 6 768 assertions, 24 réservés à PostgreSQL ; aucun échec. Douze tests fonctionnels et deux courses ajoutés. Huit tests npm, contrat/types, TypeScript/Astro et formatage vérifiés. Migration locale appliquée sans données comptables fictives ; aucun livre activé et essai du job avec tous les compteurs à zéro. Détail et preuves : VALIDATION.md et preuves/backend-postgresql.xml / backend-sqlite.xml.
