# Prompt maître de réalisation de KORA

Version mise à jour le 29 octobre 2026 — décisions consolidées avec Marie, incluant le carrousel animé et les règlements fournisseurs à échéance.

Ce fichier est un prompt complet à donner à Codex dans le dépôt de développement de KORA. Il décrit le produit à réaliser, ses règles métier, ses interfaces et ses critères de validation. Il ne constitue pas une application déjà développée ni un avis de conformité juridique ou comptable.

## 0. Mission et méthode de réalisation

Tu dois concevoir et implémenter KORA, une plateforme de commerce destinée d’abord à Douala au Cameroun, avec un site marchand, une application web complète de gestion et un espace livreur accessible depuis le site. Le résultat doit être utilisable de bout en bout, connecté à un backend Laravel et à PostgreSQL, avec Monetbil pour les paiements. L’application mobile native destinée aux clients viendra plus tard : elle prolongera le site marchand et ne sera pas une application de gestion.

Le périmètre fonctionnel courant décrit ici fait partie de la livraison avant lancement, à l’exception explicite de l’application mobile native client reportée par Marie. Toutes les fonctions du site et de la gestion web sont requises maintenant ; ne pas les transformer en simple maquette, en bouton décoratif ou en liste de travaux abandonnée. Les développements seront nécessairement séquencés par dépendances techniques, mais ces séquences ne constituent pas des versions commerciales amputées. Chaque fonction doit avoir ses données, permissions, traitements, erreurs, écrans et vérifications. Ne pas créer ni développer une application native dans la livraison actuelle.

Commencer par inspecter le dépôt existant, ses instructions et les références disponibles. Réutiliser ce qui fonctionne. Ne pas écraser des modifications utilisateur. Si le dépôt est vide, créer la structure adaptée. Vérifier la documentation officielle des versions réellement installées ; ne pas assembler des extraits d’API incompatibles. Fixer les versions stables compatibles et conserver les fichiers de verrouillage.

Produire un plan d’implémentation court, une matrice des exigences et un registre des décisions, puis écrire réellement le code. Ne pas s’arrêter au plan. Continuer à travers les modules autorisés. Si la session doit être reprise, conserver l’avancement exact dans le dépôt, avec les vérifications déjà effectuées et la prochaine action concrète. Ne pas annoncer une fonctionnalité terminée sur la seule présence d’un écran.

Faire les choix d’implémentation courants de façon autonome. Les informations métier absentes doivent devenir des paramètres explicites et validables. Ne jamais inventer des identifiants d’entreprise, accords fournisseurs, frais Monetbil, garanties ou clés de services. Une intégration privée sans accès doit être entièrement implémentée et testable en environnement de test ; la configuration et la validation réelles manquantes doivent rester identifiées comme conditions de lancement, sans fausse réussite.

Le développement, les jeux de données et les paiements de démonstration restent séparés de la production. Ne pas déclencher d’encaissements réels, envoyer de messages à des clients réels ni publier le service pendant le développement sans instruction correspondante.

## 1. Sources et décisions prioritaires

Les dernières décisions de ce prompt priment sur les formulations contradictoires du briefing historique.

Références à utiliser, disponibles initialement dans les pièces jointes :

- `Briefing_detaille_Marketplace_Douala_MAJ(1).docx` : briefing historique de 19 sections.
- `Kora Direction-selection.png` : direction claire.
- `Kora Direction-selection (1).png` : direction sombre.
- `Kora Color & Type-selection.png` : palette sombre et typographies.
- `Kora Color & Type-selection (1).png` : palette claire et typographies.
- `image(20261028-215928).png` : logo Kora noir avec le chat sur le a.
- `image(20261028-230552).png` : référence de composition avec grand sac détouré à droite et titre à gauche. Retenir cette mise en scène ; les trois petites cartes de produits du bas ne sont pas retenues. L’image fixe montre une composition ; les mouvements à implémenter sont définis en sections 6.1 à 6.4.

Dans le dépôt, rassembler les références accessibles sous `references/` sans modifier les originaux. Si une pièce manque, avancer avec les éléments présents et signaler précisément la référence manquante. Ne pas recréer un logo différent ni présenter une approximation comme l’original.

Décisions actées :

1. Lancement géographique à Douala. Zones, tarifs et créneaux sont administrables.
2. Les produits restent rattachés à des boutiques partenaires identifiables sur le site.
3. KORA négocie un pourcentage avec chaque fournisseur, éventuellement par produit, famille et quantité.
4. Le prix public KORA doit correspondre au prix public convenu avec la boutique, hors promotions explicites.
5. Le pourcentage négocié permet d’obtenir un prix d’achat inférieur ; ne pas ajouter mécaniquement ce pourcentage au prix client.
6. Le client paie sur le site avant livraison. Le paiement en espèces à la remise n’est pas activé dans le modèle actuel.
7. KORA utilise sa trésorerie disponible pour l’approvisionnement lorsque les fonds collectés ne sont pas encore décaissables.
8. Le fournisseur délivre une facture au nom de la personne ou de l’entité qui achète réellement ; KORA produit ensuite les documents de vente au client sous l’identité de l’exploitant réel.
9. Le stock est hybride : produits détenus physiquement chez KORA et produits à collecter chez les fournisseurs. Les deux peuvent coexister pour une même référence.
10. Le client paie les frais de livraison hors promotion. Les collectes supplémentaires et trajets coûteux peuvent créer des suppléments annoncés avant paiement.
11. Offre souhaitée : trois premières livraisons admissibles offertes, dans des zones définies, avec au moins un article éligible de prix unitaire égal ou supérieur à 5 000 FCFA. Ne pas remplacer cette condition par un seuil panier de 15 000 FCFA.
12. Site, back-office web, espace livreur, messagerie interne, WhatsApp, recherche photo, demandes de produits introuvables, assistant catalogue, cartes de fidélité, tableaux de favoris, fonctions professionnelles, partenaires et garanties sont inclus avant lancement.
13. L’identité visuelle jointe est déjà choisie. La possibilité de personnalisation depuis l’administration doit respecter cette identité de base.
14. Les capacités de portail fournisseur et de campagnes commerciales sont développées maintenant ; leur activation commerciale reste un choix de l’administrateur.
15. Toutes les surfaces web sont disponibles en français et en anglais, avec modes clair, sombre et préférence système. Cette exigence concerne également le back-office web, l’espace livreur, les communications automatiques et les modèles documentaires. La future application native client reprendra ces préférences lors de son développement ultérieur.
16. L’application mobile native viendra plus tard pour les clients et les achats, en prolongement du site marchand. Elle ne sert pas à gérer l’entreprise et n’est pas une condition du lancement web. Préserver la réutilisation de l’API sans créer de projet Expo ni produire de build natif maintenant.
17. Un client peut demander un produit absent du catalogue en envoyant une photo et des informations. L’équipe recherche un fournisseur, prépare et publie une vraie fiche, puis prévient les clients concernés avec un lien d’achat.
18. L’accueil comporte un grand carrousel marchand animé et administrable : produit mis en avant, sélection, boutique ou campagne événementielle. Sur bureau, conserver le texte à gauche et le grand produit à droite de la nouvelle référence. Créer une composition verticale spécifique sur mobile et retirer les trois petites cartes de produits du bas.
19. Le site doit avoir une identité animée forte, du contenu substantiel et des mouvements au rythme lisible. Le back-office pilote les contenus, campagnes, médias et réglages autorisés du carrousel, en français/anglais et clair/sombre.
20. Développer maintenant dans le back-office et le backend la réception de marchandises avec paiement fournisseur différé, échéanciers, dettes, règlements partiels, rappels et rapprochements. La mise en pratique commerciale peut venir plus tard. Distinguer l’achat à crédit du dépôt-vente selon les contrats, comme décrit en section 18.1.

Les anciens taux de 5 %, 10 % et 12 %, les frais et budgets du briefing ne sont pas des paramètres commerciaux définitivement validés. Ne pas les appliquer automatiquement à toutes les boutiques.

## 2. Architecture et surfaces livrées

Utiliser un même dépôt organisé en plusieurs applications :

| Emplacement proposé | Technologie et responsabilité |
| --- | --- |
| `apps/storefront` | Astro, TypeScript et composants React hydratés de manière ciblée pour la boutique publique, le panier, le compte client et le paiement |
| `apps/operations` | Next.js et TypeScript pour le back-office, l’espace fournisseur et l’espace livreur, avec mode application web installable |
| `services/api` | Laravel pour toute la logique métier, les autorisations et les intégrations |
| `packages/ui` | Composants React web indépendants du routeur lorsque leur partage est pertinent |
| `packages/design-tokens` | Couleurs, typographie, espacements et contrats de thèmes partagés |
| `packages/api-client` | Client TypeScript dérivé du contrat API et types partagés |
| `docs` | Architecture, décisions, exigences, installation, exploitation et preuves de validation |

Astro et Next.js sont dans le même projet global, mais gardent leurs moteurs de rendu et routeurs respectifs. Ne pas essayer d’exécuter un routeur Next.js à l’intérieur d’une page Astro. Ne pas importer dans les composants partagés des dépendances comme `next/link`, `next/image` ou les API serveur Next.js. Fournir des adaptateurs par application. Garder les tokens de design et le contrat API réutilisables pour la future application native client ; ne pas créer ses écrans ou son projet maintenant.

Le site Astro peut être aussi interactif et soigné que le design le demande : le framework ne détermine pas la qualité esthétique. Hydrater les parties actives et utiliser le rendu serveur lorsque nécessaire. Conserver le parcours parcourir–panier–paiement–compte dans une surface cohérente, sans navigation permanente entre deux moteurs.

Exemple de déploiement, sans domaine réel supposé : boutique sur le domaine public, opérations sur un sous-domaine du même domaine et API sur un autre sous-domaine. L’entrée Livreur du site mène à l’espace authentifié approprié. Le déploiement doit avoir des règles de routage et d’assets explicites, sans conflit entre applications.

Laravel est l’autorité pour le prix, les droits, l’éligibilité, les paiements, le stock, les frais, la fidélité, les remboursements et la comptabilité. Les autres applications ne dupliquent pas ces décisions. Le frontend ne peut pas modifier un prix en envoyant un montant différent.

Choix proposé pour la base : PostgreSQL hébergé chez Supabase. Laravel utilise la connexion PostgreSQL sécurisée et ses migrations. Ne pas dupliquer le système d’identités entre Laravel et Supabase Auth. Ne pas exposer les tables commerciales directement au navigateur. Désactiver l’exposition API inutile de ces tables ou en interdire explicitement l’accès par les rôles publics. Séparer le rôle de migration du rôle d’exécution et limiter les droits.

Adapter connexion directe et pool de connexions à l’hébergement réel. Utiliser TLS, protéger les secrets et mesurer la latence entre API et base. L’architecture doit aussi fonctionner avec un autre hébergeur PostgreSQL compatible sans réécrire les règles métier.

Prévoir un stockage objet pour médias et pièces privées, un système de tâches asynchrones, un cache partagé lorsque nécessaire et un processus de planification. Les workers et le planificateur font partie du service réellement déployé. Ne pas imposer un cluster de microservices pour une petite équipe : garder un backend Laravel organisé par domaines.

Le back-office web couvre les commandes, fournisseurs, approvisionnement, stock, livraisons, messages, finances autorisées et paramètres métier principaux. Il est responsive et peut être installable en PWA, utilisable sur téléphone et bureau. Les opérations documentaires complexes disposent d’une vue adaptée, avec prévisualisation, saisie et téléchargement. Cette utilisation mobile du web ne constitue pas une application native de gestion à développer séparément. La future application native sera destinée aux clients : catalogue, recherche, panier, paiement, compte, commandes, fidélité, favoris et assistance, sur la même API. Son implémentation est hors de la livraison actuelle.

## 3. Modèle boutiques produits et approvisionnement

Distinguer impérativement :

- La boutique partenaire visible publiquement.
- Le fournisseur contractuel et ses coordonnées internes.
- La référence de produit et ses variantes.
- L’offre de la boutique : prix public, conditions et disponibilité.
- La source physique de préparation : dépôt KORA ou point de collecte fournisseur.
- Le lot d’approvisionnement : origine, quantité, coût réel et propriété.

Un fournisseur peut avoir plusieurs boutiques ou points de collecte. Un produit canonique peut être associé à plusieurs offres publiques, mais une offre choisie a une boutique clairement identifiée. Ne pas fusionner silencieusement des offres ayant des garanties, états ou conditions différentes.

Une fiche publique comporte la boutique, un lien vers sa vitrine, le rôle de KORA dans la vente et la livraison, et les garanties effectivement applicables. Le libellé juridique exact de la qualité de vendeur est configurable et validé pour l’organisation réelle. Ne pas afficher des mentions contradictoires avec les factures et contrats.

Un produit placé physiquement chez KORA conserve son origine fournisseur et sa boutique de rattachement. Sa présence dans un dépôt ne crée pas une nouvelle boutique fictive. Une commande peut contenir plusieurs boutiques tout en étant préparée dans un seul dépôt : le transport se calcule à partir des sources physiques retenues, pas simplement du nombre de boutiques affichées.

Pour des alternatives d’approvisionnement, comparer le SKU, l’état, les accessoires, la garantie, le prix et la source réelle. Toute modification d’une condition promise nécessite un choix explicite du client. Ne pas maquiller une origine fournisseur différente dans l’historique.

## 4. Prix publics pourcentages et promotions

Enregistrer les accords fournisseurs avec période d’effet, périmètre, mode de calcul, conditions et pièce contractuelle. Autoriser une règle par fournisseur, catégorie, produit ou palier de quantité. Définir une priorité non ambiguë et montrer la règle résolue avant publication.

Pour une remise d’achat calculée sur le prix public de référence :

- Prix public de référence : `R`.
- Pourcentage négocié : `p`.
- Prix d’achat convenu : `C = R - remise(R, p)`, sauf coût négocié fixe explicite.
- Prix client courant : `S`, normalement égal à `R` hors promotion.
- Marge brute commerciale illustrative : `S - C`, avant les autres coûts et le traitement comptable/fiscal retenu.

Exemple de test, non tarif imposé : pour une boîte affichée à 5 000 FCFA et un accord à 3 %, l’achat vaut 4 850 FCFA et l’écart avant frais vaut 150 FCFA. Si KORA finance une vente promotionnelle à 4 900 FCFA sans nouvel accord fournisseur, l’achat reste 4 850 FCFA et l’écart tombe à 50 FCFA. Ne pas recalculer automatiquement le prix fournisseur à partir du prix promotionnel.

Gérer distinctement une promotion financée par KORA, par le fournisseur ou conjointement. Enregistrer l’accord et les montants assumés par chaque partie. Prévenir l’administrateur d’une marge estimée négative ; une dérogation autorisée et auditée peut exister pour une campagne budgétée, sans bloquer aveuglément toute promotion.

Utiliser des montants entiers en XAF, des calculs décimaux exacts pour les taux et une règle d’arrondi documentée. Jamais de flottants binaires pour la monnaie. Pour les références vendues par unité, préciser l’arrondi unitaire utilisé puis multiplier par la quantité ; traiter explicitement les exceptions au poids ou au volume si elles sont activées. Conserver le détail des ajustements d’arrondi.

Photographier les conditions commerciales dans chaque ligne de commande : boutique, désignation, SKU, variante, prix de référence, prix facturé, remises, règle fournisseur applicable, taxes et garantie. Une modification ultérieure de la fiche ne réécrit pas une vente passée. Limiter les snapshots de coût aux vues internes autorisées.

Ne pas fabriquer des prix barrés, compteurs d’urgence, économies, meilleures ventes ou stocks faibles. Les affichages doivent découler de données réelles et des règles applicables.

## 5. Stock hybride et dépôts

Développer maintenant la gestion des stocks détenus et des disponibilités fournisseurs, avec plusieurs dépôts possibles sans les imposer à l’exploitation initiale.

Pour le stock KORA : réceptions, emplacements, quantités en main, réservées, disponibles, en quarantaine, endommagées et en retour ; transferts ; inventaires ; corrections motivées ; seuils de réapprovisionnement ; lots et dates si la catégorie l’exige ; numéros de série ou IMEI pour les équipements concernés. Chaque mouvement conserve son auteur, son motif et sa pièce d’origine.

Pour le stock fournisseur : quantité communiquée éventuellement inconnue, date de vérification, contact, délai de préparation et confirmation de réservation externe. Ne pas présenter une réservation en base KORA comme une réservation garantie dans la boutique sans confirmation fournisseur.

Calculer une allocation par ligne : tout du dépôt, tout du fournisseur, ou répartition explicite. Proposer en interne un plan limitant coût de collecte, incertitude, délais et risque de rupture. La priorité configurable peut favoriser le stock déjà détenu, mais ne pas consommer un produit non conforme à l’offre choisie.

Réserver atomiquement les quantités KORA pendant une période de paiement configurée. Gérer la libération, les paiements tardifs et la réallocation. Une transaction ne doit pas rester ouverte pendant un appel réseau. Prévenir les doubles ventes avec des contraintes et verrous adaptés ; Redis seul ne constitue pas le registre de stock.

En cas d’indisponibilité après paiement, créer un incident par ligne et demander au client de choisir une alternative conforme, une attente, une livraison partielle ou un remboursement. Une nouvelle livraison facturée exige son accord préalable. Ne pas imposer un avoir comme seule issue lorsque de l’argent doit être remboursé.

Les produits industriels emballés sont possibles ; fruits et repas cuisinés sont exclus du catalogue courant. Les caractéristiques de conservation, date, lot et emballage doivent être gérées pour les familles concernées. Ne pas promettre une livraison standard pour un article qui demande une chaîne logistique indisponible.

## 6. Identité visuelle et qualité des interfaces

Reproduire fidèlement la direction choisie, puis construire un système de composants cohérent. Ne pas repartir sur une direction générique. Utiliser le logo Kora avec son chat sur le a, sans guillemets ajoutés ni traits ressemblant à des guillemets.

Tokens de référence claire : fond `#FFFFFF`, surface `#FAFAF8`, texte `#141414`, accent action `#E2543A`, vert positif `#2F8F4E`. Référence sombre : fond `#121212`, surface `#1C1C1B`, texte `#F3F2EE`, accent `#FF6B4E`, vert `#2F8F4E`. Adapter les combinaisons texte/fond nécessaires pour des contrastes lisibles tout en conservant les couleurs de marque. Ne pas supposer qu’un texte blanc sur chaque accent est toujours accessible.

Typographies de référence : Young Serif pour des titres éditoriaux choisis et Hanken Grotesk pour navigation, fiches, prix et formulaires. Charger les seules graisses nécessaires avec une stratégie évitant les sauts visuels. Les tableaux et prix doivent privilégier la lisibilité.

Caractère attendu : commerce professionnel, humain, local, chaleureux et rigoureux. Des photographies réelles, une mise en page précise et des textes naturels soutiennent une identité animée forte. La mascotte ponctue utilement certains états sans gêner l’achat. Prévoir une mise en scène marquante pour l’accueil et plusieurs interactions distinctives dans le site, en conservant une utilisation rapide et accessible des tâches courantes.

Prévoir des mises en page spécifiques téléphone, tablette et bureau : navigation utilisable au pouce, recherche accessible, filtres dans un panneau adapté, actions claires, formulaires courts et tableaux de gestion utilisables sur petit écran. Respecter clavier, focus, labels, lecteurs d’écran, réduction des animations et tailles de cibles tactiles.

La boutique doit montrer des produits, boutiques et conditions crédibles. Les données d’exemple sont identifiées en développement et ne deviennent pas de faux avis ou de faux partenaires en production. Les états vide, erreur, attente, indisponibilité et permission refusée doivent être conçus avec autant de soin que l’état rempli.

### 6.1. Grande scène marchande et carrousel de mise en avant

La nouvelle référence montre un produit principal très présent à droite, un grand titre à gauche, de l’espace blanc et une profondeur créée par le détourage, le décor et l’ombre. Reprendre ce principe dans l’identité KORA déjà choisie : logo, typographies, couleurs et personnalité. Ne pas reproduire la marque Furnimart, ses textes, ses montants en dollars ni ses chiffres d’avis.

Créer un carrousel de scènes composées plutôt qu’une suite d’affiches aplaties. Titres, descriptions, prix, boutons et commandes restent de vrais éléments HTML localisés et accessibles. Le média produit et les décors sont des couches séparées pour permettre une animation contrôlée.

Sur bureau, une diapositive comprend :

- Un repère éditorial ou événementiel, comme La sélection rentrée.
- Un titre fort avec retours à la ligne maîtrisés dans les deux langues.
- Une description utile d’une ou deux phrases et une action principale contextualisée : Découvrir le produit ou Explorer la sélection.
- À droite, un grand visuel détouré dont le cadrage laisse le produit identifiable, avec ombre et décor graphique de marque.
- Pour un produit individuel : nom, boutique partenaire et prix réel, sans obstruction du visuel. Ne proposer que des variantes qui existent.
- Une navigation discrète : précédent/suivant, compteur, progression et choix direct par libellés courts.

Les trois petites cartes colorées sous le produit dans la référence ne sont pas retenues. Remplacer cette zone par une respiration ou une ligne de navigation éditoriale compacte, par exemple 01 Rentrée, 02 Maison, 03 Nos découvertes. Ces libellés changent de scène ; ils ne constituent pas une seconde rangée de mini-produits. Sur petit écran, utiliser compteur et segments de progression avec commandes accessibles.

Une scène peut présenter un produit, une collection filtrée, une boutique ou une campagne saisonnière. Une collection peut avoir un produit vedette et quelques éléments secondaires liés à la sélection, sans recréer les trois cartes rejetées. L’équipe choisit le type de scène depuis la gestion.

Exemples à alimenter uniquement avec un catalogue réel : rentrée avec sac ou équipement scolaire ; Noël avec des idées cadeaux ; sélection maison avec un équipement ; découverte d’un produit partenaire. Ne pas publier ces exemples comme des offres déjà existantes.

Une mise en avant manuelle porte un libellé honnête comme Notre sélection ou Mis en avant par KORA. En vogue et Meilleures ventes utilisent des données réelles et des critères/périodes documentés. Ne pas fabriquer de popularité ; identifier les mises en avant sponsorisées selon les règles applicables.

Prix et disponibilité proviennent de l’offre catalogue, promotions du moteur commercial. Aucun prix écrit dans un média ne doit contredire la fiche. Si une offre est suspendue, indisponible ou expirée, appliquer le comportement configuré : remplacer la scène, retirer l’action d’achat ou afficher le statut réel. Une collection ne promet pas que chaque article est disponible si ce n’est pas vérifié.

Choisir quelques scènes fortes plutôt qu’un défilement interminable. Gérer zéro, une ou plusieurs scènes : contrôles masqués si inutiles, présentation de repli honnête si rien n’est publié et aucune donnée fictive de production.

### 6.2. Composition mobile et tablette

Ne pas réduire les deux colonnes du bureau jusqu’à les rendre illisibles. Sur téléphone, recomposer la scène verticalement : repère de sélection, titre expressif, grand produit centré, informations commerciales et bouton, puis commandes discrètes. Placer le descriptif selon le type de scène avec un ordre de lecture cohérent.

Le produit reste généreux. Adapter sa zone à sa silhouette : sac vertical, téléphone, chaussures ou objet horizontal. Prévoir cadrage et point focal mobiles, ou média alternatif représentant la même offre réelle. Les décors évoluent selon le format sans changer arbitrairement la direction artistique.

Réserver l’espace des médias dès le premier affichage. Ne pas imposer une hauteur de 100vh qui coupe les boutons. Tenir compte des petits écrans, barres navigateur, zones sûres et grands caractères. Le visiteur peut défiler naturellement pour lire la suite ; ne pas comprimer tous les éléments pour les faire entrer dans un seul écran.

Adapter titres et limites éditoriales au français/anglais. Prix, condition promotionnelle et boutique ne disparaissent pas derrière un raccourcissement arbitraire. Vérifier les contenus longs et l’agrandissement des caractères. Garder une stabilité de hauteur entre les scènes pour ne pas déplacer la page pendant la lecture.

Proposer glissement horizontal au doigt et boutons en alternative. Distinguer le geste horizontal du défilement vertical sans capturer toute la page. Un glissement ne déclenche pas accidentellement un achat. Conserver des cibles tactiles confortables.

Sur tablette, choisir vertical ou deux colonnes selon l’espace et les contenus. Fournir dans le back-office des aperçus bureau, tablette et téléphone pour chaque scène.

### 6.3. Chorégraphie du hero et rythme

Définir une signature de mouvement KORA : produit, décor, titre et détails arrivent avec un léger décalage et une direction commune. Éviter la répétition d’un simple fondu sur chaque section et les rebonds appliqués à tous les éléments.

Proposition de séquence : décor révélé, produit entrant avec translation contenue et légère variation d’échelle, ombre qui se stabilise, titre et description par blocs lisibles, puis actions et détails. Au changement, le produit sort avec une direction cohérente tandis que le décor évolue et que le suivant prend place. Les couches créent une profondeur sans exiger un modèle 3D pour chaque article.

Ne pas déformer la photo pour simuler un autre produit. Les effets de masque ou balayage agissent sur la révélation et le décor, sans fausse couleur, matière ou forme. Une rotation complète nécessite de vrais médias correspondants.

Réglages de départ à tester : transition principale de 800 à 1 200 ms, décalages entre couches de 60 à 120 ms, maintien d’environ 8 secondes après l’entrée et la disponibilité du contenu. Adapter ce maintien à la lecture réelle dans les deux langues. Les gestes courants — panier, favori, filtre — reçoivent un retour immédiat et ne sont pas ralentis à la durée d’une scène éditoriale.

Défilement automatique configurable, pause/reprise visible, navigation directe et progression compréhensible. Suspendre au survol lorsque pertinent, au focus et pendant l’interaction. Après pause explicite ou navigation manuelle, ne pas reprendre sans règle claire et reprise choisie. Suspendre hors écran et en onglet inactif ; ne pas rattraper brutalement les diapositives manquées.

Un bouton ciblé ne doit pas changer de destination sous le doigt au moment du clic. Gérer les changements rapides sans empilement de timers. Texte, image, prix, état actif et lien évoluent ensemble : aucun produit avec le prix d’une autre scène.

Au clavier et avec lecteur d’écran, rendre les commandes utilisables et annoncer les changements utiles sans annonces incessantes. Les scènes inactives ne conservent pas leurs liens dans la tabulation. Sans JavaScript, afficher au minimum la première scène publiée et ses accès utiles.

Respecter le réglage système de réduction des mouvements : supprimer parallaxe et grands déplacements, désactiver l’autoplay initial, proposer une présentation stable et une navigation manuelle sobre. Tous les contenus et fonctions restent disponibles. Un réglage utilisateur de mouvement réduit peut compléter celui du système.

Rendre le premier contenu utile sans attendre une introduction obligatoire. Ne pas cacher le titre principal derrière un chargement décoratif. Éviter vidéos lourdes obligatoires, effets continus hors champ et retards artificiels avant l’achat.

### 6.4. Animations et profondeur du reste du site

La demande d’un site animé et substantiel concerne le parcours complet. Prévoir plusieurs familles de mouvements reliées à l’action ou au contenu :

| Zone | Mise en scène ou retour |
| --- | --- |
| Navigation | Menus fluides et recherche qui se déploie sans déplacement brutal de la page |
| Catégories | Compositions visuelles et réactions au survol/focus avec équivalent tactile |
| Produits | Actions révélées proprement et changement de photo réelle si disponible |
| Panier | Tiroir travaillé, confirmation d’ajout et total mis à jour clairement |
| Favoris | Épingle qui rejoint le tableau choisi et réorganisation fluide |
| Recherche photo | Progression réelle des étapes, résultats soignés, aucun faux délai |
| Sélections | Grandes compositions spécifiques avec produits associés et destination claire |
| Fidélité | Carte numérique soignée et progression d’avantages réels |
| Commande | Étapes explicites et récapitulatif stable |
| Suivi | Changements visuels liés aux véritables événements de commande |
| Événements | Ambiances rentrée/Noël programmées qui laissent l’achat lisible |
| Mascotte | Interventions contextuelles sans apparition permanente ni obstruction |

Pour le panier, prévoir un tiroir aux coins arrondis et une superposition adaptée au téléphone. Un éventuel mouvement de l’article vers le panier ne retarde pas l’enregistrement réel de l’ajout. Appliquer le même principe aux favoris.

L’accueil garde une profondeur réelle après le carrousel : catégories, sélection du moment, articles pertinents, boutiques, composition événementielle, idées par usage/budget, tableaux publics consentis, Demander un produit, fidélité et informations de confiance. Ordre et sections sont administrables. Ne pas ajouter de fausses preuves ou de blocs répétitifs pour créer de la longueur artificielle.

Varier grandes scènes éditoriales, découverte et grilles efficaces. Une section événementielle possède un contenu, une sélection et une destination utile. Les fiches gardent caractéristiques, boutique, livraison, garanties, médias et avis existants. La présentation ne doit être ni expédiée ni étirée par du défilement vide.

Les révélations au scroll ne capturent pas la molette et n’imposent pas une scène obligatoire. Prévoir un repli simple si un effet avancé n’est pas disponible. Le caractère propre vient de la composition, des contenus et de la chorégraphie KORA ; ne pas promettre une nouveauté absolue invérifiable.

Documenter durées, courbes, distances et variantes réduites dans des tokens de mouvement. Réutiliser un système d’animation cohérent ; ne pas charger plusieurs moteurs équivalents. Vérifier performances et lecture dans les deux langues et les deux thèmes.

## 7. Navigation et pages publiques

Implémenter au minimum :

1. Accueil marchand avec grand carrousel animé administrable, composition mobile dédiée, recherche, catégories, sélections éditoriales, boutiques et conditions de livraison utiles, selon les sections 6.1 à 6.4.
2. Catalogue et pages de catégories/sous-catégories, filtres, tri et pagination.
3. Résultats de recherche texte et recherche par image.
4. Fiche produit, choix des variantes, boutique, disponibilité fiable, livraison estimée, garantie et avis vérifiés.
5. Vitrine de boutique avec produits et informations publiques validées.
6. Comparateur de produits compatibles avec une même grille de caractéristiques.
7. Panier sauvegardé et panier de paiement sélectionné.
8. Parcours adresse–plan de livraison–récapitulatif–paiement–confirmation.
9. Compte, profil, adresses, commandes et détail de commande.
10. Tableaux de favoris et listes cadeaux.
11. Carte de fidélité, historique des points et avantages.
12. Factures, garanties et réclamations.
13. Messagerie et centre d’aide avec accès WhatsApp.
14. Partenaires et candidature au partenariat.
15. Espace professionnel, demandes de devis et commandes en quantité.
16. Pages de confiance, contact, conditions de vente, utilisation, confidentialité et retours.
17. Entrées authentifiées vers espace livreur, gestion et fournisseur selon les droits.
18. Page Demander un produit, accessible depuis la recherche, le menu et l’aide, avec suivi dans Mes demandes de produits.

La navigation doit garder les recherches et filtres lors d’un retour. Les liens partageables doivent ouvrir une vue cohérente. Les promotions événementielles ne doivent pas masquer les informations essentielles. Mettre l’achat et la découverte au premier plan, sans mur de fenêtres surgissantes.

## 8. Catalogue administrable et publication

Gérer catégories en arbre, attributs typés, unités, variantes, marques, produits, offres, photos, vidéos courtes, documents, accessoires compatibles, poids, volume et transport. Les filtres suivent les attributs de la catégorie. Ne pas utiliser un formulaire identique avec cinquante champs inutiles pour toutes les familles.

Autoriser brouillon, à vérifier, publié, suspendu et archivé. Contrôler les informations essentielles et la boutique d’origine avant publication. Prévoir une édition en masse, un import avec simulation, un rapport d’erreurs par ligne et une protection contre les doublons.

Les anciennes URLs doivent continuer à fonctionner par redirection après changement de slug. Un produit archivé doit conserver l’accès aux factures, garanties et commandes historiques, sans pouvoir être ajouté à une nouvelle commande si la vente est fermée.

Prévoir un historique des prix publics et des vérifications fournisseur. Les valeurs de démonstration ne doivent pas être des valeurs commerciales par défaut.

L’assistant IA du formulaire produit est décrit plus bas et doit intervenir dans ce vrai formulaire, avec comparaison et validation humaine avant enregistrement.

## 9. Langues française et anglaise et modes clair et sombre

Livrer une internationalisation complète, pas seulement un sélecteur décoratif. Traduire navigation, formulaires, validations, erreurs, tableaux, recherche, messages transactionnels, aide, états vides, notifications, accessibilité, documents et interfaces professionnelles. Les messages échangés entre personnes conservent leur langue d’origine ; une traduction éventuelle doit être demandée et identifiée.

Utiliser des clés de traduction structurées, pluriels et interpolation sûre. Ne pas construire des phrases par concaténation. Prévoir `fr-CM` et `en-CM`, fuseau métier `Africa/Douala`, stockage des instants en UTC, rendu localisé des dates et montants XAF sans décimales monétaires inutiles. Le montant d’une même vente ne change pas avec la langue.

Préférence de langue : choix explicite conservé dans le compte pour l’utilisateur connecté et localement pour le visiteur ; à défaut, langue navigateur parmi les langues supportées ; sinon français. La langue du lien visité reste respectée. Un changement de langue conserve autant que possible la page, le produit, les filtres et le panier.

Pour le site public, utiliser des URLs localisées cohérentes, par exemple `/fr/...` et `/en/...`, des liens `hreflang`, des canoniques et des sitemaps adaptés. Prévoir les correspondances de slugs localisés, métadonnées et liens partagés. Ne pas mélanger des pages personnelles dans l’index public.

Le back-office gère titres, descriptions, attributs, catégories, sélections, pages légales, FAQ et textes de campagne dans les deux langues, avec indicateur de traduction manquante. Les noms de marque, SKU et identifiants ne sont pas traduits. Prévoir une langue source et une traduction approuvée. Si un contenu commercial manque dans la langue demandée, afficher un repli explicitement identifiable ; ne pas masquer une garantie essentielle derrière un changement de langue. Pour les conditions contractuelles, utiliser des versions approuvées et reliées entre elles.

La recherche doit retrouver le catalogue dans les deux langues avec les synonymes adaptés. Les préférences linguistiques déterminent les notifications et documents générés, avec conservation de la langue et de la version utilisées. Une traduction d’une facture existante ne crée pas une seconde vente ni une seconde numérotation comptable ; c’est une autre représentation du même document.

Pour le thème, proposer Clair, Sombre et Système. Appliquer le choix explicite avant affichage pour éviter l’éclair blanc en mode sombre. Mémoriser la préférence, synchroniser le compte si connecté et respecter la préférence système si cette option est choisie. Utiliser des tokens sémantiques pour fonds, surfaces, textes, bordures, états, ombres et graphiques. Les photos produits ne sont pas inversées artificiellement.

Vérifier tous les écrans, documents prévisualisés, menus, graphiques, formulaires et états d’erreur dans les deux modes. Fournir les variantes du logo adaptées aux fonds, sans le redessiner. Une campagne événementielle doit avoir des variantes clair/sombre et ne doit jamais écraser le choix de thème de l’utilisateur. Ne pas placer dans un cache public une réponse dont le thème ou la langue révèle des informations personnelles.

## 10. Panier persistant et achat différé

Le « garder-garder » correspond à un panier durable, sans crédit ni réservation permanente. Autoriser ajout progressif, changement de quantité/variante, sélection des lignes à payer, suppression et déplacement vers un tableau de favoris. Afficher une date d’ajout lorsque cela aide à comprendre une variation de prix.

Pour le visiteur, garder un panier local ou associé à une session opaque. Pour le compte, persister côté serveur et retrouver les lignes sur ses appareils. À la connexion, fusionner les paniers de façon déterministe et signaler les conflits de variantes ou de quantité. Ne pas compter deux fois des lignes déjà synchronisées.

Avant le paiement, résoudre à nouveau prix, disponibilité, origine physique, garantie, promotions et livraison. Afficher les différences et demander acceptation lorsqu’elles changent le montant ou les conditions. Une fiche modifiée en arrière-plan ne vaut pas consentement. Les articles non sélectionnés restent dans le panier sauvegardé après l’achat.

Pour un panier partagé, utiliser un lien révocable et non devinable. Partager des références et quantités, pas les coordonnées, adresses, messages privés ni informations de paiement du propriétaire. Copier un panier partagé entraîne un nouveau calcul des conditions pour son destinataire.

## 11. Favoris en tableaux et listes cadeaux

Construire une expérience inspirée des tableaux et épingles de Pinterest, avec une identité KORA : collection visuelle, couvertures, noms, descriptions, sections, réorganisation et ajout simple depuis une fiche ou carte produit. Ne pas copier les éléments de marque Pinterest.

Un client peut créer plusieurs tableaux, épingler un produit ou une variante dans plusieurs tableaux, déplacer/copier une épingle, ajouter une note personnelle et retrouver la boutique, le prix actuel, le prix à l’enregistrement et la disponibilité courante. Gérer la disparition d’un produit sans casser le tableau.

Visibilité privée par défaut. Prévoir partage non répertorié par lien révocable et publication publique explicite. Les tableaux collaboratifs sont inclus : invitations acceptées, droits propriétaire/éditeur/lecteur, révocation et journal des modifications utiles. Aucune inscription automatique d’un contact ni publication implicite.

Permettre l’ajout au panier d’une sélection d’épingles et montrer les frais de collecte possibles avant commande. Le partage WhatsApp ou autre utilise les mécanismes de partage disponibles sans envoyer de message de lui-même.

Les listes cadeaux permettent des quantités souhaitées, une date éventuelle, des préférences de variante et un statut acheté contrôlé pour limiter les doublons. Le destinataire peut choisir de masquer les achats pour préserver la surprise. Si une livraison se fait à son adresse, l’acheteur n’obtient pas cette adresse ni son téléphone sans autorisation. Une réservation cadeau expire si le paiement n’aboutit pas et ne bloque pas fictivement le stock.

## 12. Recherche texte recherche photo et assistant de choix

La recherche texte couvre titres, catégories, marques, boutiques et caractéristiques utiles dans les deux langues. Prévoir suggestions, fautes, accents, synonymes, filtres combinés, tri, pagination et aucun résultat pertinent. Enregistrer les recherches sans résultat sous une forme minimisée pour améliorer le catalogue.

La recherche photo fait partie du lancement. Permettre téléversement ou caméra avec permission. Corriger l’orientation, réduire la taille, retirer les métadonnées sensibles et traiter l’image côté serveur. Combiner selon la solution retenue représentation visuelle, reconnaissance de texte utile et index des produits réellement présents. L’API doit retourner des identifiants de catalogue existants et des degrés de pertinence, pas fabriquer une fiche à partir d’un modèle.

Le résultat indique Produit correspondant ou Articles similaires selon le niveau de correspondance. Ne pas annoncer une identification certaine sans preuves. Donner la possibilité de compléter par du texte et des filtres. Si aucun article ne correspond, proposer le parcours Demander un produit décrit en section 12.1, sans déclarer que l’article est en stock. Avec l’accord du client, reprendre sa recherche et sa photo dans le brouillon de demande pour éviter une nouvelle saisie.

Implémenter une abstraction de fournisseur IA/embeddings avec une implémentation réelle configurable, tests contractuels et procédure d’indexation/réindexation. Le moteur doit mettre à jour l’index quand un produit est publié, traduit, modifié ou archivé. Les vecteurs ne doivent pas contourner les filtres de publication ou de disponibilité. La réindexation ne bloque pas une page client.

L’assistant de choix pose des questions utiles sur budget, usage, dimensions ou préférences, puis compare uniquement des articles existants. Il explique brièvement son choix avec les données connues. Il ne doit pas inventer compatibilité, disponibilité, certification, garantie ni promotion. Les prix et commandes sont toujours calculés par les services métier.

Le comparateur est structuré par catégorie et distingue les valeurs inconnues. Ne pas comparer des caractéristiques sans unité ou dans des unités incompatibles. Prévoir une vue mobile lisible et un lien partageable sans données personnelles.

### 12.1. Demander un produit introuvable

Livrer un service de demande de produit à l’équipe KORA, distinct de la recherche automatique dans le catalogue. Un client qui ne trouve pas ce qu’il veut peut envoyer sa photo et les précisions utiles pour que l’équipe le recherche puis l’ajoute au site. Le service est inclus dès le lancement, en français/anglais et modes clair/sombre.

Points d’entrée : bouton Vous ne trouvez pas votre produit ? / Can't find your product? dans les résultats vides ou insatisfaisants de recherche texte/photo, menu du site, centre d’aide et page dédiée. Prévoir des URLs localisées cohérentes, par exemple `/fr/demander-un-produit` et `/en/request-a-product`. Le parcours reste accessible même si le moteur IA est indisponible.

Le formulaire permet d’envoyer une ou plusieurs photos, le nom ou une description du produit, la marque/référence connue, la variante souhaitée, quantité, budget indicatif facultatif, lien de référence facultatif et précisions. Une photo est recommandée lorsqu’elle existe ; une description suffisante permet aussi de soumettre une demande sans photo. Distinguer les champs requis des renseignements facultatifs. Sur téléphone, proposer caméra ou galerie avec permission au moment utile. Le client vérifie le récapitulatif avant envoi.

Un visiteur peut préparer la demande sans perdre ses photos ni son texte, puis se connecter ou créer son compte avant l’envoi définitif pour disposer d’un suivi privé. Rattacher la demande à son compte et lui donner une référence. Une demande n’est ni une commande payée ni une garantie de disponibilité : afficher que le prix et les conditions seront communiqués après recherche. Aucun paiement, réservation payante ou débit automatique à ce stade.

Dans Mes demandes de produits, montrer description, photos, date, statut, échanges et prochaine action connue. Permettre d’ajouter une précision, de répondre à l’équipe et de retirer une demande encore ouverte. Un statut peut être : Envoyée, En étude, Précisions nécessaires, Recherche fournisseur, Produit identifié, En préparation de publication, Disponible sur le site, Non trouvé ou Clôturée. Les notes internes ne sont pas visibles au client.

Le back-office dispose d’une boîte Demandes de produits avec recherche, filtres, priorités internes, agent affecté, rapprochement des demandes similaires et historique. L’équipe peut demander des précisions par la messagerie interne, contacter ses fournisseurs, enregistrer des propositions et relier un produit déjà existant. Un regroupement interne de demandes ne révèle jamais l’identité ou les messages d’autres clients.

Lorsqu’un fournisseur et un accord sont trouvés, une action Créer une fiche depuis cette demande reprend les informations utiles dans un brouillon du formulaire produit habituel. L’équipe confirme la boutique partenaire, les caractéristiques, la garantie, la disponibilité, le prix public, le prix négocié et la source physique de préparation. L’assistant IA peut aider à rédiger les versions française et anglaise ; il ne confirme pas lui-même l’existence du fournisseur, le stock ou le prix. Les photos client restent privées et ne sont pas automatiquement publiées comme photos catalogue ; utiliser des médias fournisseur autorisés ou obtenir les droits nécessaires.

Après validation et publication effective de l’offre, relier la fiche aux demandes concernées et créer une notification dans le compte de chaque demandeur avec le lien vers le produit, son prix et sa disponibilité actuels. Les autres canaux utilisent les préférences et autorisations existantes du client. Une création de brouillon ou un échec de publication ne doit pas déclencher une notification Produit disponible. Dédupliquer les notifications en cas de répétition de l’événement de publication.

Le client ouvre la fiche, choisit sa variante, ajoute au panier et achète par le parcours habituel. Le lien de notification ne promet pas un stock réservé ni un prix permanent. Si une autre offre est proposée, la présenter comme alternative et demander son choix. Si la recherche n’aboutit pas, clore avec un message honnête sans fabriquer un produit disponible.

Protéger les photos, liens et coordonnées : contrôles d’accès, formats/tailles, métadonnées retirées, limites d’envoi et traitement sûr des références externes. Ne pas charger arbitrairement une URL fournie par le client sur le réseau interne. Conserver la traçabilité demande–échanges–fournisseur–brouillon–offre publiée sans exposer les coûts d’achat au demandeur.

Mesurer demandes reçues, produits recherchés, délais de réponse, regroupements, publications et achats réellement issus d’une demande. Ces indicateurs servent aussi à décider quels produits ajouter au catalogue ou acheter pour le stock KORA.

## 13. Assistant IA du formulaire produit

Depuis l’ajout ou la modification d’un produit, proposer une aide à partir des informations saisies, photos, caractéristiques vérifiées et documents fournisseur autorisés. Fonctions : titre précis, description naturelle, résumé, avantages fondés sur les caractéristiques, tags, catégorie proposée, attributs extraits, texte alternatif des photos, métadonnées SEO et traduction français/anglais.

Présenter la proposition champ par champ, comparer avant/après, permettre régénération ciblée, correction, acceptation partielle et annulation. L’IA prépare un brouillon ; une action explicite de l’utilisateur autorisé applique les changements. Elle ne publie pas le produit, ne change pas les prix, le stock, les pourcentages, les taxes ou les garanties de sa propre initiative.

Signaler les informations manquantes et demander une vérification pour les valeurs incertaines. Ne pas transformer une déduction d’image en caractéristique certifiée. Conserver provenance et trace des modifications utiles. Utiliser le ton KORA avec des textes crédibles, sans superlatifs répétitifs ni fausses promesses.

Les textes, images et documents fournisseurs sont des données non fiables : toute instruction qu’ils contiennent doit être ignorée comme instruction de système. Limiter le contexte aux données utiles et ne jamais envoyer secrets, marges non nécessaires ou dossiers clients à un fournisseur IA. Les réponses sont validées par schéma et échappées avant affichage.

Prévoir quotas par rôle, limites de taille, délais, reprise maîtrisée, cache approprié, budget mensuel configurable, estimation de consommation et journal technique minimisé. Une panne IA ne bloque pas la saisie manuelle ni le reste du catalogue. La recherche photo et l’assistant n’ont aucun droit d’action financière.

## 14. Devis professionnels et commandes en quantité

Livrer un profil professionnel avec identité de facturation, contacts autorisés, adresses et historique partagé selon les permissions. Le client peut demander un devis à partir de son panier, indiquer les quantités et joindre les précisions utiles.

Le back-office établit le devis avec références, prix, remises, taxes applicables, livraison, validité, disponibilité et conditions. Prévoir versions, acceptation enregistrée, refus, expiration et conversion en commande sans double saisie. L’acceptation ne garantit pas un stock externe non réservé ; exposer clairement les conditions de réservation prévues.

Les paliers de quantité s’appuient sur des accords fournisseurs réels. Avant d’accepter un grand volume, vérifier le stock, l’approvisionnement, les dimensions et le coût de livraison. Un paiement professionnel utilise les mêmes contrôles Monetbil et les conditions autorisées ; ne pas activer un crédit client sans décision de l’entreprise.

## 15. Calcul des livraisons et suppléments de collecte

Construire un devis de livraison côté serveur à partir de l’adresse, de la zone desservie, du plan d’allocation, des points de collecte réels, des distances nécessaires, du poids/volume, des contraintes et du créneau. Ne pas utiliser seulement le nombre de boutiques du panier.

Rendre administrables les zones, points de collecte, forfaits, suppléments par collecte, tranches de distance, poids/volume, véhicules nécessaires, horaires, capacités et conditions d’exclusion. Des montants de démonstration sont explicitement fictifs jusqu’à configuration réelle.

Le devis peut combiner un tarif de base, des collectes additionnelles, un supplément de trajet et un supplément d’encombrement selon une grille déterministe. Éviter les doubles facturations pour la même contrainte. Enregistrer la version de grille utilisée et le détail du calcul. Si un service de distance est indisponible, utiliser une grille de zones réellement configurée ou annoncer qu’un devis doit être confirmé ; jamais afficher 0 FCFA par défaut.

Montrer avant paiement des options réellement réalisables : livraison groupée économique, créneau programmé, expédition séparée si autorisée. Expliquer le supplément, par exemple 2 collectes supplémentaires, sans révéler la marge ni les négociations internes. Permettre de comparer à une sélection préparée au dépôt lorsqu’elle est conforme aux produits choisis.

L’utilisateur accepte le total exact du plan retenu. Le devis a une durée de validité ; à expiration, recalculer et montrer les changements avant nouveau paiement. Après encaissement confirmé, une variation de coût interne n’autorise pas un prélèvement ou supplément unilatéral. En cas de changement nécessaire, proposer une option et conserver l’accord.

Prévoir tournées avec plusieurs commandes et plusieurs collectes, ordre des arrêts, véhicules, capacité, créneaux et livreur de remplacement. Une optimisation de tournée ne modifie pas le prix promis ni les preuves de remise. Toute position GPS doit être récente et limitée au périmètre utile ; ne pas simuler un suivi temps réel.

Gérer les adresses par quartier, repère, instructions, coordonnées facultatives, contact principal et contact alternatif. Ne pas rendre la carte obligatoire. Détecter une zone non desservie avant le paiement et proposer une solution de contact.

Les créneaux ont capacités, durée de préparation, heure limite, indisponibilités et jours particuliers. Les réservations de créneau expirent avec la réservation de paiement et sont confirmées atomiquement. Ne pas vendre le même dernier créneau à deux clients en parallèle.

## 16. Promotions et trois premières livraisons

Le moteur de promotions doit gérer dates, zones, clients admissibles, catégories/produits, minimum unitaire ou panier explicitement distincts, quantités, budget global, limites individuelles, cumul, financeur et règles de remboursement.

Configurer l’offre souhaitée avec trois livraisons admissibles par client et au moins un article éligible d’un prix unitaire de 5 000 FCFA ou plus. Définir dans l’administration si ce seuil utilise le prix effectivement payé après remises ; proposer ce dernier comme règle initiale, et afficher cette condition. Les commandes annulées ne consomment pas définitivement l’offre. Un échec de remise imputable à l’exploitation doit recevoir un traitement explicite.

La couverture des frais doit être un paramètre obligatoire : totalité du devis de livraison admissible ou seulement sa composante de base. Si des suppléments restent dus, ne pas afficher Livraison gratuite sans précision ; afficher les frais de base offerts et les suppléments restants avant paiement. Si la campagne offre réellement la totalité, ne pas ajouter ensuite un supplément caché pour les boutiques éloignées.

Réserver atomiquement budget et utilisation pendant le paiement, confirmer à l’état métier choisi et libérer en cas d’expiration/annulation. Les tentatives simultanées ne doivent ni dépasser le budget ni utiliser deux fois le dernier avantage. Définir le cas des livraisons partielles d’une seule commande pour ne pas consommer plusieurs avantages par accident.

Prévoir codes promotionnels, remises par produit, offres quantité, campagnes événementielles, coupons fidélité et parrainage. Un tableau de marge montre le cumul réel avant activation. Les contrôles antifraude doivent rester proportionnés : comptes différents partageant une adresse ne sont pas automatiquement frauduleux.

## 17. Commandes paiement et trésorerie

Créer une commande et des lignes figées à partir d’un devis validé. Chaque ligne garde son offre, boutique, variante, prix et conditions. Les réservations, consommations de campagne et attribution des sources physiques sont liées à cette commande.

Séparer les machines à états :

| Objet | États représentatifs à préciser dans le code |
| --- | --- |
| Paiement | créé, en attente, confirmé, échoué, annulé, à vérifier, remboursé partiellement, remboursé |
| Approvisionnement | à confirmer, confirmé, à acheter, acheté, collecté, indisponible |
| Préparation | non commencée, partielle, prête, en anomalie |
| Livraison | à affecter, affectée, collecte en cours, en route, remise, échec, retour |
| Réclamation | ouverte, à compléter, en analyse, solution proposée, action en cours, résolue, contestée |

Définir les transitions autorisées, acteurs, préconditions et effets. Un statut global client est une synthèse lisible, pas une source de vérité qui efface l’état des lignes. Chaque transition importante est traçable.

Intégrer Monetbil par un adaptateur Laravel réel : création de demande, URL de paiement ou mécanisme retenu, notifications, vérification côté serveur, rapprochement et états tardifs. Utiliser la documentation de la version activée sur le compte. Ne pas recopier un exemple qui désactive la vérification TLS. Les clés secrètes restent côté serveur.

Vérifier l’authenticité par le mécanisme réellement proposé par le prestataire, puis rapprocher via son API les transactions sensibles : identifiant, compte/service, référence, montant et devise attendus. Si une signature de notification n’est pas proposée, ne pas inventer ce contrôle ni faire confiance au seul corps reçu. Une URL de retour navigateur ou une capture de Mobile Money ne constitue pas une confirmation.

Implémenter idempotence des commandes, tentatives de paiement, notifications et traitements métier. Une requête répétée ne crée pas deux ventes, deux factures, deux mouvements de stock ni deux récompenses. Détecter aussi un véritable deuxième paiement et l’orienter vers un traitement de trop-perçu ; ne pas l’ignorer.

Une fermeture du navigateur, un délai réseau ou une notification tardive ne doivent pas perdre la commande. Afficher le paiement en vérification avec un mécanisme de rapprochement borné. Ne pas déclencher un nouvel encaissement tant qu’une tentative potentiellement réussie n’a pas été clarifiée.

En cas de paiement confirmé après expiration des réservations, tenter une réallocation conforme. Si impossible, ouvrir un incident et proposer une résolution avec remboursement ; ne pas déclarer une vente livrable sur un stock négatif.

La trésorerie distingue fonds collectés, fonds encore indisponibles chez le prestataire, solde retirable vérifié, retraits demandés, argent effectivement disponible, dépenses d’approvisionnement, frais, remboursements et fonds engagés. Un chiffre d’affaires n’est pas un solde disponible. Une avance du fondateur, un apport et un emprunt ont des natures distinctes.

Prévoir saisie/rapprochement des retraits et décaissements réellement disponibles pour le compte, avec preuve, frais et état. Ne pas promettre une automatisation de remboursement ou de reversement si le prestataire ne la permet pas. Le parcours manuel de traitement doit être complet, autorisé, vérifié et suivi jusqu’au paiement réel.

## 18. Achats fournisseurs contrats et réception

L’interface d’approvisionnement regroupe les besoins par fournisseur et point de collecte, en distinguant commandes clients et achats destinés au stock KORA. Elle montre les quantités, conditions négociées, coût prévu, disponibilité, trésorerie engagée et ordre de priorité.

Gérer demandes de prix, bons de commande fournisseur, confirmations, paiements, factures fournisseur, réceptions partielles, écarts de prix/quantité, contrôle qualité et retours. Une facture achat peut couvrir plusieurs commandes clients ; une commande client peut demander plusieurs achats. Les relations sont explicites et les allocations de coûts évitent les doublons.

Enregistrer les factures au nom du véritable acheteur professionnel. Préserver les pièces originales et leur empreinte d’intégrité. Des photos, OCR ou suggestions d’extraction facilitent la saisie, mais les données financières sont validées avant comptabilisation. L’achat fournisseur et la facture client sont deux opérations distinctes.

Le module contrats comporte des modèles versionnés et éditables pour partenariats fournisseurs, conditions de remise, qualité, disponibilité, facturation, garantie, retours, règlement et livraison. Il peut aussi produire les documents des livreurs. Les champs sont alimentés par les données vérifiées des parties, avec aperçu français/anglais, contrôle des champs manquants et export PDF et DOCX.

Un contrat généré passe par brouillon, vérification, approuvé, envoyé le cas échéant, accepté/signé, en vigueur, expiré ou résilié. Développer l’envoi comme action explicite autorisée, sans envoyer automatiquement les brouillons. Prévoir téléversement d’un exemplaire signé et enregistrement du mode réel d’acceptation. Ne pas présenter un simple dessin ou une case cochée comme une signature électronique qualifiée.

Conserver la version exacte acceptée, les annexes, signataires, dates, historique et éventuels avenants. Modifier un modèle ne modifie pas les contrats déjà signés. Les clauses peuvent être proposées ou rédigées dans l’outil mais ne sont pas prétendument validées juridiquement par l’IA. Les taux contractuels applicables alimentent les règles de prix avec dates d’effet.

### 18.1. Marchandises reçues avec paiement fournisseur différé

Construire ce module maintenant dans le back-office avec ses traitements backend, même si KORA utilise ces accords commerciaux seulement après sa croissance. Le fournisseur peut livrer une quantité au dépôt et accorder un délai de paiement. La réception rend les quantités physiquement suivies selon leur statut et leur contrat ; elle ne doit pas exiger un paiement comptant fictif pour enregistrer le stock.

Le contrat d’approvisionnement précise un mode distinct :

| Mode | Gestion attendue |
| --- | --- |
| Achat comptant | Réception et achat réglé selon le paiement réellement effectué |
| Achat à paiement différé / crédit fournisseur | Dette liée aux marchandises achetées, à régler aux échéances convenues ; le montant dû ne dépend pas automatiquement du nombre d’unités déjà vendues |
| Dépôt-vente / consignation, si convenu | Propriété, règlement des unités vendues, invendus et pertes traités selon le contrat ; ne pas comptabiliser implicitement toute la réception comme un achat classique immédiatement dû |

Ne pas déduire le mode d’accord du seul fait que des cartons arrivent au dépôt. L’équipe choisit le contrat réel et conserve ses conditions. L’interface explique la différence en langage simple. Le modèle de propriété des lots doit pouvoir distinguer un stock KORA acheté et un stock conservé pour le compte d’un fournisseur. La boutique d’origine reste identifiée dans les deux cas.

Créer dans la fiche fournisseur des conditions de règlement : mode, délai, point de départ du délai — date de facture, réception, date fixe ou règle contractuelle — échéances, acompte éventuel, limite de crédit lorsqu’elle existe et version contractuelle. Copier les conditions applicables dans chaque achat ; modifier la fiche fournisseur ne réécrit pas les dettes existantes.

Depuis une réception, rattacher bons, factures et quantités à l’achat, enregistrer coût unitaire/total réel, date de réception et statut de contrôle. Gérer les réceptions partielles et factures qui arrivent après la marchandise sans créer deux dettes pour le même achat. Le traitement comptable transitoire approprié est défini avec le comptable et lié aux pièces.

La vue Fournisseurs à payer présente pour chacun : montant engagé, facturé, déjà réglé, reliquat, échéances futures, montants échus, litiges documentés et avoirs. La vue Échéancier montre les paiements prévus par date avec filtres fournisseur, contrat, état et période. Les créneaux de rappel sont configurables et leurs dates suivent Africa/Douala.

Autoriser un paiement en une fois ou plusieurs règlements partiels, anticipés ou à l’échéance. Un règlement peut être affecté à plusieurs factures avec détail ; une facture peut recevoir plusieurs règlements. Enregistrer montant, date réelle, canal, référence, preuve, auteur et état vérifié. Une intention de paiement ou un clic Payer ne solde pas une dette avant confirmation de l’opération réelle.

Protéger les affectations concurrentes, empêcher double imputation et montrer les trop-versés comme avances ou créances à rapprocher. Le total des échéances correspond au montant à répartir et les écarts sont expliqués. Exemple de validation, non condition imposée : achat de 100 articles à 1 000 FCFA = dette de 100 000 FCFA ; règlement vérifié de 30 000 FCFA = reliquat de 70 000 FCFA. Les ventes réalisées ne constituent pas un règlement fournisseur.

Un retour de marchandise ne réduit pas silencieusement la dette : relier le retour, l’accord fournisseur, l’avoir ou la pièce appropriée et l’ajustement validé. Une marchandise endommagée, perdue, retournée par un client ou encore invendue ne supprime pas à elle seule une obligation d’achat à crédit. Pour un dépôt-vente, appliquer les conditions particulières acceptées sans les transposer à un achat classique.

Dans le dépôt-vente, suivre par lot les quantités confiées, vendues, retournées, endommagées et restantes. Produire un relevé des ventes à régler, selon période contractuelle, avec rapprochement des retours et mouvements. Ne pas appliquer une deuxième fois une remise ou commission déjà incluse dans le prix négocié. Les dettes/redevances sont reconnues selon l’accord réel et le schéma comptable validé.

L’accueil de gestion remonte les échéances proches, retards et dépassements de crédit selon les règles configurées. Des rappels internes sont programmables, sans envoyer automatiquement des messages externes non autorisés. Une nouvelle commande fournisseur affiche les engagements existants et peut exiger une validation en cas de dépassement ; une réception réellement survenue reste enregistrable et traçable.

Relier l’échéancier au prévisionnel de trésorerie, avec fonds disponibles, fonds encore détenus par le prestataire, remboursements à assurer et dépenses prévues. Distinguer argent encaissé, dette fournisseur et bénéfice. Ne pas présenter la totalité des encaissements clients comme argent libre à dépenser.

Proposer relevé fournisseur bilingue, détail des achats/réceptions, paiements, avoirs et solde à une date. Conserver historique des renégociations, échéances modifiées et accords joints. Une modification autorisée n’efface pas la condition antérieure. Limiter ces opérations aux rôles achats/finance compétents et les auditer.

L’ensemble se relie aux contrats, au stock hybride, aux achats, à la trésorerie, aux justificatifs et à la comptabilité en partie double. Développer aussi les vues responsive pour les consulter et les gérer depuis le back-office web sur téléphone. Ne pas réserver ce module à une future application native.

## 19. Back-office complet et rôles

Le back-office est l’outil de travail principal de KORA. Donner des actions utilisables et des tableaux filtrables, pas une juxtaposition de métriques fictives. L’accueil montre les urgences réelles, commandes à préparer, achats à effectuer, livraisons à affecter, dossiers SAV, paiements à rapprocher et alertes de trésorerie/stock.

Modules complets : commandes, produits, demandes de produits introuvables, catégories/attributs, boutiques, fournisseurs, contrats, accords, achats, stock/dépôts, transport, paiements, factures, comptabilité, fidélité, promotions, parrainage, avis, messagerie, partenaires, devis professionnels, contenu, thèmes, traductions, équipe, audit et état des intégrations.

Prévoir recherches, filtres enregistrés, pagination, actions en masse avec simulation et rapport, exports autorisés et historique des modifications importantes. Les actions potentiellement irréversibles ont une confirmation contextuelle ; les gestes courants restent fluides. Ne pas permettre des suppressions qui cassent la comptabilité ou l’historique des achats.

Rôles proposés, cumulables par attribution explicite :

| Rôle | Périmètre principal |
| --- | --- |
| Propriétaire / superadministrateur | Configuration de l’entreprise, délégation et actions sensibles |
| Gestionnaire | Exploitation commerciale et commandes selon droits |
| Responsable catalogue | Produits, traductions et publications autorisées |
| Approvisionneur | Fournisseurs, achats, collecte et avances affectées |
| Responsable stock | Réception, inventaires et mouvements autorisés |
| Responsable livraison | Planification, affectations et incidents |
| Livreur | Missions qui lui sont affectées et preuves de remise |
| Support | Messages, commandes nécessaires et dossiers clients autorisés |
| Comptable | Écritures, rapprochements, états et clôtures autorisées |
| Fournisseur partenaire | Uniquement ses boutiques, offres et engagements autorisés |
| Client | Ses propres données et fonctions de partage consenties |

L’approvisionneur peut être le fondateur qui livre aussi ; ses permissions sont la somme de ses rôles, sans donner tous les droits à tous les livreurs. Les fournisseurs n’accèdent ni aux accords des autres boutiques ni aux données clients inutiles à leur mission. Chaque autorisation est vérifiée sur la ressource concernée, pas seulement sur le rôle global.

Le portail fournisseur est implémenté : invitations, profil, contrats/documents autorisés, propositions de fiches, disponibilités, demandes de collecte, confirmations, garanties et historique des achats le concernant. Au départ, KORA peut garder la publication centralisée et ne délivrer aucun accès autonome. C’est une configuration d’exploitation d’une fonction construite, pas un module repoussé.

Les capacités d’abonnement fournisseur et de mise en avant sponsorisée sont incluses comme outils administrables, sans prélèvement ni tarif commercial inventé. Distinguer une éventuelle formule Standard et une option de visibilité facultative, avec consentement, période, facturation, résiliation et absence de fausse visibilité organique. Aucun abonnement obligatoire n’est activé au lancement sans décision explicite de l’entreprise. Ne pas ajouter un prélèvement récurrent que le prestataire ne supporte pas ; gérer alors les renouvellements par demandes de paiement identifiées.

## 20. Espace livreur et gestion web sur téléphone

Le livreur voit ses missions du jour, filtres par état, créneaux, arrêts, articles, quantités, consignes, contacts nécessaires et ouverture d’itinéraire. Il peut accepter une affectation si ce mode est choisi, commencer une collecte, signaler un écart, confirmer un enlèvement, partir en livraison et enregistrer la remise.

Afficher un contrôle par article : conformité visuelle, quantité, emballage, éventuelle photo de preuve et numéro de série autorisé. Les preuves ne rendent pas impossible un recours client ultérieur. Prévoir client absent, adresse inaccessible, article manquant, dommage, véhicule indisponible et réaffectation.

La remise utilise un code à usage unique ou une procédure alternative supervisée et auditée. Le code ne figure pas dans la vue livreur avant saisie ; il est limité en tentatives et lié à la livraison et à sa période. Une preuve alternative demande un motif et le contrôle prévu. Aucun code ne déclenche un débit financier.

Le client peut vérifier l’identité professionnelle du livreur depuis sa commande : prénom ou identité publique autorisée, identifiant professionnel et référence de mission. Ne pas publier les papiers personnels du livreur. La vérification par QR éventuel utilise une URL sûre, révocable et minimale.

Le mode connexion instable permet de consulter les informations strictement nécessaires déjà autorisées, préparer un brouillon de preuve et réessayer une synchronisation. Les actions en attente sont visibles. Le serveur vérifie à nouveau mission, version et permissions avant acceptation. Une mission réaffectée n’est pas déclarée livrée par un ancien appareil hors ligne. Effacer les données locales opérationnelles à la déconnexion et selon la rétention définie.

Le back-office et l’espace livreur sont des interfaces web responsive, éventuellement installables en PWA. Gérer les permissions navigateur caméra/localisation à la demande, les notifications web lorsqu’elles sont prises en charge et les liens vers une ressource autorisée. Ne pas développer d’application native de gestion ni de builds Android/iOS dans ce périmètre. Les futures applications mobiles natives concernent l’expérience client du site marchand et seront développées plus tard.

## 21. Facturation et documents client

Créer des factures KORA sous l’identité exacte de l’exploitant réel, avec marque commerciale et mentions applicables à son régime. Prévoir configuration des identifiants, coordonnées, numérotation, taxes réellement applicables, remises, lignes, livraison et conditions. Ne pas charger de TVA par défaut sans que le régime ne le justifie.

Ne pas réutiliser les identifiants de MR Propre ou d’une autre entreprise sans que cette entreprise soit effectivement l’exploitant ou le cocontractant dans une organisation formalisée. En développement, les champs manquants sont clairement indiqués et les documents portent la mention de démonstration si nécessaire.

Distinguer devis, confirmation de commande, reçu d’encaissement, facture de vente, facture/justificatif d’acompte si applicable, bon de livraison, avoir et preuve de remboursement. Définir le moment d’émission avec le régime retenu ; un paiement reçu ne transforme pas automatiquement chaque brouillon en facture définitive.

La numérotation est allouée côté serveur de façon transactionnelle, unique et traçable. Une facture émise ne se modifie ni ne se supprime silencieusement. Les corrections suivent un document correctif et une piste d’audit. Ne pas renuméroter l’historique pour masquer une annulation.

Conserver les données et éléments graphiques nécessaires à la reproduction de chaque document émis. Un changement de logo ou d’adresse n’altère pas rétroactivement les factures originales. Les PDF sont privés et téléchargés avec contrôle d’accès. Une URL opaque seule ne remplace pas ce contrôle.

Prévoir langue choisie, impression lisible, pagination, réémission identifiée et export pour le comptable. La génération de documents volumineux est asynchrone et traçable. Les obligations de facturation électronique applicables à l’entreprise doivent être recensées et intégrées selon le dispositif réel, sans affirmer qu’un PDF interne constitue à lui seul une facture fiscale conforme.

## 22. Comptabilité et pilotage financier

La demande dépasse un graphique de ventes. Développer un module de comptabilité en partie double, configurable dans le cadre SYSCOHADA applicable, avec des paramétrages et affectations revus par le comptable de l’entreprise avant usage réel. Ne pas annoncer une certification comptable ou fiscale inexistante.

Fonctions : plan de comptes importable et versionné, journaux, exercices, périodes, écritures et pièces, brouillons, validation, grand livre, balance, auxiliaires clients/fournisseurs, lettrage, rapprochements banque/Mobile Money/Monetbil, caisse et dépenses, immobilisations et amortissements selon règles configurées, clôtures et exports.

Chaque écriture validée doit être équilibrée débit/crédit. Les écritures validées ne se modifient pas directement : correction par contrepassation ou écriture complémentaire liée. Les périodes clôturées sont protégées et les réouvertures exceptionnelles sont autorisées et auditables. Conserver la traçabilité entre événement métier, document et écriture pour éviter les doublons.

Distinguer ventes, taxes éventuelles, achats, coût des marchandises vendues, stocks, frais prestataires, frais de livraison payés/collectés, subventions promotionnelles, charges, avances, dettes fournisseurs, apports et emprunts. La qualification de commission ou d’achat-revente dépend des contrats et du rôle réel : ne pas comptabiliser automatiquement tout l’encaissement comme commission ni appliquer un schéma d’agent par habitude.

Gérer les remboursements partiels, avoirs, pertes de stock, retours réintégrés après contrôle et écarts d’inventaire. La méthode de valorisation de stock choisie doit être explicite, cohérente et vérifiable ; les changements de méthode ne réécrivent pas arbitrairement les exercices passés.

La fidélité et le parrainage ont leurs mouvements et traitements comptables configurés. Une réduction commerciale, un paiement reçu et une récompense à verser ne sont pas le même événement. Le remboursement d’une commande ne crée pas des écritures de chiffre d’affaires supplémentaires.

Produire des états de pilotage et les états comptables correspondant au paramétrage retenu : recettes/dépenses, résultat, situation de trésorerie, bilan et flux lorsque requis. Ne pas inventer des chiffres pour remplir un état. Afficher période, sources, statut provisoire/définitif et rapprochements manquants. Les sorties réglementaires et déclarations fiscales nécessitent les formats et règles effectivement applicables ; documenter les champs à valider.

Suivre aussi la marge contributive opérationnelle par commande, produit, boutique et campagne. Elle est distincte du résultat comptable complet et du cash disponible. Inclure coût réel des marchandises, paiement, collecte/livraison, remises, récompenses et coûts variables connus ; montrer les coûts encore estimés.

## 23. Carte de fidélité et avantages

Livrer une véritable carte numérique KORA dans le compte client et utilisable sur mobile, avec identité visuelle de la marque, numéro opaque, QR de consultation limité, statut et avantages. Pas de numéro de téléphone ou d’adresse en clair dans le QR. Le QR ne permet ni connexion au compte, ni dépense, ni modification directe du solde.

Le moteur de fidélité est entièrement administrable : règles de gain, catégories exclues, base après remises, exclusion de livraison, arrondis, validité éventuelle des points, délai de validation, plafonds, avantages, paliers et budget. Les conditions publiées sont traduites et versionnées.

Proposition de simulation uniquement, sans engagement commercial automatique : 1 point par tranche complète de 1 000 FCFA d’articles admissibles effectivement payés, et 100 points échangeables contre une réduction de 500 FCFA. Afficher ces valeurs comme paramètres d’exemple en environnement de test ; l’administrateur fixe les valeurs réelles avant activation. Le programme peut utiliser d’autres taux et seuils sans recodage.

Prévoir plusieurs niveaux, par exemple Essentiel, Plus et Privilège, avec noms et critères modifiables. Calculer la progression sur un indicateur défini, comme dépenses admissibles nettes sur une période glissante, et non sur les points restants qui diminuent quand le client les utilise. Un niveau peut donner accès à des remises définies ou à des avantages de livraison réellement financés.

Maintenir un registre de mouvements de points : en attente, acquis, utilisés, restitués, annulés, expirés et ajustements motivés. Le solde est dérivé de ce registre et protégé contre les écritures concurrentes. Une commande ne donne une récompense qu’une fois, après paiement et événement de livraison requis.

Les points utilisés au checkout sont réservés, consommés après validation et libérés si la tentative expire. La réduction maximale et le minimum de reste à payer suivent des règles explicites. Appliquer la réduction aux lignes concernées pour pouvoir rembourser correctement une partie de commande.

En cas de retour, reprendre proportionnellement les gains et restituer les points dépensés selon les conditions de la campagne. Si les points gagnés ont déjà été utilisés, gérer un solde ajusté ou un traitement supervisé ; ne pas bloquer automatiquement le remboursement monétaire dû. Le client voit l’explication de chaque mouvement.

Les points sont des avantages commerciaux internes, pas un compte de monnaie électronique rechargeable ni un solde retirable. Ne pas mélanger cette carte avec les récompenses de parrainage payables. Le support peut consulter les mouvements nécessaires et les ajustements sont limités aux rôles autorisés.

## 24. Parrainage et fidélisation commerciale

Le module parrainage est construit avant lancement : un niveau, invitation d’un nouveau client, récompense sur les deux premiers achats admissibles selon les conditions approuvées. Taux, plafonds, seuil de versement, délai et budget sont configurables. Les anciennes hypothèses de 2 %, 1 000 FCFA de plafond et 2 000 FCFA de seuil ne sont pas imposées en production.

Valider les gains seulement après les événements réellement requis de paiement/livraison, avec traitement des retours. Éviter auto-parrainage et doublons sans considérer une IP ou adresse commune comme preuve suffisante. Prévoir demandes de versement, vérification par l’équipe, exécution par canal disponible et rapprochement. Aucun gain sur le seul recrutement.

Ajouter avis sur achats livrés vérifiés, photos modérées, signalement d’un avis, réponses de l’équipe, alertes de disponibilité/prix, historique consulté, achat à nouveau et sélections éditoriales. Les communications marketing et notifications facultatives ont des préférences et un désabonnement respectés. Les avis négatifs ne sont pas supprimés parce qu’ils sont négatifs.

## 25. SAV garanties retours et réclamations

L’espace Mes achats et garanties regroupe facture, paiement, offre achetée, conditions archivées, durée commerciale réelle, numéro de série et demandes ouvertes. La garantie peut varier par offre et fournisseur. Ne pas inventer une garantie universelle de 6 ou 12 mois.

Depuis une ligne de commande, le client signale manquant, dommage apparent, non-conformité, panne ou demande correspondant aux conditions. Il joint texte, photos et pièces utiles. Le dossier reçoit une référence, un statut, un interlocuteur et un historique visible ; les notes internes restent distinctes.

Un signalement opérationnel rapide dans les 24 heures peut être demandé pour les problèmes visibles, conformément au briefing ; ne pas en faire une suppression automatique des droits et garanties applicables. La confirmation de livraison par code ne vaut pas renonciation à un défaut découvert après remise.

Le back-office propose échange, réparation, retour, remboursement total/partiel ou autre solution acceptée. Relier les collectes de retour, frais assumés, contrôle de l’article, numéro de série, décision fournisseur et remboursement effectif. Une pièce retournée n’est pas immédiatement revendable avant contrôle.

La responsabilité fournisseur, transporteur ou KORA est examinée selon les faits, contrats et preuves. Ne pas appliquer un partage financier automatique 50/50. KORA reste le point de contact organisé du client et suit le dossier jusqu’à résolution. Ne pas afficher Assurance s’il n’existe pas de contrat d’assurance correspondant.

## 26. Messagerie interne WhatsApp et notifications

Livrer une messagerie client–support en complément du numéro WhatsApp Business de service client. Prévoir conversation générale ou liée à commande/produit/SAV, messages horodatés, pièces jointes autorisées, état d’envoi, non lus, affectation à un agent, réponses rapides et historique. L’agent peut convertir une conversation en dossier structuré.

Mettre en place des canaux temps réel authentifiés ou une synchronisation fiable adaptée, avec contrôle d’accès à chaque conversation, reconnexion, pagination et traitement des doublons. Les fichiers privés sont autorisés à la lecture ; une URL copiée ne doit pas donner accès à une autre personne non autorisée. Les notes internes ne passent pas dans la conversation client.

Les messages et notifications automatiques sont en français/anglais selon la préférence enregistrée. Distinguer message transactionnel, rappel et campagne. Conserver statut de remise, erreurs et tentatives sans afficher de faux message envoyé. Les envois de test ne partent pas vers les contacts de production.

Le bouton WhatsApp utilise le numéro réellement configuré et une référence utile préremplie, avec consentement de partage du client. Ne pas présumer que le site peut lire ou synchroniser l’historique d’une application WhatsApp personnelle. Si un connecteur Business officiel est activé, implémenter ses permissions et conditions réelles et documenter les limites.

Développer les notifications intégrées et les notifications web sur les navigateurs compatibles, avec préférences, liens vers ressources autorisées et désinscription des canaux facultatifs. Un contenu sensible ne doit pas apparaître par défaut dans un aperçu public de notification. Les notifications natives attendront la future application client.

## 27. CMS thèmes et événements pilotés depuis la gestion

Tout le contenu et toutes les règles configurables prévues doivent être administrables : logo et variantes, favicon, couleurs, typographies autorisées, informations de l’entreprise, contacts, horaires, zones, textes, menus, catégories, blocs d’accueil, photos, bandeaux, FAQ, partenaires, conditions d’utilisation/vente/confidentialité/retours et traductions.

Créer un éditeur de pages par blocs structurés : sélection produits, sélection boutiques, visuel avec texte, catégorie, message de service, bannière et contenu éditorial. Prévoir glisser-déposer accessible ou réorganisation équivalente, brouillon, aperçu sur tailles d’écran, validation, publication, programmation et retour à une version précédente. Le moteur doit afficher réellement ces blocs sur Astro et invalider les caches concernés.

Pour les événements, gérer un calendrier de campagnes : Nouvel An, 8 mars et autres dates décidées par l’équipe. Une campagne comporte période dans Africa/Douala, langues, pages ciblées, visuels, textes, sélection produits, promotions associées et variations de thème clair/sombre. Définir priorité et règle de résolution si plusieurs campagnes se chevauchent. La fin rétablit automatiquement la configuration précédente sans supprimer les contenus.

La personnalisation travaille sur des tokens sémantiques et des blocs approuvés. Contrôler formats, contrastes, dimensions, poids des médias et cohérence des variantes. Ne pas permettre à un éditeur de déposer du JavaScript arbitraire ou du HTML exécutable dans un champ de thème. Une modification de configuration est appliquée sans reconstruire tout le catalogue à la main, avec publication et invalidation fiables.

Les conditions légales ont des versions, dates d’effet, langues approuvées et historique. Conserver la version acceptée lors de l’inscription et de la commande selon le parcours défini. Une nouvelle version n’écrase pas les contrats passés. Si une acceptation supplémentaire est nécessaire, prévoir un parcours explicite plutôt qu’une case cochée automatiquement.

Les changements sensibles — identité de facturation, destinations de versement, rôles, contrats publiés, conditions légales et configuration paiement — exigent permission dédiée, réauthentification adaptée et audit. Prévoir une séparation auteur/approbateur activable quand l’équipe le permet. Les réglages de contenu courants ne doivent pas demander une procédure lourde.

Le CMS doit être puissant dans les limites des fonctions construites ; il ne faut pas promettre que n’importe quelle future règle ou fonctionnalité inconnue pourra être inventée sans développement.

### 27.1. Éditeur de carrousel et mises en avant

Ajouter une rubrique dédiée du back-office qui gère les scènes, leur ordre et leur campagne : rentrée, Noël ou sélection permanente. Elle est accessible aux rôles autorisés de communication/catalogue.

Champs d’une scène : nom interne, type produit/collection/boutique/campagne, cible catalogue, titre FR/EN, texte FR/EN, boutons, média détouré, cadrage/média mobile, texte alternatif, décor autorisé, point focal, variantes clair/sombre, preset de mouvement, durée de lecture, dates d’effet, priorité et état de publication. Prix et disponibilité sont reliés au catalogue, sans duplication manuelle dans ce module.

Fournir des presets de composition : produit vedette détouré, collection et campagne saisonnière. Leurs positions, couches et limites éditoriales sont contrôlées. L’équipe choisit et ajuste sans écrire du code ou manipuler une timeline complexe. Aucun JavaScript ou CSS arbitraire dans les réglages.

L’aperçu utilise le même composant que le site et des données de brouillon autorisées : téléphone/tablette/bureau, FR/EN, clair/sombre, animation et mouvement réduit. La prévisualisation reste privée, non indexée et exclue des statistiques publiques.

Permettre duplication, réorganisation, programmation, publication, pause, remplacement d’un produit et retour à une version précédente. Utiliser un cadrage de repli validé si une image mobile manque. Avertir si traduction ou condition essentielle manque. Gérer le chevauchement des campagnes avec priorité explicite et dates Africa/Douala.

Publication, fin programmée et modification de catalogue actualisent les caches concernés. Une scène de Noël ne persiste pas après sa fin et le mode clair/sombre du client reste respecté. Suivre vues utiles, clics et commandes attribuées selon des règles documentées, sans compter les aperçus et images hors champ comme de vraies impressions.

## 28. Partenaires et candidature

La page Partenaires présente uniquement les partenaires réels publiés : nom, logo autorisé, domaine, présentation, boutique et avantages vérifiables. Prévoir filtres si le volume le justifie et une présentation soignée même avec peu de partenaires.

Le formulaire de candidature collecte identité du contact, activité, coordonnées, localisation, types de produits et accord sur l’usage de ces données. L’administration suit les candidatures, documents, échanges, décisions et conversion vers un fournisseur/boutique/contrat, sans duplication.

Quand aucun partenaire n’est encore publié, afficher une invitation honnête à rejoindre le projet. Ne pas remplir la page avec des marques connues fictivement partenaires. La capacité fonctionne dès lancement même si la publication de certains dossiers attend leur accord réel.

## 29. Sécurité approfondie et protection des données

Définir et implémenter un modèle de menaces couvrant clients, membres d’équipe, fournisseurs, livreurs, sessions, documents, médias, IA, paiements et infrastructure. Utiliser OWASP ASVS 5.0.0 comme référentiel de vérification : exigences de niveau 2 applicables et contrôles renforcés justifiés sur administration et finance. Documenter les exigences retenues et leurs preuves. Ne pas déclarer une certification ou une sécurité absolue.

Authentification : mots de passe hachés avec un algorithme adapté, vérification des contacts quand utilisée, récupération sécurisée, sessions renouvelées, expiration, révocation et protection contre la découverte des comptes. MFA obligatoire pour les rôles d’administration et de finance, avec récupération contrôlée. Prévoir passkeys ou un second facteur réellement implémenté selon bibliothèques stables. Les voies alternatives ne doivent pas contourner le second facteur.

Pour les interfaces web propriétaires, utiliser l’authentification Laravel/Sanctum par cookies HttpOnly et Secure avec protection CSRF, configuration SameSite et origines explicitement autorisées. Ne pas mettre des jetons durables de session dans localStorage. Documenter les domaines, cookies et protections réellement choisis. Le contrat API doit rester compatible avec une authentification adaptée à la future application native client ; sa réalisation est reportée et ne doit pas introduire de jetons inutiles ou de voie moins protégée dans la livraison actuelle.

Autorisation : refus par défaut, permissions par action, propriété des ressources et appartenance à la boutique ou à la mission contrôlées au serveur pour chaque requête. Une URL ou un UUID difficile à deviner n’est pas une autorisation. Protéger aussi téléchargements, exports, canaux temps réel, fichiers et tâches exécutées en arrière-plan.

Valider schémas et données de toutes les API. Protéger contre affectation de champs non autorisés, injections SQL/commande, XSS persistante ou réfléchie, CSRF, SSRF, chemins de fichiers et élévation de privilèges. Échapper les textes ; assainir le contenu riche avec une liste autorisée. Ne pas interpoler des paramètres dans SQL ou shell. L’import CSV/Excel doit aussi empêcher les formules malveillantes lors des exports destinés aux tableurs.

Limitation de débit contextualisée : connexion, OTP, récupération, recherche image, génération IA, panier, demandes de paiement, codes de remise et chat. Prévoir quotas, protection contre les abus et détection d’anomalies sans rendre l’accès ordinaire pénible. Les protections de périphérie/CDN et pare-feu applicatif, si déployées, complètent les contrôles backend.

Fichiers : contrôler taille, nombre, extension, contenu réel et dimensions ; réencoder les images, supprimer les métadonnées sensibles, refuser les fichiers exécutables et analyser les pièces selon les formats admis. Stocker hors des emplacements exécutables et séparer public/privé. Les noms sont générés côté serveur. Vérifier les redirections et destinations si une importation distante est autorisée ; interdire réseaux internes et URL arbitraires non nécessaires.

Paiements et opérations financières : secret serveur, TLS vérifié, références attendues, contrôle des montants, vérification prestataire, idempotence, verrouillage des ressources et journal des événements. Revalidation renforcée pour changement de destination de paiement, remboursement, décaissement, ajustement de fidélité et export sensible. Aucun front ou modèle IA ne peut autoriser ces opérations.

Configurer une politique CSP adaptée à Astro, Next.js et Monetbil avec les seules origines nécessaires, ainsi que les en-têtes de protection utiles. Ne pas contourner la politique en autorisant globalement tous scripts ou toutes origines. Protéger les pages de gestion contre l’intégration dans une iframe non autorisée. Configurer CORS explicitement ; pas de wildcard avec credentials.

Secrets : aucun secret dans Git, bundle navigateur, application distribuée, captures ou journaux. Utiliser un gestionnaire de secrets ou les variables protégées de l’hébergement, rotation et droits minimaux. Les pages de réglage montrent l’état d’un connecteur sans restituer sa clé. Si des valeurs sensibles doivent être stockées, appliquer chiffrement, accès restreint et gestion réelle des clés.

Séparer développement, test et production. Comptes de service aux privilèges minimaux, migrations contrôlées, dépendances verrouillées, analyses de vulnérabilités et mises à jour maîtrisées. Ne pas exposer le mode debug, les traces SQL, outils de développement ou bases sur des ports publics inutiles. Utiliser des images de déploiement et processus non privilégiés lorsque applicable.

Conserver des journaux d’audit append-only ou protégés contre les modifications par les rôles métier, avec acteur, action, ressource, horodatage, résultat et corrélation. Ne pas journaliser secrets, PIN Mobile Money, contenus privés complets ou données inutiles. Fixer une durée de conservation par catégorie et gérer accès/export/suppression en tenant compte des obligations réelles de conservation comptable et contractuelle.

La sécurité doit être vérifiée : tests d’isolation entre clients et boutiques, contrôle des téléchargements, analyse des dépendances, recherche de secrets et scénarios d’abus ciblés. Toute affirmation de résultat de test doit correspondre à une exécution réelle, pas à une intention.

## 30. Données et invariants à implémenter

Définir un schéma relationnel documenté. Les groupes suivants donnent le périmètre, sans imposer une table par ligne :

| Domaine | Objets et relations essentielles |
| --- | --- |
| Identité | Utilisateur, profil, préférences langue/thème, rôle, permission, sessions et facteurs |
| Organisation | Exploitant, membre d’équipe, boutique, fournisseur, point de collecte et accès |
| Catalogue | Catégorie, attribut, valeur, produit, variante, offre boutique, traductions et médias |
| Accords | Contrat, version, annexes, acceptation, grille d’achat et dates d’effet |
| Stock | Dépôt, emplacement, lot, série, mouvement, réservation, inventaire et allocation |
| Achat | Besoin, commande fournisseur, facture achat, paiement, réception, écart, mode d’accord, échéancier, dette, affectation de règlement, avoir et relevé de dépôt-vente |
| Client | Adresse, panier, ligne, tableau, épingle, invitation et liste cadeau |
| Demandes de produits | Demande privée, médias, précisions, agent, statut, rapprochement, pistes fournisseur, offre publiée liée et notification |
| Vente | Devis tarifaire, devis professionnel, commande, ligne et snapshots |
| Livraison | Zone, grille, devis, créneau, réservation, tournée, arrêt, mission et preuve |
| Paiement | Tentative, transaction, notification reçue, rapprochement, retrait, remboursement |
| Documents | Facture, avoir, bon, numéro, langue, empreinte et accès |
| Comptabilité | Exercice, période, compte, journal, pièce, écriture, ligne et rapprochement |
| Fidélité | Carte, niveau, règle, registre de points, réservation et avantage |
| Campagnes | Promotion, budget, utilisation, code, financeur et parrainage |
| SAV | Dossier, article, preuve, décision, retour, contrôle et résolution |
| Relation | Conversation, membre autorisé, message, pièce et notification |
| Contenu | Page, version, bloc, traduction, thème, événement, scène de carrousel, média/cadrage par format, preset de mouvement, sélection et publication |
| Partenaires | Candidature, partenaire publié, documents et décision |
| Exploitation | Audit, tâche, événement métier, outbox, configuration et état de connecteur |

Invariants :

- Une ligne de vente a toujours une boutique et des conditions figées.
- Quantités réservées et disponibles ne deviennent pas négatives par concurrence.
- Un mouvement de stock correspond à un événement traçable et n’est pas simplement supprimé.
- Un paiement confirmé n’est pas réutilisé pour financer une autre commande.
- Une notification prestataire peut être reçue plusieurs fois sans multiplier ses effets.
- Une commande peut avoir plusieurs paiements réels ; les trop-perçus sont identifiés.
- Une facture émise et une écriture validée sont historisées et corrigées sans réécriture silencieuse.
- Le total d’une pièce comptable validée est équilibré.
- Les avantages, budgets et créneaux sont réservés et consommés atomiquement.
- Un remboursement est plafonné au montant remboursable restant, avec concurrence protégée.
- Un montant public ne révèle jamais prix d’achat, taux fournisseur ou marge interne.
- Un rôle fournisseur ou livreur ne peut pas accéder aux ressources d’un autre périmètre.
- Une traduction ne crée pas une seconde opération métier.
- Une publication de thème ne modifie pas les documents historiques.

Utiliser clés étrangères, index, contraintes d’unicité et vérifications adaptées. Les identifiants opaques côté API ne remplacent ni les contraintes ni les autorisations. Utiliser versionnement optimiste pour les éditions susceptibles de se chevaucher et transactions/verrous pour les ressources financières ou quantitatives.

## 31. API événements et intégrations

Décrire les endpoints dans un contrat OpenAPI versionné. Groupes : authentification/préférences, catalogue/recherche, demandes de produits introuvables et leur suivi, boutiques, tableaux, panier, devis/pricing, créneaux, commandes, paiements, documents, fidélité, SAV, messagerie, professionnel, fournisseurs, achats, stock, tournées, comptabilité, CMS, traductions et administration.

Utiliser réponses paginées, codes d’erreur stables et messages localisés, identifiants de corrélation et format cohérent des montants/dates. Filtrer les champs par rôle. Les DTO publics et internes sont distincts. Ne pas renvoyer un modèle Eloquent complet contenant coûts, secrets ou relations privées.

Définir événements tels que PaymentConfirmed, StockAllocated, PurchaseReceived, DeliveryConfirmed, RefundCompleted et ContentPublished. Les noms sont indicatifs ; les garanties métier comptent davantage. Déclencher les effets externes après validation de la transaction, avec outbox transactionnelle ou mécanisme équivalent fiable. Une publication réussie ne doit pas perdre son invalidation de cache parce qu’un réseau a échoué.

Les jobs sont idempotents, bornés en tentatives, observables et relançables sans duplication. Séparer les tâches urgentes de paiement des imports et indexations lourds. Éviter une dépendance synchrone au fournisseur IA pour afficher une fiche produit ou confirmer un encaissement.

Pour chaque intégration, livrer adaptateur réel, configuration, tests contractuels, erreurs, limites, mécanisme de reprise et état opérationnel. Les implémentations de test sont réservées aux tests. Ne jamais faire passer une simulation pour un paiement, une recherche visuelle, un SMS ou un envoi réellement exécuté.

## 32. Performance référencement et accessibilité

Viser les Core Web Vitals sur les usages réels : LCP inférieur ou égal à 2,5 secondes, INP inférieur ou égal à 200 ms et CLS inférieur ou égal à 0,1 au 75e percentile, séparément sur mobile et bureau. Ce sont des objectifs mesurés, pas une garantie sur tous réseaux. Documenter appareils et conditions des tests de laboratoire puis installer une mesure terrain respectueuse des données.

Rendre les pages publiques majoritairement en HTML, hydrater les composants nécessaires, fractionner les charges utiles, charger les médias à la bonne taille et réserver leurs dimensions. Prioriser l’image principale utile, différer les autres, compresser et utiliser des formats adaptés. Ne pas charger le code du back-office, de la caméra ou de l’IA dans toutes les pages publiques.

Mettre en cache le catalogue public avec invalidation sur événements et durée cohérente. Éviter un rebuild complet à chaque vente. Toujours revérifier les montants et disponibilités avant paiement. Les pages personnelles, commandes, messages et jetons ne passent jamais dans un cache public partagé. Ne pas conserver une page authentifiée dans un service worker accessible au prochain utilisateur de l’appareil.

Optimiser requêtes SQL, index, accès aux relations et pagination. Mesurer les requêtes lentes et prévenir N+1. Les imports, PDF, notifications, OCR et indexations passent en arrière-plan lorsque nécessaire. Tester des volumes représentatifs, pas uniquement quatre produits en mémoire.

SEO : titres/descriptions réellement localisés, URLs lisibles, canoniques, hreflang, sitemap et robots, données structurées produits/boutiques quand exactes, prix/disponibilité cohérents et métadonnées de partage. Ne pas indexer paniers privés, documents et tableaux privés. Contrôler les combinaisons de filtres indexables pour ne pas créer une infinité d’URLs pauvres.

Accessibilité : HTML sémantique, navigation clavier, focus géré dans tiroirs/dialogues, labels, erreurs reliées aux champs, annonces utiles des changements, contrastes, zoom et réduction des animations. Les grilles de favoris gardent un ordre de lecture logique. Le changement de langue ou thème ne fait pas perdre le travail saisi.

Pour le grand carrousel et les animations, charger en priorité le média de la première scène et ne pas télécharger toutes les images/vidéos lourdes avant le premier rendu. Préparer la scène suivante à proximité de son utilisation, adapter les dimensions aux écrans et libérer les ressources hors champ. Privilégier les animations de transformation/opacité quand pertinentes, mesurer les effets de masque et éviter les recalculs de mise en page permanents. Les médias suivants ne dégradent pas l’image principale ni les interactions. Vérifier le coût sur téléphone réel, mouvement réduit et réseau contraint ; un grand nombre d’effets ne doit pas signifier un grand nombre de tâches permanentes.

## 33. Exploitation sauvegardes et configuration du lancement

Fournir environnement local reproductible, exemples de configuration sans secrets, migrations, données de démonstration séparées et commandes d’installation vérifiées. Une procédure de création du premier administrateur utilise des secrets choisis au déploiement, pas un mot de passe connu conservé dans le dépôt.

Livrer les builds web de production, la procédure Laravel, workers, planificateur, stockage, cache, routage et paramètres d’authentification. L’application native client est reportée ; aucun projet Expo, build natif ni certificat de distribution n’est requis maintenant. L’hébergement réel doit être choisi et chiffré ; ne pas prétendre qu’une configuration gratuite suffit à un service financier fiable.

Prévoir surveillance des erreurs, santé API/base/worker, file de jobs, notifications, paiements non rapprochés, stock incohérent et budgets IA/promotion. Définir alertes utiles et procédures de reprise. Un endpoint public de santé ne révèle ni mots de passe ni détails d’infrastructure.

Sauvegarder base, médias privés/publics nécessaires, documents et configurations critiques selon des règles distinctes. Les sauvegardes de la base Supabase ne sauvegardent pas les objets Storage eux-mêmes. Vérifier une restauration en environnement séparé, conserver les preuves et définir les objectifs de perte de données et délai de reprise adaptés. Une sauvegarde non testée ne suffit pas.

Prévoir migration vers une nouvelle version sans perte de commandes, versionnement de l’API en vue de la future application client, retour arrière des déploiements et procédure pour migrations non réversibles. Ne pas présenter un rollback de code comme annulant automatiquement une migration destructrice.

Le dossier de lancement identifie les éléments réels à renseigner : exploitant et régime, domaine, compte Monetbil et conditions, moyens de retrait, numéro WhatsApp, contrats approuvés, fournisseurs réels, prix, zones, frais, créneaux, garantie, fidélité, budget, services IA et canaux de notification. Une valeur absente n’est pas remplacée par une valeur inventée en production.

## 34. Scénarios de validation avant lancement

Écrire et exécuter des vérifications adaptées au risque : tests unitaires des calculs et invariants, intégration base/API/permissions, contrats des adaptateurs externes et parcours de bout en bout. Ne pas multiplier des tests triviaux qui reproduisent simplement le code. Les scénarios financiers, concurrence, autorisations et régression de commande sont obligatoires.

La matrice suivante sert de base aux tests et démonstrations. Chaque ligne doit être reliée aux exigences, à une implémentation et à une preuve de vérification. Les scénarios dépendant d’un service réel distinguent explicitement preuve de simulation, test de sandbox et validation de production autorisée.

| ID | Scénario et résultat attendu |
| --- | --- |
| V01 | Boutique partenaire publiée : produit rattaché, lien vitrine correct, aucune marge exposée |
| V02 | Accord 3 % sur 5 000 FCFA : achat 4 850, écart avant frais 150, arrondis cohérents |
| V03 | Promotion KORA à 4 900 : achat inchangé à 4 850, financement promotionnel traçable |
| V04 | Modification d’accord : nouvelles ventes ajustées, anciennes conditions préservées |
| V05 | Ajout de catégorie et attribut : formulaire, filtres et traductions mis à jour sans changement de code |
| V06 | Variante indisponible : aucune commande acceptée pour une autre variante silencieusement |
| V07 | Panier visiteur retrouvé et fusionné au compte sans doublon |
| V08 | Panier conservé plusieurs jours : changement de prix explicite avant paiement |
| V09 | Paiement d’une sélection de lignes : autres articles conservés dans le panier |
| V10 | Deux paiements tentés pour le dernier article KORA : pas de double allocation |
| V11 | Stock fournisseur inconnu : message fiable et confirmation selon règle configurée |
| V12 | Même commande de plusieurs boutiques depuis un dépôt : calcul sur le point physique réel |
| V13 | Collectes éloignées : suppléments expliqués, acceptés et figés avant encaissement |
| V14 | Service de distance indisponible : repli configuré ou devis à confirmer, jamais livraison nulle inventée |
| V15 | Dernier créneau demandé en parallèle : une seule capacité consommée |
| V16 | Panier admissible aux trois livraisons : compteur, seuil unitaire 5 000 et zone corrects |
| V17 | Campagne couvrant seulement le tarif de base : reste à payer visible, pas de gratuité totale annoncée |
| V18 | Campagne couvrant tout le devis : aucun supplément caché après paiement |
| V19 | Annulation ou expiration : réservations stock, créneau, budget et avantages libérées correctement |
| V20 | Retour navigateur de succès falsifié : commande non payée tant que le serveur n’a pas confirmé |
| V21 | Notification Monetbil répétée et livrée dans un ordre variable : effets uniques et états cohérents |
| V22 | Paiement en attente puis succès après fermeture navigateur : commande retrouvée et rapprochée |
| V23 | Succès tardif après expiration de réservation : réallocation ou résolution, sans stock négatif |
| V24 | Véritable deuxième paiement : trop-perçu identifié et traitement sans seconde vente |
| V25 | Mauvais montant, devise, service ou référence : paiement non affecté automatiquement |
| V26 | Article fournisseur manquant après paiement : choix client, traitement partiel et remboursement suivis |
| V27 | Plusieurs achats pour une commande et facture achat groupée : allocations sans duplication |
| V28 | Achat destiné au dépôt : réception, lot, coût et origine boutique conservés |
| V29 | Trésorerie : paiement collecté mais non disponible distinct du cash d’approvisionnement |
| V30 | Facture client français/anglais : même opération et numéro, représentations localisées |
| V31 | Changement de logo et conditions : factures et acceptations historiques inchangées |
| V32 | Deux émissions simultanées de facture : numéros uniques et piste d’audit |
| V33 | Remboursements partiels concurrents : pas de remboursement supérieur au reliquat |
| V34 | Écriture non équilibrée : validation impossible ; écriture validée corrigée sans écrasement |
| V35 | Réception, vente, paiement, frais et retour : pièces et états comptables rapprochables |
| V36 | Clôture comptable : éditions interdites hors procédure autorisée et auditée |
| V37 | Points sur commande livrée : attribution unique et calcul sur montant admissible net |
| V38 | Deux paiements utilisant les mêmes points : aucune double dépense |
| V39 | Retour partiel : points, remise, avoir et remboursement cohérents |
| V40 | Points déjà utilisés avant retour : remboursement monétaire non bloqué arbitrairement |
| V41 | Changement de niveau fidélité : progression indépendante des points consommés |
| V42 | QR carte fidélité : consultation minimale et aucune action financière ou connexion implicite |
| V43 | Tableau privé et lien révoqué : aucune consultation non autorisée ou indexation |
| V44 | Tableau collaboratif : invitation acceptée, droits différenciés et révocation effective |
| V45 | Liste cadeau : doublon limité, réservation expirée libérée et adresse destinataire protégée |
| V46 | Recherche texte français/anglais : synonymes, filtres, aucun résultat et slugs cohérents |
| V47 | Recherche photo réelle : résultat de catalogue existant, similarité présentée honnêtement |
| V48 | Image trop lourde ou malveillante : rejet sûr sans exécution et sans blocage du service |
| V49 | Index photo réactualisé : produit archivé absent des résultats achetables |
| V50 | Assistant IA rédaction : proposition corrigible, publication humaine et aucun prix inventé |
| V51 | Texte fournisseur contenant des instructions : aucune action privilégiée ni fuite de données |
| V52 | Panne ou quota IA atteint : saisie manuelle et achat ordinaires disponibles |
| V53 | Assistant de choix : recommandations uniquement parmi données réelles, inconnues indiquées |
| V54 | Devis professionnel : version, acceptation, validité et conversion sans double commande |
| V55 | Contrat fournisseur : champs réels, versions FR/EN, exemplaire accepté et avenant conservés |
| V56 | Livreur non affecté : pas d’accès à une mission ni à ses pièces privées |
| V57 | Mission réaffectée pendant perte réseau : ancien appareil incapable de confirmer la remise |
| V58 | Code remise : usage unique, tentatives limitées et contrôle par article approprié |
| V59 | Garantie : durée propre à l’offre achetée et non à la fiche actuellement modifiée |
| V60 | SAV : échange, retour, contrôle et remboursement suivis jusqu’au résultat réel |
| V61 | Messagerie : aucune fuite entre conversations, notes internes protégées, reprise sans doublons |
| V62 | WhatsApp : numéro configuré et référence correcte, aucun faux historique synchronisé |
| V63 | Parrainage : deux achats admissibles, gains uniques, retours et budget pris en compte |
| V64 | CMS : changement réel de logo, bloc, texte et thème visible après publication |
| V65 | Campagne Nouvel An / 8 mars : dates Douala, priorités, langues, modes et retour normal corrects |
| V66 | Modification des conditions : version publiée et acceptations antérieures traçables |
| V67 | Partenaire créé depuis candidature : fiche et boutique correctes sans doublons |
| V68 | Client A, client B, fournisseur A/B : isolation API, fichiers, exports et canaux temps réel |
| V69 | Appel API direct avec rôle ou prix falsifié : refus ou recalcul serveur sûr |
| V70 | MFA, récupération, déconnexion et révocation : protections identiques sur les voies concernées |
| V71 | Catalogue, panier, paiement, gestion, livraison et SAV : parcours en FR clair, FR sombre, EN clair, EN sombre |
| V72 | Préférences langue/thème : premier affichage, système, choix manuel et synchronisation sans perte du panier |
| V73 | Clavier et lecteur d’écran : recherche, filtres, tiroirs, formulaire et paiement utilisables |
| V74 | Mobile réseau contraint : mesures de chargement, aucune secousse visuelle excessive et reprise des erreurs |
| V75 | Cache et service worker : aucune page personnelle visible à un autre compte après déconnexion |
| V76 | Export/import : droits, gros volumes, erreurs par ligne et protection des tableurs |
| V77 | Redémarrage worker après interruption : jobs repris sans double effet commercial |
| V78 | Gestion et livraison web sur téléphone : responsive, permissions caméra/localisation à la demande et droits serveur, sans dépendance à une app native |
| V79 | Restauration : base et fichiers récupérés dans un environnement séparé, cohérence vérifiée |
| V80 | Parcours complet réel de test : produit boutique, commande, encaissement, achat, remise, facture et écritures |
| V81 | Produit introuvable : demande avec photos et informations créée depuis recherche texte/photo ou page dédiée, référence et suivi privé disponibles |
| V82 | Demande préparée par un visiteur : brouillon conservé pendant connexion, aucune commande ni paiement déclenché |
| V83 | Client A/B : photos, précisions et échanges d’une demande non accessibles à un autre compte |
| V84 | Traitement équipe : affectation, demande de précision, recherche fournisseur et création d’un brouillon lié sans publication automatique |
| V85 | Publication réelle du produit recherché : lien dans la demande et notification unique au demandeur, dans sa langue |
| V86 | Brouillon non publié ou publication échouée : aucune fausse notification de disponibilité |
| V87 | Demandes similaires : regroupement interne possible sans révéler l’identité d’autres clients ; images privées non publiées automatiquement |
| V88 | Produit finalement non trouvé ou plus disponible : statut honnête, alternatives identifiées et achat uniquement après choix client |
| V89 | Carrousel bureau : grand produit à droite, texte à gauche, identité KORA, aucune rangée des trois mini-cartes rejetées |
| V90 | Carrousel téléphone/tablette : composition propre, visuel généreux, textes/prix/actions lisibles et défilement vertical conservé |
| V91 | Éditeur : scène produit/collection/boutique/campagne créée, réordonnée et publiée avec vrai rendu sur le site |
| V92 | Prévisualisations : téléphone/bureau, FR/EN et clair/sombre corrects, brouillons privés et exclus des métriques |
| V93 | Passage de scène : photo, texte, prix et destination cohérents même avec clics rapides ou interruption |
| V94 | Autoplay : pause, navigation manuelle, focus, hors écran et onglet inactif gérés sans reprise imposée |
| V95 | Mouvement réduit et clavier : contenu accessible, scènes inactives hors tabulation, aucun grand déplacement obligatoire |
| V96 | Prix/promotion/stock du produit vedette modifié : carrousel actualisé sans offre mensongère ni cache durablement obsolète |
| V97 | Campagnes rentrée/Noël : programmation, priorités et fin appliquées, pas de scènes expirées |
| V98 | Zéro/une/plusieurs scènes : présentation de repli valide et commandes utiles seulement |
| V99 | Premier rendu : premier produit disponible sans introduction bloquante, médias suivants non bloquants et lecture des textes longs stable |
| V100 | Panier/favoris/sections : animations distinctives, retours immédiats, persistance réelle et absence de défilement forcé |
| V101 | Réception à paiement différé : stock ajouté selon contrôle sans paiement comptant fictif, dette et échéances reliées |
| V102 | Délai fournisseur : date de facture/réception/date fixe appliquée selon accord, version historique conservée |
| V103 | Achat 100 000 FCFA, paiement vérifié 30 000 : reliquat 70 000, distinct des ventes clients |
| V104 | Paiements partiels ou multifactures simultanés : affectations correctes, aucun double règlement ni reliquat négatif inexpliqué |
| V105 | Paiement demandé mais non confirmé : facture fournisseur non marquée réglée et état visible |
| V106 | Retour fournisseur : stock, avoir accepté et dette ajustés de façon liée, sans effacement arbitraire |
| V107 | Réception avant facture ou en plusieurs lots : rapprochement correct sans création d’une dette en double |
| V108 | Achat à crédit et dépôt-vente : propriété, obligation de règlement et invendus distingués selon les contrats |
| V109 | Échéances proches/échues : alertes internes, prévisionnel et limites de crédit cohérents avec les montants réellement engagés |
| V110 | Relevé fournisseur FR/EN : achats, réception, paiements, avoirs, renégociations et solde traçables avec droits d’accès |

## 35. Livrables de code et documentation attendus

Remettre le code complet des applications web du périmètre courant, du backend et des packages partagés ; migrations, données de démonstration, contrat OpenAPI, tests utiles, configurations d’environnement documentées, scripts de build, procédure d’installation, procédure de déploiement et exploitation. L’application native client est prévue plus tard et ne figure pas parmi ces livrables.

Maintenir dans le dépôt :

- `docs/EXIGENCES.md` : identifiants des exigences et couverture par écrans, API et vérifications.
- `docs/DECISIONS.md` : choix techniques et métier, paramètres à renseigner et raisons utiles.
- `docs/ARCHITECTURE.md` : applications, données, intégrations et séparation des responsabilités.
- `docs/REGLES_METIER.md` : prix, stock, livraison, promotions, fidélité, paiement et documents.
- `docs/INSTALLATION.md` : démarrage reproductible et prérequis exacts.
- `docs/EXPLOITATION.md` : workers, planificateur, surveillance, sauvegarde, restauration et incidents.
- `docs/SECURITE.md` : menaces, contrôles retenus, permissions et preuves de vérification.
- `docs/VALIDATION.md` : résultats réellement exécutés, captures pertinentes et limites identifiées.
- `docs/LANCEMENT.md` : configurations réelles et validations nécessaires avant ouverture.
- `docs/AVANCEMENT.md` : état concret par exigence et prochaine action pour reprise de session.

Les documents et commentaires de développement peuvent être en français ; les textes du produit sont traduits en français et anglais. Conserver les noms de variables et identifiants de code cohérents.

Pour chaque fonctionnalité annoncée terminée, vérifier qu’elle possède un chemin d’utilisation complet, persistance, permissions, erreurs, états vides et comportement réseau adéquat. Les fonctions IA et paiement doivent être reliées à leurs adaptateurs réels ; les tests ne remplacent pas les accès nécessaires au service final.

Ne pas annoncer le service prêt à lancer si l’identité commerciale, les tarifs, le compte marchand, les contrats, les domaines ou un service indispensable restent non configurés. Donner la liste factuelle de ce qui est fonctionnel, testé, configuré et restant à valider, sans masquer un échec sous une mention Terminé.

## 36. Ordre d’exécution technique sans réduction de périmètre

L’ensemble du périmètre web reste exigé avant le lancement ; seule l’application mobile native client est explicitement reportée. Suivre cet ordre de dépendances, en adaptant les regroupements au dépôt réel :

1. Inspection, exigences, références, architecture, environnements et identité/permissions.
2. Système visuel, grand carrousel administrable, système d’animation, traductions FR/EN et thèmes clair/sombre partagés.
3. Boutiques, fournisseurs, contrats, catalogue, accords de prix, stock hybride et paiements fournisseurs à échéance.
4. Recherche texte/photo, demandes de produits introuvables, assistant catalogue et assistant de choix.
5. Panier, tableaux, cadeaux, tarifs de livraison et créneaux.
6. Commandes, paiements, achats, trésorerie et documents.
7. Livraison, missions, preuves, suivi et gestion web responsive/PWA sur téléphone et bureau.
8. Fidélité, promotions, parrainage et fonctions professionnelles.
9. SAV, messagerie, notifications, partenaires et CMS événementiel.
10. Comptabilité complète, rapprochements et états, intégrés progressivement aux événements précédents.
11. Vérifications transversales, sécurité, performances, restauration et configuration du lancement.

Ces étapes ne doivent pas devenir une justification pour abandonner la recherche photo, la fidélité, la comptabilité ou un autre module. Inversement, ne pas prétendre développer un système de cette taille par une seule génération non vérifiée. Avancer dans le code, tester ce qui a changé, conserver le contexte et achever les dépendances.

À chaque compte rendu, expliquer simplement le comportement obtenu, sa vérification et les contraintes réelles. Ne pas multiplier les validations conversationnelles pour les choix réversibles déjà couverts. Ne pas réinventer la direction visuelle fournie. Ne pas livrer une façade qui ressemble à un commerce mais ne traite pas correctement les ventes.

## 37. Références techniques et sources à reconsulter selon les versions

Sources officielles consultées pour le cadrage initial le 28 octobre 2026, complétées le 29 octobre pour le carrousel et les animations. Elles ne remplacent pas la vérification de la version et du contrat réellement utilisés au moment du développement.

- Astro, intégration React : https://docs.astro.build/en/guides/integrations-guide/react/
- Astro, architecture en îlots : https://docs.astro.build/en/concepts/islands/
- Astro, rendu à la demande : https://docs.astro.build/en/guides/on-demand-rendering/
- Next.js, composants serveur/client : https://nextjs.org/docs/app/getting-started/server-and-client-components
- Next.js, applications par zones : https://nextjs.org/docs/app/guides/multi-zones
- Laravel, documentation : https://laravel.com/docs
- Laravel, Sanctum : https://laravel.com/docs/sanctum
- Laravel, base de données et transactions : https://laravel.com/framework/docs/database
- Laravel, tâches en file : https://laravel.com/framework/docs/queues
- Supabase avec Laravel : https://supabase.com/docs/guides/getting-started/quickstarts/laravel
- Supabase, connexions PostgreSQL : https://supabase.com/docs/guides/database/connecting-to-postgres
- Supabase, sauvegardes et limites : https://supabase.com/docs/guides/platform/backups
- Supabase, tarification : https://supabase.com/pricing
- Monetbil, encaissements : https://monetbil.company/doc/sandbox/collection
- Monetbil, décaissements : https://www.monetbil.company/doc/sandbox/disbursement
- OWASP ASVS : https://asvs.owasp.org/
- OWASP, autorisations : https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
- OHADA, AUDCIF et SYSCOHADA : https://www.ohada.org/acte-uniforme-relatif-au-droit-comptable-et-a-linformation-financiere-audcif/
- DGI Cameroun, Code général des impôts 2026 : https://www.impots.cm/index.php/fr/publication/code-general-des-impots-mis-jour-au-1er-janvier-2026
- Google, Core Web Vitals : https://web.dev/articles/vitals
- W3C WAI, carrousels accessibles et commandes de pause : https://www.w3.org/WAI/tutorials/carousels/
- MDN, préférence de mouvement réduit : https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion

Fin du prompt maître. Commencer par inspecter le dépôt et les références, établir la couverture des exigences, puis implémenter le produit décrit sans réduire silencieusement son périmètre.
