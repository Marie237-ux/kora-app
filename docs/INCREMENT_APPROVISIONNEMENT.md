# Backend stock et approvisionnement — troisième incrément

Priorité demandée par Marie : poursuivre le backend. Aucun module du lancement n'est retiré ; les 38 sections et 110 scénarios restent obligatoires. Cet incrément couvre les traitements/API ci-dessous ; les écrans correspondants et le parcours de vente complet restent à construire.

## Implémenté et vérifié localement

- Documents PDF/JPEG/PNG/WebP privés, 10 Mio maximum : octets originaux et SHA-256 conservés, scan ClamAV configurable, téléchargement en pièce jointe sous droits avec contrôle d'intégrité. Un scan absent/échoué reste pending ; une infection est bloquée. Justificatifs de paiement lisibles uniquement par finance.
- Contrats FR/EN : brouillon, revue, approbation propriétaire, acceptation documentée et résiliation ; versions et conditions figées dans les achats. Aucun envoi ni signature électronique automatique. Génération de modèles/export PDF/DOCX encore à implémenter.
- Profils de variante : suivi simple, lot, série ou IMEI, péremption, mesures physiques explicitement inconnues si non renseignées. Dépôts, emplacements, propriété KORA/fournisseur, contrôles qualité, réceptions partielles, lots et unités exactes.
- Réservations atomiques sur l'offre et les conditions exactes, politique de durée explicitement configurée, expiration/restitution, transferts et retours documentés. Une indisponibilité ne substitue pas une autre offre ou garantie.
- Inventaires physiques : protection des unités réservées, unités manquantes tracées, récupération limitée aux manquants connus du même lot ; aucune réintroduction d'une unité consommée. IMEI unique même entre variantes/fournisseurs.
- Stock fournisseur déclaré : quantité nullable, fraîcheur et préparation ; demande de réservation distincte de confirmation réelle. Libération demandée distincte de libération confirmée ; aucun contact fournisseur automatique.
- Achats avec contrat/prix/profil figés, confirmation référencée, plafond de crédit et dérogation motivée du propriétaire. Réception physique possible avant facture/confirmation, sans paiement fictif. Excédent isolé jusqu'à l'avenant accepté ; quantité commandée originale préservée.
- Factures saisies puis approuvées : dette unique, charges explicites, arrondis XAF exacts et échéances selon facture/première réception/date fixe à Douala. Correction/annulation de saisie avant approbation, versions conservées ; approbation refusée sur une version devenue ancienne.
- Règlements : intention distincte du constat vérifié sur pièce, montant réel distinct du montant demandé, référence réelle unique par compte, affectations partielles/multifactures et avances. Aucun ordre bancaire ni mouvement Mobile Money réel.
- Retours physiques sans effacement arbitraire de dette ; avoir accepté séparément, reliquat non utilisé affectable ultérieurement au même fournisseur. Affectations append-only pour conserver les soldes connus à une date.
- Dépôt-vente : propriété fournisseur, dette seulement sur ventes constatées et relevé approuvé, pas de deuxième remise sur un coût déjà figé. Pas de dette sur les invendus.
- Échéances et renégociations versionnées, montants réglés/restants calculés avant filtrage/pagination ; relevé et chronologie fournisseur FR/EN avec dates de connaissance, pièces, réceptions, paiements, avoirs et versions.
- Permissions/MFA, confirmation du mot de passe de moins de 15 minutes sur les routes finance, commandes transactionnelles avec UUID Idempotency-Key, audit et outbox. Les erreurs SQL sont journalisées sans requête, paramètres ni contenu privé.
- OpenAPI 0.4.0, 108 chemins au total pour les domaines implémentés, types partagés régénérés. Vérification automatique de la correspondance routes stock/achats, permissions et exigences d'idempotence.

## Preuves finales de cet incrément

| Vérification | Résultat |
| --- | --- |
| PHP SQLite | 139 tests recensés, 133 exécutés, 6 réservés à PostgreSQL ; 1 892 assertions, aucun échec |
| PHP PostgreSQL | 139 tests, 2 012 assertions, aucun échec ni test ignoré |
| Concurrence réelle | Deux processus par scénario : réservation unique, affectation financière sans dépassement et une seule notification/reçu d’outbox ; un test multiconnexion distinct vérifie le snapshot |
| Syntaxe PHP | 92 fichiers contrôlés, aucune erreur |
| npm test | 7 tests réussis : matrice intégrale, références, contrat/permissions/routes et HTML serveur |
| Types/contrat | Génération OpenAPI identique ; TypeScript et Astro sans erreur, avertissement ou indice |
| HTTP développement | 14 tests réussis, dont refus des nouveaux accès privés directement et via les deux proxys |
| Migration locale | Dix migrations appliquées à kora ; tests uniquement dans kora_test ou SQLite |

Rapports : preuves/backend-sqlite.xml, preuves/backend-postgresql.xml, preuves/http-developpement.xml. Les tests utilisent des fixtures séparées et un scanner contrôlé explicitement injecté dans les tests : aucun succès antivirus réel n'est inventé.

## Limites maintenues ouvertes

ClamAV local n'est pas installé/configuré ; il faut un moteur et des signatures à jour avant usage réel des pièces. S3 n'est pas vérifié avec un bucket autorisé. Aucun contournement clean en production.

La sortie manuelle de stock protégée par finance ne remplace pas l'orchestration commande/paiement Monetbil. Le plan de stock est interne et ne fournit pas encore les promesses du checkout public. Contrats modèles/export, consultations/avenants d'achat complets, prévisionnel de trésorerie complet, rapprochement comptable, exports sensibles et portails restent à réaliser. Un consommateur de rappels internes est maintenant livré ; autres handlers et exploitation système du planificateur restent ouverts.

Les écrans de gestion de cet incrément ne sont pas encore livrés. Aucun parcours interactif navigateur, paiement prestataire, transfert réel, envoi externe ou publication distante n'est déclaré effectué. Voir AVANCEMENT.md, EXIGENCES.md et UTILISATION_APPROVISIONNEMENT.md.

Suite backend : alertes, rappels internes et prévisionnel fournisseurs décrits dans INCREMENT_RAPPELS_FOURNISSEURS.md. Les chiffres de tests ci-dessus sont ceux de la suite complète actualisée, avec trois concurrences multiprocessus et une preuve de snapshot PostgreSQL.
