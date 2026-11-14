# Préparation des commandes payées — backend

Incrément du 7 novembre 2026, vérifié et documenté le 10 novembre. Après plusieurs étapes de trésorerie, Marie a demandé de reprendre la chaîne d'exécution des commandes : une commande payée doit pouvoir être préparée et sortie réellement du stock avant toute mission de livraison (§20, §36). Cet incrément couvre la préparation ; les missions viennent ensuite.

## Liste de préparation

`GET operations/preparations` liste les commandes payées et encore ouvertes, paiement le plus ancien en premier, filtrables par `status` (`not_started`, `partial`, `anomaly`, `ready`). Chaque entrée donne les quantités requises et préparées, la date du créneau et le nombre d'anomalies ouvertes.

`GET operations/orders/{id}/preparation` donne, par ligne : quantités commandées, annulées, requises, préparées, retenues et encore en attente de stock. Pour chaque réservation retenue : origine (`warehouse` pour le stock KORA, `receipt` pour une réception affectée), dépôt, emplacement, lot, péremption, état et numéros de série/IMEI attendus. **Aucun prix, coût ni adresse client** n'y figure : c'est un document interne de l'équipe stock. Lecture cohérente via `ReadSnapshot`.

Les unités ni préparées ni retenues dans un emplacement KORA apparaissent en `awaiting_stock_quantity` : encore à acheter, recevoir ou collecter. Elles ne sont pas préparées ici.

## Sortie réelle du stock

`POST operations/orders/{id}/preparation/picks` reçoit `version` (version de préparation de la commande), `reservation_id`, `identifiers` et une note facultative. La réservation doit être active et appartenir à une ligne de cette commande (stock KORA de la ligne ou réception affectée encore retenue).

Pour un produit suivi par série/IMEI, le préparateur saisit les identifiants réellement en main ; ils doivent correspondre exactement aux unités réservées, quel que soit l'ordre. Un autre numéro ne sort pas au titre de cette vente.

La sortie consomme la réservation par le traitement de stock existant : mouvement `sale_issue` portant le numéro de commande, stock physique et réservé diminués, unités sérialisées passées en `consumed`. Le prélèvement conserve une copie des emplacements et lots d'origine, car ceux-ci peuvent être renommés ou fermés ensuite. **Une réservation ne peut être sortie qu'une fois** (contrainte unique) ; une requête rejouée avec la même clé d'idempotence renvoie le résultat enregistré sans seconde sortie.

Les routes génériques de sortie ou libération de stock refusent toujours les réservations d'une commande payée, y compris pour le propriétaire. Une réception affectée déjà sortie ne peut plus être libérée comme simple réception.

## Commande prête et anomalies

`POST operations/orders/{id}/preparation/ready` reçoit `version`, `packages` (1 à 100) et une note facultative. Elle exige que toutes les unités non annulées de chaque ligne soient sorties et qu'aucune anomalie ne reste ouverte. Une commande entièrement annulée n'a rien à préparer.

`POST operations/orders/{id}/preparation/anomalies` signale une anomalie par ligne : `missing`, `damaged`, `expired`, `mismatch` ou `other`, avec quantité (au plus les unités non annulées) et note obligatoire. Une anomalie signalée sur une commande déjà prête **retire l'état prêt** : le colis n'est plus remis à un livreur tant qu'elle n'est pas traitée. `POST operations/preparation-anomalies/{id}/resolve` clôt une anomalie avec version et note de résolution ; elle ne se résout qu'une fois.

Le statut de préparation est une synthèse recalculée depuis les faits (prélèvements, anomalies, déclaration prête) à chaque écriture ; il ne remplace jamais les quantités des lignes. Chaque action est enregistrée dans les événements de commande et l'audit.

## Côté client

La commande client expose `preparation_status` réduit à `not_started`, `in_progress` ou `ready`, et `prepared_quantity` par ligne. Les anomalies internes, emplacements, lots et notes ne lui sont pas montrés. Le total payé ne change pas.

## Droits, concurrence et protections

Rôles : `stock.read` pour consulter, `stock.write` pour sortir, déclarer prête et traiter les anomalies. Un gestionnaire peut lire sans sortir ; un rôle catalogue n'a pas accès. Session avec MFA, CSRF, clé d'idempotence UUID et versions attendues (409 sur version périmée). Une commande non payée ne peut pas être préparée : son stock n'est retenu que pour la fenêtre de paiement.

Ordre de verrouillage : commande → ligne → réservation → offre → soldes, identique aux autres écritures de commande. Une course PostgreSQL entre deux vrais processus PHP vérifie qu'une unité reçue est **soit sortie pour préparation, soit libérée, jamais les deux**.

En base, les prélèvements sont immuables (ni modification ni suppression) et ne s'insèrent que pour une réservation consommée de même quantité, liée à la bonne ligne. Les anomalies ne se suppriment pas ; seule la transition ouverte → résolue est acceptée, sans réécrire le signalement. Garde-fous PostgreSQL (fonctions/déclencheurs) et SQLite équivalents.

## Migration, contrat et vérifications

Migration additive `2026_11_07_000033_create_order_preparations` : colonnes de préparation sur `orders`, tables `order_preparation_picks` et `order_preparation_anomalies`. Contrat **0.30.0 / 346 chemins**, types régénérés ; script `scripts/contract/update-order-preparation-contract.mjs`, à exécuter après celui des financements.

`OrderPreparationTest` comprend 6 tests : sortie du stock KORA puis commande prête avec rejeu idempotent, unités fournisseur non reçues visibles et blocage de l'état prêt, séries exactes, anomalies bloquantes et retrait de l'état prêt, droits/versions/commande non payée/file, protection SQL des prélèvements. Course PostgreSQL : `test_a_received_unit_is_either_issued_for_preparation_or_released_never_both`. Résultats des suites complètes : [VALIDATION.md](VALIDATION.md).

## Limites et restant obligatoire

- **Remplacement d'une unité déjà sortie** : une anomalie sur une unité prélevée (abîmée, non conforme) se résout par une note, mais aucune route ne remet encore l'unité en stock/quarantaine ni ne réserve une unité de remplacement. À traiter avec les retours/SAV.
- Collecte directe chez un fournisseur, emballage détaillé (poids/dimensions par colis), étiquettes et bons de livraison.
- Missions de livraison (§20) : affectation livreur, remise vérifiée, preuves, incidents, réaffectations et PWA. L'état prêt n'atteste ni une remise ni une livraison ; la fidélité attend toujours une livraison vérifiée.
- Annulations générales et retours des marchandises déjà sorties ; écrans stock et client FR/EN clair/sombre.

Prochaine étape : missions de livraison à partir des commandes prêtes.
