# Retraits manuels et versements rapprochables

Suite backend des §17/§22, vérifiée le 5 novembre 2026. Contrat 0.27.0, 328 chemins. Ce module enregistre des demandes déjà effectuées auprès du prestataire et des versements réellement déclarés, avec preuves et examen. Il n'envoie aucune instruction à Monetbil et ne certifie aucun fonds disponible.

## Demande et examen

La source est un compte de type `monetbil`, la destination un autre compte `bank`, `mobile_money` ou `cash`. Les correspondances comptables doivent être actuelles, utilisables dans ce livre et de même orientation ; elles sont examinées avant usage réel. Les orientations débit et crédit sont supportées, sans mélange dans un transfert.

La demande fige montant entier XAF de 1 à 10^18, référence prestataire opaque sensible à la casse, correspondances, date comptable, instant réel déclaré `requested_at`, preuve privée `accounting_evidence` propre et motif. La date comptable est non future, dans une période ouverte, et au moins égale au jour local de la demande réelle. Les instants sont conservés en UTC ; les jours métier utilisent Africa/Douala. Les événements d'une demande ne peuvent être antidatés avant ses événements déjà enregistrés.

`pending` signifie demande externe déclarée en attente d'examen : son montant fait déjà partie des retraits attendus. Ce n'est pas un simple projet interne. L'approbation vérifie version, empreinte, preuve et correspondances actuelles. En réel, le créateur ne peut examiner sa demande. L'approbation ne marque aucun versement reçu.

## Brut, net et frais réels

Chaque réception partielle relie une écriture manuelle validée entière du même livre : sortie brute du compte prestataire, entrée nette du destinataire et frais sur les seules autres lignes non trésorerie. Brut = net + frais ; net strictement positif, frais positifs ou nuls, montants exactement acceptés et brut plafonné au reliquat.

Aucun autre compte de trésorerie, source métier, contrepassation ou écriture déjà utilisée n'est admissible. L'écriture et ses lignes restent réservées définitivement, même après correction. Une écriture utilisée pour une dépense ne peut être réutilisée. Une référence de versement ne peut désigner deux réceptions actives du même livre et compte source.

La preuve propre, `received_at` non futur et le motif complètent les empreintes immuables du journal et des correspondances. La réception ne peut précéder l'instant réel de demande ni être comptabilisée avant son jour local. En réel, l'examinateur diffère du créateur de demande et de l'auteur du posting.

Le journal existe déjà : aucun second transfert ni seconde charge n'est créé lors du rattachement. Le brut transféré n'est pas une nouvelle dépense de l'entreprise. Les frais réels déjà comptabilisés sont exposés séparément ; ils ne sont pas ajoutés une seconde fois aux engagements du prévisionnel. Leur affectation à un éventuel budget de frais n'est pas automatisée : ce budget et son reliquat doivent être examinés explicitement pour éviter de conserver un engagement devenu sans objet.

## Annulation, erreur de saisie et corrections

L'annulation externe documentée (`cancelled`) clôt seulement le reliquat exact accepté. Version, empreinte, preuve et motif sont obligatoires ; un changement concurrent du reliquat provoque un conflit 409. Les versements antérieurs restent conservés et la référence de demande reste réservée.

Une erreur de saisie (`void`) est distincte : tous les reçus actifs doivent être contrepassés avant cette correction. Un nouvel enregistrement peut reprendre la référence uniquement en nommant le dernier prédécesseur par `replaces_id`, avec une révision incrémentée et une date au moins égale à celle de correction. L'empreinte du prédécesseur est conservée. Une demande annulée ne peut être réactivée par ce mécanisme.

La correction d'un reçu produit une contrepassation comptable exacte liée et passe le reçu à `reversed` dans la même transaction. Le journal, la pièce et le snapshot originaux demeurent intacts. L'état interne `reversing` ne libère aucune capacité avant l'aboutissement de la contrepassation. Les contrepassations ordinaires de l'écriture initiale et de sa correction sont bloquées par le domaine et SQL.

Après correction, une nouvelle écriture peut reprendre la référence du versement seulement avec le dernier reçu contrepassé explicitement nommé. Une demande encore approuvée peut recevoir son reliquat. Après annulation, seul un remplacement du reçu de cette même demande est permis, avec un brut au plus égal au reçu corrigé : aucun nouveau versement sur la partie annulée. La correction ne ressuscite aucun montant en attente et n'émet aucun retour externe de fonds.

## Rapprochement des deux côtés

Le détail du reçu expose séparément les rapprochements actifs de la sortie prestataire et de l'entrée destinataire, avec leurs relevés et dates de fin. `both_closed` exige deux rapprochements sur des relevés clôturés et un reçu encore enregistré. Une clôture sur un seul côté ne suffit pas.

Toute correction exige d'abord l'annulation des rapprochements actifs des deux lignes ; un relevé clôturé doit être rouvert selon les règles existantes. Domaine et SQL protègent ce préalable, y compris si la contrepassation serait datée après le relevé. Une période fermée ou un autre blocage du journal annule toute la transaction.

Ce rapprochement reflète l'état opérationnel courant, sans constituer une archive historique ni une certification de disponibilité externe.

## Résumé et prévisionnel

Le résumé agrège tous les retraits du livre, indépendamment des listes paginées par 24 : brut historique, brut contrepassé, brut reçu net des corrections, net destinataire, frais, reçu rapproché des deux côtés, reliquats en attente d'examen/approuvés/annulés. Les totaux sont des chaînes exactes, même au-delà de l'entier natif. `remaining_gross` reste un reliquat théorique ; seul `pending_gross` indique la part actuellement attendue.

`scope=registered_withdrawals_only`, `state_basis=current_operational_snapshot`, `available_funds=null`, `availability_verified=false`, `provisional=true`. Un `as_of` non vide est refusé : aucun historique figé n'est simulé.

Le prévisionnel expose ce résumé dans `withdrawals`, en réel comme en démonstration sur le seul livre sélectionné. `cash.pending_withdrawals` est le brut encore attendu des demandes déclarées, y compris celles en attente d'examen ; zéro signifie aucune attente enregistrée dans ce périmètre. Les demandes externes non déclarées restent une lacune de couverture. Les fonds détenus, disponibles, retirables et `free_cash` restent inconnus ; un relevé rapproché ne les remplace pas.

## API, sécurité et persistance

Préfixe `/api/v1/operations/accounting/books/{book}/treasury` :

| Route | Permission |
| --- | --- |
| GET `withdrawals`, `withdrawals/{id}`, `withdrawals/{id}/receipts`, `withdrawal-summary` | accounting.read |
| POST `withdrawals` | accounting.write |
| POST `withdrawals/{id}/approve`, `cancel`, `void`, `receipts` | accounting.review |
| POST `withdrawals/{id}/receipts/{receipt}/reverse` | accounting.reverse |

Sessions HttpOnly, MFA, CSRF, idempotence UUID et confirmation du mot de passe depuis quinze minutes au plus pour les mutations. Une réponse idempotente rejouée reste sa réponse originale ; lire le détail pour consulter l'état courant. Lectures composées dans un snapshot cohérent et ressources hors livre refusées.

Migration additive `2026_11_05_000030_create_withdrawals`. Capacités, transitions, chronologie, références/révisions, immutabilité et exclusions de réutilisation protégées en SQLite/PostgreSQL. Mutations sérialisées par le verrou du livre partagé avec journal, rapprochements et calendrier ; les gardes PostgreSQL prennent aussi ce verrou.

Douze tests fonctionnels et deux nouvelles courses PostgreSQL couvrent les réceptions concurrentes et réception contre annulation du reliquat accepté. Résultats complets et preuves dans VALIDATION.md. Aucun paquet ni donnée financière réelle ajouté.

Restent notamment la disponibilité externe vérifiée, les intégrations prestataires réelles, les autres engagements/événements/valorisations, les états et exports complets et les interfaces. La priorité reste de terminer le backend avant de reprendre le front.
