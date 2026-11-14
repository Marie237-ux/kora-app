# Utilisation du backend stock et approvisionnement

Cette étape fournit des API de gestion. Les écrans stock/achats restent à construire ; le catalogue et le carrousel existants se consultent dans la gestion sur http://localhost:3000/fr.

## Accès et contrat

Préfixe : /api/v1/operations. Utiliser une session web HttpOnly et le CSRF, après connexion et MFA. Les routes finance exigent une confirmation du mot de passe de moins de 15 minutes par POST /api/v1/auth/user/confirm-password ; une session MFA seule ne suffit pas.

Chaque commande métier requiert un UUID Idempotency-Key. Conserver la même clé lors d'une reprise après coupure : même contenu effectif, même résultat. Un contenu différent avec la même clé donne 409. Import de document et rescan sont des opérations de fichier, sans cette déduplication. Ne pas réutiliser une clé pour une nouvelle intention.

Les listes paginent par 24 et acceptent page. La locale fr/en est résolue par l'API. Schémas, corps et permissions exacts : openapi.json et packages/api-client/src/schema.ts. Les montants cumulés XAF sont des chaînes décimales, les coûts unitaires bornés des entiers ; aucune virgule ni notation scientifique.

## Préparer les données réelles

1. Créer/publier le fournisseur, sa boutique et l'offre avec ses variantes et son accord tarifaire dans la gestion catalogue. Ne saisir aucune donnée commerciale inventée.
2. Définir le profil physique par PUT inventory/profiles/{variantId} : tracking_mode, requires_expiry et logistics. Les mesures inconnues restent nulles et le transport unavailable.
3. Créer les dépôts et emplacements actifs : inventory/warehouses et inventory/locations.
4. Importer les pièces par POST documents, multipart file/purpose/supplier_id. Objectif selon pièce : supplier_contract, supplier_invoice, payment_evidence ou stock_evidence.
5. Créer le contrat FR/EN sur contracts, le relire, le faire approuver, puis enregistrer une acceptation réellement constatée avec sa pièce clean et sa référence. Les termes fixent mode, point de départ, parts et délais ; les parts totalisent 10 000.

En l'absence de ClamAV opérationnel, la pièce reste pending et ces usages sont bloqués. Ne pas modifier directement scan_status en base pour contourner ce contrôle. Le téléchargement contrôlé vérifie également l'empreinte du fichier.

## Achats et réceptions

POST purchases prend le fournisseur, le contrat accepté, l'objet de l'achat, une référence et les offres/quantités. Le serveur résout les coûts et fige contrat, offre/garantie, tarif et profil.

La confirmation demande la version courante et une référence fournisseur réelle. Le crédit tient compte des dettes ouvertes, factures saisies, engagements confirmés et réceptions déjà constatées. Seul le propriétaire peut motiver une dérogation.

POST purchases/{id}/receipts enregistre le fait physique, sa date horodatée avec fuseau, l'emplacement et une pièce. Il ne prouve pas un paiement. Coût omis : coût figé de l'achat ; changement de coût soumis aux droits et à un motif. Une réception avant facture reste possible.

Séries/IMEI : liste exacte des identifiants, aucune substitution ; lots et péremption selon le profil figé. Les excédents sont isolés en quarantaine. GET purchases/{id}/receipts retrouve leurs lignes ; POST receipt-lines/{id}/approve-overage exige une version d'achat, un avenant clean et un motif propriétaire avant leur mise à disposition/facturation. Le coût négocié initial et la quantité commandée originale restent dans l'historique.

## Stock et réservations

GET inventory/balances, units et movements consultent stock, unités et pièces de mouvement ; les coûts restent masqués au magasinier. GET inventory/receivable-purchases et les réceptions proposent une vue sans prix au rôle stock.

Configurer explicitement la durée par PUT settings/inventory avant toute réservation. GET inventory/offers/{id}/plan fournit un plan interne indicatif ; POST inventory/reservations crée une réservation atomique, sa libération est explicite et sa péremption traitée par inventory:expire.

La sortie manuelle issue exige finance, confirmation récente, pièce et référence de vente vérifiée par l'opérateur. Elle sert aux constats manuels de cet incrément ; elle ne constitue ni un checkout ni une vérification Monetbil.

Transferts et retours demandent les identifiants réels des unités sérialisées. Les inventaires inventory/counts protègent les réservations et conservent les manquants ; seules des unités manquantes connues du même lot peuvent revenir. Ajustement simple réservé au stock non sérialisé. Chaque correction exige pièce et motif.

Le stock supplier-availability peut être inconnu et expire ; supplier-reservations sépare demande, confirmation et libération externe. Enregistrer uniquement les références réellement constatées. Aucun message fournisseur n'est envoyé par ces routes.

## Factures, dette et règlements

La saisie purchase-invoices ne suffit pas à reconnaître la dette. Une correction de transcription ou une annulation de saisie reste possible avant approbation, avec version/motif et historique ; ce n'est pas une annulation légale du document fournisseur. Une facture approuvée se corrige par les pièces métier appropriées, pas par écrasement.

L'approbation finance exige la version vue et crée une obligation unique. Selon le contrat, les échéances partent de la facture, de la première réception enregistrée ou d'une date fixe, en jours civils Africa/Douala. Pas de date inventée lorsqu'une réception est encore attendue.

supplier-payments crée une intention. verify enregistre un constat réel avec justificatif clean, date, montant réellement versé, référence réelle et affectations. Une intention ne solde rien ; une référence de transaction ne peut pas être utilisée deux fois sur le même compte. Une avance non affectée doit être explicitement acceptée, puis peut être affectée ultérieurement sans modifier les écritures précédentes.

Un retour physique ne supprime pas la dette. supplier-credits enregistre un avoir accepté, documenté et plafonné à la facture ; son reliquat peut être affecté par supplier-credits/{id}/allocate à une autre obligation du même fournisseur.

Pour le dépôt-vente, le stock reste propriété fournisseur. purchases/{id}/consignment-statements rapproche seulement les ventes constatées non déjà rapprochées de la période et reconnaît le dû correspondant au coût figé ; les invendus ne deviennent pas une dette.

## Relevé et historique

GET payables/obligations et payables/schedule montrent montant nominal, règlement et restant. La couverture suit les échéances les plus anciennes avant d'appliquer les filtres. Une renégociation crée une version complète portant le montant nominal de la dette, conserve les affectations et demande un avenant clean.

GET suppliers/{id}/statement fournit le solde FR/EN et le lien daté vers ledger. Le relevé distingue factures et relevés de dépôt-vente, dettes ouvertes/échues, avances et crédits non utilisés. known_as_of signifie ce que KORA connaissait à cette date, pas une réécriture rétroactive par la date d'une pièce saisie plus tard.

La chronologie paginée comprend achats, réceptions, dettes, règlements/affectations, avoirs, retours, versions et avenants. Les montants d'événements ne s'additionnent pas comme un solde : plusieurs événements décrivent des étapes de la même opération. Conserver as_of lors de la pagination.

Aucun compte bancaire n'est débité par ces API. Les alertes, exports, rapprochements comptables, portails et orchestrations commande/prestataire restent obligatoires avant lancement.


## Alertes et prévisionnel ajoutés

La politique owner, les alertes, notifications internes, diagnostics/reprises et le prévisionnel de dettes sont désormais disponibles en API ; voir INCREMENT_RAPPELS_FOURNISSEURS.md. Le prévisionnel ne donne pas encore un cash libre rapproché. Les exports et écrans restent à construire.
