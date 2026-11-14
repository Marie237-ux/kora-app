# Factures et avoirs client — backend

Les documents de vente sont établis sous l'identité de l'exploitant réel, paramétrée dans la gestion. Rien n'est inventé : tant que cette identité est incomplète, chaque document est une démonstration clairement marquée.

## Identité de l'exploitant

`PUT operations/settings/billing` (permission `billing.settings`, propriétaire ; mot de passe reconfirmé depuis 15 min au plus ; versionné et audité). Champs : raison sociale, nom commercial, forme, NIU, RCCM, adresse, ville, contacts, régime fiscal (`unset`, `vat`, `no_vat`), taux de TVA en points de base et mentions FR/EN.

- Champs requis : raison sociale, NIU, RCCM, adresse, ville et un régime fiscal choisi. Tant qu'il en manque, `missing` les liste et les documents émis portent le bandeau **« DOCUMENT DE DÉMONSTRATION »**, avec leur propre numérotation `DEMO-FAC-2026-000001`. La vraie séquence `FAC-2026-000001` reste vierge.
- **Aucune TVA par défaut.** Elle n'apparaît que sous le régime `vat` avec un taux explicite. Les prix publics sont TTC : la TVA en est extraite, arrondie au franc (demi supérieur), sans débordement d'entier même à 10^18 XAF.

## Facture et avoir

- **Facture** : brouillon pour une commande payée (une par commande), puis émission (`billing.issue`, mot de passe reconfirmé). L'émission alloue le numéro sous verrou et fige tout le contenu : vendeur, client, lignes FR/EN, garantie, livraison, totaux, paiements reçus, mentions, empreinte du logo. Le tout est stocké avec son empreinte SHA-256.
- Un document émis n'est **jamais modifié ni supprimé**. Changer d'identité ou de logo ne touche pas aux documents passés : le logo utilisé est conservé à part, par empreinte.
- **Avoir** : établi pour un remboursement réellement versé, lié à la facture émise et limité à son montant non encore crédité (`AV-2026-000001`). Un remboursement de trop-perçu ne relève pas d'un avoir : cet argent n'a jamais fait partie de la vente.
- **Français/anglais** : deux rendus du même document figé, avec le même numéro et les mêmes montants.

## Accès et PDF

PDF générés à la demande depuis l'instantané figé, avec dompdf 3.1.6 : aucune ressource distante, pas de PHP embarqué, contenu échappé. Le client ne voit que les documents émis de ses propres commandes, et l'accès est contrôlé à chaque téléchargement. La gestion (`billing.read`) peut prévisualiser un brouillon, marqué « BROUILLON ». Réponses `private, no-store`.

Rôles : `billing.read` (propriétaire, gestionnaire, support, comptable), `billing.issue` (propriétaire, comptable), `billing.settings` (propriétaire).

## Vérifications

`CustomerDocumentsTest` (4 tests) : démonstration tant que l'identité est incomplète, aucune TVA par défaut, émission unique, facture figée face à un changement d'identité, TVA 19,25 % sur 2 500 → 404 / 2 096, avoir lié et plafonné, confidentialité client/brouillon/permissions, extraction de TVA exacte jusqu'à 10^18. Exemplaire PDF contrôlé visuellement. Contrat 0.12.0 / 212 chemins (`scripts/contract/update-documents-contract.mjs`).

## Restant obligatoire

- Validation du régime fiscal, des mentions et du dispositif de facturation électronique réellement applicable par l'exploitant et son comptable. Le pied de page le rappelle : un PDF interne n'est pas à lui seul une facture fiscale conforme.
- Reçu d'encaissement, confirmation de commande et bon de livraison ; génération asynchrone pour les gros volumes ; export comptable (§22).
- Écrans de gestion et espace client « Mes factures ».
