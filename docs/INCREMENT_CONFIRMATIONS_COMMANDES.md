# Confirmations fournisseur et choix client — backend

Incrément du 4 novembre 2026 : relier la confirmation externe aux unités fournisseur de chaque ligne payée et traiter une rupture sans réécrire la vente. Les achats, la préparation et la livraison restent des traitements distincts.

## Demande et confirmation

`POST /api/v1/operations/orders/{id}/lines/{lineId}/supplier-reservations` reçoit `version` (le `supply_version` interne de la ligne) et `expires_at` avec fuseau. La commande doit être payée et la ligne doit encore avoir des unités fournisseur non annulées. Propriétaire et achats (`purchases.write`, session avec MFA) peuvent agir ; le rôle achats a désormais aussi `orders.read` pour suivre ces commandes.

La demande est un enregistrement interne, pas un appel, message, achat ou engagement confirmé par la boutique. Elle référence explicitement la ligne et conserve `purpose_id = order_line_id`. Deux demandes valables ne peuvent pas être ouvertes pour la même ligne ; les anciennes demandes restent consultables. La route générique refuse d'utiliser une ligne de commande comme simple finalité non reliée.

Les routes existantes `POST operations/supplier-reservations/{id}/confirm` et `/reject` reconnaissent les réservations reliées. Pour confirmer, l'équipe enregistre une référence externe réelle ; `confirmed_quantity` facultatif permet une réponse partielle. Le plan de disponibilité soustrait les unités effectivement confirmées dans cette réponse partielle, au lieu de toute la quantité demandée. Une réponse complète ne constitue toujours pas une collecte ni un achat.

Les écritures prennent leurs verrous dans l'ordre commande → ligne → réservation. Une réponse ancienne ne peut pas confirmer une demande remplacée ou des unités annulées entre-temps. Une demande interne non confirmée pour une ancienne quantité devient `superseded` après le choix de remboursement ; une nouvelle demande porte uniquement sur le reste. Les libérations génériques sont refusées sur une réservation de commande sans traitement client adapté.

## Rupture et expiration

Une réponse partielle/négative ouvre un incident client par ligne. L'équipe peut également signaler une rupture par `POST operations/orders/{id}/lines/{lineId}/unavailable` : version de la ligne, quantité manquante, message FR/EN et référence privée. Les quantités signalées sont bornées aux unités fournisseur restantes, sans annuler arbitrairement les unités KORA.

Les incidents successifs ont une séquence propre ; les décisions et remboursements passés ne sont pas écrasés. Message client, état, choix possibles et montant proposé sont inclus dans `orders/{id}.supply_cases`. Les références de contact, coûts et négociations restent privés. Les événements et l'audit conservent l'historique.

La validité est contrôlée dès la lecture : une confirmation expirée ne reste pas annoncée comme valable avant le passage du planificateur (`supplier_confirmation_valid = false`, état `confirmation_expired` lorsque pertinent). `orders:expire-supply --limit=100`, planifié chaque minute, marque les réservations échues et ouvre une demande de choix. Il traite aussi les anciennes réservations remplacées pour ne pas encombrer indéfiniment sa sélection. Rejouer ce traitement ne duplique pas l'incident.

## Choix du client

`POST /api/v1/orders/{id}/supply-cases/{caseId}/choice` exige le propriétaire réel de la commande, la version de l'incident et une clé d'idempotence UUID.

- `wait` : attente explicite, sans remboursement et sans garantie de nouveau délai inventé. L'équipe peut obtenir une nouvelle confirmation ; une réponse complète résout l'incident ouvert.
- `refund_missing` : annule les unités manquantes et demande leur remboursement, en conservant les autres unités de la commande. `accepted_refund_amount` doit correspondre exactement à la proposition calculée par le serveur. Les frais nets de livraison sont conservés pour une commande partielle, sans supplément ajouté ; ils sont remboursés intégralement si le choix annule les dernières unités de toute la commande. Un changement du montant impose une nouvelle acceptation.

Montant produits : prix unitaire figé × unités manquantes, en XAF entiers. Les remboursements successifs sont additionnés sans compter deux fois les frais de livraison. Une demande client reste `refund_requested` jusqu'au versement réellement vérifié par la finance, via le flux existant avec pièce analysée, montant et référence. Aucun appel de remboursement Monetbil ni transfert réel n'est déclenché. La demande liée au choix client ne peut pas être effacée par l'annulation générique d'un remboursement.

Après remboursement total des unités toutes annulées, `closed_at` clôt l'exécution, le créneau est libéré et une éventuelle promotion de livraison consommée est rendue. Cette restitution n'a lieu qu'une fois, après versement vérifié. L'état du paiement demeure `paid` : l'argent reçu et les remboursements restent des faits distincts et consultables.

## Vente et documents préservés

Prix, quantités achetées, sources initiales, total accepté et facture émise restent figés. `cancelled_quantity` conserve séparément les unités annulées ; une future préparation doit les exclure. Une confirmation fournisseur valable porte seulement sur les unités restantes. Les avoirs s'appuient sur le remboursement vérifié et la facture émise, avec les contrôles existants. Une facture n'est pas réécrite pour cacher l'incident.

## Contrat, migration et vérifications

Migration `2026_11_04_000018_link_order_supply`, appliquée à la base locale `kora` sans réinitialisation. Contrat **0.14.0 / 220 chemins** ; types régénérés par `scripts/contract/update-order-supply-contract.mjs` après commandes et promotions. Les contrôles du contrat couvrent les routes gestion et client du nouveau contrôleur, leurs droits et leur idempotence.

`OrderSupplyTest` : 11 tests ciblés, 414 assertions SQLite. Demande liée, paiement préalable, contrôle des lignes, confirmation partielle, attente/reprise, facture inchangée et avoir lié, remboursement partiel/complet, restitution de promotion, confidentialité et langues, expiration idempotente, réservations comptées correctement, ruptures successives et réponse devenue obsolète. Une nouvelle course PostgreSQL lance deux processus PHP pour deux choix incompatibles sur la même version : une seule décision est acceptée, avec au plus une demande de remboursement.

Suites complètes : PostgreSQL, 206 tests et 3 728 assertions, tous réussis ; SQLite, 194 exécutés et 12 réservés à PostgreSQL, 3 536 assertions, aucun échec. Huit tests npm et contrôles OpenAPI/formatage réussis. Résultats et limites : [VALIDATION.md](VALIDATION.md).

## Restant obligatoire

- Création assistée des achats depuis les besoins, confirmations contractuelles complètes, collecte et consommation physique du stock. Les affectations manuelles achats/réceptions sont livrées dans l'incrément suivant (INCREMENT_AFFECTATIONS_COMMANDES.md) ; aucun achat automatique n'est déclenché.
- Alternatives produit avec consentement, nouveau prix/garantie et plan logistique validés ; option de livraison partielle programmée et réorganisation des créneaux. Le choix de rembourser les unités manquantes conserve le reste mais ne crée pas une mission de livraison.
- Annulations générales hors rupture fournisseur, retours/SAV, toutes les restitutions d'avantages correspondantes et rapprochement des remboursements libres déjà saisis par la finance avec les incidents concernés.
- Écrans FR/EN clair/sombre, messages/notifications de compte et canaux externes autorisés, parcours interactifs et compte Monetbil réel. Dans cet incrément, l'incident est disponible dans la commande ; aucun nouveau canal de notification n'est déclaré livré.
- Installation et supervision effectives du planificateur système ; sa présence dans `schedule:list` ne prouve pas l'exécution d'un service de production.

Suite réalisée : affectations entre achats/réceptions et lignes de commande (INCREMENT_AFFECTATIONS_COMMANDES.md). Les unités déjà reçues et retenues sont protégées contre une fausse rupture fournisseur ; seules les unités restant à acquérir demandent une nouvelle confirmation. Prochaine dépendance backend : devis professionnels (§14).
