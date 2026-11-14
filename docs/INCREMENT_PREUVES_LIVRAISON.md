# Photos de preuve et vérification du livreur — backend

Incrément du 11 novembre 2026, suite de la chaîne d'exécution (§20, scénarios V56–V58). Il complète la remise par code et la livraison directe avec deux éléments demandés par le cahier des charges :
- la photo de preuve facultative ;
- la vérification par le client de l'identité professionnelle du livreur.

## Photos pendant la mission

Le livreur peut ajouter des photos à une mission en cours (`POST courier/missions/{id}/photos`, envoi de fichier) avec un type : `pickup` (colis à l'enlèvement), `handover` (remise), `damage` (dommage), `incident` ou `other`, et une note facultative.

- Seul le livreur affecté peut en ajouter, et seulement tant que la mission est en cours ; après la livraison ou l'annulation, le dossier est fermé.
- Chaque photo est une pièce privée de type `delivery_evidence`, sans fournisseur. Elle passe les contrôles de format, de taille et l'antivirus habituels. Ce type n'est pas accepté par l'envoi générique de documents : il ne s'ajoute que par une mission.
- L'équipe livraison voit la liste dans la mission et télécharge chaque photo par `GET operations/missions/{id}/photos/{photoId}` (`delivery.read`), sans avoir besoin du droit général sur les documents. Le fichier est servi en pièce jointe, sans cache.
- Les photos sont en ajout seul, protégées en SQL (ni modification ni suppression). Elles restent des preuves et **n'empêchent pas un recours ultérieur du client** (§20, §25).

## Vérification du livreur par QR code

Pendant une mission en cours, la vue du livreur contient `verification` : référence de mission, jeton et lien vers la boutique (`/fr/verifier-livreur?reference=…&token=…`). Il l'affiche sous forme de QR code. Le client le scanne et vérifie ainsi le livreur.

`GET /api/v1/courier-verifications?reference=…&token=…` est **public** (sans connexion), limité à 30 requêtes par minute. Il renvoie uniquement : valide, référence de mission, nom public, badge. Le client compare avec le nom et le badge affichés dans sa commande. Aucun téléphone, e-mail ni papier d'identité n'est publié.

Le jeton est une signature liée à la mission, au livreur et à l'affectation. Il n'est pas stocké et devient **invalide de lui-même** :
- à la réaffectation (le nouveau livreur reçoit un nouveau jeton) ;
- à la livraison ou à l'annulation ;
- à la suspension du livreur ; il redevient valide à sa réactivation, si la mission est toujours la sienne.

Un jeton inconnu ou périmé répond « introuvable », sans indiquer pourquoi.

## Migration, contrat et vérifications

Migration additive `2026_11_11_000038_create_delivery_mission_photos`, appliquée à la base locale `kora` (vide). Contrat **0.35.0 / 392 chemins** (`update-mission-proofs-contract.mjs`, après celui de la livraison directe) ; la chaîne rejouée donne une empreinte identique.

Tests ajoutés à `DeliveryMissionsTest` :
- photos : ajout par le livreur, type invalide refusé, autre livreur refusé, lecture par le dispatch, refus pour le rôle catalogue, refus après livraison, suppression bloquée ;
- QR : identité minimale sans connexion, faux jeton refusé, suspension puis réactivation, réaffectation qui invalide l'ancien code et en donne un nouveau.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- La page publique de la boutique (`/fr/verifier-livreur`) et l'affichage du QR dans l'espace livreur sont à construire avec les écrans ; l'API est prête.
- Pas de photo obligatoire : la remise par code ou par procédure supervisée reste la preuve principale.
- Pas de géolocalisation de la remise (aucun suivi GPS simulé).
