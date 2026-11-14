# Prompt — Refonte complète du site public KORA (front)

Réponds-moi toujours en français.

## Contexte

Projet KORA : marketplace des boutiques de Douala. Dossier : `C:\Users\DELL LATITUDE 3310\OneDrive\Bureau\MarieDigitalSolutions\KORA APP\Site Web`.

- **Backend Laravel terminé** (`services/api`), contrat OpenAPI 0.45.0 dans `docs/openapi.json`. Ne pas toucher au backend sauf nécessité réelle pour le front.
- **Site public** : `apps/storefront` (Astro 7 en rendu serveur + îlots React 19). Client d'API : `packages/api-client`. Le proxy `/api/v1/*` et `/sanctum/*` existe déjà (`src/server/proxy.ts`, `src/pages/api/[...path].ts`).
- **Lancer en local** :
  - base PostgreSQL : `node scripts/postgres.mjs start` (port 55432, base `kora`) ;
  - API : `php services/api/artisan serve --host=localhost --port=8000` ;
  - site : `npm run dev:storefront` → **http://localhost:4321/fr**.
- Cahier des charges complet : `KORA_PROMPT_MAITRE_CODEX.md`, surtout §6 à §6.4 (identité, grande scène, mobile, animations) et §7.

## Ce que je veux

Refaire **tout le site public de zéro** en reprenant fidèlement l'esprit de ma référence : `references/image(20261028-230552).png`. C'est la maquette « Unpack Your Style » avec le grand sac à dos blanc et orange.

Le rendu précédent était laid :
- thème sombre affiché par défaut ;
- produits dessinés à la main au lieu de vraies photos ;
- composition lourde.

Je veux un site **beau, clair, aéré, haut de gamme**, au niveau de la référence.

### Direction visuelle (à respecter)

- **Thème clair par défaut, toujours.** Fond blanc pur, beaucoup d'espace. Le thème sombre existe seulement si l'utilisateur le choisit : ne pas suivre le mode sombre de Windows par défaut.
- **Couleurs KORA** :
  - fond `#FFFFFF`, surface `#FAFAF8`, texte `#141414` ;
  - action `#E2543A` (boutons pilules pleins, comme le « See all » orange de la référence) ;
  - vert confiance `#2F8F4E` (badges « En stock », lien « Vendu par ») ;
  - planches `references/3.png` et `references/4.png`.
- **Logo** : `apps/storefront/public/brand/logo.png` (Kora avec le chat sur le « a »). Ne jamais le redessiner.
- **Typographies** : Hanken Grotesk partout. Le grand titre de la scène d'accueil doit avoir **la force et la netteté du titre de la référence** (gros, serré, très lisible). Propose-moi deux essais côte à côte avant de trancher : Hanken Grotesk 700/800 resserré, ou Young Serif.
- **Grande scène d'accueil (desktop)**, comme la référence :
  - à gauche : petit repère, très grand titre, deux phrases, bouton pilule orange plein + bouton pilule contour ;
  - à droite : **un grand produit détouré** sur fond blanc, avec les **formes grises douces empilées derrière** (comme les bandes floues derrière le sac) et une ombre portée au sol ;
  - en bas à droite : le prix en gros (« 18 500 FCFA »), « Vendu par [boutique] », badge de stock, flèches précédent/suivant ;
  - pastilles de couleur seulement si de vraies variantes existent ;
  - **pas de note ni d'avis inventés.**
- Sous la scène :
  - ne pas reproduire telle quelle la rangée des trois petites cartes pastel (le cahier des charges §6.1 l'interdit dans la scène) ;
  - mettre une navigation discrète « 01 Rentrée · 02 Maison · 03 Nos découvertes » ;
  - **réutiliser le style des cartes pastel arrondies de la référence pour les grilles de produits** du reste du site : fond pastel arrondi, produit détouré, prix, bouton « + » rond.
- **Mobile** : composition verticale dédiée (§6.2) : repère, titre, grand produit centré, prix et bouton, puis commandes. Rien d'écrasé.
- **Animations** sobres et nettes (§6.3) : entrée décalée du décor, du produit, puis du texte ; mouvement réduit respecté.

### Données de test (décision prise, ne pas rediscuter)

- Créer **4 boutiques de test** avec **5 à 7 produits chacune**, **directement dans la base locale**, comme des fiches normales **modifiables et supprimables depuis le back-office** :
  - « Akwa Style » (mode, sacs, chaussures) ;
  - « Bonapriso Tech » (téléphones, audio, montres) ;
  - « Deïdo Maison » (cuisine, déco, mobilier) ;
  - « Makepe Beauté » (parfums, soins).
- **Images : je les veux belles, prises sur Pinterest.** C'est ma décision et ma responsabilité ; c'est uniquement pour mes tests et mes démonstrations, je les supprimerai ensuite.
  - Récupérer des photos produits **sur fond blanc ou détouré**, en bonne résolution (736 px ou plus).
  - Les détourer proprement sur fond transparent.
  - Les importer dans la médiathèque (images publiques), liées aux offres.
  - Une recherche Pinterest a déjà été lancée dans `%TEMP%\kora-pins\` (`urls.json`, vignettes dans `thumbs/`) ; la réutiliser si elle est complète.
- Utiliser la commande existante, réservée au local : `php services/api/artisan kora:demo-storefront <manifest.json> --fresh`.
  - Fichier : `services/api/app/Console/Commands/DemoStorefront.php`.
  - Le manifeste JSON décrit catégories, boutiques, produits (nom FR/EN, description, prix, prix promo éventuel, image, texte alternatif) et scènes d'accueil (décor `bands`).
  - `KORA_DEMO=true` est déjà mis dans `services/api/.env`.
  - `--fresh` supprime l'ancien jeu de test, y compris les 3 produits dessinés actuellement en base.

### Pages à livrer (toutes, FR/EN)

1. Accueil :
   - grande scène ;
   - rayons ;
   - sélection du moment ;
   - mise en avant éditoriale ;
   - les 4 boutiques ;
   - bloc confiance (livraison suivie, Mobile Money, boutiques vérifiées, après-vente) ;
   - « Vous ne trouvez pas votre produit ? ».
2. Catalogue : recherche, rayons, boutiques, tri, pagination, état vide soigné.
3. Fiche produit : grand visuel sur bandes, prix, boutique, stock, garantie, quantité, ajout au panier, livraison, produits du même rayon.
4. Liste des boutiques et page d'une boutique.
5. Panier : tiroir latéral arrondi + page panier (API `/api/v1/cart`, en-tête `Idempotency-Key` sur chaque modification).
6. Recherche photo : l'IA n'est pas activée, l'écran doit l'annoncer proprement.
7. Demander un produit.
8. Devenir partenaire.
9. Pages légales.
10. Connexion, inscription et compte (composants existants dans `packages/ui`, à intégrer au nouveau style).
11. Page 404.

### Méthode imposée

1. **D'abord un seul écran** : en-tête + grande scène d'accueil, en desktop **et** mobile, en thème clair, avec les vraies photos.
   - Faire des captures avec Chrome sans interface : `chrome.exe --headless=new --blink-settings=preferredColorScheme=1 --window-size=1440,1000 --screenshot=...`.
   - Les comparer à la référence et **me les montrer**.
   - **Attendre mon accord** avant de faire les autres pages.
2. Ensuite les autres pages, une par une, chacune vérifiée en capture desktop et mobile.
3. Accessibilité : contrastes, focus visible, navigation au clavier, `alt` sur les images, mouvement réduit.
4. Pas de faux avis, pas de fausses notes, pas de fausse popularité. Les prix viennent de l'API.

## État laissé par la discussion précédente

Des fichiers d'une première tentative existent dans `apps/storefront/src`. **Tu peux les jeter ou les reprendre librement** :
- `styles/site.css`
- `layouts/Site.astro`
- `components/Shell.tsx`, `HeroStage.tsx`, `AddToCart.tsx`, `ProductTile.astro`, `icons.tsx`
- `lib/copy.ts`, `lib/cart.ts`, `lib/server.ts`
- `pages/[locale]/index.astro`

Les anciennes pages (catalogue, produits, boutiques, `[auth].astro`) utilisent encore `layouts/Base.astro`.

Le backend accepte le décor de scène `bands` (`SceneContent.php`). Le contrat OpenAPI n'a pas encore cette valeur : l'ajouter dans un script de contrat si tu la gardes.
