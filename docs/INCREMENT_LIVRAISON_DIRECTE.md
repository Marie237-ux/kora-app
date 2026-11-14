# Livraison directe boutique → client — backend

Incrément du 11 novembre 2026, sur décision de Marie (INCREMENT_COLLECTES_FOURNISSEUR.md posait la question) :

- **Oui** à la livraison directe de la boutique au client, sans passage au dépôt.
- Quand l'article n'est pas dans un dépôt KORA, **c'est le livreur qui réceptionne** la marchandise en boutique.
- Preuve : **la photo du bon de la boutique et les numéros de série**, les deux.
- Pour certains achats **un responsable accompagne le livreur**, pour d'autres non.

## Comment les unités restent suivies

Sans dépôt, les unités passent par un **emplacement de stock « en route »** (le sac du livreur). Le propriétaire le crée une seule fois, comme un dépôt et un emplacement ordinaires (par exemple un dépôt « Livreurs en route »). KORA ne crée aucune donnée d'exploitation à sa place. À la réception en boutique, les unités entrent dans cet emplacement avec leur preuve, puis en sortent aussitôt pour la commande du client (mouvement de vente). Le stock garde donc la trace complète de chaque unité : achat → réception avec photo et séries → sortie pour la commande → remise au client.

## Déroulé

1. **Commande payée** ; unités confirmées par le fournisseur, achetées et affectées à la ligne du client (parcours existant).
2. **Collecte directe** (dispatch, `POST operations/purchases/{id}/direct-collections`) : commande, boutique, emplacement « en route » et responsable facultatif (droit `stock.receive`). Elle reprend les unités affectées, pas encore reçues ni promises à une autre collecte.
3. **Préparation** : les unités couvertes par une collecte directe comptent comme couvertes. Si rien ne vient du dépôt, l'équipe stock déclare la commande prête avec **0 colis**, ce qui n'est possible que dans ce cas.
4. **Mission** : créée et affectée comme d'habitude. Elle distingue les colis du dépôt (enlevés une seule fois) du total à remettre.
5. **En boutique** : le livreur envoie la photo du bon (`POST courier/missions/{id}/shop-photos`), puis confirme la réception (`POST courier/missions/{id}/shop-receipts`) avec, pour chaque ligne, la quantité, les numéros de série (et le lot ou la péremption si l'article l'exige) et le nombre de colis. Si un responsable est désigné, **c'est lui** qui réceptionne (`POST operations/collections/{id}/direct-receipt`) ; le livreur ne le peut pas.
6. **En une seule opération** : photo vérifiée (pièce privée, contrôlée par l'antivirus), unités reçues avec leurs séries dans l'emplacement « en route », retenues pour la ligne du client, sorties pour la commande, colis ajoutés au chargement de la mission (« en livraison »).
7. **Remise au client** par son code, avec le contrôle par article et séries habituel.

Un manque en boutique est enregistré avec une note obligatoire. La remise reste **impossible** tant qu'une unité de la commande n'est ni sortie ni annulée, tant qu'une réception en boutique est en attente ou tant que les colis du dépôt n'ont pas été enlevés. Le dispatch traite alors le manque par le parcours d'indisponibilité fournisseur existant (choix du client, remboursement).

## Journée du livreur

La tournée place l'étape **« Réceptionner chez la boutique X »** dans l'ordre de la journée, par zone et au plus proche, comme les autres arrêts. La consigne est par exemple : « Réceptionner chez Boutique X — Point Akwa (rue …, repère …) : 3 × Produit Y — commande CMD-…, collecte COL-…. Photographiez le bon de la boutique, saisissez les numéros de série, puis confirmez la réception. » Avec un responsable : « Aller chez … avec Prénom Nom, qui réceptionne ».

Actions attendues : `shop_receipt`, `wait_supervisor`, `take_over_first` (reprendre d'abord les colis d'un autre livreur), puis `handover` seulement lorsque tout est à bord. Exemple vérifié : une commande entièrement achetée en boutique donne **réceptionner chez la boutique → livrer le client**, sans étape au dépôt.

## Sécurité et contrôles

- Le livreur n'envoie une photo que pour une collecte directe de la commande de **sa** mission ; sinon la collecte est introuvable. L'envoi délégué saute seulement le contrôle de rôle : format, taille et antivirus restent appliqués.
- La réception reprend les contrôles existants : achat confirmé, conformité aux conditions vendues, séries uniques et en nombre exact, emplacement actif, plafonds d'affectation, choix client en attente.
- Ordre de verrouillage : commande → mission → collecte → fournisseur → achat → lignes → stock, compatible avec les écritures de commande.
- Les actions des collectes vers dépôt (affecter, collecter, déposer…) ne s'appliquent jamais à une collecte directe, qui est seulement réceptionnée ou annulée.

## Migrations, contrat et vérifications

Les migrations des missions (000034) et des collectes (000036), jamais livrées et appliquées seulement à la base locale vide, ont été annulées puis modifiées :
- missions : colis du dépôt séparés du total, total pouvant partir de 0 ;
- collectes : destination dépôt ou client, commande, emplacement « en route », responsable, photo, auteur de la réception, séries par ligne.

La référence à l'affectation d'achat est vérifiée par un garde-fou SQL plutôt que par une clé étrangère, pour que le test de mise à niveau de la migration 000019 puisse toujours la reconstruire. Contrat **0.34.0 / 389 chemins** (`update-direct-delivery-contract.mjs`, après celui des collectes) ; la chaîne rejouée donne une empreinte identique.

Tests : `DirectDeliveryTest` (2) — réception par le livreur avec photo, manque refusé sans note, emplacement « en route » vidé par la sortie, commande livrée par code ; responsable désigné seul à réceptionner, photo refusée pour une autre collecte, actions de dépôt refusées. Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- Le dépôt et l'emplacement « en route » sont à créer une fois par le propriétaire avant la première livraison directe.
- Pas de saisie hors ligne de la réception ; la photo et la réception exigent une connexion au moment de l'action.
- Retours d'une unité livrée en direct : même circuit SAV que les autres, à construire.
