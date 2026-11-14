# SAV, garanties et retours — backend

Incrément du 11 novembre 2026 (§25, scénarios V39 en partie et SAV des parcours V71/V80), suite de la chaîne d'exécution. KORA reste l'interlocuteur unique du client et suit chaque dossier jusqu'à sa résolution.

## Côté client

- **Mes achats et garanties** (`GET account/purchases`) : commandes livrées, et pour chaque ligne la garantie réellement vendue (figée à la commande, jamais une garantie universelle inventée), la quantité et les numéros de série remis, et les dossiers ouverts.
- **Ouvrir un dossier** (`POST account/after-sales`) depuis une ligne livrée : manquant, dommage, non-conformité, panne ou autre, avec quantité (au plus ce qui a été remis), numéros de série exacts pour un article suivi, et description. La référence `SAV-…` est attribuée. La remise par code **ne vaut pas renonciation** à un défaut découvert ensuite ; un signalement dans les 24 heures est noté (`reported_quickly`), jamais exigé.
- **Joindre des photos ou documents** (pièces privées « claim_evidence », contrôlées par l'antivirus) et écrire au service client.
- **Accepter ou refuser** la solution proposée ; un refus permet une nouvelle proposition.
- Le client voit le statut, le prénom de son interlocuteur, la solution proposée avec son montant, l'état du remboursement et l'historique des messages. Il ne voit **ni les notes internes ni la responsabilité retenue**. Les dossiers d'un autre client restent introuvables.

## Côté service client (`support.read` / `support.write`)

- Liste et détail complet ; téléchargement des pièces jointes ; messages au client ou **notes internes**.
- Désignation de l'interlocuteur (une personne ayant `support.write`).
- **Proposition de solution** : échange, réparation, retour avec remboursement, remboursement (total par défaut, partiel si plus bas) ou autre solution. Elle s'accompagne de la **responsabilité retenue sur les faits** (fournisseur, transporteur, KORA, client, indéterminée) et de sa justification ; jamais de partage automatique 50/50. Le montant par défaut est exactement le prix payé pour ces unités, part de remise fidélité déduite, et ne dépasse jamais ce qui reste remboursable.
- **Refus motivé** du dossier, avec message au client.

Le propriétaire reçoit les droits SAV ; le rôle `support` les avait déjà.

## Retour, contrôle et remboursement

1. **Retour** (`stock.receive`) : les articles rendus rentrent dans **leur lot d'origine**, à l'état `returned` de l'emplacement choisi, avec un mouvement `customer_return`. Ils ne sont **pas vendables** à ce stade. Pour un article suivi, seules les séries du dossier, réellement vendues, sont acceptées. Les lots en dépôt-vente sont refusés : il faudrait d'abord corriger le relevé du fournisseur.
2. **Contrôle** (`stock.write`) : « revendable » (l'article redevient disponible) ou « défectueux » (mis de côté comme endommagé), avec une note obligatoire.
3. **Remboursement** (`payments.refund`, confirmation récente du mot de passe) : demandé par le flux habituel, plafonné à l'encaissé, après réception du retour si la solution l'exige. La tranche exacte des unités (montant, remise, points) est réservée pour la fidélité, comme pour une rupture fournisseur : avoir, remboursement et restitution des points restent cohérents. Le versement se constate ensuite avec sa preuve, comme tout remboursement.
4. **Clôture** (`support.write`) : possible seulement quand la solution a **réellement eu lieu** (remboursement versé, articles rendus réceptionnés et contrôlés), avec un message au client.

## Protections

Garde-fous SQL (PostgreSQL et SQLite) :
- un dossier ne se supprime pas et sa déclaration ne se réécrit pas ;
- une solution proposée exige la responsabilité et la date de décision ;
- un dossier clos est immuable ;
- les messages sont en ajout seul ;
- le contrôle d'un retour se fait une seule fois, avec note.

Ordre de verrouillage : commande → dossier → stock. Versions attendues et clé d'idempotence sur toutes les écritures.

## Migration, contrat et vérifications

Migration additive `2026_11_11_000039_create_after_sales`, appliquée à la base locale `kora` (vide). Contrat **0.36.0 / 409 chemins** (`update-after-sales-contract.mjs`, après celui des preuves de livraison) ; la chaîne rejouée donne une empreinte identique.

`AfterSalesTest` (2) couvre :
- achats et garanties, quantité excessive refusée, pièce jointe ;
- note interne invisible au client, prénom de l'interlocuteur, montant au-dessus du payé refusé, proposition acceptée ;
- remboursement refusé avant le retour, retour en quarantaine sans effet sur le stock vendable, clôture refusée tant que le remboursement n'est pas versé puis tant que le contrôle manque, contrôle « revendable » qui rend l'unité disponible, dossier résolu ;
- confidentialité entre clients, droits par rôle, refus motivé puis dossier figé, protections SQL.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites et restant obligatoire

- **Échange et réparation** : reprise chez le client et livraison de l'article d'échange ajoutées le 11 novembre (INCREMENT_LOGISTIQUE_SAV.md) ; le retour d'un article réparé au client reste à construire.
- **Décision du fournisseur** sur la garantie, retour au fournisseur depuis le stock défectueux (la fonction de retour fournisseur existe en stock mais n'est pas encore reliée au dossier) et frais assumés par chacun.
- **Remplacement d'une unité déjà sortie** au moment de la préparation : ajouté le 11 novembre (INCREMENT_LOGISTIQUE_SAV.md).
- Notifications au client (e-mail, WhatsApp) à chaque étape ; écrans client et support.
