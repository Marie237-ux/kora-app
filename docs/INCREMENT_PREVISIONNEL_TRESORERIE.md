# Prévisionnel consolidé des engagements connus

Suite backend du 5 novembre 2026, §17/§18/§22. Contrat 0.26.0, 320 chemins. Le prévisionnel réunit les échéances fournisseurs, achats engagés non encore reconnus, ventes consignées à rapprocher, remboursements demandés et budgets de dépenses du livre sélectionné. C'est une lecture des faits connus, pas une certification de trésorerie disponible ni une autorisation de décaissement.

## API et état de référence

`GET /api/v1/operations/accounting/books/{book}/treasury/forecast?from=YYYY-MM-DD&to=YYYY-MM-DD`, permission `accounting.read`, session HttpOnly et MFA. Dates inclusives, écart maximal de 366 jours. Aucune mutation, clé d'idempotence ou confirmation récente du mot de passe supplémentaire sur cette lecture ; les contrôles habituels restent requis pour les opérations financières séparées.

`state_basis=current_operational_snapshot` et `calculated_at` UTC désignent l'état métier au moment de la lecture. `from`/`to` classent les échéances ; ils ne reconstruisent pas l'état historique des dettes ou remboursements. Les filtres non vides `as_of`, `supplier_id` et `treasury_account_id` sont refusés : filtrer une seule source en conservant les autres aurait produit un faux total global. Les vues fournisseurs filtrées et le résumé historique des dépenses restent disponibles séparément.

Le livre réel est unique dans le modèle existant. Il inclut les données métier globales de production et uniquement ses propres dépenses. Un livre de démonstration expose `scope=book_expenses_only`, ses dépenses et les comptes qui lui sont liés ; fournisseurs, remboursements et collectes sont `null`, sans fuite des agrégats réels ni des dépenses d'autres livres. Aucun livre n'est créé automatiquement pour cette consultation.

## Engagements et échéances

Les échéances fournisseurs utilisent la projection existante : règlements et avoirs déjà affectés couvrent les premières échéances avant tout filtre de dates. Les obligations reconnues sans échéancier restent sans date. Achats confirmés non facturés, unités reçues admissibles et factures enregistrées non approuvées restent des engagements distincts des dettes reconnues, sans recompter les unités déjà facturées. Les ventes consignées sans relevé approuvé restent à rapprocher ; elles ne sont pas assimilées à des encaissements libres.

Les avances et avoirs non affectés sont montrés dans les totaux fournisseurs mais ne sont jamais déduits arbitrairement des autres dettes. Ils ne prouvent pas non plus qu'un montant est encore disponible sur un compte.

Les dépenses utilisent leurs dates effectives au jour du calcul à Douala : seuls les reliquats approuvés et non annulés à cette date sont engagés. Les sorties déjà comptabilisées et contrepassations réduisent ou rétablissent le reliquat à leur date comptable. Un budget annulé ne redevient pas engagé après correction d'un ancien règlement. Budgets en attente et approbations futures apparaissent séparément dans `pending_budget` et `not_yet_effective` ; ils ne sont pas ajoutés aux engagements actuels. Une annulation saisie à l'avance ne retire un budget en attente qu'à sa date effective. Le jour effectif commun est fixé au début du rapport, y compris pour le calcul des échéances fournisseurs échues.

Les remboursements XAF `requested` sont à financer ; `paid` est présenté séparément et `cancelled` est exclu. Aucune échéance de versement n'existe dans ce workflow : les demandes restent dans `undated`, sans transformer leur date de création en promesse de paiement. Un compteur signale les remboursements d'une autre devise, qui ne sont pas additionnés en XAF.

`before_window`, `within_window` et `after_window` répartissent les échéances fournisseurs et dépenses approuvées. `undated` réunit les obligations sans échéancier, achats non reconnus, ventes consignées à rapprocher et remboursements à assurer. Leur somme donne `known_outflows`. `past_due` est un indicateur recouvrant certaines de ces sommes et n'est donc pas ajouté une seconde fois. Les lignes quotidiennes sont triées par date et conservent séparément fournisseur et dépenses ; les montants sans date ne reçoivent pas de date artificielle.

Le rapprochement ne peut pas déduire qu'une saisie manuelle de budget représente la même obligation qu'un achat fournisseur. Utiliser chaque workflow pour son objet métier et traiter explicitement les doubles saisies ; le rapport ne fabrique pas de compensation entre sources sans lien.

## Encaissements et positions documentées

`collections.confirmed_collected` additionne seulement les tentatives confirmées en XAF, mode réel explicite, montant reçu connu, date de confirmation et identifiant prestataire présents. Les vrais seconds paiements avec références différentes restent distincts. Un même couple prestataire/identifiant confirmé plusieurs fois est ambigu : son groupe entier est exclu de la somme et signalé, plutôt que dédoublonné arbitrairement. Les paiements de test sont comptés séparément ; états à vérifier, contrepassés, mode inconnu ou confirmation incomplète sont signalés sans additionner un montant dont la devise effective peut être inconnue.

Ce total est un encaissement brut confirmé sur le périmètre décrit, pas un chiffre d'affaires, un solde prestataire, un montant retirable ou du cash actuel. Il n'est pas soustrait des engagements pour calculer de l'argent libre. Le rapport ne rappelle pas le prestataire et ne vérifie pas à nouveau les transactions externes. `unresolved_attempt_count` signale des tentatives non retenues dans ce total ; il ne signifie pas que tous leurs incidents restent ouverts.

Les comptes réels incluent les comptes sans correspondance ou obligation de couverture, pour que leurs lacunes restent visibles. Chaque position donne le dernier relevé clôturé achevé au plus tard aujourd'hui, avec identifiant, empreinte, solde et date, ainsi que le rapport de couverture existant au jour du calcul. Un relevé clôturé au 4 novembre ne devient pas un solde disponible du 5 novembre. Les soldes de relevés de dates différentes ne sont pas additionnés.

`cash.available_funds`, `provider_held_funds`, `withdrawable_funds`, `pending_withdrawals` et `free_cash` restent `null`, avec `availability_verified=false`. `coverage.complete=false` indique les soldes externes non vérifiés, retraits et autres engagements non intégrés. `provisional=true` reste explicite, même si un compte possède un rapprochement complet.

## Exactitude et cohérence

Les totaux du prévisionnel fournisseurs, des achats non reconnus et de la consolidation utilisent des entiers arbitraires et des chaînes décimales. Les contrats de lecture acceptent ces agrégats sans plafond de 10^18. Les plafonds des opérations, des prix unitaires, des quantités et des gardes de crédit existants restent inchangés ; aucune autorisation de dépense supplémentaire n'est créée par un grand total.

Toute la consultation, y compris le livre et les sources, s'exécute dans un `ReadSnapshot` : transaction PostgreSQL `REPEATABLE READ, READ ONLY`. Une demande de remboursement validée par une seconde connexion entre la lecture fournisseur et celle des remboursements n'apparaît qu'au rapport suivant. Aucun mélange de versions au sein d'une réponse. Cette lecture n'est pas une archive figée et les totaux ne sont pas tronqués par une pagination de 24 lignes.

Dix tests fonctionnels couvrent consolidation réelle, démonstration et livres isolés, fenêtres inclusives, dates effectives, annulations/corrections, avances non affectées, délais sans date inventée, contrôles d'accès, encaissements incomplets/test/doublons, relevés datés et agrégat exact de 10^19 XAF. Un test PostgreSQL utilise réellement une seconde connexion pour écrire entre deux sources. Résultats définitifs dans VALIDATION.md.

Aucune nouvelle migration, dépendance, identité commerciale, taxe, banque ou opération réelle ajoutée. Restent notamment la disponibilité externe vérifiée, les retraits manuels suivis et rapprochés, les autres engagements/événements/valorisations, états/exports complets et interfaces. La priorité reste le backend avant la reprise du front.

## Évolution 0.27.0 — retraits déclarés

Le champ withdrawals expose désormais le résumé des retraits manuels du livre sélectionné. cash.pending_withdrawals devient une chaîne exacte du brut encore attendu des demandes déclarées, y compris en attente d’examen ; zéro ne certifie pas l’absence de demandes externes non déclarées. Cette lacune reste explicite dans la couverture. Le transfert interne et ses frais déjà comptabilisés ne sont pas ajoutés aux nouveaux engagements known_outflows. Les fonds disponibles/détenus/retirables et free_cash restent inconnus. Les mentions null des retraits ci-dessus décrivent le contrat antérieur 0.26.0. Voir INCREMENT_RETRAITS_MANUELS.md.

## Évolution 0.28.0 — constats externes datés

Le champ balance_observations expose les justificatifs de solde examinés du périmètre sélectionné, leur expiration et les éventuels agrégats exacts à instant commun. Un montant à observed_at n’est pas extrapolé en solde actuel ; les champs cash disponibles/détenus/retirables et free_cash restent inconnus. Les retraits déclarés en attente conservent leur calcul précédent. Voir INCREMENT_CONSTATS_SOLDES.md.

## Évolution 0.29.0 — principal des financements reçus

Le champ financing et la colonne quotidienne financement ajoutent les seuls principaux réellement reçus encore dus, classés par échéance ou sans date. Apports, promesses non reçues et parties annulées non versées ne créent aucun remboursement attendu. Les financements n’ajoutent pas une seconde dette depuis le journal et ne deviennent pas du chiffre d’affaires ou du cash disponible. En démonstration, book_commitments_only remplace book_expenses_only, avec dépenses et financements du seul livre ; sources globales toujours exclues. Voir INCREMENT_FINANCEMENTS.md.
