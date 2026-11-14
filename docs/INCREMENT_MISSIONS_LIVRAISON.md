# Missions de livraison et journée du livreur — backend

Incrément du 10 novembre 2026, suite directe de la préparation (INCREMENT_PREPARATION.md), dans la chaîne d'exécution demandée par Marie (§15, §20, §36 étape 7). Deux passes : les missions (du dépôt jusqu'à la remise vérifiée), puis la tournée du jour, conçue pour qu'un livreur sache **exactement** quoi faire, où et dans quel ordre, même sans le patron.

## Livreurs

`POST operations/couriers` (permission `delivery.settings`) enregistre l'identité professionnelle publique d'un compte ayant le rôle `courier` : nom public et badge unique. Aucun papier d'identité n'est stocké. `PUT operations/couriers/{id}` met à jour le nom ou suspend le livreur ; un livreur inactif, ou qui a perdu le rôle, ne reçoit plus de mission. Le propriétaire reçoit le droit `delivery.assign` ; le rôle `dispatch` l'avait déjà. Le fondateur qui livre aussi cumule ses rôles, sans donner tous les droits à tous les livreurs.

## Mission : du dépôt à la porte du client

`POST operations/orders/{id}/missions` crée la mission d'une commande payée, ouverte, **déclarée prête** et non livrée. Une seule mission en cours par commande (index unique partiel). La mission reprend le nombre de colis de la préparation et génère le code de remise du client.

Étapes et garde des colis :

| Étape | Qui | Effet |
| --- | --- | --- |
| Affectation | dispatch | `assign` : livreur actif, acceptation facultative, rang d'arrêt facultatif |
| Acceptation / refus | livreur | seulement si le mode avec acceptation est choisi ; un refus remet la mission à affecter |
| Enlèvement | livreur | nombre de colis identique à la préparation ; sinon incident `pickup_discrepancy` |
| Remise | livreur + client | contrôle par article puis code du client ; la commande devient livrée |

La mission suit **qui détient les colis** (dépôt ou livreur). Une annulation n'est possible que colis au dépôt.

## Code de remise et procédure alternative

Le code à 6 chiffres est affiché **uniquement au client**, dans sa commande, tant qu'il est valable (72 h par défaut, `KORA_HANDOVER_CODE_HOURS`). Il est conservé chiffré pour cet affichage et vérifié par empreinte HMAC liée à la mission. Il n'apparaît jamais dans la vue livreur ni dans la gestion.

Avant le code, le livreur confirme le contrôle par article : nombre de colis, quantité de chaque ligne et numéros de série/IMEI exacts de ce qui est sorti du stock. Un écart est refusé sans consommer de tentative : c'est un incident, pas une livraison.

Un code erroné est **enregistré et compté** même si la requête échoue (le résultat est validé dans la transaction plutôt que levé en exception). Après 5 erreurs (`KORA_HANDOVER_CODE_ATTEMPTS`), le code est bloqué, même le bon. Le dispatch peut émettre un nouveau code ; l'ancien cesse aussitôt de fonctionner. Aucun code ne déclenche de débit.

Procédure alternative : le livreur la demande avec un motif (`code_unavailable`, `code_locked`, `recipient_delegate`, `other`), une note et le même contrôle par article. Elle doit être validée ou refusée par **une autre personne** que le livreur, avec une note de contrôle ; c'est vérifié en domaine et en SQL.

À la remise (code ou alternative validée), la mission passe `delivered` et `orders.delivered_at` est renseigné. C'est le seul endroit qui l'écrit ; la fidélité attendait cette date. La confirmation de remise ne vaut pas renonciation à un défaut découvert ensuite (§25).

## Incidents et réaffectation

Le livreur signale : client absent, adresse inaccessible, article manquant, dommage, véhicule indisponible, écart à l'enlèvement ou autre. Le client voit seulement « retardée », sans la note interne. Le dispatch traite : nouvel essai du même livreur, retour des colis au dépôt (mission à réaffecter), ou réaffectation directe.

Réaffecter alors qu'un livreur détient les colis exige une **note de transmission** ; le nouveau livreur confirme l'enlèvement auprès de lui (journal `from: courier`). Chaque affectation change la version : **l'ancien appareil ne peut plus agir**. Un middleware revérifie l'affectation actuelle avant tout rejeu idempotent ; pour l'ancien livreur, la mission devient introuvable, même via une réponse enregistrée (scénario V57).

Pendant une mission en cours, une anomalie de préparation est refusée : les problèmes passent par la mission, qui sait qui détient les colis.

## Ce que voit chacun

- **Client** (`delivery.mission` de sa commande) : référence de mission, statut simplifié (`scheduled`, `out_for_delivery`, `delayed`, `delivered`), nom public et badge du livreur, son code et son expiration.
- **Livreur** (`courier/missions`) : seulement ses missions en cours, avec quartier, repère, consignes, coordonnées facultatives, contacts, créneau, articles, quantités, séries autorisées et dépôts d'enlèvement. Il ne voit jamais de prix, de coût ni le code. Une fois la mission livrée ou retirée, l'historique ne garde que la référence et l'issue. Un livreur non affecté ne voit rien (V56).
- **Dispatch** : garde des colis, affectations, état du code (expiré, bloqué, tentatives restantes) sans sa valeur, incidents, remises alternatives et journal complet.

## Journée du livreur (tournée)

`POST operations/rounds` crée la tournée d'un livreur pour une date à partir de ses missions en cours. Une tournée ouverte par livreur et par date ; une mission dans une seule tournée ouverte. Le serveur propose l'ordre des arrêts (`RoundPlanner`, sans service de distance) :

1. Une livraison n'est **jamais** placée avant tous les enlèvements de sa mission.
2. Le travail est regroupé par **zone de livraison** (ensemble de quartiers). Dans la zone où se trouve le livreur, il livre d'abord les colis déjà à bord, puis fait les enlèvements de la zone, puis livre ce qu'il vient de récupérer pour cette zone.
3. Il passe ensuite à la zone du **créneau client le plus urgent** parmi les colis à bord, sinon au prochain enlèvement.

C'est exactement le cas décrit par Marie : récupérer à la boutique A ; si le prochain enlèvement (boutique B) est dans le quartier d'un client à livrer, livrer ce client puis récupérer chez B, et ainsi de suite. Un test unitaire reproduit ce scénario ; 200 plans aléatoires vérifient la règle 1.

`GET courier/day?date=` (aujourd'hui à Douala par défaut) donne au livreur des **étapes numérotées** :

- type d'étape (récupérer / livrer) et lieu : dépôt (nom, adresse, zone), livreur détenteur des colis, ou client (quartier, repère, consignes, contacts, créneau) ;
- une **consigne lisible** FR/EN, par exemple « Récupérer 3 colis au Dépôt Akwa (rue …) pour MIS-0000001, MIS-0000002. Comptez les colis puis confirmez l'enlèvement. » ou « Livrer 2 colis à Akwa (repère : …), créneau 10:00–12:00 — mission MIS-0000001. Contrôlez les articles avec le client puis saisissez son code. » ;
- l'**étape en cours**, l'étape suivante, la progression (étapes faites, livraisons faites, colis à bord) ;
- pour chaque mission de l'étape, l'**action attendue** d'après son état réel : accepter, enlever, enlever d'abord, remettre, attendre le dispatch ou la validation.

Les enlèvements consécutifs au même dépôt forment une seule étape ; chaque client est une étape. L'état d'une étape **n'est jamais coché à la main** : il est lu sur les missions (colis à bord, remise enregistrée). Une mission réaffectée ou annulée apparaît « retirée » dans la journée de l'ancien livreur.

Le dispatch peut réordonner à la main (`PUT rounds/{id}/stops`), mais seulement avec tous les arrêts et chaque enlèvement avant sa livraison. Il peut aussi changer l'ensemble des missions (`PUT rounds/{id}/missions`) : les étapes faites restent en tête, le reste est replanifié depuis la situation réelle, sans récupérer deux fois les colis déjà à bord. Une tournée annulée devient un historique immuable.

## Droits, concurrence et protections

`delivery.read` pour consulter, `delivery.assign` pour créer/affecter/traiter, `delivery.settings` pour les livreurs, `missions.own` pour l'espace livreur. Session MFA pour la gestion ; CSRF, clé d'idempotence UUID et versions attendues sur toutes les écritures ; limite de 30 requêtes/min par action livreur. Ordre de verrouillage : commande → mission → incident/alternative → profil livreur. Les tournées d'un même livreur sont sérialisées sur son profil, sans verrouiller les missions.

Garde-fous SQL (PostgreSQL et SQLite) : statuts et cohérence garde/livreur, mission livrée ou annulée immuable, version croissante, incidents et alternatives (valideur ≠ livreur) à transitions uniques, tentatives et journal en ajout seul, tournées et arrêts protégés après annulation.

Course PostgreSQL entre deux vrais processus PHP : une remise par code et une réaffectation simultanées ne peuvent **pas** aboutir toutes les deux.

## Migration, contrat et vérifications

Migrations additives `2026_11_10_000034_create_delivery_missions` et `2026_11_11_000037_create_delivery_rounds` (d'abord numérotée 000035 ; annulée sur la base locale vide puis renumérotée le 11 novembre pour accueillir les collectes fournisseur, voir INCREMENT_COLLECTES_FOURNISSEUR.md), appliquées à la base locale `kora` (vide). Contrat **0.32.0 / 371 chemins** : scripts `update-delivery-missions-contract.mjs` (0.31.0) puis `update-delivery-rounds-contract.mjs` (0.32.0), à exécuter après celui de la préparation.

Tests : `DeliveryMissionsTest` (6), `DeliveryRoundsTest` (2), `RoundPlannerTest` (3, unitaires) et la course `test_a_handover_and_a_reassignment_cannot_both_win`. La fixture « commande payée et prête » est partagée (`PreparedOrderFixtures`). Résultats des suites : [VALIDATION.md](VALIDATION.md).

## Limites et restant obligatoire

- **Ordre « au mètre près »** : complété le 11 novembre (INCREMENT_COLLECTES_FOURNISSEUR.md) par la localisation des points de collecte et un ordre au plus proche lorsque les coordonnées sont connues. Sans service de distance routière, ni durées de trajet ni heures d'arrivée estimées.
- **Collecte en boutique fournisseur** : ajoutée le 11 novembre (INCREMENT_COLLECTES_FOURNISSEUR.md) avec dépôt au dépôt KORA ; la livraison directe boutique → client sans passage au dépôt reste à décider.
- Photo de preuve, QR de vérification du livreur, véhicules et capacités, suivi GPS (non simulé), mode hors ligne côté PWA, notifications au client.
- Livraison partielle à la porte, retours et SAV des colis refusés ou abîmés, remplacement d'une unité déjà sortie.
- Écrans livreur (téléphone/PWA) et dispatch FR/EN clair/sombre.
