# Couverture de trésorerie et ouvertures rapprochées

Suite backend des §17/§22 et de INCREMENT_REPORTS_TRESORERIE.md. Contrat 0.23.0, 308 chemins. Une obligation explicitement configurée permet de détecter les relevés manquants et de vérifier le lien entre leurs soldes et le journal validé. Aucun compte, montant d'ouverture, mouvement ou disponibilité n'est créé par cette configuration.

## Activation et examen

Le propriétaire prépare une version à partir d'un relevé d'ancrage non annulé et d'une pièce privée `accounting_evidence` propre, avec motif. La date de début est celle du relevé ; aucun montant n'est accepté en entrée. Le snapshot conserve la correspondance de comptes, le relevé, la pièce, l'ouverture calculée et leurs empreintes. Un brouillon `pending` ne change pas l'obligation active.

Le comptable examine la dernière version et son empreinte. En réel, le créateur ne peut approuver sa propre version, même s'il possède les deux rôles. Les périodes/exercices et relevés concernés déjà clôturés doivent être rouverts avant activation. Le solde d'ouverture doit être exact et sa provenance inchangée depuis la préparation.

Après la première approbation, la date de début est fixe : ni report vers une date plus récente pour masquer un manque, ni extension historique implicite. Une nouvelle version examinée peut remplacer la précédente à cette même date, après réouverture des clôtures concernées. Les anciennes versions restent conservées. Seuls les brouillons peuvent être annulés ; aucune désactivation d'une obligation approuvée par cette API. La désactivation du compte de trésorerie ne supprime pas les contrôles historiques.

## Solde d'ouverture et solde de clôture

Le solde comptable cumule toutes les lignes validées du compte lié, dans le même livre, sur tous les exercices : strictement avant la date de début pour l'ouverture, jusqu'à la fin du relevé pour la clôture. Débit moins crédit si les entrées sont au débit, sens inversé sinon. Les additions utilisent des entiers exacts ; aucun report automatique à zéro au changement d'exercice.

L'égalité exigée est : `solde comptable = solde du relevé + net des écritures en attente − net des lignes de relevé en attente`. À l'ouverture, les reports viennent de la dernière clôture du relevé précédent ; à la clôture, ils viennent des décisions examinées du relevé courant. Un écart empêche l'examen de l'ouverture ou la clôture. Les écritures d'ouverture et corrections nécessaires passent explicitement par le workflow comptable habituel.

Une empreinte ordonnée des lignes et écritures validées complète le montant et le compteur. Ajouter puis contrepasser une écriture avant l'ouverture rétablit le montant mais invalide quand même l'examen antérieur. La provenance inclut aussi la version et l'empreinte de la clôture précédente. Une nouvelle version examinée est alors requise.

Chaque nouvelle clôture couverte fige le rapprochement de solde dans `closure.snapshot.balance`, exposé aussi par `balance_reconciliation`. Les anciennes clôtures sans cette preuve ne sont pas modifiées et ne deviennent pas automatiquement vérifiées. Les corrections conservent les versions antérieures.

## Couverture du calendrier

Le rapport de couverture inspecte tous les jours depuis la date configurée jusqu'à la date demandée. Il expose les intervalles manquants, relevés ouverts et clôtures sans preuve correspondant à la politique active. Une période sans aucun relevé ne peut donc plus être clôturée silencieusement lorsqu'une obligation s'applique.

`complete=true` exige une ouverture toujours valide, aucune lacune, aucun relevé ouvert et aucune clôture non vérifiée. Sans configuration, ou pour une date antérieure au début, le rapport reste incomplet/non applicable. Le périmètre `configured_treasury_account` désigne uniquement le compte explicitement configuré, sans prétendre couvrir tous les comptes externes de l'entreprise.

La clôture d'une période ou d'un exercice exige cette couverture depuis le début de chaque obligation applicable jusqu'à sa fin. L'audit conserve les rapports et les références exactes des preuves : identifiants, versions et empreintes des relevés/clôtures. Une clôture d'exercice ne crée aucune écriture d'ouverture.

## Corrections et protection concurrente

Une écriture antérieure au début configuré modifierait tous les soldes suivants. Tant qu'un relevé couvert reste clôturé, son posting est interdit par le domaine et les déclencheurs SQL. Il faut rouvrir la chaîne concernée, effectuer la correction explicite, préparer puis examiner une nouvelle version d'ouverture avant de reclôturer. Les protections existantes continuent de bloquer les écritures à l'intérieur des intervalles rapprochés.

Toutes les mutations partagent le verrou du livre : examen d'ouverture, posting, clôture de relevé et de calendrier se sérialisent. Les politiques ont un contenu immuable, des transitions SQL bornées et une seule version courante par livre/trésorerie. Les lectures composées utilisent un snapshot transactionnel.

## Routes ajoutées

Préfixe : `/api/v1/operations/accounting/books/{book}/treasury`.

| Route | Permission |
| --- | --- |
| GET `coverage-policies` | accounting.read |
| GET `coverage?treasury_account_id=…&to=YYYY-MM-DD` | accounting.read |
| POST `coverage-policies` | accounting.settings |
| POST `coverage-policies/{id}/review` | accounting.review |
| POST `coverage-policies/{id}/void` | accounting.settings |

Session HttpOnly, MFA, CSRF ; mutations avec mot de passe confirmé depuis 15 minutes au plus, clé d'idempotence UUID et version. Examen avec empreinte de la politique. Les identifiants hors livre sont refusés ; les listes de politiques sont paginées. Le rapport porte sur le compte et la date expressément demandés.

## Migration, vérifications et limites

Migration additive `2026_11_04_000027_create_treasury_coverage`, sans dépendance supplémentaire ni activation automatique. Les suites SQLite/PostgreSQL, preuves et application à la base locale sont consignées dans VALIDATION.md après leur exécution. Les tests couvrent les lacunes sans relevé ouvert, écarts et changements d'ouverture, reports antérieurs, contrepassation de net nul, changement d'exercice et sens crédit, révisions, permissions, idempotence et garde-fous SQL. Deux courses PostgreSQL opposent examen/clôture et posting antérieur/clôture, avec constat des sessions réellement en attente du verrou.

Ce contrôle compare des écritures validées à des relevés transcrits et pièces examinées. Il ne certifie pas l'authenticité bancaire du relevé ou la disponibilité de l'argent : `available_funds=null`, `availability_verified=false`, `provisional=true`. Aucun raccordement bancaire/Monetbil réel, aucun paiement ni retrait. L'obligation couvre uniquement les comptes configurés et sa date fixe ; extension historique et suppression approuvée restent à concevoir explicitement.

Restent : disponibilité externe et fonds engagés, dépenses/frais/retraits, inventaire physique de caisse, allocations partielles, lettrage des tiers, autres événements/valorisations, états/exports complets et interfaces. Aucun scénario de lancement n'est déclaré complet sur cette seule preuve API.
