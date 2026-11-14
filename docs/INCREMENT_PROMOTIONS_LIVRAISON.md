# Promotions de livraison — backend

Reprise du travail de Claude le 4 novembre 2026. Cet incrément couvre les campagnes de livraison du §16 ; le moteur commercial complet et ses écrans restent obligatoires avant lancement.

## Configuration et accès

Routes sous `/api/v1/operations/promotions/delivery-offers` : liste paginée (24), création de brouillon, modification par `PUT /{id}`, puis `POST /{id}/activate`, `/pause` et `/end`.

- Nom FR/EN, début et fin facultative, zones, seuil unitaire, utilisations par client, budget et couverture sont explicites. Les dates avec fuseau sont normalisées en UTC.
- Pour l'offre souhaitée, configurer 3 utilisations et un seuil de 5 000 XAF. Le socle évalue le prix unitaire effectivement payé après remises (`threshold_basis: paid_unit_price`), parmi les lignes sélectionnées ; un total panier élevé ou un prix de référence supérieur ne suffisent pas. Cette base de calcul est exposée au client et à la gestion ; le choix d'une autre base n'est pas encore proposé.
- `full` couvre tout le devis, suppléments compris ; `base` couvre uniquement son forfait. Les suppléments restent annoncés dans `payable_delivery` et `remaining_delivery`. L'interface devra réserver « livraison gratuite » à une couverture réellement totale.
- Financeur actuellement pris en charge : KORA. Aucun budget, tarif ou campagne réelle n'est créé automatiquement ; les valeurs des fixtures sont exclusivement des tests.
- Propriétaire : `promotions.manage` et `promotions.read`. Gestionnaire : lecture seule. Session avec MFA requise ; reconfirmation du mot de passe depuis 15 minutes au plus pour activer, suspendre ou terminer. Écritures versionnées, idempotentes et auditées.

## Devis, commande et paiement

Le devis conserve l'avantage proposé, son identité, sa version et ses conditions. Il distingue livraison brute (`total`), remise et livraison nette (`payable_delivery`) sans divulguer les négociations fournisseur.

La création de commande revalide l'offre sous verrou : dates, zone, seuil, quota individuel et budget global. Une modification de campagne, même si la remise reste identique, impose un nouveau devis. Le total exact doit toujours être accepté. Une seule utilisation est liée à chaque commande, quel que soit le nombre de collectes ou de futures livraisons partielles.

Une réservation de paiement compte immédiatement dans le quota et le budget. Elle devient consommée seulement au paiement confirmé côté serveur. À expiration, elle cesse de bloquer des droits ; `payments:reconcile` la marque libérée. Un paiement échoué laisse la commande réessayable jusqu'à cette expiration. Mettre une campagne en pause ne retire pas les avantages déjà promis. Une modification du budget sous les montants réservés et consommés est refusée.

Un paiement tardif conserve le prix déjà accepté. Si le budget libéré a été repris entre-temps, la consommation est enregistrée avec un incident `promotion_budget_exceeded`, sans nouveau prélèvement au client. La gestion constate les montants réels, même au-delà du budget ; le disponible ne devient jamais négatif. Une notification rejouée ne crée pas de seconde consommation ni de second incident pour ce paiement.

La confirmation de paiement prend les verrous panier, devis et promotion avant une éventuelle nouvelle réservation de stock, comme la création de commande. Cette organisation évite une inversion introduite par l'ajout des campagnes.

## Commande et documents

Les conditions de l'avantage sont figées dans la commande. La facture conserve les frais bruts, la remise nommée et le net ; ses rendus PDF FR/EN utilisent le même instantané. Terminer ou modifier la campagne ne réécrit pas la facture émise.

Contrat OpenAPI **0.13.0 / 217 chemins**, types TypeScript régénérés. Script : `scripts/contract/update-promotions-contract.mjs`, à exécuter après les scripts livraison, commandes et factures. Il peut être rejoué sans dupliquer ses ajouts.

## Vérifications

`DeliveryOffersTest` comporte 10 tests : trois utilisations au maximum, seuil sur le prix payé et les seules lignes sélectionnées, zone, couverture complète et forfait seul, réservation/libération, suspension/fin, dates et fuseaux, confirmation récente, changement de conditions, baisse du budget, paiement tardif/rejeu et facture figée FR/EN.

Deux tests de `PostgresConcurrencyTest` lancent de vrais processus PHP indépendants : deux clients pour le dernier budget et deux commandes d'un même client pour sa dernière utilisation. Les deux sessions doivent être observées en attente sur le verrou de campagne ; une seule réservation est acceptée. Ils prouvent la réservation du domaine, pas un parcours complet de deux encaissements Monetbil réels.

Suites complètes du 4 novembre : PostgreSQL, 194 tests et 3 300 assertions, tous réussis ; SQLite, 183 exécutés et 11 réservés à PostgreSQL, 3 122 assertions, aucun échec. Huit tests npm, TypeScript/Astro, synchronisation OpenAPI et formatage Prettier/Pint réussis. Rapports et limites dans [VALIDATION.md](VALIDATION.md).

## Restant obligatoire du §16

- Codes promotionnels, remises produit et quantité, filtres catégorie/produit/client, cumul, coupons fidélité et parrainage, campagnes événementielles et vue de marge avant activation.
- Autres financeurs et répartition des contributions ; choix administratif de la base du seuil si d'autres règles sont retenues.
- Annulations générales et règles de remboursement, dont échecs de remise imputables à l'exploitation. L'incrément suivant couvre déjà la restitution unique d'un avantage consommé après annulation totale pour rupture fournisseur et remboursement vérifié (INCREMENT_CONFIRMATIONS_COMMANDES.md) ; les autres cas restent à construire.
- Écrans FR/EN clair/sombre de configuration, détail client avant paiement et suivi des consommations ; validation interactive et intégration Monetbil réelle.

Suite réalisée : confirmations fournisseur reliées aux lignes de commande et choix client face aux ruptures (INCREMENT_CONFIRMATIONS_COMMANDES.md). Prochaine dépendance : affectations achats/réceptions vers les lignes client, puis devis professionnels. Les autres exigences restent dans EXIGENCES.md.
