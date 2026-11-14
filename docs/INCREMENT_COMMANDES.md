# Commandes et paiement Monetbil — backend

Une commande naît d'un devis de livraison valable avec un créneau réservé, et du total exact accepté par le client. Le paiement passe par Monetbil ; seule une vérification côté serveur peut le confirmer.

## Commande figée

- Le client envoie le devis, sa version et le total accepté. Le serveur recalcule articles + livraison ; toute différence est refusée (`TOTAL_CHANGED`). Un panier à revoir ou un devis obsolète, expiré ou sans créneau est refusé.
- Un devis ne produit qu'une commande (contrainte unique). Numéro `CMD-0000001` alloué côté serveur, sous verrou, dans la même transaction.
- Chaque ligne fige boutique, nom FR/EN, SKU, variante, prix, garantie. Le prix de référence et l'accord fournisseur en vigueur sont figés à part, en vue interne seulement.
- Les quantités détenues par KORA sont réservées pour la fenêtre de paiement (politique de stock, 30 s à 1 h). Les quantités à collecter chez un fournisseur restent « à confirmer ». Le créneau reste tenu jusqu'à la même échéance.

## Paiement

1. `POST orders/{id}/payments` crée une tentative dans une transaction, appelle Monetbil **hors transaction**, puis enregistre la page de paiement. Prestataire non configuré ou indisponible : 422, rien n'est débité, la commande reste payable.
2. Une tentative récente non clarifiée est proposée à nouveau au lieu d'un second encaissement (15 min par défaut). Une tentative « à vérifier » bloque toute nouvelle demande.
3. La notification Monetbil est vérifiée par sa signature (algorithme du SDK officiel). Elle est enregistrée sous forme d'empreinte, sans téléphone ni nom. Puis la transaction est **revérifiée via `checkPayment`**. Le retour navigateur n'est qu'un indice, revérifié de la même façon.
4. Confirmation seulement si la référence, le montant et la devise renvoyés correspondent à la tentative. Sinon la tentative passe « à vérifier », avec un incident : montant différent, champs manquants, mode test, référence étrangère ou transaction déjà utilisée. Une panne ou une réponse illisible vaut « inconnu », jamais « échoué ».
5. Une fois payée : créneau confirmé, lignes achetées retirées du panier (les autres restent), historique `paid`. Rejouer une notification n'a aucun second effet.

## Cas difficiles

- **Deuxième paiement réel** : la commande reste payée une fois ; un incident `overpayment` signale l'argent à rembourser.
- **Paiement après expiration** : nouvelle réservation conforme du stock et du créneau si possible ; sinon un incident par ligne (`stock_unavailable_after_payment`) ou pour le créneau. Jamais de stock négatif.
- **Expiration** : `payments:reconcile` (chaque minute) revérifie les tentatives dont la transaction est connue (au plus toutes les 2 min, 60 fois), puis expire les commandes non payées et rend stock et créneau.

## Gestion et permissions

`orders.read` (propriétaire, gestionnaire, planification, support, comptable) : liste et détail interne avec coûts figés, sources, tentatives, incidents et historique. `payments.read` : incidents. `payments.reconcile` (propriétaire, comptable) : revérification manuelle.

## Incidents et remboursements

- Un incident se clôt par une décision explicite et auditée (permission `payments.refund`, mot de passe reconfirmé depuis 15 min au plus). Les issues possibles dépendent du type d'incident.
- **Argent reçu (écart de montant, trop-perçu)** : la seule issue est un remboursement vérifié ; une note ne suffit pas.
- **Réponse prestataire incomplète** : une personne autorisée peut confirmer le paiement d'après les relevés du prestataire, avec une pièce jointe obligatoire.
- **Mode test, référence étrangère, transaction déjà utilisée, créneau perdu** : classement motivé.
- Remboursement en deux temps : une **demande** réserve un montant ; seul un **versement vérifié** (même montant, canal, référence unique par canal, pièce `customer_payment_evidence` analysée) le marque payé et clôt l'incident lié. Une demande peut être annulée tant qu'elle n'est pas versée.
- Plafond : demandes et remboursements versés ne dépassent jamais l'argent **réellement reçu** d'après le prestataire, y compris un montant différent de celui demandé ; les transactions de test ne comptent pas. Les demandes sont prises sous le verrou de la commande.
- Après remboursement d'un paiement erroné, la tentative passe `reversed` et la commande redevient payable, ou expire si son délai est dépassé.
- Le client voit ses remboursements : « en cours » tant qu'ils ne sont que demandés, « payé » après versement vérifié.

## Limites de débit

Chaque route a désormais son propre compteur, par utilisateur ou par IP (`RouteThrottle`, qui remplace le limiteur par défaut). Avant, toutes les routes partageaient un seul compteur : quelques ajouts au panier pouvaient bloquer un paiement ou une invitation. `RateLimitTest` le vérifie.

## Vérifications

`OrdersTest` (10 tests, dont remboursements et incidents) avec un prestataire simulé ; `PostgresConcurrencyTest` : deux demandes de remboursement simultanées par deux processus réels, une seule acceptée ; `MonetbilGatewayTest` (4 tests) sur l'adaptateur réel contre des réponses HTTP simulées : formulaire signé, page HTTPS exigée, statuts 1/0/-1 et 7/8/9, panne = inconnu, signature des notifications. Contrat 0.11.0 / 204 chemins via `scripts/contract/update-orders-contract.mjs`.

## Restant obligatoire

- Configuration et validation sur un vrai compte Monetbil (voir LANCEMENT.md) ; aucun encaissement réel n'a été effectué.
- Choix du client en cas d'article indisponible après paiement (alternative, attente, livraison partielle) ; annulation client ; confirmation fournisseur liée à la commande ; séparation auteur/approbateur des remboursements si l'équipe le souhaite.
- Factures et documents (§21), trésorerie complète (§17), promotions (§16), comptabilité (§22).
- Preuve multiprocessus PostgreSQL de deux notifications simultanées ; écrans boutique et gestion.
