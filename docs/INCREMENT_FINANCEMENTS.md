# Avances du fondateur, apports et emprunts monétaires

Suite backend des §17/§18/§22, 5 novembre 2026. Contrat 0.29.0, 340 chemins. Les accords documentés distinguent `founder_advance`, `capital_contribution` et `loan`. Les versements reçus et remboursements de principal sont reliés à des écritures manuelles déjà validées ; aucun transfert externe ou second posting n'est produit.

## Termes et qualification explicites

L'accord fige référence interne unique par livre, nature déclarée, nom déclaré du financeur, compte de contrepartie, principal entier XAF de 1 à 10^18, date effective non future, échéance unique ou absence de date, preuve privée `accounting_evidence` propre et motif. L'échéance ne précède pas la date effective ; elle est obligatoirement absente pour un apport.

La contrepartie est un compte actif/postable sans auxiliaire, de type `liability` pour avance/emprunt ou `equity` pour apport, distinct de toute trésorerie configurée dans le livre. Le plan est examiné avant usage réel. Aucun numéro de compte, taux, taxe, identité légale ou régime de financement n'est inventé. La nature est choisie et examinée selon les pièces réelles ; elle ne constitue pas une qualification juridique automatique.

Le périmètre suit uniquement le principal monétaire, non renouvelable, à échéance unique ou non datée. Les souscriptions et opérations juridiques de capital, crédits renouvelables, échéanciers enrichis, calculs d'intérêts, frais et dépenses réglées directement par le fondateur pour le compte de KORA restent des traitements distincts. Une dépense payée personnellement ne doit pas être transformée en fausse entrée de trésorerie KORA.

L'accord `pending`, puis son approbation, ne créent ni fonds reçus, ni dette de principal déjà financée, ni bénéfice, ni écriture. En réel, l'examinateur diffère du créateur, même si les rôles sont cumulés. L'examen vérifie empreinte, preuve actuelle et contrepartie. Sa date effective est non future, au moins égale à celle de l'accord et en période ouverte ; l'instant administratif de revue est conservé séparément.

Les termes initiaux restent immuables. Une erreur exige annulation de la partie non versée et, si nécessaire, correction préalable des mouvements puis un nouvel accord documenté avec une nouvelle référence interne. La référence initiale reste réservée ; aucune réécriture silencieuse d'un contrat financé.

## Fonds reçus et principal remboursé

Un mouvement sélectionne une écriture manuelle validée entière de deux lignes dans le même livre : trésorerie sur un compte d'actif à entrées au débit, et contrepartie de dette/capitaux propres prévue. Le versement débite la trésorerie et crédite la contrepartie ; le remboursement inverse les deux lignes. Les correspondances d'entrées au crédit ne sont pas prises en charge par ce workflow. Une configuration incompatible est refusée explicitement.

L'écriture ne comporte aucune source métier, contrepassation, autre trésorerie, frais ou intérêt. Elle n'a jamais été utilisée dans un financement, une dépense ou un retrait, même corrigé. Les frais et intérêts réellement survenus doivent avoir leurs écritures et justificatifs distincts ; les rapprochements existants permettent de grouper plusieurs écritures avec une ligne de relevé. Une entrée refusée au rattachement reste dans le journal : ce module ne prétend pas empêcher une opération externe déjà survenue.

Le principal est exactement accepté. Les réceptions cumulées nettes des corrections ne dépassent jamais le montant convenu. Les remboursements cumulés ne dépassent jamais le principal reçu net. Rembourser ne reconstitue aucune capacité de financement : une nouvelle avance ou un renouvellement exige un autre accord.

Un apport reçu est exposé comme `capital_received`, sans dette ordinaire de remboursement. La route de remboursement d'emprunt lui est interdite. Une correction de saisie comptable ne vaut pas opération juridique de restitution du capital et n'émet aucun paiement.

Preuve propre, référence opaque sensible à la casse, motif et instant réel déclaré `occurred_at` non futur complètent le snapshot immuable du journal. Le jour local de l'événement est au moins celui de l'accord et au plus celui du posting. Les dates comptables sont non futures, non décroissantes par accord et en période ouverte. Les instants sont UTC, les jours métier utilisent Africa/Douala. En réel, l'examinateur diffère du créateur de l'accord et de l'auteur du posting.

## Annulation et corrections

L'annulation clôt uniquement la partie non versée, exactement acceptée par `accepted_undrawn`. Un versement concurrent qui change ce reste provoque un conflit 409. Preuve, motif, auteur et date sont conservés. Le principal reçu reste dû ; ses remboursements demeurent possibles après annulation. Un accord entièrement versé n'est pas annulable par cette route.

La correction d'un mouvement produit une contrepassation exacte liée et passe le mouvement à `reversed` dans la même transaction. L'état interne `reversing` compte encore dans les totaux jusqu'à l'aboutissement. Une réception ne peut être corrigée si le principal reçu restant tomberait sous le principal déjà remboursé : corriger d'abord les remboursements concernés. Une correction de remboursement rétablit la dette, même après annulation de la partie non versée.

Tout rapprochement actif de la ligne de trésorerie doit être annulé avant correction ; un relevé clôturé doit d'abord être rouvert. Les gardes domaine/SQL empêchent le contournement par une contrepassation ordinaire ou par la contrepassation du contre-mouvement. Un calendrier fermé ou une autre erreur du journal annule toute la transaction.

Une écriture initiale ne redevient jamais utilisable. La référence de mouvement, unique active par livre et compte de trésorerie, ne peut être reprise qu'avec le dernier mouvement contrepassé explicitement nommé, de même direction, avec sa révision suivante et une date non antérieure à la correction. Cela permet une reclassification documentée vers un nouvel accord, sans effacer l'ancienne histoire. Après annulation, un versement n'est admis qu'en remplacement d'un mouvement de ce même accord, sans augmenter son montant ; la capacité annulée ne revient jamais en financement attendu.

Une sortie ne peut appartenir à la fois à un remboursement de principal et à un règlement de dépense. Cette exclusion est réciproque dans le domaine et en SQL. Les achats, remboursements clients et règlements fournisseurs conservent leurs workflows et sources distincts.

## Soldes et prévisionnel

Le résumé rassemble tous les accords du livre, indépendamment des listes paginées par 24, et distingue chaque nature. `funded_principal` et `repaid_principal` sont nets des corrections. `funded_unrepaid` représente leur différence, y compris les apports ; seul `outstanding_principal` est une dette remboursable, nulle pour les apports. `undrawn_principal` reste un reliquat théorique ; `pending_funding` indique seulement le non-versé d'un accord approuvé et non annulé.

Les dettes de principal réellement financées sont classées selon leur échéance, sans date fabriquée. Elles rejoignent le prévisionnel, ses regroupements quotidiens et `known_outflows`, sans ajouter une seconde dette depuis le solde du journal. Les financements prévus mais non reçus, apports et parties annulées non versées ne créent aucun remboursement attendu. Les engagements futurs de frais/intérêts doivent être documentés séparément, notamment dans les dépenses prévues, sans double rattachement.

Les agrégats sont des chaînes entières exactes, même au-delà de l'entier natif. Une dette réellement constatée ne disparaît pas parce que sa correspondance est ensuite archivée ou sa preuve remise en quarantaine : ces faits et le journal doivent être traités explicitement, sans annulation automatique de dette.

En démonstration, le prévisionnel prend désormais le périmètre `book_commitments_only` : dépenses et financements du seul livre, sans sources métier globales. Ce libellé remplace `book_expenses_only` dans le contrat 0.29.0 ; les lignes quotidiennes ajoutent `financing`. Les livres réels conservent `known_operational_commitments`. La lecture composée partage un snapshot cohérent.

`scope=registered_principal_financing_only`, `state_basis=current_operational_snapshot`, `available_funds=null`, `provisional=true`. Un paramètre `as_of` non vide est refusé : ce résumé opérationnel n'est pas une archive historique figée. Les fonds reçus ne sont ni du chiffre d'affaires, ni automatiquement de l'argent libre ; trésorerie actuelle et `free_cash` restent inconnus.

## API et persistance

Préfixe `/api/v1/operations/accounting/books/{book}/treasury` :

| Route | Permission |
| --- | --- |
| GET `financings`, `financings/{id}`, `financings/{id}/movements`, `financing-summary` | accounting.read |
| POST `financings` | accounting.write |
| POST `financings/{id}/approve`, `financings/{id}/cancel`, `financings/{id}/movements` | accounting.review |
| POST `financings/{id}/movements/{movement}/reverse` | accounting.reverse |

Sessions HttpOnly, MFA, CSRF, idempotence UUID et confirmation du mot de passe depuis quinze minutes au plus pour les mutations. Ressources hors livre/accord refusées. Une réponse idempotente rejouée conserve son état original ; consulter le détail ou le résumé pour obtenir l'état courant.

Migration additive `2026_11_05_000032_create_financings`. Termes, historique, références, périmètres, transitions, calendrier et capacités protégés en SQLite/PostgreSQL. Mutations et gardes PostgreSQL prennent le verrou du livre, partagé avec journal et rapprochements. Les clés autoréférentes sont ajoutées après création des tables sur PostgreSQL.

Treize tests fonctionnels, deux courses PostgreSQL et un snapshot avec remboursement engagé sur une seconde connexion vérifient ces règles. Les échéances avant/dans/après fenêtre, bornes inclusives, principal échu et sans date sont distingués sans ajouter de financement non reçu. Résultats complets dans VALIDATION.md. Les sept familles de capture existantes restent inchangées : les mouvements de financement font déjà référence au journal, sans second posting. Aucun paquet ni donnée financière réelle ajouté.

Restent notamment financement direct de dépenses pour compte de KORA, échéanciers/conditions financières enrichis et opérations juridiques spécifiques, vérification des prestataires réels, autres engagements et événements/valorisations, états/exports complets et interfaces. Priorité backend avant reprise du front maintenue.
