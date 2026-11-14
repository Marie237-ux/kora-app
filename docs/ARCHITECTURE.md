# Architecture

Monorepo npm avec apps/storefront (Astro SSR et îlots React), apps/operations (Next.js App Router), services/api (Laravel), packages/design-tokens, packages/ui et packages/api-client. Aucun routeur Next dans Astro ; composants partagés indépendants des routeurs.

Laravel est la seule autorité pour les prix, droits, stocks, paiements, avantages et documents. PostgreSQL est le registre transactionnel. Les DTO publics excluent les coûts, accords et marges. Les interfaces web utilisent les sessions Laravel HttpOnly et CSRF ; aucun jeton persistant dans localStorage. Le stockage local ne contient que des préférences non secrètes et les brouillons explicitement prévus.

En local : boutique localhost:4321, opérations localhost:3000, API localhost:8000, PostgreSQL isolé sur un port distinct. Les domaines et origines de production restent à configurer. Connexion PostgreSQL directe locale ; TLS obligatoire pour une base distante. Aucun Supabase Auth ni accès navigateur direct aux tables.

Backend monolithique modulaire. Chaque écriture sensible utilise transaction et verrouillage ; aucune transaction ouverte pendant un appel prestataire. Une outbox transactionnelle porte les effets asynchrones ; workers et planificateur seront exploités séparément avec les mêmes sources. Les images sont privées au stockage ; leur registre ouvre explicitement les routes publiques approuvées. Les documents privés ont leur propre registre, scan et politique d’accès.

L'API /api/v1 est versionnée et décrite dans docs/openapi.json. Les endpoints non implémentés ne sont pas annoncés opérationnels. Les règles restent réutilisables pour une future application native client.

Catalogue : CatalogueManager conserve brouillon et publication, CategoryAttributes définit les champs hérités et filtres, AgreementResolver sélectionne l’accord effectif puis appelle PriceCalculator. BusinessEvents écrit révisions/audit/outbox sous transaction. Le stockage objet précède la transaction DB, avec suppression compensatrice du nouvel objet en cas d’échec.

Les révisions de publication gardent le calcul commercial, mais ne remplacent pas les futures lignes de commande figées. Les totaux JSON sont des chaînes décimales ; les navigateurs ne recalculent pas les coûts. Les deux frontends relaient les routes médias par le même proxy de session/CSRF.


## Stock et approvisionnement

Domain/Inventory, Domain/Procurement, Domain/Documents et Domain/Shared séparent mouvements physiques, conditions contractuelles, engagements, dettes et règlements. CommandBus déduplique les commandes dans la même transaction que les écritures métier/audit/outbox. Le fournisseur commun est verrouillé pour les affectations financières ; offre, soldes et unités pour les réservations. PostgreSQL fournit la preuve de concurrence.

Les achats figent offre, coûts et profil ; le stock ne substitue pas une variante ou garantie incompatible. Soldes/unités portent condition, propriété et localisation ; les mouvements documentés conservent la piste physique. La sortie manuelle n’est pas encore orchestrée avec une commande client ou Monetbil.

Les révisions de facture/contrat sont conservées. Les affectations sont append-only et les échéanciers gardent leurs versions. Somme et multiplication des montants sont vérifiées avant débordement ; JSON expose les cumuls en chaînes.

Le registre de documents privés est désormais implémenté, distinct des médias publiables. Import/scan hors verrous de stock/finance, DB après stockage avec compensation en cas d’échec. Les commandes exigent les métadonnées clean ; le téléchargement recalcule l’empreinte. Aucun appel réseau dans une transaction financière. ClamAV/S3 réels et exploitation du planificateur non prouvés.

OpenAPI 0.7.0 : 146 chemins implémentés. Les nouvelles API passent par les proxys de session existants ; leurs écrans restent à construire. Outbox : rappels internes et projections/notifications des demandes ; autres handlers restants.


## Rappels internes et lecture financière cohérente

PayableProjection calcule en SQL la couverture de toutes les échéances avant leurs filtres ; PurchaseCommitment regroupe les coûts d'achats non reconnus sans requête par fournisseur. Les rapports ouvrent un ReadSnapshot PostgreSQL REPEATABLE READ / READ ONLY pour éviter de mélanger deux états pendant une mise à jour concurrente.

PayableAlerts rafraîchit sous verrou fournisseur les signaux et cycles. La politique versionnée détermine la mise en file à Douala ; l'outbox et l'état last_queued_at sont écrits atomiquement. PayableReminderHandler revalide la dette/politique avant un effet de notification interne. Notification et reçu d'outbox sont dans la même transaction locale ; erreur après effet => annulation complète, tentative minimale puis reprise bornée.

SupplierPayableReminder, ContentPublished, ProductRequestOfferReady et ProductRequestMessageAdded ont des handlers locaux. Les autres événements restent unsupported et sans faux processed_at. Diagnostics et reprises sont privés, lectures de notifications propres à l'utilisateur et sous permissions actuelles. Les futurs effets distants auront besoin d'adaptateurs et d'idempotence propres au prestataire ; ils ne sont pas activés ici.

Le prévisionnel couvre les dettes fournisseurs ; trésorerie disponible/détenue, remboursements à financer et free_cash restent inconnus tant que le rapprochement complet manque. Les séquences d'audit utilisées pour les ancres/cohortes financières doivent être conservées avec les pièces et historiques.

Les demandes de produits isolent brouillons de session, dossiers de compte, photos privées, messages client et notes internes. L’outbox recontrôle la publication avant notification et sérialise les événements concurrents sur la demande. La purge conserve les suppressions d’objets en échec pour réessai.

L’index texte SQL FR/EN ne contient que des champs publiés. La publication/archivage le rafraîchit dans sa transaction locale ; les résultats relisent les garde-fous publics. Les synonymes sont versionnés ; les recherches vides ne conservent qu’un agrégat HMAC journalier. Les embeddings/photo restent à intégrer séparément.

Le panier serveur appartient à une session opaque ou à un compte, avec versions et commandes idempotentes. La fusion consomme le panier visiteur une seule fois et conserve les variantes distinctes. Prix, attributs, origine boutique et garanties sauvegardés sont comparés aux conditions actuelles ; leur acceptation vérifie une empreinte recalculée sous verrou. Les liens partagés ne contiennent que références/quantités et sont révocables. Ni livraison chiffrée, checkout, paiement ni réservation permanente ne sont déduits du panier.
