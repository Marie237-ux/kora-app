# Réparations et retours fournisseur — backend

Incrément du 13 novembre 2026, suite de INCREMENT_SAV.md et INCREMENT_LOGISTIQUE_SAV.md. Il ferme leurs deux limites :
- le retour au client d'un article réparé ;
- le renvoi au fournisseur d'une unité défectueuse, avec sa réponse sur la garantie.

## Réparation : l'unité du client reste la sienne

1. Dossier « réparation » accepté, unité reprise (déplacement de reprise) puis réceptionnée au dépôt (non vendable).
2. **Pas de contrôle de revente** : l'article du client en réparation ne peut pas être déclaré « revendable » ni renvoyé comme une unité défectueuse de KORA. Il suit la réparation.
3. **Ouverture de la réparation** (`REP-…`), avec le réparateur :
   - le fournisseur (garantie) ;
   - un réparateur tiers (nom obligatoire) ;
   - ou l'atelier interne.
4. **Remise au réparateur** avec un **bon** contrôlé par l'antivirus. L'unité passe à l'emplacement qui représente « chez le réparateur » : le stock dit toujours où elle est physiquement.
5. **Retour au dépôt** :
   - avec le **bon de retour** (obligatoire s'il était sorti) ;
   - l'**issue** : réparé ou non réparable, avec un motif ;
   - le coût éventuel et **qui le prend en charge** (fournisseur, KORA, client).
6. **Retour au client** : la même unité **sort du stock vers son propriétaire**, sans vente ni réservation (mouvement `repair_return`). Un livreur la remet avec un **code à usage unique** que le client voit dans son dossier. Ce déplacement entre dans la journée du livreur comme les autres.
7. **Clôture** du dossier seulement quand toutes les unités réparées sont **revenues chez le client**.

Le client voit l'étape et l'issue de sa réparation, jamais le réparateur, le coût ni les bons. Le fournisseur réparateur voit dans son portail (garanties) les réparations qui lui sont confiées, avec numéros de série et dates, sans données client.

## Retour au fournisseur d'une unité défectueuse

- Depuis un retour **contrôlé défectueux** :
  - seules ses unités mises de côté (stock « damaged » du même lot) peuvent partir ; le stock vendable est refusé ;
  - elles quittent le stock par la fonction de retour fournisseur existante, avec un bon et la référence du fournisseur ;
  - le renvoi est **rattaché au dossier**.
- **Réponse du fournisseur** sur la garantie : avoir, remplacement, réparation ou refus. Elle est enregistrée **une seule fois**, avec un motif et éventuellement sa preuve (droit achats).
- Un **avoir** n'est jamais supposé : il est saisi dans les dettes fournisseurs (lié à ce retour) et apparaît alors dans le dossier.
- L'équipe voit ces renvois dans le dossier ; le fournisseur les voit dans son portail.

## Migrations, contrat et vérifications

Migration additive `2026_11_13_000051_create_after_sales_repairs`, appliquée à la base locale `kora` (vide) :
- réparations et renvois au fournisseur ;
- lien d'un déplacement de livraison vers une réparation.

Garde-fous SQL :
- étapes de réparation cohérentes : bon exigé à l'envoi, issue au retour, date de remise au client ;
- réparation rendue figée ;
- réponse du fournisseur enregistrée une fois avec motif ;
- aucune suppression.
- La règle des déplacements accepte désormais une livraison qui porte **soit** un remplacement réservé, **soit** une unité réparée, jamais aucun des deux. Les tests SAV existants passent inchangés.

Contrat **0.45.0 / 565 chemins** (`update-after-sales-repairs-contract.mjs`) ; la chaîne rejouée donne une empreinte identique.

Tests `AfterSalesRepairsTest` (2) :
- **réparation complète par le fournisseur** :
  - refus du contrôle de revente et du renvoi comme défectueux ;
  - fournisseur exigé ; bon exigé à l'envoi ; unité à l'emplacement du réparateur ;
  - retour client refusé avant le retour de réparation ; réparation visible au fournisseur ;
  - bon de retour exigé ; clôture refusée avant la remise ;
  - sortie du stock vers le client, code, remise par le livreur, réparation « rendue », clôture ;
  - réparation figée ;
- **renvoi au fournisseur** :
  - stock vendable refusé, unité défectueuse sortie ;
  - réponse sans motif refusée, réponse unique ;
  - visible dans le dossier et le portail ; droits.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- Une unité **non réparable** est rendue au client si on le décide ; proposer ensuite un autre remède (échange, remboursement) passe par un nouveau dossier, la proposition d'un dossier accepté ne pouvant pas être changée.
- Le coût de réparation est enregistré mais pas encore comptabilisé, ni refacturé au client.
- Un **remplacement** accordé par le fournisseur se réceptionne par le parcours d'achat/réception habituel.
- Pas de notifications au client ni au fournisseur (canaux non configurés) ; aucun écran.
