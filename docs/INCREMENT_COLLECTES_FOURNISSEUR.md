# Collectes en boutique fournisseur et localisation des points — backend

Incrément du 11 novembre 2026, suite de INCREMENT_MISSIONS_LIVRAISON.md. Marie demande que la journée du livreur soit précise « au millimètre » : récupérer à telle boutique, livrer le client du quartier, récupérer la suivante, etc. Deux manques sont comblés :
- les étapes de collecte en boutique fournisseur ;
- la localisation des points de collecte, pour ordonner les arrêts par proximité réelle.

## Localisation des points de collecte

Les points de collecte (boutique ou dépôt) reçoivent un quartier, un repère et des coordonnées, tous facultatifs (`POST/PUT operations/delivery/collection-points`). Latitude et longitude vont ensemble. Un champ omis lors d'une mise à jour garde sa valeur : les anciens clients de l'API ne les effacent pas. La carte reste facultative, comme pour les adresses client.

## Collecte fournisseur

`POST operations/purchases/{id}/collections` (dispatch) crée la collecte d'un **achat confirmé** chez le point actif d'une boutique, vers un **dépôt KORA actif**. Elle reprend les unités restant à recevoir sur les lignes de cette boutique et pas déjà promises à une autre collecte en cours (toutes par défaut, ou des quantités choisies). Deux collectes simultanées ne promettent jamais deux fois les mêmes unités : une course PostgreSQL entre deux vrais processus le vérifie.

Déroulé :

| Étape | Qui | Effet |
| --- | --- | --- |
| Affectation | dispatch | livreur actif, acceptation facultative |
| Collecte | livreur, en boutique | compte chaque ligne (jamais plus qu'attendu) et le nombre de colis ; un **manque** exige une note et reste visible (`shortfall`) |
| Dépôt | livreur, au dépôt | tous les colis, même nombre qu'à la collecte |
| Réception | équipe stock | réception habituelle avec pièce justificative, puis lien avec la collecte (`received`) |

**Transporter n'est pas réceptionner.** La collecte ne crée ni stock, ni dette, ni paiement. Les marchandises entrent en stock uniquement par la réception de l'équipe stock (`stock.receive`), avec sa pièce, son emplacement et ses lots ou séries. La réception reliée doit concerner le même achat et un emplacement du dépôt de destination ; une réception ne sert qu'à une collecte. Les unités manquantes en boutique redeviennent à collecter.

Incidents : boutique fermée, marchandise pas prête ou non conforme, véhicule indisponible, autre. Le dispatch relance (`retry`) ou annule ; l'annulation n'est possible que tant que rien n'a été compté. Une réaffectation alors que les marchandises sont chez un livreur exige une note de transmission ; le nouveau livreur les **reprend** sans recompter, avec le même nombre de colis. L'ancien livreur perd aussitôt l'accès, même par rejeu idempotent. Le livreur voit les noms, références et quantités, jamais les prix d'achat.

## Journée du livreur : boutiques, dépôts et clients mêlés

Une tournée accepte désormais des missions de livraison et des collectes (`mission_ids`, `collection_ids`). Le planificateur les traite comme des tâches au même titre :
- collecter en boutique avant de déposer au dépôt ;
- enlever au dépôt avant de livrer le client ;
- travail regroupé par zone ;
- dans la zone : déposer ou livrer ce qui est à bord, puis enlever ou collecter, puis déposer ou livrer ce qui vient d'être pris ;
- zone suivante : celle du créneau client le plus urgent.

**Proximité** : dans une zone, lorsque les arrêts ont des coordonnées, le suivant est **le plus proche** de la position précédente (distance à vol d'oiseau). Les arrêts sans coordonnées suivent, dans l'ordre quartier/créneau. Un test unitaire vérifie ce cas.

Nouvelles étapes dans `GET courier/day` :

- **Collecter** : boutique, nom du point, adresse, quartier, repère, coordonnées, liste des articles. La consigne indique par exemple « Collecter chez Boutique X — Point Akwa (rue …, repère …) : 2 × Produit Y — collecte COL-0000001. Comptez chaque article ; s'il en manque, notez ce que dit la boutique. »
- **Déposer** : dépôt (nom, adresse, quartier, coordonnées), avec la consigne « Déposer 2 colis de COL-0000001 au Dépôt Z (…) et les remettre à l'équipe stock. »
- Actions attendues : `collect`, `collect_first`, `drop`, en plus de celles des missions. Progression : collectes faites/totales, et colis à bord (clients et fournisseurs).

Exemple vérifié par un test : une collecte en boutique et une livraison dans la même zone donnent **collecter → enlever au dépôt → déposer au dépôt → livrer le client**.

## Droits, concurrence et protections

`delivery.assign` crée, affecte, relance et annule ; `delivery.read` consulte ; `stock.receive` relie la réception ; `missions.own` pour le livreur. Ordre de verrouillage à la création : fournisseur → achat → lignes d'achat, comme les réceptions (les offres ne sont pas verrouillées). Garde-fous SQL (PostgreSQL et SQLite) : statuts cohérents avec la garde, quantités comptées une seule fois et jamais au-delà de l'attendu, collectes reçues ou annulées immuables, journal en ajout seul. Les arrêts de tournée portent soit une mission, soit une collecte, avec le bon type d'arrêt.

## Migrations, contrat et vérifications

Migrations additives `2026_11_11_000035_locate_collection_points` et `2026_11_11_000036_create_supplier_collections`. La migration des tournées, d'abord numérotée 000035 et seulement appliquée à la base locale vide, a été annulée puis renumérotée `2026_11_11_000037_create_delivery_rounds` pour référencer les collectes. Les trois migrations sont appliquées à `kora` (vide). Contrat **0.33.0 / 385 chemins** (`update-supplier-collections-contract.mjs`, après celui des tournées) ; la chaîne des scripts rejouée donne une empreinte identique.

Tests : `SupplierCollectionsTest` (3), cas de proximité dans `RoundPlannerTest`, `DeliveryRoundsTest` mis à jour, course `test_two_collections_cannot_promise_the_same_purchase_units`. Résultats : [VALIDATION.md](VALIDATION.md).

## Limites et restant obligatoire

- **Livraison directe boutique → client** : décidée par Marie et construite le 11 novembre, voir INCREMENT_LIVRAISON_DIRECTE.md (réception par le livreur ou un responsable désigné, photo du bon et numéros de série).
- Pas de service de distance routière : l'ordre par proximité est à vol d'oiseau et sans durées ni heures d'arrivée estimées.
- Preuve photo de la collecte et du dépôt, bon de collecte imprimable, collecte demandée par le portail fournisseur.
- Écrans dispatch et livreur (PWA).
