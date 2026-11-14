# Constats datés des soldes externes

Suite backend des §17/§18/§22, 5 novembre 2026. Contrat 0.28.0, 333 chemins. Le module conserve des justificatifs de solde déjà obtenus, leur examen et leurs corrections, puis les expose dans le prévisionnel. Aucun appel de consultation du solde prestataire, transfert ou mouvement comptable n'est effectué.

## Nature des montants

Le solde déclaré est un entier signé XAF borné à ±10^18. Sur un compte Monetbil, il représente les fonds déclarés détenus chez le prestataire ; la part retirable est explicitement renseignée entre zéro et ce solde non négatif. Ce montant retirable est un sous-ensemble, jamais une somme à ajouter aux fonds détenus. `available_funds` reste null sur ce compte.

Sur un compte banque, mobile money ou caisse, `available_funds` désigne les fonds propres utilisables déclarés, hors crédit et découvert : de zéro au solde positif. Un solde négatif est conservé avec zéro fonds propres disponibles. `withdrawable_funds` reste null. Le module ne modélise pas une autorisation de crédit ou une compensation entre comptes.

Chaque champ inconnu reste null. Zéro exige un constat examiné qui prouve le montant zéro ; l'absence de constat ne vaut jamais zéro. Les montants sont des déclarations appuyées par un justificatif et une revue humaine, sans certification automatisée par le prestataire.

## Instant et durée de validité

Le constat fige une correspondance comptable actuelle/examinée, référence, solde, montant propre disponible ou retirable selon le compte, instant réel déclaré `observed_at`, expiration explicitement choisie `expires_at`, preuve privée `accounting_evidence` propre et motif. Les instants UTC sont normalisés à la seconde.

L'observation ne peut être future ni antérieure à celle du dernier constat de ce compte dans ce livre. L'expiration suit strictement l'observation, dans les vingt-quatre heures suivantes au maximum. Cette limite borne la fenêtre de revue ; elle ne garantit pas l'absence de mouvements pendant la fenêtre. Aucune durée par défaut n'est inventée. Un constat déjà expiré reste enregistrable dans l'historique, mais ne peut être approuvé.

Les montants décrivent uniquement l'instant observé. Un paiement ou retrait ultérieur ne met pas à jour cette photographie. Le module ne la présente pas comme le solde actuel et ne soustrait aucun mouvement ultérieur à partir de données potentiellement incomplètes.

## Séquence, examen et correction

La création accepte exactement la dernière `sequence` du compte, zéro initialement, puis l'incrémente. Deux créations concurrentes acceptant la même séquence ne peuvent réussir ensemble. Le dernier constat est choisi par cette séquence, indépendamment de la pagination et de son statut.

L'approbation exige version, empreinte et motif ; elle recontrôle la preuve, son empreinte et les correspondances actuelles. Seul le dernier constat non expiré peut être approuvé. En réel, le créateur ne peut l'examiner, même s'il possède aussi le rôle comptable. Une approbation simultanée à une nouvelle saisie peut conserver l'ancien examen dans l'histoire, mais le résumé utilise toujours la nouvelle saisie en attente.

Une correction `void` conserve le snapshot initial et ajoute auteur, instant, motif et nouvelle preuve propre avec son empreinte. En réel, son auteur diffère du créateur. Un nouveau constat est nécessaire pour présenter un autre montant.

Aucune correction, expiration, mise en quarantaine du justificatif ou nouvelle attente d'examen ne fait réapparaître un ancien solde approuvé. Le détail historique peut encore porter `approved` ; le résumé réévalue son utilisation selon l'expiration, les preuves et la configuration en vigueur.

## Résumé et prévisionnel

En réel, le résumé inclut tous les comptes de trésorerie actifs, même sans correspondance ou constat ; les manques restent visibles. En démonstration, seuls les comptes actifs liés au livre sont inclus. Les comptes inactifs sont hors de ce périmètre ; leur historique reste consultable. Un périmètre vide n'est pas une couverture complète.

Les états possibles sont `missing`, `pending_review`, `void`, `expired`, `configuration_changed`, `evidence_unavailable`, `reviewed_fresh`. Ce dernier signifie justificatif examiné, encore dans sa fenêtre de validité, et configuration exploitable ; il ne signifie pas consultation externe du solde actuel.

Les totaux ne sont calculés que si tous les comptes du périmètre sont exploitables et portent exactement le même instant d'observation. Sinon `as_of` et tous les totaux restent null : des photographies prises à des instants différents ne sont pas additionnées. Les agrégats exacts peuvent dépasser l'entier natif ; les limites par opération ne changent pas.

Le résumé distingue fonds propres disponibles sur les comptes hors Monetbil, fonds détenus chez Monetbil et leur part retirable. Il n'ajoute pas les encaissements client, les retraits attendus ou reçus, les dettes ni les avances à ces soldes. Aucun double comptage de transfert, nouvelle écriture ou compensation automatique.

Le prévisionnel expose le résumé dans `balance_observations`, dans le même snapshot de lecture que ses autres sources. Les champs de trésorerie actuelle `cash.available_funds`, `provider_held_funds`, `withdrawable_funds` et `free_cash` restent inconnus ; `current_availability_verified=false`, `provisional=true`. Le champ des retraits déclarés en attente conserve sa règle existante. Les constats datés ne rendent pas la couverture globale des engagements complète.

## API et persistance

Préfixe `/api/v1/operations/accounting/books/{book}/treasury` :

| Route | Permission |
| --- | --- |
| GET `balance-observations`, `balance-observations/{id}`, `balance-observation-summary` | accounting.read |
| POST `balance-observations` | accounting.write |
| POST `balance-observations/{id}/approve`, `balance-observations/{id}/void` | accounting.review |

L'historique est paginé par 24, filtrable par compte, trié par instant décroissant puis séquence décroissante par compte. Le résumé porte l'état connu courant et les instants explicitement attachés aux constats ; un paramètre `as_of` non vide est refusé. Une réponse rejouée par idempotence conserve son état original ; relire le détail ou le résumé pour consulter l'état actuel de la revue.

Sessions HttpOnly, MFA, CSRF, idempotence UUID et mot de passe confirmé depuis quinze minutes au plus pour les mutations. Livre étranger refusé. Les écritures utilisent le verrou partagé du livre. Migration additive `2026_11_05_000031_create_balance_observations` : historique immuable et transitions, montants, dates, séquences, périmètres, preuves et configuration protégés en SQLite/PostgreSQL. Les gardes PostgreSQL prennent aussi le verrou du livre.

Les vérifications comprennent douze tests fonctionnels, deux courses PostgreSQL réelles et un contrôle de lecture seule/snapshot avec modification du justificatif sur une seconde connexion. Détail des résultats dans VALIDATION.md. Aucun paquet, compte, solde initial ou mouvement financier réel ajouté.

Restent la vérification du solde actuel auprès des prestataires réels, leur configuration et validation réelle, les autres fonds engagés et opérations de financement, les événements/valorisations et états/exports complets, puis les interfaces. La priorité backend avant reprise du front est maintenue.
