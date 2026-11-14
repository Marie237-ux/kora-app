# Résultats de vérification

Les preuves concernent les incréments réellement présents, y compris le backend des demandes de produits. Elles ne valident pas l'ensemble du lancement.

## Suite du 13 novembre 2026 — réparations et retours fournisseur

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 487 recensés, 441 exécutés, 46 réservés à PostgreSQL ; 14179 assertions, aucun échec ; 2 min 14 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 487 tests, 15342 assertions, aucun échec ni test ignoré ; 16 min 47 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.45.0, 565 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000051 appliquée seulement à kora (vide) |

Nouveau test : AfterSalesRepairsTest (2), détaillé dans INCREMENT_REPARATIONS_RETOURS_FOURNISSEUR.md. La règle des déplacements SAV modifiée par la migration 000051 laisse AfterSalesTest inchangé et réussi sur les deux bases.

## Suite du 13 novembre 2026 — abonnements fournisseurs et mise en avant

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 485 recensés, 439 exécutés, 46 réservés à PostgreSQL ; 14047 assertions, aucun échec ; 9 min 01 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 485 tests, 15210 assertions, aucun échec ni test ignoré ; 21 min 23 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.44.0, 558 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000050 appliquée seulement à kora (vide) |

Nouveau test : SupplierSubscriptionsTest (2), détaillé dans INCREMENT_ABONNEMENTS_FOURNISSEURS.md.

## Suite du 13 novembre 2026 — états et exports comptables

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 483 recensés, 437 exécutés, 46 réservés à PostgreSQL ; 13961 assertions, aucun échec ; 2 min 46 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 483 tests, 15124 assertions, aucun échec ni test ignoré ; 25 min 06 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.43.0, 541 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000049 appliquée seulement à kora (vide) |

Nouveau test : AccountingStatementsTest (3), détaillé dans INCREMENT_ETATS_EXPORTS_COMPTABLES.md.

## Suite du 13 novembre 2026 — portail fournisseur

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 480 recensés, 434 exécutés, 46 réservés à PostgreSQL ; 13842 assertions, aucun échec ; 7 min 19 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 480 tests, 15005 assertions, aucun échec ni test ignoré ; 37 min 44 s (machine chargée) ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.42.0, 534 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000048 appliquée seulement à kora (vide) |

Nouveau test : SupplierPortalTest (3), détaillé dans INCREMENT_PORTAIL_FOURNISSEUR.md.

## Suite du 12 novembre 2026 — recherche photo, assistants et IA

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 477 recensés, 431 exécutés, 46 réservés à PostgreSQL ; 13681 assertions, aucun échec ; 2 min 42 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 477 tests, 14844 assertions, aucun échec ni test ignoré ; 27 min 37 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.41.0, 505 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000047 appliquée seulement à kora (vide) |
| Prestataires IA | Aucun appel réel : prestataires factices dans les tests, tout appel réseau non prévu interdit ; contrats Voyage et Claude vérifiés sur requêtes simulées |

Nouveaux tests : PhotoSearchTest (4) et AiAssistantsTest (2), détaillés dans INCREMENT_RECHERCHE_PHOTO_IA.md.

## Suite du 12 novembre 2026 — CMS, événements et conditions légales

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 471 recensés, 425 exécutés, 46 réservés à PostgreSQL ; 13519 assertions, aucun échec ; 3 min 02 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 471 tests, 14682 assertions, aucun échec ni test ignoré ; 20 min 29 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.40.0, 492 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000046 appliquée seulement à kora (vide) |

Nouveau test : CmsTest (3) — pages (contenu refusé : couleurs illisibles, police inconnue, balises, bloc inconnu, lien `javascript:` ; média privé bloquant ; aperçu non indexé et fermé au public ; version figée en base ; programmation Douala avec bascule seule ; produit retiré et média révoqué masqués ; retour arrière ; droits), événements (refus thème illisible, dates, bannière ; priorité et langue ; thème fusionné ; conflit de version ; prochain changement ; fin automatique), conditions légales (bilingue, droit dédié, confirmation récente, quatre yeux, version figée, historique public, version capturée dans la commande, acceptation unique, refus commande d'autrui et brouillon, acceptations indélébiles, nouvelle version à sa date d'effet).

Après les suites complètes, l'en-tête `Vary: X-Kora-Locale` a été ajouté à la page publique (cache par langue) avec une assertion de plus : CmsTest relancé seul sur SQLite et PostgreSQL, 3 tests, 150 assertions, réussis.

## Suite du 11–12 novembre 2026 — avis, alertes, notifications et messagerie

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 468 recensés, 422 exécutés, 46 réservés à PostgreSQL ; 13370 assertions, aucun échec ; 5 min 31 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 468 tests, 14533 assertions, aucun échec ni test ignoré ; 28 min 22 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.39.0, 474 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migrations additives 000044 et 000045 appliquées seulement à kora après contrôle de cible |

Nouveaux tests :
- EngagementTest (2) : avis vérifiés (refus avant livraison, modification en attente, photo réencodée, motif « négatif » impossible, publication avec photo publique, page produit sans e-mail, signalements, réponse, droits) ; alertes uniques en anglais, états d'envoi « non configuré » ou « préférence », préférences coupées, désinscription signée, historique, racheter, abonnement web ;
- ConversationsTest (2) : contexte étranger refusé, renvoi sans doublon, pièce jointe, réponse rapide dans la langue du client, note interne invisible, synchronisation par numéro, non-lus, notification sans aperçu du contenu, isolation entre clients et liens copiés, conversion en dossier SAV unique, droits ; lien WhatsApp seulement si configuré et accepté.

Une première exécution PostgreSQL complète s'était terminée avec une erreur dans ProductRequestsTest : la connexion à la base avait expiré au démarrage du test (« timeout expired »), sur une machine surchargée. La classe relancée seule passe (15 tests, 200 assertions). La suite complète relancée passe sans erreur ; la preuve XML est celle de cette relance.

La publication d'une photo d'avis par un modérateur passe par une option « déjà autorisé » de la médiathèque, pour ne pas donner aux modérateurs l'accès à toute la médiathèque ; version et révocation restent contrôlées. Aucun paquet ajouté. Voir INCREMENT_AVIS_MESSAGERIE.md.


## Suite du 11 novembre 2026 — parrainage et partenaires

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 464 recensés, 418 exécutés, 46 réservés à PostgreSQL ; 13176 assertions, aucun échec ; 7 min 59 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 464 tests, 14339 assertions, aucun échec ni test ignoré ; 39 min 35 s (machine très chargée) ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.38.0, 442 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migrations additives 000042 et 000043 appliquées seulement à kora après contrôle de cible ; aucun programme, parrainage, candidature ni partenaire |

Nouveaux tests :
- ReferralsTest (2) : refus du propre code, d'un code inconnu, d'un second parrain et d'un client déjà acheteur ; deux gains créés, un remboursement versé qui annule le second et fait compter le troisième, budget plafonné au franc près, filleuls anonymes, seuil de versement, approbation puis versement avec preuve, protections SQL ; téléphone partagé signalé, gain non réglé avant revue, revue réservée à referral.manage ;
- PartnersTest (2) : page vide puis publiée, consentement exigé, piège anti-robot sans enregistrement, doublon signalé, note, pièce, décision, conversion en fournisseur et boutique, doublons refusés, publication exigeant FR et EN, fiche publique sans coordonnées privées ni lien vers une boutique non publiée, archivage ; droits et protections.

Corrections en cours de route :
- PostgreSQL refusait la clé autoréférente d'une candidature dans le même CREATE : elle est ajoutée après la création ;
- PostgreSQL refusait un MAX verrouillé pour numéroter les programmes : les lignes sont verrouillées puis comptées ;
- dans les tests, l'ordre de trois achats payés la même seconde dépendait d'identifiants aléatoires : les achats du test sont espacés d'une minute ; le code trie bien par date de paiement puis par identifiant.

Aucun paquet ajouté. Voir INCREMENT_PARRAINAGE_PARTENAIRES.md.


## Suite du 11 novembre 2026 — remplacement en préparation et déplacements SAV

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 460 recensés, 414 exécutés, 46 réservés à PostgreSQL ; 12997 assertions, aucun échec ; 3 min 26 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 460 tests, 14160 assertions, aucun échec ni test ignoré ; 22 min 29 s (machine chargée) ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.37.0, 420 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migrations additives 000040 et 000041 appliquées seulement à kora après contrôle de cible |

Trois nouveaux tests :
- OrderPreparationTest : remplacement simple (unité mise de côté « damaged », commande plus prête, remplaçant retenu aux mêmes conditions puis sorti, quantités nettes, sur-retour refusé) et remplacement sérialisé (seule la série rendue revient en stock) ;
- AfterSalesTest : échange complet (reprise et envoi, tournée reprise → prise de l'échange → dépôt du retour → livraison, quantité contrôlée à la porte, code erroné compté, retour contrôlé défectueux, clôture refusée avant la remise de l'échange puis acceptée).

Les tests existants de missions, collectes, livraison directe et SAV passent avec les quantités désormais nettes des remplacements. Aucun paquet ajouté. Voir INCREMENT_LOGISTIQUE_SAV.md.


## Suite du 11 novembre 2026 — SAV, garanties et retours

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 457 recensés, 411 exécutés, 46 réservés à PostgreSQL ; 12835 assertions, aucun échec ; 7 min 9 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 457 tests, 13998 assertions, aucun échec ni test ignoré ; 13 min 10 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.36.0, 409 chemins ; chaîne des scripts rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000039 appliquée seulement à kora après contrôle de cible ; aucun dossier |

AfterSalesTest (2), sur une commande réellement livrée par code, couvre :
- achats et garanties, quantité excessive refusée, pièce jointe du client lisible par le support ;
- note interne invisible au client, responsabilité non exposée, prénom de l'interlocuteur, montant supérieur au payé refusé, montant par défaut exact, acceptation du client ;
- remboursement refusé avant retour, retour en état « returned » sans effet sur le stock disponible (mouvement customer_return), clôture refusée avant versement puis avant contrôle, contrôle « revendable » qui rend l'unité disponible, dossier résolu avec remboursement versé ;
- confidentialité entre clients, rôles catalogue et stock refusés, refus motivé qui fige le dossier, protections SQL.

Tests PostgreSQL ciblés (inventaire, commandes, fidélité au paiement, ruptures fournisseur, pièces) : 49 réussis après l'ajout du retour client en stock et de la tranche fidélité SAV. Aucun paquet ajouté. Voir INCREMENT_SAV.md.


## Suite du 11 novembre 2026 — photos de preuve et vérification du livreur

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 455 recensés, 409 exécutés, 46 réservés à PostgreSQL ; 12708 assertions, aucun échec ; 2 min 33 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 455 tests, 13871 assertions, aucun échec ni test ignoré ; 19 min 52 s (machine chargée) ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.35.0, 392 chemins ; chaîne des scripts de livraison rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000038 appliquée seulement à kora après contrôle de cible ; aucune photo ni mission |

Deux nouveaux tests dans DeliveryMissionsTest. Photos : ajout par le livreur affecté, type invalide refusé, autre livreur refusé, liste et téléchargement par le dispatch, refus pour le rôle catalogue et après livraison, suppression bloquée en SQL. QR : réponse publique minimale sans connexion (sans e-mail), faux jeton refusé, suspension puis réactivation du livreur, réaffectation qui invalide l'ancien code et en délivre un nouveau au nouveau livreur. Aucun paquet ajouté, aucune photo ni vérification réelle. Voir INCREMENT_PREUVES_LIVRAISON.md.


## Suite du 11 novembre 2026 — livraison directe boutique → client

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 453 recensés, 407 exécutés, 46 réservés à PostgreSQL ; 12607 assertions, aucun échec ; 8 min 33 s (machine chargée) ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 453 tests, 13770 assertions, aucun échec ni test ignoré ; 15 min 40 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.34.0, 389 chemins ; chaîne missions → tournées → collectes → livraison directe rejouée avec empreinte identique ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migrations 000034 à 000037, appliquées seulement à kora (vide), annulées puis réappliquées après modification ; aucune donnée concernée |

DirectDeliveryTest (2) couvre : zéro colis au dépôt refusé sans collecte directe, collecte directe unique par reste à collecter, tournée « réceptionner chez la boutique → livrer » sans étape dépôt, enlèvement au dépôt refusé quand il n'y a rien. Côté livreur : photo du bon envoyée, manque refusé sans note, réception complète. Les unités sont reçues avec la photo dans l'emplacement « en route » puis sorties pour la commande (emplacement vide, une sortie de vente), la remise se fait par le code du client et la commande est livrée. Avec un responsable désigné : seul lui réceptionne, le livreur voit qui reçoit, une photo pour une autre collecte est refusée, les colis s'ajoutent à la mission et les actions de collecte vers dépôt sont refusées.

Le test de mise à niveau de la migration 000019 (OrderPurchasesTest, PostgreSQL) échouait car la table des collectes pointait par clé étrangère vers les affectations d'achat. La référence est désormais contrôlée par un garde-fou SQL (affectation existante, même ligne de commande et d'achat) : test réussi.

Aucun paquet ajouté, aucun achat, réception, livraison ou envoi réels. Voir INCREMENT_LIVRAISON_DIRECTE.md.


## Suite du 11 novembre 2026 — collectes en boutique fournisseur et points localisés

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 451 recensés, 405 exécutés, 46 réservés à PostgreSQL ; 12476 assertions, aucun échec ; 2 min 38 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 451 tests, 13639 assertions, aucun échec ni test ignoré ; 15 min 2 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.33.0, 385 chemins ; chaîne missions → tournées → collectes rejouée avec empreinte identique (après correction d'un ajout de description non idempotent) ; contract-check réussi ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Tournées annulées sur kora (vides) puis migrations 000035 à 000037 appliquées après contrôle de cible ; collectes, tournées, missions et commandes restent à zéro |

SupplierCollectionsTest (3) couvre : création depuis un achat confirmé (jamais plus que le reste à recevoir non promis), tournée mêlant collecte et livraison (collecter → enlever → déposer → livrer), consigne avec articles et repère, coordonnées du point, manque compté avec note obligatoire, dépôt au même nombre de colis, réception reliée seulement par l'équipe stock et avec la bonne réception, unités manquantes de nouveau à collecter. Il couvre aussi : réaffectation avec reprise des colis sans recompter, ancien livreur sans accès, annulation refusée après comptage, incident relancé puis annulé, droits (livreur, catalogue, stock), protections SQL et localisation facultative des points (coordonnées ensemble, valeurs conservées si omises). RoundPlannerTest gagne un cas de proximité (plus proche voisin dans une zone, arrêts non localisés ensuite). Nouvelle course PostgreSQL : deux créations simultanées ne promettent pas deux fois les mêmes unités d'achat.

L'échec aléatoire de la course préparation/libération est résolu : la fixture du test fabriquait une commande sans créneau ni nom de ligne, et la vue de préparation échouait quand la sortie de stock gagnait. La fixture reprend les clés d'une vraie commande, la lecture du créneau est tolérante (préparation, missions, tournées, commande client) et la vue est vérifiée après la course quelle que soit l'issue. Aucune donnée réelle concernée.

Aucun paquet ajouté, aucun serveur web, achat, réception, mission ou notification réels. Livraison directe boutique → client non construite (décision attendue). Voir INCREMENT_COLLECTES_FOURNISSEUR.md.


## Suite du 10–11 novembre 2026 — missions de livraison et journée du livreur

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 446 recensés, 401 exécutés, 45 réservés à PostgreSQL ; 12310 assertions, aucun échec ; 1 min 42 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 446 tests, 13449 assertions, aucun échec ni test ignoré ; 14 min 23 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.32.0, 371 chemins ; scripts missions puis tournées rejoués avec empreinte identique ; contract-check réussi ; 8 tests npm réussis ; toutes les nouvelles routes documentées |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migrations additives 000034 et 000035 appliquées seulement à kora après contrôle de cible ; missions, tournées, livreurs et commandes restent à zéro |

Nouveaux tests : DeliveryMissionsTest (6) couvre enlèvement puis remise par code, contrôle par article refusé sans consommer de tentative, code erroné compté malgré l'échec et rejeu idempotent identique, blocage après 5 erreurs même pour le bon code, remise alternative refusée au livreur qui cumule le rôle dispatch puis validée par une autre personne, réaffectation avec garde des colis (V57 : ancien appareil sans vue, sans rejeu et sans remise), acceptation/refus, incident vu « retardé » par le client sans note interne, retour au dépôt, annulation et nouvelle mission, droits (V56 : livreur non affecté, rôles catalogue/stock/gestionnaire), commande non prête, livreur inactif ou sans rôle, protections SQL. DeliveryRoundsTest (2) couvre la journée étape par étape (enlèvement groupé au dépôt, consigne, action attendue, étape courante/suivante, colis à bord, contacts masqués après remise, aucun code ni prix ni identifiant d'arrêt), puis réordonnancement refusé si une livraison précède son enlèvement, mission réaffectée « retirée », replanification gardant les étapes faites, unicité tournée/mission et annulation immuable. RoundPlannerTest (3, unitaires) reproduit le scénario de Marie et vérifie la précédence sur 200 plans aléatoires.

Nouvelle course PostgreSQL : une remise par code et une réaffectation simultanées ne peuvent pas aboutir toutes les deux (livrée par le premier livreur, ou réaffectée sans livraison).

Incidents d'exécution relevés pendant cette reprise : (1) DeliveryOffersTest échouait lorsqu'il était lancé vers 23 h 50 à Douala, car un saut de 11 minutes passait minuit et décalait « demain » par rapport au jour de la règle de créneau. Test stabilisé à midi le même jour, puis réussi à 23 h 58. (2) La course préparation/libération échouait de façon aléatoire : quand la sortie de stock gagnait, la vue de préparation lisait une commande fabriquée par la fixture du test sans créneau ni nom de ligne. Cause trouvée le 11 novembre grâce au détail d'erreur ajouté au worker (OrderPreparation.php, clés « slot » puis « name ») ; fixture complétée comme une vraie commande, lecture du créneau rendue tolérante, et vue de préparation désormais vérifiée après la course quelle que soit l'issue (voir la section du 11 novembre).

Aucun paquet ajouté, aucun serveur web, paiement, mission réelle, SMS ou notification externe. Écrans livreur/dispatch non construits. Voir INCREMENT_MISSIONS_LIVRAISON.md.


## Suite du 10 novembre 2026 — préparation des commandes payées

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 434 recensés, 390 exécutés, 44 réservés à PostgreSQL ; 9945 assertions, aucun échec ; 1 min 59 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 434 tests, 11037 assertions, aucun échec ni test ignoré ; 13 min 11 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.30.0, 346 chemins ; contract-check réussi, script rejoué avec empreinte identique ; 8 tests npm réussis |
| Formatage | Prettier/Pint appliqués (script de contrat et test de préparation), format:check réussi ; OrderPreparationTest rejoué sur PostgreSQL après formatage (6 tests, 279 assertions) |
| Base locale | Migration additive 000033 appliquée seulement à kora après contrôle de cible ; commandes, prélèvements et fournisseurs restent à zéro |

Six nouveaux tests fonctionnels couvrent la sortie du stock KORA retenu puis la déclaration prête avec rejeu idempotent sans seconde sortie, les unités fournisseur non reçues visibles en attente et bloquant l'état prêt, la réception affectée déjà sortie qui ne peut plus être libérée, les séries/IMEI exactes quel que soit l'ordre, l'anomalie bloquante puis résolue une seule fois, le retrait de l'état prêt par une anomalie ultérieure, les droits stock/gestionnaire/catalogue, versions périmées, commande non payée, file filtrée et la protection SQL des prélèvements. La liste de préparation ne contient ni prix, ni coût, ni adresse ; le client voit seulement not_started/in_progress/ready.

Une nouvelle course PostgreSQL fait attendre deux vrais processus PHP sur la commande : une unité reçue est soit sortie pour préparation, soit libérée, jamais les deux ; stocks physique et réservé cohérents dans les deux issues.

Une première exécution parallèle des deux suites a produit une erreur et un échec SQLite hors préparation (ExpensePlansTest, PrivateDocumentTest) : les deux suites partagent le disque de test storage/framework/testing/disks/local. Suite SQLite relancée seule : aucun échec. Les suites complètes restent à exécuter successivement.

Relecture des annulations : les remboursements fournisseur refusent déjà les unités reçues et l'expiration ne concerne que les commandes non payées ; aucune unité sortie n'est remboursée comme stock disponible. Limite consignée : aucune route ne remplace ni ne remet en stock une unité déjà sortie puis signalée abîmée. Aucun paquet ajouté, aucun serveur web, paiement, mission ou communication externe. TypeScript/Astro non réexécutés : aucun fichier front modifié, types générés identiques. Voir INCREMENT_PREPARATION.md.


## Suite du 5 novembre 2026 — avances, apports et emprunts monétaires

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 427 recensés, 384 exécutés, 43 réservés à PostgreSQL ; 9666 assertions, aucun échec ; 3 min 5 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 427 tests, 10732 assertions, aucun échec ni test ignoré ; 12 min 57 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.29.0, 340 chemins ; types/contract-check réussis, script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués, format:check réussi ; dernier test d'échéances formaté par les mêmes outils |
| Base locale | Migration additive 000032 appliquée seulement à kora après contrôle de cible ; livres, écritures, trésorerie, dépenses, retraits, constats, financements/mouvements, fournisseurs, commandes et remboursements restent à zéro |

Treize nouveaux tests fonctionnels distinguent avances du fondateur, apports et emprunts monétaires, accord prévu et principal réellement reçu, remboursements partiels, capacité non renouvelable, annulation exacte du seul non-versé et dette reçue conservée. Ils couvrent contreparties explicitement choisies, dates non futures et calendrier, preuve propre, examen séparé en réel, livres, MFA/permissions/confirmation récente, références et remplacements bornés après annulation, historique SQL et plafonds, corrections atomiques et rapprochements à annuler d'abord. Les remboursements ordinaires d'apport sont refusés. Une même écriture ne devient ni une seconde dette ni simultanément règlement de dépense et remboursement de principal, avec garde SQL réciproque.

Dix réceptions de 10^18 portent le principal dû et le regroupement quotidien du prévisionnel à 10^19, exactement, même avec une liste limitée à 24 sur 25 accords. Les échéances avant/dans/après fenêtre, bornes inclusives, principal échu et non daté sont distingués. Le financement seulement approuvé mais non reçu reste exclu des remboursements attendus. Les financements annulés conservent leur principal reçu dû ; corriger un remboursement le rétablit sans ressusciter la capacité non versée annulée. Frais, intérêts et frais financés par le fondateur hors flux de trésorerie KORA ne sont pas inventés.

Deux nouvelles courses PostgreSQL prouvent les sessions réellement en attente sur le verrou du livre : deux remboursements de 700 ne peuvent dépasser 1000 reçus ; remboursement de 700 et correction du versement de 1000 ne peuvent aboutir à un principal reçu inférieur au principal remboursé. Un troisième test utilise réellement une seconde connexion qui engage un remboursement de 200 pendant le prévisionnel READ ONLY : la réponse en cours conserve 600, la suivante voit 400. Les seize tests ciblés passent (501 assertions), puis les deux suites complètes s'exécutent successivement pour préserver le stockage de test partagé.

Les deux premiers tests étaient rouges sur les routes absentes. Les deux premiers parcours, puis les douze parcours initiaux et les dix tests du prévisionnel existant ont réussi ; un treizième parcours a ensuite ajouté la classification explicite des échéances. La relecture vérifie périmètres, contreparties, exclusivité des écritures, financement non renouvelable, correction après annulation, données de lecture seule et instants/date comptable distincts. Aucun garde métier affaibli. Les clés autoréférentes PostgreSQL sont ajoutées après création des tables, selon le correctif déjà vérifié pour les retraits.

Le contrat 0.29.0 ajoute financing et une colonne quotidienne financement au prévisionnel. Le scope de démonstration devient book_commitments_only pour inclure dépenses et financements de ce livre ; sources globales toujours exclues. Les anciens types book_expenses_only sont remplacés explicitement. Sept familles de capture inchangées : les mouvements sont déjà liés au journal existant, sans seconde comptabilisation. Aucune recette, identité d'entreprise, taxe ou somme disponible déduite de ces financements ; trésorerie actuelle et free_cash restent inconnus.

Relecture locale du domaine, du schéma, des routes, du contrat et des corrections ; aucun dépôt Git pour revue différentielle ou commit. Aucun paquet ajouté, composer audit non réexécuté, aucun transfert prestataire/paiement/publication réel ni serveur web lancé. Les reconstructions ciblent seulement kora_test ; la migration de kora est additive et sans données financières. Financement direct de dépenses pour compte de KORA, conditions/échéanciers enrichis et opérations juridiques de capital, vérification réelle des prestataires, autres engagements/événements/valorisations, états/exports et interfaces restent obligatoires. Priorité backend maintenue ; voir INCREMENT_FINANCEMENTS.md. Preuves XML courantes et résultats historiques ci-dessous.


## Suite du 5 novembre 2026 — constats datés des soldes externes

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 411 recensés, 371 exécutés, 40 réservés à PostgreSQL ; 9249 assertions, aucun échec ; 2 min 4 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 411 tests, 10231 assertions, aucun échec ni test ignoré ; 12 min 35 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.28.0, 333 chemins ; types et contract-check réussis, script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués, format:check réussi |
| Base locale | Migration additive 000031 appliquée seulement à kora après contrôle de cible ; livres, écritures, trésorerie, dépenses, retraits, reçus, constats, fournisseurs, commandes et remboursements restent à zéro |

Douze nouveaux tests fonctionnels couvrent preuves datées et examen, source Monetbil distincte des comptes liquides, fonds propres disponibles hors découvert, soldes négatifs, zéro distinct de l'inconnu, expiration explicite dans les 24 heures, instants non futurs/non décroissants, corrections immuables sans repli, changement de correspondance, justificatif remis en quarantaine, idempotence, droits/MFA/confirmation récente, séparation des auteurs en réel, livres et pagination par 24. Les totaux exigent un instant commun et toutes les preuves exploitables ; dix comptes de 10^18 sont additionnés exactement à 10^19. Le prévisionnel expose ces constats sans nouvelle écriture ni disponibilité actuelle certifiée.

Les gardes SQLite/PostgreSQL protègent aussi les montants, séquences, preuves/configurations en vigueur et transitions complètes ; historique et empreintes restent immuables. Deux nouvelles courses PostgreSQL constatent les sessions réellement en attente sur le verrou du livre : deux créations acceptant la même séquence ne peuvent réussir ensemble ; une approbation concurrente à une nouvelle observation ne fait jamais réapparaître l'ancien montant dans le résumé. Les quinze tests ciblés passent (369 assertions).

La relecture a révélé qu'un contrôle de correspondance reprenait un verrou de compte incompatible avec le snapshot READ ONLY de production. Un nouveau test PostgreSQL hors transaction de fixture a d'abord reproduit l'erreur SELECT FOR UPDATE (journal .cache/balances-readonly-red.log). TreasuryBindings conserve le verrou par défaut pour les mutations et accepte explicitement une lecture sans verrou pour ce résumé. Le test final vérifie la transaction en lecture seule et modifie le justificatif sur une seconde connexion pendant le rapport : la réponse en cours conserve 800, la suivante signale evidence_unavailable et un total inconnu. Le snapshot reste cohérent sans relâcher les protections des mutations.

Les deux premiers tests étaient rouges sur les routes absentes. Trois préparations de tests utilisaient ensuite le rôle comptable pour configurer des correspondances réservées au propriétaire, ou modifiaient un utilisateur différent du créateur réel ; rôles et créateur de fixture corrigés sans assouplissement des permissions. Les deux suites complètes finales sont exécutées successivement pour préserver leur stockage de test partagé. Le worker PostgreSQL réservé à kora_test peut figer explicitement l'horloge pour les deux nouvelles courses de validité temporelle ; les autres scénarios conservent leur fonctionnement.

Relecture locale du domaine, des transitions, du schéma, des routes et du contrat ; aucun dépôt Git pour revue différentielle ou commit. Aucun paquet ajouté, composer audit non réexécuté, aucun appel de solde prestataire, paiement/publication réel ni serveur web lancé. Les reconstructions restent réservées à kora_test ; la migration de kora est additive et sans donnée financière. Les soldes observés décrivent leur instant explicite : aucun mouvement ultérieur n'est déduit automatiquement, aucun total de dates différentes n'est fabriqué, et la trésorerie actuelle/free_cash restent inconnues. Intégrations réelles, autres fonds engagés/opérations de financement, événements/valorisations, états/exports complets et interfaces restent obligatoires. Priorité backend maintenue ; voir INCREMENT_CONSTATS_SOLDES.md. Preuves XML courantes et résultats historiques ci-dessous.


## Suite du 5 novembre 2026 — retraits manuels et versements rapprochables

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 396 recensés, 359 exécutés, 37 réservés à PostgreSQL ; 8942 assertions, aucun échec ; 3 min 31 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 396 tests, 9862 assertions, aucun échec ni test ignoré ; 14 min 59 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.27.0, 328 chemins ; génération et contract-check réussis, script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués et format:check réussi |
| Base locale | Migration additive 000030 appliquée seulement à kora après contrôle de cible ; livres, écritures, trésorerie, dépenses, retraits, reçus, fournisseurs, commandes et remboursements restent à zéro |

Douze nouveaux tests fonctionnels couvrent demandes externes déclarées, examen, réceptions partielles, brut/net/frais, références opaques et remplacements explicites, annulation du reliquat exact, erreurs de saisie, preuves propres, dates non futures et chronologie, livres/droits/MFA/confirmation récente, séparation des auteurs en réel et orientation crédit des entrées. Le rattachement utilise une écriture entière déjà validée, sans seconde charge ou transfert. Les totaux de vingt-cinq demandes de 10^18 restent exacts à 2,5 × 10^19, indépendants de la pagination.

Les corrections produisent une contrepassation exacte liée, atomique avec le reçu, sans réemploi de l'ancienne écriture. Domaine et SQL bloquent les voies ordinaires et les corrections tant qu'une des deux lignes reste rapprochée ; les relevés clôturés doivent être rouverts puis leurs rapprochements annulés. La relecture ajoute le remplacement borné d'un reçu après annulation : même demande, dernier reçu contrepassé explicitement nommé, brut au plus égal au précédent ; aucun nouvel encaissement sur le reliquat annulé. Le prévisionnel montre les seuls retraits déclarés encore attendus, sans ajouter les transferts internes ou leurs frais déjà comptabilisés aux nouveaux engagements. Fonds disponibles et inventaire externe restent non vérifiés.

Deux courses PostgreSQL constatent des sessions réellement en attente sur le verrou du livre : deux réceptions de 700 ne peuvent dépasser une demande de 1000 ; une réception de 700 et une annulation acceptant un reliquat de 1000 ne peuvent réussir ensemble. Les quatorze tests ciblés PostgreSQL passent (448 assertions), puis les deux suites complètes s'exécutent successivement pour préserver leur stockage de test partagé.

Les premiers tests étaient rouges sur les routes absentes. Deux préparations ont été corrigées ensuite : rôle comptable remis avant le contrôle d'un livre étranger, et écriture au jour courant postée avant l'écriture future destinée au refus de rattachement. Le premier passage PostgreSQL a révélé que la clé étrangère autoréférente était ajoutée avant la clé primaire par la création Laravel ; les deux clés autoréférentes sont désormais ajoutées après création des tables sur PostgreSQL. Les reconstructions et les passages suivants réussissent, sans assouplissement des invariants métier.

Relecture locale du domaine, du schéma, des routes, du contrat et des cas de correction ; aucun dépôt Git disponible pour revue différentielle ou commit. Aucun paquet ajouté, composer audit non réexécuté, aucun prestataire/paiement/publication réel déclaré validé et aucun serveur web lancé. La migration locale est additive et ne crée aucune donnée financière ; les reconstructions ne ciblent que kora_test. Disponibilité externe vérifiée, intégrations réelles, autres engagements/événements/valorisations, états/exports complets et interfaces restent obligatoires. Priorité backend avant reprise du front. Voir INCREMENT_RETRAITS_MANUELS.md ; preuves XML courantes et résultats historiques ci-dessous.

Le premier passage complet PostgreSQL a échoué sur un scénario existant de réservation du stock réellement reçu : aucune des deux sessions n’avait atteint le verrou dans le délai de démarrage de 30 secondes, sans diagnostic d’erreur du worker. Le délai est porté à 90 secondes, avec limite de processus de 180 secondes et sondage à 100 ms ; la preuve stricte des deux sessions réellement bloquées reste obligatoire. Le scénario concerné et les deux courses de retraits sont rejoués avec succès, puis toute la suite PostgreSQL est relancée. Aucun code métier changé pour cet ajustement ; SQLite avait déjà réussi sur le même code métier. Les logs et le XML du passage échoué sont conservés dans .cache/withdrawals-full-pgsql-before-startup-budget.log et .cache/withdrawals-pgsql-before-startup-budget.xml.


## Suite du 5 novembre 2026 — prévisionnel consolidé des engagements connus

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 382 recensés, 347 exécutés, 35 réservés à PostgreSQL ; 8553 assertions, aucun échec ; 1 min 16 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 382 tests, 9414 assertions, aucun échec ni test ignoré ; 9 min 51 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.26.0, 320 chemins ; génération et contract-check réussis, script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués et format:check réussi |
| Base locale | Contrôle en lecture seule de kora ; dernière migration 000029, aucun ajout de schéma ; livres, écritures, trésorerie, dépenses, fournisseurs, commandes et remboursements restent à zéro |

Dix nouveaux tests fonctionnels vérifient la réunion des reliquats fournisseurs, achats non reconnus, dépenses approuvées et remboursements à financer sans seconde écriture, double dette ni date de versement inventée. Les livres de démonstration excluent toutes les sources métier globales et dépenses d'autres livres. Fenêtres inclusives, montants échus, budgets en attente, approbations futures, annulations et contrepassations à dates effectives sont distingués. Une annulation ne ressuscite pas après correction d'un règlement. Les avances non affectées ne sont pas déduites des dettes ; les premières échéances déjà couvertes n'apparaissent pas une seconde fois dans la fenêtre.

Les encaissements XAF réellement confirmés avec mode explicite, date et référence sont distincts des paiements de test, à vérifier, contrepassés, de devise incertaine ou incomplets. Une référence prestataire ambiguë est signalée et exclue du total. Remboursements demandés et déjà versés restent séparés. Relevé clôturé daté et comptes sans configuration ne deviennent pas de l'argent disponible : soldes externes, retraits et free_cash restent null. Permissions/MFA, livre inconnu et paramètres historiques/filtres partiels non supportés sont contrôlés.

Deux engagements fournisseurs de 10^18 sont agrégés exactement, avant puis après leur reconnaissance, sans doublon. Huit budgets de 10^18 portent le total consolidé à 10^19, au-delà de l'entier natif ; les totaux et regroupements quotidiens restent des chaînes exactes. Le prévisionnel fournisseurs existant bénéficie aussi de ces agrégats, sans élargir les plafonds d'opération, de prix/quantité ni les gardes de crédit.

Un nouveau test PostgreSQL crée réellement un remboursement supplémentaire sur une seconde connexion entre les requêtes des sources fournisseurs et remboursements. La réponse en cours conserve 101000, la suivante voit 101700 ; la lecture s'exécute dans le snapshot REPEATABLE READ READ ONLY partagé. Onze tests PostgreSQL ciblés ont d'abord réussi (430 assertions), puis les suites complètes ont été exécutées successivement pour préserver le stockage de test partagé. Le dernier passage complet ajoute aussi le contrôle d'un compte sans correspondance de trésorerie et d'une tentative en devise étrangère.

Les premiers tests d'intégration étaient rouges sur la route encore absente (3 tests). Deux préparations de test ont ensuite été corrigées : prix unitaire ramené à la limite réelle avec quantité admissible pour former 10^18, et reconfirmation du mot de passe après avance simulée d'un jour. Aucun garde de production assoupli. La première génération de types signalait une référence Locale absente ; paramètre locale défini explicitement puis génération/contrat réussis.

Le premier passage ciblé PostgreSQL était empêché par le serveur local arrêté. Redémarrage du cluster existant, récupération automatique de PostgreSQL terminée avant les tests réussis ; aucune réinitialisation de kora. Les migrations de reconstruction ne concernent que kora_test.

La relecture a ajouté un cas temporel : un budget encore en attente mais annulé à une date future reste en attente jusqu'à cette date. La classification utilise désormais les dates d'approbation/annulation plutôt que le seul statut courant ; les engagements approuvés étaient déjà calculés à date. Le passage PostgreSQL en cours a été interrompu avant ses scénarios concurrents, sans échec constaté, puis le test ciblé, le formatage et les deux suites complètes ont été relancés sur la version finale. Journal intermédiaire conservé dans .cache/forecast-full-pgsql-before-pending-date-fix.log.

Relecture locale des périmètres réel/démonstration, calculs, instant de référence, sources et contrats ; aucun dépôt Git pour revue différentielle/commit. Aucun paquet ajouté, composer audit non réexécuté ; aucun navigateur, prestataire, paiement ou publication réel déclaré validé. Aucune nouvelle migration ni écriture financière de production. Disponibilité externe vérifiée, retraits, autres engagements/événements/valorisations, états/exports complets et interfaces restent obligatoires. Priorité backend avant reprise du front. Voir INCREMENT_PREVISIONNEL_TRESORERIE.md ; preuves XML courantes et résultats historiques ci-dessous.

## Suite du 5 novembre 2026 — dépenses et engagements documentés

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 371 recensés, 337 exécutés, 34 réservés à PostgreSQL ; 8140 assertions, aucun échec ; 1 min 43 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 371 tests, 8976 assertions, aucun échec ni test ignoré ; 11 min 8 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.25.0, 319 chemins ; types synchronisés, contract-check réussi et script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués et format:check réussi |
| Base locale | Migration additive 000029 appliquée à kora sans réinitialisation ; livres, écritures, trésorerie, budgets et règlements de dépenses restent à zéro |

Onze nouveaux tests fonctionnels couvrent budgets en attente/approuvés, règlements partiels puis solde, annulation du seul reliquat accepté, relevés antérieurs conservés et absence de seconde écriture. Les contrepassations de dépenses sont atomiques et exactes ; un relevé clôturé refuse toute la correction sans état intermédiaire ni capacité libérée. Les routes ordinaires et le SQL ne permettent pas de contourner le lien avec la dépense ni de contrepasser sa contre-écriture.

Montants excessifs, mauvais sens de trésorerie, transfert interne, double affectation, chronologie, calendrier fermé, références normalisées, pièces non propres, mauvais livre et transitions SQL invalides sont refusés. Les tests couvrent permissions/MFA/confirmation récente, idempotence, séparation des auteurs en réel, agrégats exacts de 2×10^18 et orientation de trésorerie inversée. Le résumé expose toujours disponibilité non vérifiée et fonds disponibles null.

Deux nouvelles courses PostgreSQL constatent les sessions en attente du verrou du livre : deux rattachements concurrents ne peuvent dépasser le budget ; règlement et annulation ne peuvent réussir avec un reliquat accepté devenu obsolète. Le journal déjà validé reste intact si un rattachement est refusé. Treize tests ciblés PostgreSQL ont réussi, 316 assertions, avant les suites complètes successives afin de préserver le stockage de test partagé.

Le premier test ciblé SQLite de séparation des auteurs réutilisait le guard Sanctum en cache après changement d'utilisateur dans la fixture. Les changements d'acteur oublient désormais explicitement les guards avant actingAs ; aucun contrôle de production assoupli. Les tests ciblés puis complets vérifient les identités distinctes.

Le premier passage complet PostgreSQL a exécuté les 371 tests avec un seul échec dans ProductRequestsTest : le délai attendu de 300 secondes valait 301, car l'horloge n'était figée qu'au premier travel après la soumission et le changement d'acteur. Le test fige désormais l'horloge avant toute préparation ; les avances simulées de deux puis trois minutes restent contrôlées et l'assertion exacte de 300 secondes est conservée. Aucun calcul métier de statistiques modifié. Le test ciblé PostgreSQL a ensuite réussi, puis les deux suites complètes ont été relancées ; premier journal conservé dans .cache/expense-full-pgsql-before-metrics-fix.log.

Relecture locale du domaine, des gardes SQL et du contrat ; aucun dépôt Git pour revue différentielle/commit. Aucun paquet ajouté, composer audit non réexécuté ; aucun navigateur, prestataire, paiement ou publication réel déclaré validé. Le périmètre reste celui des budgets de dépenses et des sorties manuelles déjà comptabilisées : aucun nouveau fait à capturer dans les sept familles existantes, aucune dette fournisseur ni certification bancaire créée. Disponibilité externe, prévisionnel global/autres fonds engagés, retraits, autres événements/valorisation, états/exports complets et interfaces restent obligatoires. Priorité backend avant reprise du front. Voir INCREMENT_DEPENSES_ENGAGEMENTS.md ; preuves XML courantes et résultats historiques ci-dessous.

## Suite du 4 novembre 2026 — lettrage comptable partiel et total

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 358 recensés, 326 exécutés, 32 réservés à PostgreSQL ; 7877 assertions, aucun échec ; 1 min 32 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 358 tests, 8660 assertions, aucun échec ni test ignoré ; 9 min 51 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.24.0, 312 chemins ; types synchronisés et contract-check réussi ; script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués et format:check réussi |
| Base locale | Migration additive 000028 appliquée à kora sans réinitialisation ; livres, écritures, trésorerie et lettrages restent à zéro |

Onze nouveaux tests fonctionnels vérifient affectations partielles puis solde final, multi-factures fournisseur, états des reliquats, absence de modification du journal et des paiements/dettes métier. Portées livre/compte/tiers, brouillons, dates, doublons, montants invalides/excessifs et déséquilibres sont refusés atomiquement. Annulation datée et relettrage préservent le rapport de la veille, snapshot et empreinte ; chronologie du livre et calendrier fermé sont contrôlés. Une facture d'un exercice clôturé est lettrée en période courante ouverte sans modifier son écriture ; un changement ultérieur du plan n'efface pas l'identité auxiliaire historique. Net nul sans lettrage explicite reste ouvert. Permissions/MFA/confirmation récente, preuve propre, version et idempotence sont vérifiées. Sommes de 2×10^18 exactes, limite par opération, pagination de 25 lignes sans tronquer les totaux et protections SQL du contenu/membres/équilibre/capacité sont couvertes.

Deux nouvelles courses PostgreSQL constatent réellement deux sessions en attente du verrou du livre : deux règlements partiels ne peuvent suraffecter une même ligne ; annulation et nouvelle affectation se sérialisent sans modifier les reliquats du jour précédent. Les suites complètes sont exécutées successivement pour préserver le stockage de test partagé. Treize tests ciblés PostgreSQL ont d'abord réussi (353 assertions), avant les derniers contrôles de limites inclus dans les suites finales.

Le premier passage PostgreSQL a révélé que migrate:fresh supprimait les tables sans supprimer les nouvelles fonctions : CREATE FUNCTION échouait à la seconde reconstruction de la base de test. Correction par CREATE OR REPLACE FUNCTION avant les courses ciblées réussies et les suites complètes. Aucun contournement des gardes ni réinitialisation de la base de développement.

Un premier passage complet SQLite a aussi révélé deux tests de fidélité dépendant de l'heure : après une avance simulée de deux heures traversant minuit, « demain » ne correspondait plus au jour de la règle hebdomadaire créée. La fixture sélectionne désormais une occurrence réellement proposée dans les créneaux du devis. Les deux scénarios de paiement tardif commencent explicitement à 23 h 30 à Douala et avancent au lendemain ; 2 tests ciblés, 114 assertions réussies avant les nouvelles suites finales. Aucune règle de production assouplie ; premier journal conservé dans .cache/lettering-full-sqlite-before-midnight-fix.log.

Relecture locale du domaine, des gardes SQL et du contrat ; aucun dépôt Git pour revue différentielle/commit. Aucun paquet ajouté, composer audit non réexécuté ; aucun navigateur, prestataire, paiement ou publication réel déclaré validé. Le lettrage ne modifie ni dettes métier ni encaissements et ne certifie pas le règlement externe. Disponibilité/fonds engagés, dépenses/retraits, autres événements/valorisations, états/exports et interfaces restent obligatoires. Priorité backend avant reprise du front reconfirmée par Marie. Voir INCREMENT_LETTRAGE_COMPTABLE.md ; XML courants et résultats historiques ci-dessous.

## Suite du 4 novembre 2026 — couverture de trésorerie et ouvertures rapprochées

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 345 recensés, 315 exécutés, 30 réservés à PostgreSQL ; 7551 assertions, aucun échec ; 2 min 10 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 345 tests, 8279 assertions, aucun échec ni test ignoré ; 9 min 36 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.23.0, 308 chemins ; types synchronisés et contract-check réussi ; script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués et format:check réussi |
| Base locale | Migration additive 000027 appliquée à kora sans réinitialisation ; livres, écritures, trésorerie et politiques restent à zéro |

Onze nouveaux tests fonctionnels vérifient l'activation explicite, les lacunes de couverture même sans relevé ouvert, les ouvertures incohérentes ou périmées, les reports hérités et les preuves de solde/clôture conservées. Une écriture suivie d'une contrepassation exacte rétablit le solde mais invalide toujours l'empreinte de l'ouverture. Cumul exact de 2×10^18, sens crédit et passage d'exercice sans remise à zéro ni écriture synthétique ; date de début fixe, révisions après réouverture, annulation limitée aux brouillons, preuve propre, permissions/MFA/confirmation récente et idempotence sont aussi couverts. En réel, le créateur avec les deux rôles ne peut approuver sa version. Les gardes SQL protègent contenu, transitions et posting antérieur à une ouverture déjà rapprochée.

Deux nouvelles courses PostgreSQL opposent approbation de couverture et clôture du relevé d'ancrage, puis posting antérieur et clôture couverte. Les deux sessions sont constatées en attente du verrou du livre ; aucun ordonnancement ne laisse une clôture couverte avec ouverture périmée ou une ancienne clôture faussement certifiée. Les suites SQLite et PostgreSQL sont exécutées successivement à cause du stockage de test partagé.

Avant les suites complètes : 11 tests SQLite ciblés (269 assertions), puis 13 tests PostgreSQL ciblés (314 assertions, dont les deux courses). Les références de preuves de clôture dans l'audit ont ensuite été ajoutées et vérifiées dans les suites complètes finales. Le premier essai SQLite avait révélé une fixture de rôle utilisant un attribut non mass-assignable : correction de la fixture avec forceFill, sans modification des autorisations de production.

Relecture locale des invariants et du contrat ; aucun dépôt Git pour revue différentielle/commit. Aucun paquet ajouté ; aucun prestataire réel, navigateur, paiement, retrait ou publication déclaré validé. La couverture ne concerne que les comptes explicitement configurés ; authenticité externe et disponibilité restent inconnues (available_funds=null, availability_verified=false, provisional=true). Lettrage, dépenses/retraits, autres événements/valorisations et interfaces restent obligatoires. Voir INCREMENT_COUVERTURE_TRESORERIE.md. Les XML sont les preuves courantes ; les sections suivantes conservent leurs résultats historiques.

## Suite du 4 novembre 2026 — reports de trésorerie et clôtures historiques

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 332 recensés, 304 exécutés, 28 réservés à PostgreSQL ; 7281 assertions, aucun échec ; 1 min 58 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 332 tests, 7964 assertions, aucun échec ni test ignoré ; 9 min 23 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.22.0, 304 chemins ; types synchronisés, script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués et format:check réussi |
| Base locale | Migration 000026 appliquée à kora sans réinitialisation ; livres/écritures et trésorerie restent à zéro, garde-fou PostgreSQL commun renforcé |

Dix nouveaux tests fonctionnels couvrent les deux sens de décalage, règlement au relevé suivant, renouvellement explicitement réexaminé, passage d’un exercice clôturé au suivant, annulation avant rapprochement, snapshots conservés après règlement et réouverture/reclôture, preuve/empreinte, portées/dates/brouillons, montants agrégés supérieurs à 10^18, deux écarts opposés de net nul et double contrôle en réel. Les places SQL nulles ne permettent pas de contourner les décisions actives. Droits/MFA/confirmation récente et idempotence sont vérifiés.

Deux courses PostgreSQL constatent les deux sessions réellement en attente du verrou de livre : déclaration en attente et rapprochement ne peuvent réclamer la même ligne simultanément ; deux règlements d’une ligne héritée d’un relevé précédent ne la consomment qu’une fois. Les suites complètes ont été exécutées successivement pour préserver le stockage de test partagé.

Un premier essai PostgreSQL a révélé l’acceptation d’une modification du snapshot de clôture : TG_ARGV nul dans le garde-fou générique sans argument rendait la comparaison indéterminée. Correction additive dans 000026, avec régression sur les anciennes lignes de relevé, avant les 23 contrôles ciblés réussis puis les suites finales. Aucun relevé ou clôture réel n’existait dans la base locale ; aucune opération financière n’a été effectuée.

Relecture locale des invariants, sans dépôt Git pour revue différentielle/commit. Aucun paquet ni prestataire ajouté, pas de publication, de navigateur ou d’antivirus réel déclaré validé. Le solde d’ouverture comptable, la couverture exhaustive, les disponibilités réelles, les allocations partielles, dépenses/retraits, lettrage et interfaces restent ouverts. Voir INCREMENT_REPORTS_TRESORERIE.md. Les XML sont les preuves courantes ; les sections suivantes conservent leurs chiffres historiques.

## Suite du 4 novembre 2026 — rapprochements de trésorerie

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 320 recensés, 294 exécutés, 26 réservés à PostgreSQL ; 7 028 assertions, aucun échec ; 3 min 21 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 320 tests, 7 661 assertions, aucun échec ni test ignoré ; 13 min 16 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.21.0, 300 chemins ; types synchronisés, script rejoué avec empreinte identique ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués puis format:check réussi |
| Base locale | Migration 000025 appliquée à kora sans réinitialisation ; correspondances, relevés, lignes et groupes à zéro |

Douze tests fonctionnels couvrent soldes/preuve/précision, identité et portée des lignes, sens débit/crédit explicite, regroupement de versement net avec frais déjà comptabilisés, idempotence, versions concurrentes, annulation sans perte d’historique, continuité/successeurs, révocation de preuve avant clôture, garde-fous SQL de contenu/ajout, calendrier et droits/MFA/confirmation récente/examen comptable avant usage réel. Les données et frais de test sont fictifs et isolés.

Deux courses PostgreSQL constatent effectivement les deux sessions en attente du verrou du livre : un seul groupe consomme les mêmes lignes ; clôture du relevé et nouveau posting ne peuvent produire simultanément un état rapproché avec mouvement supplémentaire. Les suites complètes sont exécutées successivement pour préserver le stockage de test partagé.

Relecture locale des invariants ; pas de dépôt Git pour revue différentielle/commit. Aucun nouveau paquet, fonds réel, transfert, publication ou communication externe. Le scanner de test est simulé, sans validation antivirus réelle ni navigateur. Soldes d’ouverture comptables, décalages de dates/éléments en circulation, couverture complète, disponibilité réelle, lettrage, dépenses/retraits et interfaces restent ouverts. Détails : INCREMENT_RAPPROCHEMENTS_TRESORERIE.md. Les XML référencés plus bas sont les preuves courantes ; les nombres des sections antérieures restent historiques.

## Suite du 4 novembre 2026 — capture comptable des événements

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 306 recensés, 282 exécutés, 24 réservés à PostgreSQL ; 6 768 assertions, aucun échec ; 2 min 19 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 306 tests, 7 353 assertions, aucun échec ni test ignoré ; 15 min 27 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.20.0, 289 chemins ; types synchronisés et script rejoué sans modification d’empreinte ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués puis format:check réussi |
| Base locale | Migration 000024 appliquée à kora sans réinitialisation ; données comptables à zéro, aucun livre activé ; job constaté chaque minute et essai local sans effet financier |

Douze nouveaux tests fonctionnels couvrent activation explicite et droits/MFA/confirmation récente, début de périmètre versionné non repoussable, capture unique sans posting ni accusé des autres consommateurs, reprise historique bornée sans inventer de facture/paiement/dette, notifications seulement après émission/vérification, les sept familles, exclusions test/démonstration/consignation/quarantaine, anomalies visibles, scoping des reçus et reprise auditée. Le scénario de panne écrit d’abord une source puis échoue : le savepoint annule la source et conserve la tentative ; cinq échecs consécutifs bloquent les reprises automatiques, puis une reprise autorisée aboutit sans perdre l’historique.

Les indicateurs consultent aussi les faits sans notification. La clôture reste bloquée sur des candidats non traités ou captures en anomalie même si le worker a été mis en pause ; une capture réussie ne dispense pas de préparer et valider l’écriture. Le déplacement du début vers le passé reprend les anciennes exclusions correspondantes. Deux livres conservent des reçus indépendants.

Deux nouvelles courses PostgreSQL observent réellement les deux sessions en attente du verrou de livre : deux workers capturent une seule source et une seule tentative ; capture manuelle et automatique partagent une source sans double audit de création. Les premiers essais ont révélé un champ id absent du résultat du worker de test ; le format de sortie a été corrigé avant les deux courses réussies et la suite finale. Les suites complètes sont exécutées successivement pour préserver le stockage de test partagé.

Relecture locale des invariants ; aucun dépôt Git disponible pour revue différentielle ou commit. Aucune nouvelle dépendance, transaction financière réelle, publication ou communication externe. Les éditions restent provisoires. Rapprochements, autres familles d’événements, résolution métier enrichie des anomalies, valorisation complète, états réglementaires et interfaces restent obligatoires. Aucun navigateur, antivirus réel, stockage distant ou planificateur système en exploitation déclaré validé. Voir INCREMENT_CAPTURE_COMPTABLE.md.

## Suite du 4 novembre 2026 — premier socle comptable

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 292 recensés, 270 exécutés, 22 réservés à PostgreSQL ; 6 283 assertions, aucun échec ; 1 min 37 s ; preuves/backend-sqlite.xml |
| PostgreSQL, suite complète finale | 292 tests, 6 778 assertions, aucun échec ni test ignoré ; 7 min 56 s ; preuves/backend-postgresql.xml |
| Contrat API | OpenAPI 0.19.0, 284 chemins ; types synchronisés et script rejoué sans modification d’empreinte ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués, puis format:check réussi |
| Base locale | Migration 000023 appliquée à kora, cible vérifiée, sans réinitialisation ; livres/comptes/exercices/schémas/sources/écritures restent à zéro |

Vingt nouveaux tests fonctionnels couvrent absence de données comptables préremplies, identité obligatoire du livre réel, équilibre, montants exacts et auxiliaires, isolation des livres, versions du plan et des schémas, examen comptable, pièces privées, immutabilité SQL, contrepassation exacte unique et dans un exercice ultérieur ouvert, clôtures/réouvertures auditables, chronologie et numérotation d’exercices courts commencés dans la même année. Les rapports conservent des totaux exacts de 2 000 000 000 000 000 000 XAF et leur ouverture dans l’exercice choisi.

Les sept types de sources sont exercés : factures/avoirs émis, encaissement confirmé hors test, remboursement payé, dette fournisseur, paiement fournisseur vérifié et réception au coût réel accepté. Les schémas sont explicites ; aucune ventilation financière réelle n’est validée par les valeurs fictives des tests. Capture unique, brouillon de source protégé, source modifiée et schéma suspendu/périmé bloquants sont vérifiés.

Deux nouvelles courses PostgreSQL observent deux sessions réellement en attente du verrou de livre : une seule validation consomme un numéro et crée une révision ; création d’un brouillon et clôture ne laissent jamais de brouillon dans une période fermée. Les suites complètes s’exécutent successivement pour préserver le stockage de test partagé.

La première exécution PostgreSQL a révélé un ordre incorrect de création de clé étrangère autoréférente et une clé de numérotation trop longue pour le registre des documents commerciaux. Corrections : clé étrangère après création de la table et séquences comptables composites dédiées. Les preuves finales ci-dessus sont postérieures à ces corrections. Les fixtures de test ont aussi été corrigées ; le serveur PostgreSQL local a été redémarré avant vérification.

Relecture locale des invariants et du §22 ; aucun dépôt Git disponible pour revue différentielle ou commit. Aucun paiement, remboursement réel, message externe, déploiement ou nouvelle dépendance. Antivirus, stockage distant, parcours navigateur, exhaustivité comptable, rapprochements, valorisation complète et états réglementaires non déclarés validés. Détail : INCREMENT_COMPTABILITE.md. R22 et V35 restent partiels ; V34/V36 sont couverts côté backend, avec parcours interactifs restant obligatoires.

## Suite du 4 novembre 2026 — utilisation des points au paiement

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète finale | 270 recensés, 250 exécutés, 20 réservés à PostgreSQL ; 5 788 assertions, aucun échec ; `preuves/backend-sqlite.xml` ; durée 3 min 36 s |
| PostgreSQL, suite complète finale | 270 tests, 6 249 assertions, aucun échec ni test ignoré ; `preuves/backend-postgresql.xml` ; durée 7 min 41 s |
| Vérifications ciblées | Régression initiale : 55 tests/1 946 assertions SQLite ; checkout initial : 13 tests/601 assertions SQLite ; PostgreSQL ciblé : 15 tests/700 assertions, dont les deux nouvelles courses |
| Contrat API | OpenAPI 0.18.0, 258 chemins ; types synchronisés ; script rejoué sans modification d'empreinte ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier/Pint appliqués, puis `format:check` réussi |
| Base de développement | Migration 000022 appliquée à `kora` sans réinitialisation ; politique d'échange désactivée, règles nulles, budget consommé nul, zéro devis/consommation fictifs ; tâche de rapprochement constatée chaque minute dans `schedule:list` |

Les 15 tests fonctionnels du nouveau checkout couvrent devis serveur sans réservation, total exact, propriété du titulaire et absence de coûts privés, réservation puis consommation unique au paiement vérifié, expiration et validité d'origine des lots. Les plafonds, minimum à payer et autorisation propriétaire des pertes sont testés. Le seuil de livraison promotionnelle utilise le prix effectivement payé après remise.

Remise de 500 XAF sur trois unités : remboursement partiel 834 puis 1 666 XAF et restitution 33 puis 67 points, sans paiement monétaire de la remise. Pour une rupture totale, les frais nets de livraison sont ajoutés : facture 5 000 XAF, avoirs 834 et 4 166 XAF. Les règles de restitution initiales survivent à une modification de politique ; les demandes fournisseur gardent leurs tranches de quantités en attente du versement. Les gains initiaux attendent la résolution des remboursements d'unités déjà annulées.

Les points du scénario V40 sont réellement gagnés sur une livraison de fixture, puis consommés dans le checkout d'une deuxième commande : le remboursement vérifié de la première n'est pas bloqué et son gain peut être repris en négatif. Les PDF de facture FR/EN sont générés avec les remises figées ; aucune nouvelle preuve visuelle de mise en page n'est déclarée.

Paiement tardif : aucune double consommation après une autre utilisation des points ; incident explicite, prix inchangé et clôture financière auditée sans débit inventé. Un budget dépassé après succès tardif conserve le coût réel et crée son incident. Le gain est suspendu tant que ces incidents sont ouverts.

Les deux nouvelles courses PostgreSQL lancent de vrais processus PHP, avec deux sessions observées en attente de verrous : un seul checkout réserve les mêmes points ; deux titulaires ne dépassent pas le dernier budget monétaire. Ce sont des transactions réellement concurrentes, pas deux appels séquentiels. Les suites complètes s'exécutent successivement à cause du stockage de test partagé.

Tous les paiements, preuves de versement et événements de remise sont contrôlés dans les fixtures. Aucun encaissement, remboursement réel, prestataire externe, message ou déploiement ; aucune dépendance ajoutée. Écrans, parcours interactifs, remise physique vérifiée, avantages automatiques des niveaux, SAV complet et comptabilisation restent obligatoires. Voir INCREMENT_CHECKOUT_POINTS.md.

## Suite du 4 novembre 2026 — carte et registre de fidélité

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète | 253 recensés, 235 exécutés, 18 réservés à PostgreSQL ; 5 086 assertions, aucun échec ; `preuves/backend-sqlite.xml` ; durée 1 min 22 s |
| PostgreSQL, suite complète | 253 tests, 5 448 assertions, aucun échec ni test ignoré ; `preuves/backend-postgresql.xml` ; durée 5 min 49 s |
| Vérifications ciblées | Première série PostgreSQL : 14 tests, 519 assertions, dont deux courses réelles ; les 17 tests fonctionnels de fidélité et les 2 courses sont inclus et réussis dans les suites complètes, sauf courses explicitement réservées à PostgreSQL |
| Contrat API | OpenAPI 0.17.0, 254 chemins ; types générés synchronisés ; script rejoué sans modification d'empreinte ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | `format` puis formatage ciblé des dernières corrections ; Prettier et Pint conformes avec `format:check` |
| Base de développement | Migration 000021 appliquée à `kora`, sans réinitialisation ; programme désactivé, règles nulles, zéro point engagé et zéro carte créés ; tâche de rapprochement constatée chaque minute dans `schedule:list`, puis exécutée localement : 0 commande/compte traités |

Les nouveaux tests couvrent carte unique par compte, jeton QR opaque/révocable et consultation anonyme minimale sans authentification du compte, isolement du titulaire, absence de barème initial, conditions figées, paiement seul sans gain, attribution unique, catégorie exclue, plafond/arrondi, budget insuffisant et reprise après financement, délai de validation, expiration et répétition des tâches. Deux processus PostgreSQL attendent réellement le verrou commun : une commande donne un seul gain ; deux comptes ne dépassent pas le dernier budget de points.

Les retours sont attribués à un remboursement versé et vérifié, avec plafonds de quantité et montant. Tests avant livraison, pendant validation et après expiration, y compris sans passage préalable du planificateur. Un retour antérieur au premier gain diminue la base avant arrondi. Les annulations/reprises/expirations précédentes ne sont pas comptées deux fois ; les crédits disponibles compensent les reprises sans dette réapparue à leur expiration. Les ruptures fournisseur structurées sont attribuées automatiquement après le versement vérifié.

Le niveau dépend des dépenses nettes glissantes, sans diminution lors d'une correction du solde. Solde négatif autorisé et remboursement monétaire non bloqué dans les scénarios de test. La réduction préalable du solde utilise ici une correction autorisée, pas une dépense de points au checkout : ce dernier parcours reste à livrer.

Les tests utilisent un paiement contrôlé et une fixture de `delivered_at`. Aucune livraison physique ni mission vérifiée n'est prétendue effectuée ; le nouveau module n'expose aucun endpoint pour écrire cette date. Restent obligatoires : consommation/réservation des points au paiement, remise et restitution par ligne, avantages financés, comptabilisation, carte/QR graphiques et écrans/parcours interactifs. Aucune dépendance installée ; `Brick\Math` est déjà requis par Laravel. Aucun service externe, transfert, message ou déploiement exécuté. Voir INCREMENT_FIDELITE.md.

## Suite du 4 novembre 2026 — profils et devis professionnels

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète | 234 recensés, 218 exécutés, 16 réservés à PostgreSQL ; 4 430 assertions, aucun échec ; `preuves/backend-sqlite.xml` |
| PostgreSQL, suite complète | 234 tests, 4 740 assertions, aucun échec ni test ignoré ; `preuves/backend-postgresql.xml` ; durée 12 min 18 s |
| Vérifications ciblées | 13 tests PostgreSQL initiaux, 394 assertions, dont conversion simultanée ; contrôle de révocation/rejeu ajouté ensuite et réussi dans les deux suites complètes |
| Contrat API | OpenAPI 0.16.0, 241 chemins ; types synchronisés, génération du contrat idempotente ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | Prettier et Pint conformes après `format`, puis `format:check` |
| Base de développement | Migration 000020 appliquée à `kora` sans réinitialisation ; expiration professionnelle constatée chaque minute dans `schedule:list` |

Les scénarios métier couvrent contacts vérifiés et isolation des entreprises, rôles du profil sans octroi de rôle KORA, adresses communes, demande indépendante du panier personnel, révisions immuables avec empreinte, consentement au total exact, refus et expiration sans attendre le planificateur. Une ancienne clé idempotente ne contourne plus une révocation ou rétrogradation.

La conversion conserve les prix produits et livraison malgré une hausse du catalogue/de la grille, refuse une substitution de garantie et emprunte le paiement ordinaire. La facture conserve l'identité acceptée malgré une modification du profil ; ses PDF FR/EN sont générés dans les tests, sans nouvelle preuve visuelle de mise en page. Le rapprochement d'un paiement simulé déjà engagé continue après révocation du payeur, qui ne peut plus consulter ou payer la commande.

La nouvelle course PostgreSQL fait attendre deux processus sur le même profil professionnel avant conversion : les deux réponses renvoient la même commande ; une seule commande, un événement de conversion et une réservation active de créneau existent.

Restent ouverts : écrans et parcours interactifs, PDF/export du devis, négociation de quantités enrichie, cumul de campagnes et intégration Monetbil réelle. Aucun nouvel appel prestataire, paiement réel, message externe, dépendance ou déploiement. Voir INCREMENT_DEVIS_PROFESSIONNELS.md.

## Suite du 4 novembre 2026 — affectations achats et réceptions

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète | 220 recensés, 205 exécutés, 15 réservés à PostgreSQL ; 4 041 assertions, aucun échec ; `preuves/backend-sqlite.xml` |
| PostgreSQL, suite complète | 220 tests, 4 316 assertions, aucun échec ni test ignoré ; `preuves/backend-postgresql.xml` ; durée 5 min 18 s |
| Vérifications ciblées | 11 tests d'affectation initiaux + 2 courses PostgreSQL réussis ; preuve de mise à niveau des réservations et maintien après paiement tardif réussie sur PostgreSQL |
| Contrat API | OpenAPI 0.15.0, 225 chemins ; types générés synchronisés ; 8 tests npm réussis ; régénération du contrat sans modification d'empreinte |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | `format` puis `format:check` : Prettier et Pint conformes |
| Base de développement | Migration 000019 appliquée à `kora` sur le cluster isolé 127.0.0.1:55432, sans réinitialisation |

Les deux nouvelles courses font réellement attendre deux processus PHP sur le fournisseur partagé : une seule affectation pour le dernier article acheté, une seule réservation pour l'unique article reçu. Les contrôles comprennent aussi la concurrence existante du stock, des paiements/choix, des promotions et des remboursements. Ils ne constituent pas des encaissements Monetbil réels ni une preuve de livraison.

Le test de migration d'une ancienne réservation payée est spécifique à PostgreSQL : SQLite ne peut pas reconstruire cette table référencée dans la transaction des fixtures. Il s'ajoute aux deux nouvelles contentions PostgreSQL et aux 12 contrôles déjà réservés à ce moteur. Les suites complètes s'exécutent successivement et leurs rapports remplacent les anciens chiffres ci-dessous.

Aucun nouveau frontend, serveur web, paiement, achat externe, notification réelle ou déploiement effectué. Aucune dépendance ajoutée ; les audits précédents ne sont pas présentés comme réexécutés. Détail : INCREMENT_AFFECTATIONS_COMMANDES.md.

## Suite du 4 novembre 2026 — confirmations fournisseur et choix client

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète | 206 recensés, 194 exécutés, 12 réservés à PostgreSQL ; 3 536 assertions, aucun échec ; `preuves/backend-sqlite.xml` |
| PostgreSQL, suite complète | 206 tests, 3 728 assertions, aucun échec ni test ignoré ; `preuves/backend-postgresql.xml` ; durée 3 min 58 s |
| Vérifications ciblées | `OrderSupplyTest` : 11 tests, 414 assertions SQLite ; course PostgreSQL de deux choix client réussie avant inclusion dans la suite complète |
| Contrat API | OpenAPI 0.14.0, 220 chemins ; types régénérés et synchronisés ; 8 tests npm réussis |
| TypeScript/Astro | 0 erreur ; Astro : 15 fichiers, 0 avertissement/indice |
| Formatage | `format` puis `format:check` : Prettier et Pint conformes |
| Développement local | Migration 000018 appliquée sans réinitialisation à `kora` sur 127.0.0.1:55432 ; tâche `orders:expire-supply --limit=100` constatée dans `schedule:list` |

La course observe deux processus indépendants en attente du même verrou de commande avant libération : un choix seulement, au plus une demande de remboursement, version incrémentée et événement uniques. Les tests fonctionnels couvrent confirmations partielles, attente/reprise, montants acceptés, restitution de livraison et de promotion, confidentialité FR/EN, facture inchangée/avoir lié, expiration idempotente, ruptures successives et réponse obsolète après annulation d'unités.

Ces preuves ne constituent ni un achat fournisseur, ni une collecte, ni un transfert réel. Tests avec adaptateur de paiement et scanner contrôlés, bases isolées et suites backend successives. Aucun nouveau frontend, serveur web, notification externe, build ou audit de dépendances n'est revendiqué. Détails : INCREMENT_CONFIRMATIONS_COMMANDES.md. Les rapports backend sont actualisés et remplacent ceux de l'étape précédente ; ses chiffres ci-dessous sont historiques.

## Reprise du 4 novembre 2026 — promotions de livraison

| Vérification exécutée | Résultat |
| --- | --- |
| SQLite, suite complète | 194 tests recensés, 183 exécutés, 11 réservés à PostgreSQL ; 3 122 assertions ; aucun échec ; `preuves/backend-sqlite.xml` |
| PostgreSQL, suite complète | 194 tests exécutés, 3 300 assertions, aucun échec ni test ignoré ; `preuves/backend-postgresql.xml` ; durée 5 min 29 s |
| `npm test` | 8 tests réussis, contrôles de routes/permissions/reconfirmation étendus au contrôleur promotions |
| `npm run check` | TypeScript Next et Astro : 0 erreur ; Astro 15 fichiers, 0 avertissement/indice |
| Contrat API | OpenAPI 0.13.0, 217 chemins ; types générés synchronisés (`api:contract-check`) ; régénération du contrat promotions sans modification de son empreinte |
| `npm run format` et `format:check` | Format appliqué ; Prettier et Pint conformes |
| Base locale de développement | Lecture seule : base `kora`, répertoire du cluster `.local/postgres` et migration promotions 000017 confirmés ; aucune réinitialisation de cette base |

`DeliveryOffersTest` comporte désormais 10 tests (371 assertions en exécution ciblée SQLite) : limite de trois utilisations, prix unitaire payé 4 999/5 000, sélection et zone, totalité/forfait et suppléments, budget, expiration, campagne modifiée, dates UTC, reconfirmation, paiement tardif/rejeu et facture figée avec PDF FR/EN. Les deux nouvelles contentions PostgreSQL vérifient séparément le budget global et la dernière utilisation d'un même client (2 tests ciblés, 24 assertions, puis inclus dans la suite complète). Les processus attendent réellement le même verrou avant d'être libérés ; ces tests portent sur la réservation du domaine, pas sur deux encaissements Monetbil réels. La fixture réutilise le même panier pour les commandes d'un client, conformément à sa contrainte d'unicité.

Les suites SQLite et PostgreSQL sont exécutées successivement car elles partagent le disque de fichiers simulé. Aucun écran, aperçu navigateur, paiement réel, message externe ou déploiement n'est validé dans cette reprise ; les audits de dépendances précédents ne sont pas annoncés comme réexécutés. Détails et limites : INCREMENT_PROMOTIONS_LIVRAISON.md.

## Historique des vérifications précédentes

| Vérification exécutée | Résultat final |
| --- | --- |
| Lecture du prompt | Intégrale, 968 lignes, 38 sections, 110 scénarios conservés |
| Références | Cinq PNG inspectés, copies et logo des deux apps identiques aux originaux ; test SHA-256 |
| Installation | Dépendances npm/Composer verrouillées, Node projet 22.23.4 ; adaptateur S3 installé ; GD/EXIF/fileinfo déclarés |
| Migrations locales | Quatorze migrations appliquées à kora sur le cluster isolé 127.0.0.1:55432 |
| npm run check | TypeScript Next et Astro : 0 erreur ; Astro 15 fichiers, 0 avertissement/indice |
| npm run build | Builds Next/Astro réussis lors de l’incrément catalogue ; aucune interface modifiée dans l’incrément backend, aucun nouveau build revendiqué |
| npm test | 8 tests réussis : 110 scénarios, références, contrat/sécurité/routes/permissions/propriété et HTML serveur |
| npm run api:contract-check | Types générés identiques à docs/openapi.json |
| npm run api:test | SQLite : 139 recensés, 133 exécutés, 6 réservés à PostgreSQL ; 1 892 assertions ; preuves/backend-sqlite.xml |
| npm run api:test:pgsql | PostgreSQL : 139 tests, 2 012 assertions, aucun ignoré ; preuves/backend-postgresql.xml |
| HTTP développement actuel | 14 tests réussis, dont nouveaux modules privés via API/deux proxys ; preuves/http-developpement.xml |
| HTTP des builds catalogue précédents | 12 contrôles réussis ; preuves/http-production.xml |
| CSP des builds catalogue précédents | 2 contrôles réussis ; preuves/csp-production.xml |
| Syntaxe PHP | Dernier contrôle : 140 fichiers (app, migrations, routes, config, langues, tests), aucune erreur ; nouveaux fichiers panier/indicateurs à inclure au prochain contrôle |
| Installation/reprise | api:setup conserve .env ; db:start retrouve le cluster isolé sans recréation ; migration:status vérifié |
| Composer | composer validate réussi ; composer audit après ajout S3 : advisories et abandoned vides |
| npm audit --omit=dev | Dernier audit : 0 vulnérabilité ; aucune dépendance npm ajoutée depuis cet audit |

Les suites PHP SQLite et PostgreSQL ont été exécutées séquentiellement pour leur dernier résultat ; elles partagent le répertoire du disque de fichiers simulé par Laravel. La base PostgreSQL de tests est exclusivement kora_test. Aucun test n'utilise la base consultée par les aperçus.

Demandes de produits : 14 tests fonctionnels et une nouvelle contention réelle de deux processus PostgreSQL. Isolation compte/session, fichiers privés, reprise après connexion, notes internes, brouillon catalogue, alternative et notification après publication sont couverts ; détails dans INCREMENT_DEMANDES_PRODUITS.md.

## Ce que les tests démontrent

Identité : permissions serveur et refus visiteur/client/livreur/fournisseur sur la gestion ; MFA vérifié côté session ; inscription/rôles forgés, Bearer alternatif, récupération/TOTP/codes uniques et révocation de sessions. Les tests de la première étape restent inclus.

Catalogue : création/publication fournisseurs-boutiques-catégories-produits-variantes-offres avec DTO publics sans coûts/contacts ; brouillon invisible jusqu'à publication ; conflits de version ; propriété des variantes et SKU ; dépendances d'archivage ; ancien slug de boutique résolu et non réattribuable. L'archivage d'une boutique ne permet pas de remplacer son fournisseur historique.

Attributs : choix traduits, champ requis contrôlé à la publication, refus d'un attribut inconnu, nombres décimaux normalisés sans flottants et booléen faux conservé. Filtres JSON vérifiés sur PostgreSQL et SQLite ; une catégorie parente retrouve les offres des descendants ; publication d'une catégorie refusée si elle invalide les produits publiés ou crée une boucle. Les définitions alimentent les formulaires et filtres HTML serveur FR/EN, testés avec des fixtures dans le processus de tests.

Prix : accord 3 % sur 5 000 donne achat 4 850 et écart 150 ; promotion KORA 4 900 conserve 4 850 et écart 50. Résolution produit/catégorie/fournisseur, paliers, coût fixe, périodes, ambiguïtés et chevauchements ; retrait idempotent et nouvel accord sans réécriture des révisions publiées. Simulation future distincte du prix actuel, expiration sans tâche planifiée, marge négative future refusée au rôle catalogue et dérogation propriétaire motivée conservée. Totaux au-delà de 2^53 transmis en chaînes décimales et affichés exactement via BigInt. Les calculs métier internes restent entiers.

Médias : contrôle du contenu JPEG/PNG/WebP, refus PHP/SVG/plus de 2 Mo/dimensions excessives, chemins serveur opaques ; image privée protégée contre un autre gestionnaire ; publication conditionnée aux droits ; retrait public effectif. Orientation EXIF corrigée, commentaire/métadonnées absents du WebP, transparence et redimensionnement vérifiés. Échec d'écriture DB : transaction annulée et nouvel objet supprimé. Publication d'une scène avec image privée refusée ; après publication du média elle réussit, puis le retrait du média retire la scène publique.

Carrousel : ancienne publication conservée, versions, restauration en brouillon, dates/priorités/pause, FR/EN et prix actuel ; première scène/repli SSR. Création API renvoyant version et état paused vérifiée après correction d'un défaut de retour des valeurs initialisées par la base.

HTTP/builds : pages accueil/catalogue/gestion FR/EN, filtres conservés, pages privées, CSRF hors raccourci PHPUnit, cookies via les deux proxys, aperçu privé SAMEORIGIN, erreurs localisées et administration catalogue/prix/médias inaccessible aux visiteurs via les proxys. CSP des builds : hashes exacts Astro, nonce frais de chaque réponse Next et refus d'encadrement du back-office.

## Échecs rencontrés et corrigés

- Installation initiale et démarrage du cluster Windows nécessitant les droits adaptés : effectués avec accès autorisé, TLS conservé, bases existantes inchangées.
- Clé étrangère de catégorie initialement créée avant sa clé primaire : migration corrigée, PostgreSQL ensuite réussi.
- Guard conservé entre requêtes PHPUnit après déconnexion : test reconstruit pour représenter une nouvelle requête.
- SSR anglais initialement lang=fr-CM : locale résolue avant rendu par le proxy.
- CSP Astro émise par l'adaptateur sous forme d'en-tête : test corrigé pour vérifier la politique effective.
- Valeur de version absente de la réponse de création de scène : modèle relu après insertion avant audit/réponse ; parcours création–publication maintenant testé avec la version renvoyée.
- Assertion utilisant le format Laravel errors alors que l'API expose error.fields : assertion ajustée au contrat réel, sans supprimer le contrôle.
- Première exécution des deux suites PHP simultanée interrompue pour éviter le partage de Storage::fake ; dernières exécutions séquentielles réussies.
- Métadonnées de Composer : cache utilisateur restreint non inscriptible ; audit réalisé avec un cache de projet, résultat sans avis de sécurité.
- Test d'ancre après deux réceptions enregistrées dans la même seconde : ordre précisé par la séquence d'audit. La première jointure PostgreSQL UUID/texte a échoué ; conversion explicite corrigée, puis suite complète réussie. Aucune ancienne échéance déjà figée n'est réécrite.

## Vérifications non exécutées ou insuffisantes

Le navigateur intégré échoue avant toute navigation : métadonnée sandboxPolicy manquante. Dernier essai lors de l’incrément catalogue ; aucune nouvelle preuve visuelle dans l’incrément backend. Aucune capture ni preuve interactive mobile, dialogues/focus, clavier, lecteur d'écran, clair/sombre, swipe/autoplay, zoom ou Core Web Vitals. Les tests de rendu HTML serveur n'exécutent pas les interactions navigateur.

Aucun bucket S3 réel ni test de panne distante/reprise d'objets vérifié. Les modèles/export contractuels PDF/DOCX et la validation documentaire complète restent à construire. Les tests fichiers utilisent le disque de test local, pas un succès distant simulé.

Pas encore de commande/checkout de bout en bout, paiement Monetbil, IA/embeddings, message réel, autres workers métier, audit ASVS complet ou restauration. Réservations, achats/réceptions et dettes fournisseurs sont testés en API ; trois scénarios ont une preuve de concurrence réelle PostgreSQL, sans prétendre couvrir tous les futurs paiements. Les révisions de catalogue ne remplacent pas les lignes de commande figées. Les conflits de version séquentiels ne constituent toujours pas une preuve de concurrence ; seuls les tests multiprocessus apportent cette preuve pour les scénarios décrits ci-dessous.

Toutes ces exigences restent obligatoires dans EXIGENCES.md. Aucune intégration privée non configurée ni donnée commerciale inventée présentée comme opérationnelle.


## Troisième incrément : stock, approvisionnement et dette

Rapports finaux backend-sqlite.xml et backend-postgresql.xml : les 54 tests existants restent inclus. 85 tests ajoutés couvrent documents, contrat, achat, réception, stock, règlement, échéances, rapprochement et montants et suivi interne ; quatre nécessitent PostgreSQL et sont explicitement ignorés sur SQLite. Les suites sont exécutées successivement, sans partage simultané du disque simulé.

- Stock : politique explicite, réservation sans surallocation/doublon, expiration CLI idempotente avec audit système sans faux acteur, absence de substitution d’offre/garantie. Séries/IMEI, expiration/quarantaine, transferts d’unités exactes, inventaires/manquants/récupération et protection des réservés/consommés ; absence de coûts dans la vue magasinier.
- Achats : réception sans règlement implicite, facture avant/après réception, échéancier unique, ancres facture/réception/fixe en jours Douala, conditions figées. Excédents indisponibles avant avenant, quantité d’origine conservée, engagement augmenté au coût accepté.
- Factures : correction/annulation de saisie avant approbation, restitution des compteurs, anciennes versions/montants conservés, approbation avec version attendue, dette unique ; facture approuvée non réinscriptible.
- Dette : 100 000 moins 30 000 vérifiés laisse 70 000 ; demande non vérifiée sans règlement. Paiements constatés distincts des intentions, référence unique par compte, multifactures/avances/avoir après paiement, affectations append-only et solde historique, refus de suraffectation. Retours distincts des avoirs acceptés.
- Dépôt-vente : propriété fournisseur et aucun dû sur réception/invendus ; vente constatée puis relevé unique sur quantité non rapprochée, coût figé sans seconde remise. Ce flux manuel n’est pas un paiement client Monetbil.
- Échéances : répartition exacte jusqu’à 10^18, échéances nulles après répartition de très petits montants admises, versions renégociées conservées, reliquat calculé avant filtre. Plafond/dérogation motivée testés ; alertes/prévisionnel fournisseurs ajoutés ci-dessous ; trésorerie complète encore absente.
- Documents : scanner absent => pending, pièces infectées refusées, téléchargement privé avec octets/SHA-256 identiques, altération détectée et bloquée, droits finance sur justificatifs. Scanner contrôlé seulement dans les fixtures ; aucun résultat ClamAV réel.
- Relevé/chronologie : FR/EN, historique à date de connaissance, isolation fournisseur, permissions, versions de contrats/factures et lignes de réception consultables. Écrans et exports non réalisés.
- Sécurité : MFA, confirmation récente finance (absence/expiration => refus), import hors transaction stock/finance, déduplication et conflit de contenu ; erreur DB journalisée sans SQL/paramètres/contenu privé.
- Contrat : 108 chemins OpenAPI implémentés au total ; types régénérés et test de correspondance des routes/permissions/idempotence/reconfirmation.

Concurrence : PostgresConcurrencyTest lance deux processus PHP indépendants sur kora_test, bloque leur ressource commune et vérifie que les deux sessions attendent réellement un verrou avant libération. Deux réservations de 7 sur 10 : une réussit, l’autre est refusée ; deux affectations de 60 000 sur 100 000 : une réussit, l’autre est refusée, reliquat 40 000. La première version du test avait une statistique d’activité figée dans la transaction ; pg_stat_clear_snapshot() avant chaque lecture a rétabli l’observation des deux sessions, sans remplacer la concurrence par une exécution séquentielle.

Limites : pièces juridiques/schéma comptable non validés professionnellement ; ClamAV/S3 réels non configurés/testés ; autres handlers d’outbox, exports, écrans, checkout et liaison commande/prestataire absents. Pas de transfert, envoi ou publication réelle.


## Suite du troisième incrément : rappels et prévisionnel

Les rapports backend complets incluent PayableMonitoringTest et les nouveaux contrôles PostgreSQL. Politique sans défaut imposé, heure/jours à Douala, répétition, désactivation, résolution/réapparition, droits/MFA, notifications FR/EN et lecture propre à chaque utilisateur testés.

La reprise est vérifiée après une interruption injectée juste après l'INSERT de notification : transaction annulée, aucun effet conservé, tentative échouée inscrite sans payload ; reprise après délai puis un seul effet/reçu. Payload invalide : cinq échecs consécutifs, arrêt, diagnostic privé et reprise propriétaire motivée avec confirmation récente, historique conservé. Les événements sans handler ne sont pas artificiellement marqués traités.

Une troisième concurrence multiprocessus PostgreSQL porte sur le même événement d'outbox : les deux sessions attendent réellement le verrou, puis une seule notification et un seul reçu existent. Un quatrième test spécifique PostgreSQL vérifie un rapport REPEATABLE READ / READ ONLY face à une modification validée par une seconde connexion, et le retour de la connexion hors mode READ ONLY après le rapport.

Les filtres d'échéancier suivent la couverture antérieure des dettes avant période/contrat/état/pagination. Prévisionnel : dette sans échéance séparée, coûts non encore reconnus distincts, avances/avoirs non affectés non soustraits arbitrairement, ventes consignées non encore rapprochées puis reconnues sans double compte. Test avec UUID décroissants et avenants acceptés dans la même seconde : coût de l'excédent restant conforme à l'ordre d'acceptation audité.

Migration 000007 appliquée à kora. schedule:list constate trois commandes chaque minute ; scan et outbox exécutés sur la base locale vide (zéro fournisseur/notification réelle), sans envoi externe. Aucun planificateur système, charge de production, capture navigateur, S3, ClamAV ou trésorerie complète n'est déclaré validé par ces contrôles.

OpenAPI 0.4.0 et types générés : 108 chemins documentés. HTTP développement : 14 tests, avec refus d'accès aux rappels/prévisionnel/diagnostics par API directe et les deux proxys. Aucun nouveau code d'interface ni build de production revendiqué. Détails : INCREMENT_RAPPELS_FOURNISSEURS.md.
