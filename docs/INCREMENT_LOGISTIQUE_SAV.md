# Remplacement en préparation et déplacements SAV — backend

Incrément du 11 novembre 2026, suite de INCREMENT_SAV.md. Il ferme deux trous de la chaîne d'exécution :
- le remplacement d'une unité déjà sortie du stock et trouvée abîmée avant la remise ;
- la logistique des dossiers SAV : reprise chez le client, livraison d'un article d'échange.

## Remplacement d'une unité en préparation

`POST operations/orders/{id}/preparation/picks/{pickId}/replace` (`stock.write`). Une unité sortie, trouvée abîmée ou non conforme avant de partir :

1. **revient en stock mise de côté** (« damaged » ou « quarantine »), dans son lot d'origine, à l'emplacement choisi (mouvement `preparation_return`). Pour un article suivi, seules ses séries exactes sont acceptées ;
2. un **remplaçant** est retenu pour la même ligne, **uniquement dans des lots vendus aux mêmes conditions** (même empreinte de conditions) que l'unité remplacée. Il apparaît dans la préparation avec l'origine « replacement » et se sort comme toute unité ;
3. la commande **n'est plus prête** jusqu'à cette nouvelle sortie.

Les quantités sorties sont désormais **nettes des unités remplacées**. Cela vaut pour la préparation, le contrôle par article à la remise, la vue du livreur et « Mes achats et garanties » : une unité remplacée ne compte qu'une fois. Ce remplacement est refusé pendant une mission en cours (on passe alors par la mission), pour un lot en dépôt-vente, ou sans stock conforme disponible (tout est alors annulé, rien n'est déplacé).

## Déplacements SAV du livreur

Un déplacement appartient à un dossier SAV accepté.

**Reprise** (`POST operations/after-sales/{id}/pickup-trips`, dispatch) : pour un retour, un échange ou une réparation. Le livreur contrôle à la porte la quantité et les numéros de série attendus, puis dépose les colis au dépôt. L'équipe stock réceptionne ensuite le retour (en quarantaine) et le contrôle, comme dans INCREMENT_SAV.md.

**Envoi d'échange** (`POST operations/after-sales/{id}/exchange-shipments`, équipe stock) : un article de remplacement vendu aux mêmes conditions est **sorti du stock aussitôt** (référence : numéro du dossier). Le livreur l'enlève au dépôt et le remet au client avec un **code à usage unique**, que le client voit dans son dossier. Un code erroné est compté, 5 essais au plus. Un envoi d'échange déjà sorti du stock ne s'annule pas ; une reprise peut être annulée tant que rien n'a été pris.

Affectation par le dispatch ; une seule reprise et un seul envoi en cours par dossier. Le livreur voit l'adresse et le contact seulement pendant le déplacement ; le client voit le nom public et le badge du livreur.

**Clôture d'un échange** : seulement quand l'article d'échange a été remis au client, en plus du retour réceptionné et contrôlé.

## Journée du livreur

Les déplacements SAV entrent dans la tournée (`trip_ids`) avec le même planificateur que le reste (par zone, au plus proche). Exemple vérifié par un test, pour un échange :
1. récupérer chez le client ;
2. prendre l'article d'échange au dépôt ;
3. y déposer le retour ;
4. livrer l'échange au client.

Consignes FR/EN, par exemple : « Récupérer chez le client à Akwa (repère : …) : 1 × Produit — dossier SAV-…, vérifiez la quantité et les numéros de série. » Les actions attendues sont `take`, `take_first`, `drop` et `handover`. La progression compte les déplacements faits et à bord.

## Migrations, contrat et vérifications

Migrations additives :
- `2026_11_11_000040_create_preparation_replacements` : unités remises en stock en préparation, et origine « replacement » autorisée par le garde-fou des prélèvements ;
- `2026_11_11_000041_create_after_sales_trips` : déplacements, journal, et arrêts de tournée liés à un déplacement (garde-fou des arrêts remplacé).

Les deux sont appliquées à la base locale `kora` (vide). Contrat **0.37.0 / 420 chemins** (`update-after-sales-logistics-contract.mjs`) ; la chaîne rejouée donne une empreinte identique.

Tests :
- `OrderPreparationTest` (+2) : remplacement simple (mise de côté, commande plus prête, remplaçant retenu puis sorti, quantités nettes, sur-retour refusé) et remplacement sérialisé (seule la série rendue change) ;
- `AfterSalesTest` (+1) : échange complet avec reprise et envoi, tournée dans l'ordre, contrôle de quantité à la porte, mauvais code compté, retour défectueux, clôture refusée avant la remise de l'échange puis acceptée.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- **Réparation** : le retour de l'article réparé au client n'a pas encore de déplacement dédié (il faut définir comment l'article du client est suivi pendant la réparation).
- **Retour au fournisseur** d'une unité défectueuse et décision du fournisseur sur la garantie : à relier au dossier.
- Remplacement en préparation sans stock conforme : passer par l'anomalie et l'approvisionnement ; pas de remplacement automatique par achat.
- Écrans et notifications.
