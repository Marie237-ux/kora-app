# Lettrage des auxiliaires clients et fournisseurs

Suite backend du §22, après les rapprochements de trésorerie. Contrat 0.24.0, 312 chemins. Le lettrage affecte des montants entre lignes comptables validées ; il ne déclenche ni paiement, ni remise, ni abandon de dette métier. Les interfaces restent à construire après les traitements backend, conformément à la priorité reconfirmée par Marie.

## Affectations partielles et totales

Un lettrage réunit de 2 à 200 lignes du même livre, du même compte auxiliaire et du même tiers (`type` et identifiant stable). Comptes et noms des tiers proviennent des instantanés comptabilisés : un changement ultérieur de nom, plan ou statut du compte ne réécrit pas les anciennes lignes. Les comptes ordinaires, brouillons, autres tiers/livres et lignes futures par rapport à la date choisie sont refusés.

Chaque affectation est une chaîne d'entier XAF positive, au plus le reliquat de la ligne. Les totaux affectés au débit et au crédit doivent être exactement égaux ; chaque côté est borné à 10^18 XAF, comme les opérations comptables ordinaires. Le rapport agrégé peut dépasser cette limite et conserve sa précision. Un paiement peut ainsi solder plusieurs factures, et une facture recevoir plusieurs règlements partiels. Aucun reliquat n'est arrondi ou ignoré.

Référence, motif et pièce privée `accounting_evidence` propre sont obligatoires. Le snapshot fige lignes, numéros et empreintes d'écritures, compte/tiers, date, preuve et montants. Le lettrage possède sa propre empreinte canonique. Un solde net nul ne lettre pas automatiquement les lignes : débit et crédit peuvent rester entièrement ouverts malgré leur compensation arithmétique.

## Dates, exercices et corrections

La date effective de création ou d'annulation doit appartenir à une période/exercice ouverts et suivre ou égaler toutes les dernières dates de création/annulation du lettrage du même livre. Les dates égales sont autorisées. Cette chronologie explicite empêche une nouvelle affectation rétroactive de consommer des montants déjà utilisés dans une situation historique. Elle est commune au livre, sans deviner une date à partir du serveur.

Les lignes sélectionnées peuvent appartenir à un exercice déjà clôturé : seule la date du lettrage doit être en période ouverte. L'ancien journal ne change pas, aucune réouverture de son exercice n'est exigée et aucune écriture d'ouverture n'est créée. Un lettrage ou une annulation à l'intérieur d'une période clôturée reste interdit.

Une annulation unique exige version, empreinte, motif et date effective. Elle conserve l'intégralité du snapshot, de l'empreinte et des membres et ajoute auteur/date de correction. Les montants redeviennent affectables à partir de cette date ; un nouveau lettrage est explicite. Une pièce mise en quarantaine après création n'empêche pas l'annulation corrective. L'ancienne preuve reste une trace historique, sans nouvelle certification de sa validité externe.

Les contrepassations comptables restent des écritures séparées. Elles ne modifient pas les affectations d'origine et leurs nouvelles lignes entrent dans le calcul des reliquats. Annuler/relettrer ou affecter ces nouvelles lignes relève d'une décision explicite. Aucune action ne met à jour l'échéancier fournisseur, l'état d'une facture, une commande ou un encaissement à partir du seul lettrage.

## Consultation à une date donnée

Le rapport `lettering-items` prend un compte et une date `as_of`, avec filtre facultatif de tiers. Il cumule les écritures validées connues de tous les exercices jusqu'à cette date, puis les affectations effectives à la même date. Une annulation au jour demandé est déjà déduite ; une annulation ultérieure ne change pas le résultat de la veille.

Chaque ligne expose identifiant stable, provenance comptable, montant affecté, reliquat et état `unmatched`, `partial` ou `matched`. Le filtre par défaut `open` masque les lignes totalement lettrées ; `all` les conserve. Les détails paginent par 24. Totaux et compteurs portent toujours sur toutes les lignes admissibles du compte/tiers, indépendamment du filtre d'affichage et de la page : débits/crédits, affectations, reliquats et solde net, calculés exactement.

`opening_scope=all_posted_years`, `totals_scope=all_eligible_lines`, `provisional=true` et `business_settlement_verified=false` explicitent le périmètre. La lecture reflète le journal connu au moment de la demande ; une nouvelle écriture validée ultérieurement à date ancienne peut enrichir ce rapport. Il ne s'agit pas d'une archive figée, d'un échéancier métier, d'une preuve d'encaissement ou d'un solde disponible.

## Routes et accès

Préfixe : `/api/v1/operations/accounting/books/{book}`.

| Route | Permission |
| --- | --- |
| GET `letterings`, `letterings/{id}` | accounting.read |
| GET `lettering-items?account_id=…&as_of=YYYY-MM-DD` | accounting.read |
| POST `letterings` | accounting.write |
| POST `letterings/{id}/void` | accounting.write |

Sessions HttpOnly, MFA et CSRF ; mutations avec confirmation du mot de passe depuis 15 minutes au plus et clé d'idempotence UUID. Historique paginé, filtrable par compte, tiers et état courant. Les ressources d'un autre livre sont refusées. Un rejeu idempotent conserve la réponse originale ; lire la ressource pour son état courant.

## Persistance et concurrence

Migration additive `2026_11_04_000028_create_accounting_lettering`, sans données ni dépendance supplémentaire. Groupes et membres restent conservés ; les déclencheurs SQLite/PostgreSQL protègent contenu, liens, montants, transitions, dates ouvertes, chronologie et capacités restantes. PostgreSQL prend également le verrou du livre dans ces gardes. Le statut interne `building` sert uniquement à assembler puis activer un groupe dans la transaction ; il n'est jamais publié par l'API.

Les écritures, créations/annulations de lettrage, import du plan et clôtures partagent le verrou du livre. Deux allocations partielles simultanées ne peuvent dépasser le montant disponible d'une même ligne. Une annulation et une nouvelle affectation se sérialisent sans réécrire la situation de la veille. Les lectures utilisent un snapshot transactionnel.

Vérifications définitives, résultats des suites complètes et application locale dans VALIDATION.md. Les tests ciblent paiements partiels/multi-factures, fournisseurs et clients, erreurs de portée/montants, historique daté, exercice clôturé, changement du plan, pièces, permissions, idempotence, montants agrégés supérieurs à 10^18 et pagination, ainsi que deux courses PostgreSQL avec attente réelle des sessions sur le verrou.

## Suite obligatoire

Les rapprochements banque/Monetbil/Mobile Money, les affectations métier de dettes et le lettrage comptable sont des processus distincts. Le lettrage ne prouve pas l'exhaustivité de la capture ni l'authenticité d'un paiement. Disponibilité réelle/fonds engagés, dépenses/frais/retraits, valorisation et autres événements, états/exports complets, interfaces et intégrations réelles restent obligatoires. Aucune certification réglementaire ou opération financière réelle déclarée.
