# Achats et réceptions affectés aux commandes — backend

Incrément du 4 novembre 2026, après les confirmations fournisseur. Il relie les besoins des lignes payées aux achats puis aux marchandises réellement reçues. La vente, la dette fournisseur et le suivi physique restent distincts.

## Affectation d'achat

`POST operations/orders/{id}/lines/{lineId}/purchase-allocations` reçoit `version` (le `supply_version` de la ligne), `purchase_line_id` et `quantity`. La commande doit être payée et ouverte ; l'achat doit être confirmé et destiné à `customer_needs`. Une confirmation fournisseur encore valable doit couvrir les unités non reçues à engager.

Le fournisseur, l'offre, la boutique, la variante, le SKU, les attributs et la garantie sont comparés aux conditions figées de la vente. Un changement ultérieur du catalogue n'autorise pas une substitution silencieuse. La somme affectée ne dépasse ni le besoin client restant, ni les unités de la ligne d'achat, excédents approuvés compris.

Plusieurs achats peuvent couvrir une commande ; un achat peut couvrir plusieurs commandes. Les quantités originales et libérées sont conservées séparément. Le coût unitaire prévu est copié depuis l'achat ; il ne modifie ni le prix de vente ni la dette fournisseur.

## Réception et stock réel

`POST operations/purchase-allocations/{id}/receipts` reçoit la version de l'affectation, `receipt_line_id` et `quantity`. La réception doit appartenir à la bonne ligne d'achat. Les excédents doivent être approuvés et les unités réellement disponibles selon le contrôle physique : emplacement actif, état disponible, date valable et conditions conformes. Une réception en quarantaine n'est pas utilisable avant traitement approprié.

L'affectation réserve **les lots exacts de cette réception**, avec leurs séries/IMEI si requis. Elle ne prélève pas silencieusement un autre lot du dépôt. Les compteurs des réceptions et les réservations physiques empêchent d'affecter deux fois les mêmes unités, y compris si un autre parcours de stock les a déjà réservées ou sorties.

Une réception partielle peut être affectée sous le minimum d'achat du catalogue : le client a déjà validé la quantité totale de sa commande, ce n'est pas une nouvelle vente. Le coût unitaire réel est copié depuis la réception avec sa quantité ; un écart déjà justifié lors de la réception ne réécrit pas le prix client.

Le client voit seulement `procurement_received_quantity` et, lorsque le besoin est couvert, `supplier_stock_received`. Les coûts, achats, justificatifs et références internes restent privés. Ce statut atteste une réception et un stock retenu, pas une préparation achevée ni une livraison.

## Réservations après paiement

Le délai du panier non payé ne doit pas rendre le stock d'une commande déjà payée. Les réservations retenues portent `order_line_id` ; leur DTO indique `expires_at: null`. Elles sont exclues des tâches d'expiration et restent protégées jusqu'à libération ou consommation explicite par le traitement de commande.

La confirmation de paiement retient aussi les réservations KORA existantes ou réallouées après paiement tardif. La migration rattache les réservations encore actives des anciennes commandes payées et ouvertes, en vérifiant offre et quantité. Elle ne ressuscite pas une réservation déjà libérée ou consommée.

Les routes génériques de libération/sortie de stock refusent ces réservations. Pour une réception affectée, `POST operations/receipt-allocations/{id}/release` restitue les unités non consommées avec version, motif et audit. La réception et son coût historique ne sont pas supprimés.

`POST operations/purchase-allocations/{id}/release` libère uniquement la partie non reçue de l'affectation. Les unités déjà reçues demandent leur propre libération préalable. **Aucune de ces opérations n'annule l'achat ni la dette fournisseur.**

## Ruptures et choix client

Les confirmations, expirations et demandes suivantes ne portent plus sur les unités déjà reçues et retenues. Une confirmation fournisseur qui expire ne transforme pas en rupture les produits physiquement reçus.

Un choix client encore attendu empêche d'affecter les unités précisément signalées manquantes. Un client ayant choisi d'attendre peut voir son incident résolu lorsque le stock conforme a réellement été reçu et affecté. Une demande de remboursement existante n'est pas effacée pour masquer cette évolution.

Lorsqu'un client annule des unités non reçues, les affectations d'achat excédentaires sont libérées automatiquement, sans toucher aux biens déjà retenus ni à la dette fournisseur. Les biens déjà reçus ne se remboursent pas comme une fausse indisponibilité fournisseur : leur annulation/retour demande le traitement physique approprié.

## Consultation, droits et concurrence

`GET operations/orders/{id}/procurement` expose le détail par ligne : quantités fournisseur, annulées, affectées et reçues, versions, coûts prévus/réels et historique des libérations. Lecture cohérente via `ReadSnapshot` (PostgreSQL REPEATABLE READ / READ ONLY).

Rôles : `purchases.read` pour lire ; `purchases.write` pour affecter/libérer. Session avec MFA, CSRF sur les écritures, clé d'idempotence UUID et versions attendues. Les informations financières ne sont pas exposées aux clients ou à un rôle stock seul.

Ordre de verrouillage des écritures : commande → ligne → fournisseur → achat → ligne d'achat → stock. Deux courses PostgreSQL font attendre de vrais processus PHP sur le fournisseur partagé : un seul obtient la dernière unité d'achat ou la dernière unité reçue. Les contraintes de capacité sont revérifiées après acquisition des verrous.

## Migration, contrat et vérifications

Migration `2026_11_04_000019_allocate_order_purchases` appliquée à la base locale `kora`, sans réinitialisation. Contrat **0.15.0 / 225 chemins**, types régénérés ; script `scripts/contract/update-order-purchases-contract.mjs`, à exécuter après le script des confirmations fournisseur.

`OrderPurchasesTest` comprend 12 tests : plusieurs achats et commandes, facture fournisseur groupée sans double dette, réceptions et coûts partiels, protection des unités, conformité, confirmation expirée, libérations et remboursement, quarantaine/excédents, séries/minimum, droits/versions/idempotence, reprise des réservations existantes. La preuve de mise à niveau des colonnes avec clés étrangères est spécifique à PostgreSQL ; SQLite ne peut pas reconstruire cette table référencée dans la transaction des fixtures.

`OrdersTest` vérifie aussi que le stock réalloué après un paiement tardif ne redevient pas disponible au délai suivant. Suites complètes : PostgreSQL, 220 tests et 4 316 assertions réussis ; SQLite, 205 exécutés et 15 réservés à PostgreSQL, 4 041 assertions, aucun échec. Huit tests npm, types, TypeScript/Astro et formatage conformes. Rapports : [VALIDATION.md](VALIDATION.md).

## Restant obligatoire

- Création assistée/groupement des achats depuis les besoins, rapprochement des frais et charges communs, vue de marge et écrans de gestion.
- Préparation, sortie du stock pour une commande, collecte directe fournisseur, missions/livraison, preuves et PWA. Les réservations ne constituent pas des sorties fictives.
- Annulations générales, retours et SAV des marchandises déjà retenues/consommées ; alternatives et replanification logistique avec accord client.
- Écrans client et gestion FR/EN clair/sombre, notifications et parcours interactifs. Aucun paiement, achat, message externe ni déploiement réel effectué.

Prochaine étape du plan backend : devis professionnels (§14), en conservant ces relations pour leurs commandes futures.
