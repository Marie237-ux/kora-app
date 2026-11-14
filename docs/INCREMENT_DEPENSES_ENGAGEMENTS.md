# Dépenses, frais et engagements documentés

Suite backend des §17/§22, terminée le 5 novembre 2026. Contrat 0.25.0, 319 chemins. Le module suit des budgets de frais prestataire, coûts de livraison et dépenses de fonctionnement, puis les relie à des sorties manuelles déjà comptabilisées. Il ne déclenche aucun paiement ni retrait. Les achats de marchandises, règlements fournisseurs et remboursements clients conservent leurs workflows distincts.

## Budget et engagement

Le budget immuable indique compte de trésorerie configuré, montant entier XAF positif au plus égal à 10^18, catégorie, description FR/EN, bénéficiaire déclaré, référence, date prévue, échéance, pièce privée propre et motif. La référence normalisée est unique par livre. Aucune taxe, qualification comptable ou identité de fournisseur n'est déduite du libellé.

`pending` ne crée aucun engagement approuvé. Le comptable examine empreinte, preuve actuelle et correspondance de compte avant de passer à `approved`. En réel, le créateur du budget ne peut l'approuver, même avec les deux rôles. Les dates doivent appartenir à une période/exercice ouverts. Le budget n'est ni une facture fournisseur, ni une dette reconnue, ni une écriture de charge ; sa comptabilisation éventuelle relève du journal et des schémas explicitement retenus.

Le reste engagé est le budget approuvé moins les sorties enregistrées nettes de leurs contrepassations. L'annulation exige version, empreinte, motif et acceptation exacte du reliquat courant. Si un règlement concurrent le modifie, réponse 409. Elle libère seulement le reste non dépensé à sa date : paiements et histoire restent conservés. Un budget entièrement dépensé ne peut être annulé sans correction préalable. Annuler un budget ne supprime aucune obligation d'achat ou dette métier.

## Règlements partiels et preuve comptable

Le comptable sélectionne une écriture manuelle validée du même livre, une ligne de trésorerie entière et une correspondance actuelle/examinée du même compte de trésorerie. Le montant doit être exactement accepté et ne pas dépasser le reste du budget. Une orientation d'entrées au débit attend une sortie au crédit ; le sens inverse attend une sortie au débit.

L'écriture doit être non contrepassée, sans source métier et sans autre ligne sur un compte de trésorerie configuré dans ce livre. Cela exclut notamment la réutilisation d'un règlement fournisseur issu d'une source et l'assimilation d'un transfert entre comptes à une dépense. Une écriture entière appartient à un seul règlement de dépense, définitivement dans son historique. Pour payer plusieurs budgets, préparer des écritures distinctes ; les paiements partiels d'un budget utilisent plusieurs écritures.

Une pièce `accounting_evidence` propre, un motif et la date réelle déclarée `paid_at`, non future, complètent la preuve. En réel, l'examinateur doit différer du créateur du budget et de l'auteur du posting. La vérification est `reviewed_manual_cash_posting`, avec empreinte du journal et de la pièce ; elle ne certifie pas un paiement externe auprès d'une banque ou de Monetbil.

L'enregistrement ne crée pas une seconde écriture : la sortie existe déjà dans le journal. Les sept familles de capture automatique existantes restent inchangées ; les budgets ne sont pas de nouveaux faits comptables à poster et leurs règlements font référence à des écritures manuelles existantes. Une éventuelle charge à payer doit être comptabilisée explicitement selon le paramétrage retenu, sans inférence depuis ce budget.

Une sortie déjà passée au journal mais supérieure au budget reste comptabilisée ; seul son rattachement est refusé. L'écart doit être traité explicitement avec le comptable. Ce module ne prétend pas empêcher un virement externe déjà survenu.

## Corrections atomiques

La route de correction de dépense produit une contrepassation exacte liée par le mécanisme comptable existant et passe le règlement à `reversed` dans la même transaction. Pièces, snapshot, empreinte et écriture initiale restent intacts. Les dates ne peuvent précéder les derniers événements du budget et doivent être en période/exercice ouverts. Une période ou un relevé rapproché qui bloque le posting fait échouer toute la transaction : aucun état intermédiaire ni engagement partiellement libéré.

La contrepassation ordinaire d'une écriture liée à un règlement enregistré est interdite par le domaine et SQL ; utiliser la route de dépense. La contrepassation produite ne peut elle-même être contrepassée par cette route ordinaire. Une nouvelle opération explicite doit représenter une nouvelle sortie réelle ; l'ancienne écriture ne redevient jamais affectable.

Le reliquat redevient engagé à la date de correction si le budget reste approuvé. Si le budget avait déjà été annulé, le montant reste annulé plutôt que de ressusciter un engagement. La contrepassation est une correction comptable : aucun remboursement ou retour de fonds externe n'est émis ou certifié.

## Consultation et périmètre

Listes de budgets triées par échéance et de règlements paginées par 24. Les détails exposent preuves, examen, corrections et soldes dérivés. Le résumé `as_of` additionne des entiers exacts sur tous les budgets admissibles : sorties enregistrées, contrepassations, net dépensé, reste, engagements et reste annulé. Les totaux peuvent dépasser 10^18 sans perte de précision. `remaining` comprend aussi le reste théorique des budgets en attente ; seul `committed` désigne le reste approuvé non annulé.

Les budgets sont pris en compte depuis leur date prévue, puis leurs approbations/annulations à date. Les paiements suivent la date du journal de trésorerie, distincte du `paid_at` déclaré ; une correction ultérieure ne retire pas le paiement d'une consultation antérieure. La lecture reflète les faits connus au moment de la demande et n'est pas une archive figée.

`scope=expense_plans_only`, `date_basis=posted_cash_entry`, `available_funds=null`, `availability_verified=false`, `external_payment_verified=false`, `provisional=true`. Ce résumé n'ajoute pas les achats, échéanciers fournisseurs, remboursements, retraits ou autres fonds engagés. Il ne constitue donc ni disponibilité vérifiée, ni prévisionnel global, ni certification comptable.

## API et persistance

Préfixe `/api/v1/operations/accounting/books/{book}` :

| Route | Permission |
| --- | --- |
| GET `expenses`, `expenses/{id}`, `expenses/{id}/payments`, `expense-summary` | accounting.read |
| POST `expenses`, `expenses/{id}/cancel` | accounting.write |
| POST `expenses/{id}/approve`, `expenses/{id}/payments` | accounting.review |
| POST `expenses/{id}/payments/{payment}/reverse` | accounting.reverse |

Sessions HttpOnly, MFA et CSRF ; mutations avec clé d'idempotence UUID et mot de passe confirmé depuis quinze minutes au plus. Lectures composées en snapshot, ressources hors livre refusées. Les mutations partagent le verrou du livre avec journal, configuration et clôtures. Un rejeu idempotent retourne sa réponse originale ; lire le détail pour obtenir l'état courant.

Migration additive `2026_11_04_000029_create_expense_plans`, commencée le 4 novembre et vérifiée le 5. Contenus, membres, dates et transitions protégés en SQLite/PostgreSQL ; clés uniques empêchant la double affectation d'une écriture, même après correction. Les gardes SQL PostgreSQL acquièrent aussi le verrou du livre. Le statut interne transitoire `reversing` reste dans la transaction et compte toujours comme dépensé ; il ne libère pas de capacité avant la contrepassation aboutie.

Résultats définitifs dans VALIDATION.md. Deux courses PostgreSQL constatent les sessions réellement en attente : deux affectations ne peuvent dépasser le budget ; règlement et annulation du reliquat accepté ne peuvent réussir avec des montants incohérents. Aucun nouveau paquet, compte, montant initial ou mouvement réel ajouté.

Restent : disponibilité externe vérifiée, prévisionnel global et autres fonds engagés, retraits, imports prestataires, autres événements/valorisations, états et exports complets, interfaces. La priorité reste de terminer le backend avant de reprendre le front.
