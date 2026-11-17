// Storefront home: offers expose their product id (CMS product selections reference products), and the hero
// scene accepts the "bands" decor used by the storefront stage.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const offer = schemas.Offer;
if (!offer.properties.product_id) {
  const { id, ...rest } = offer.properties;
  offer.properties = { id, product_id: { type: 'string', format: 'uuid' }, ...rest };
  offer.required = ['id', 'product_id', ...offer.required.filter((k) => k !== 'id')];
}
if (!offer.properties.compare_at_price) {
  offer.properties.compare_at_price = { anyOf: [{ type: 'integer', minimum: 0 }, { type: 'null' }], description: 'Prix boutique avant une promotion active (barré), sinon null.' };
  offer.required.push('compare_at_price');
}
for (const name of ['SceneContent', 'HeroScene']) {
  const decor = schemas[name]?.properties?.decor;
  if (decor && !decor.enum.includes('bands')) decor.enum = ['bands', ...decor.enum];
}
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log('Storefront home contract updated.');

// Catalogue filters (price range, deals, stock, location, multi-values) and facets; shop location.
{
  const api2 = JSON.parse(readFileSync(file, 'utf8'));
  const s2 = api2.components.schemas;
  const shopRef = s2.Offer.properties.shop;
  if (!shopRef.properties.district) {
    shopRef.properties.city = { type: 'string' };
    shopRef.properties.district = { anyOf: [{ type: 'string' }, { type: 'null' }] };
    shopRef.required.push('city', 'district');
  }
  if (!s2.Shop.properties.district) {
    s2.Shop.properties.city = { type: 'string' };
    s2.Shop.properties.district = { anyOf: [{ type: 'string' }, { type: 'null' }] };
    s2.Shop.required.push('city', 'district');
  }
  const list = api2.paths['/api/v1/catalogue'].get;
  const names = list.parameters.map((p) => p.name);
  const add = (name, schema, description) => names.includes(name) || list.parameters.push({ name, in: 'query', schema, description });
  add('city', { type: 'string' }, 'Villes, séparées par des virgules.');
  add('district', { type: 'string' }, 'Quartiers, séparés par des virgules.');
  add('price_min', { type: 'integer', minimum: 0 }, 'Prix effectif minimal (FCFA).');
  add('price_max', { type: 'integer', minimum: 0 }, 'Prix effectif maximal (FCFA).');
  add('on_sale', { type: 'boolean' }, 'Seulement les offres en promotion active.');
  add('in_stock', { type: 'boolean' }, 'Seulement les offres disponibles.');
  add('per_page', { type: 'integer', enum: [12, 24, 48] }, 'Taille de page (24 par défaut).');
  add('filters', { type: 'object', additionalProperties: { type: 'string' } }, 'Caractéristiques : filters[clé]=v1,v2 ; sans rayon, clés de tous les rayons publiés.');
  const option = { type: 'object', properties: { value: { type: 'string' }, label: { type: 'string' }, count: { type: 'integer', minimum: 0 } }, required: ['value', 'label', 'count'], additionalProperties: false };
  delete s2.CatalogueFacets;
  s2.CatalogueFacets = {
    type: 'object',
    properties: {
      total: { type: 'integer', minimum: 0 },
      price: { type: 'object', properties: { min: { type: 'integer' }, max: { type: 'integer' } }, required: ['min', 'max'], additionalProperties: false },
      on_sale: { type: 'integer', minimum: 0 },
      in_stock: { type: 'integer', minimum: 0 },
      shops: { type: 'array', items: option },
      cities: { type: 'array', items: option },
      districts: { type: 'array', items: option },
      categories: { type: 'array', items: option },
      characteristics: {
        type: 'array',
        items: {
          type: 'object',
          properties: { key: { type: 'string' }, type: { type: 'string', enum: ['choice', 'boolean'] }, label: { type: 'string' }, unit: { anyOf: [{ type: 'string' }, { type: 'null' }] }, options: { type: 'array', items: option } },
          required: ['key', 'type', 'label', 'unit', 'options'],
          additionalProperties: false,
        },
      },
    },
    required: ['total', 'price', 'on_sale', 'in_stock', 'shops', 'cities', 'districts', 'categories', 'characteristics'],
    additionalProperties: false,
  };
  api2.paths['/api/v1/catalogue/facets'] = {
    get: {
      operationId: 'get__api_v1_catalogue_facets',
      tags: ['Catalogue public'],
      security: [],
      description: 'Valeurs filtrables du rayon ou de la recherche en cours, avec le nombre réel de produits.',
      parameters: [{ name: 'q', in: 'query', schema: { type: 'string', maxLength: 150 } }, { name: 'category', in: 'query', schema: { type: 'string', maxLength: 120 } }],
      responses: { 200: { description: 'Succès', content: { 'application/json': { schema: { type: 'object', properties: { data: { $ref: '#/components/schemas/CatalogueFacets' } }, required: ['data'], additionalProperties: false } } } }, 422: { $ref: '#/components/responses/ValidationError' } },
    },
  };
  writeFileSync(file, JSON.stringify(api2, null, 2) + '\n');
  console.log('Catalogue filters contract updated.');
}

// Shop profile photo (public media path) and shop location in the back-office draft.
{
  const api3 = JSON.parse(readFileSync(file, 'utf8'));
  const s3 = api3.components.schemas;
  const nullable = { anyOf: [{ type: 'string' }, { type: 'null' }] };
  for (const shop of [s3.Offer.properties.shop, s3.Shop]) {
    if (!shop.properties.avatar) {
      shop.properties.avatar = { ...nullable, description: 'Photo de profil de la boutique (chemin média public), sinon null.' };
      shop.required.push('avatar');
    }
  }
  const draft = s3.CatalogueDraft.properties;
  for (const key of ['city', 'district', 'avatar_path']) if (!draft[key]) draft[key] = { ...nullable };
  writeFileSync(file, JSON.stringify(api3, null, 2) + '\n');
}

// Product detail: the other variants of the same product in the same shop.
{
  const api4 = JSON.parse(readFileSync(file, 'utf8'));
  const offer = api4.components.schemas.Offer;
  if (!offer.properties.variants) {
    offer.properties.variants = {
      type: 'array',
      description: 'Fiche produit seulement : variantes publiées du même produit dans la même boutique.',
      items: {
        type: 'object',
        properties: {
          id: { type: 'string', format: 'uuid' },
          attributes: { type: 'object', additionalProperties: true },
          image: { anyOf: [{ type: 'string' }, { type: 'null' }] },
          price: offer.properties.price,
          availability: offer.properties.availability,
        },
        required: ['id', 'attributes', 'image', 'price', 'availability'],
        additionalProperties: false,
      },
    };
  }
  writeFileSync(file, JSON.stringify(api4, null, 2) + '\n');
}

// Product detail variants carry their SKU and pre-sale price (instant variant switch on the page).
{
  const api5 = JSON.parse(readFileSync(file, 'utf8'));
  const item = api5.components.schemas.Offer.properties.variants.items;
  if (!item.properties.sku) {
    item.properties.sku = { type: 'string' };
    item.properties.compare_at_price = { anyOf: [{ type: 'integer', minimum: 0 }, { type: 'null' }] };
    item.required.push('sku', 'compare_at_price');
  }
  writeFileSync(file, JSON.stringify(api5, null, 2) + '\n');
}

// Wholesale packaging of an offer (null when not sold in bulk) and its back-office fields.
{
  const api6 = JSON.parse(readFileSync(file, 'utf8'));
  const s6 = api6.components.schemas;
  if (!s6.Offer.properties.bulk) {
    s6.Offer.properties.bulk = {
      anyOf: [
        {
          type: 'object',
          properties: {
            unit: { type: 'string', enum: ['carton', 'lot', 'boite', 'sac', 'palette'] },
            unit_size: { type: 'integer', minimum: 2 },
            min_units: { type: 'integer', minimum: 1 },
          },
          required: ['unit', 'unit_size', 'min_units'],
          additionalProperties: false,
        },
        { type: 'null' },
      ],
      description: 'Vente en gros : unité (carton, lot…), pièces par unité, minimum d’unités ; null si non vendu en gros.',
    };
    s6.Offer.required.push('bulk');
  }
  const draft = s6.CatalogueDraft.properties;
  if (!draft.bulk_unit) {
    draft.bulk_unit = { anyOf: [{ type: 'string', enum: ['carton', 'lot', 'boite', 'sac', 'palette'] }, { type: 'null' }] };
    draft.bulk_unit_size = { anyOf: [{ type: 'integer', minimum: 2 }, { type: 'null' }] };
    draft.bulk_min_units = { anyOf: [{ type: 'integer', minimum: 1 }, { type: 'null' }] };
  }
  writeFileSync(file, JSON.stringify(api6, null, 2) + '\n');
}

// Product detail: the service that comes with the product (ships from, delivery, warranty, bulk steps, zones).
{
  const api7 = JSON.parse(readFileSync(file, 'utf8'));
  const offer = api7.components.schemas.Offer;
  if (!offer.properties.service) {
    const row = { type: 'object', properties: { label: { type: 'string' }, text: { type: 'string' } }, required: ['label', 'text'], additionalProperties: false };
    const rows = { type: 'array', items: row };
    offer.properties.service = {
      type: 'object',
      description: 'Fiche produit seulement : service rattaché au produit, calculé par l’API.',
      properties: {
        ships_from: { type: 'string', enum: ['warehouse', 'shop'] },
        delivery: { type: 'object', properties: { intro: { type: 'string' }, route: rows, terms: rows }, required: ['intro', 'route', 'terms'], additionalProperties: false },
        warranty: { type: 'object', properties: { months: { anyOf: [{ type: 'integer' }, { type: 'null' }] }, title: { type: 'string' }, intro: { type: 'string' }, steps: rows }, required: ['months', 'title', 'intro', 'steps'], additionalProperties: false },
        bulk: { anyOf: [{ type: 'object', properties: { intro: { type: 'string' }, steps: rows }, required: ['intro', 'steps'], additionalProperties: false }, { type: 'null' }] },
        zones: { type: 'array', items: { type: 'object', properties: { name: { type: 'string' }, districts: { type: 'array', items: { type: 'string' } } }, required: ['name', 'districts'], additionalProperties: false } },
      },
      required: ['ships_from', 'delivery', 'warranty', 'bulk', 'zones'],
      additionalProperties: false,
    };
  }
  writeFileSync(file, JSON.stringify(api7, null, 2) + '\n');
}

// Published reviews carry the author's profile photo (null when none).
{
  const api8 = JSON.parse(readFileSync(file, 'utf8'));
  const find = (node) => {
    if (!node || typeof node !== 'object') return null;
    if (node.properties && node.properties.verified_purchase && node.properties.author) return node;
    for (const v of Object.values(node)) { const hit = find(v); if (hit) return hit; }
    return null;
  };
  const op = api8.paths['/api/v1/products/{id}/reviews'].get;
  let item = find(op);
  if (!item && op.responses['200'].content['application/json'].schema.$ref) item = find(api8.components.schemas);
  if (item && !item.properties.avatar) {
    item.properties.avatar = { anyOf: [{ type: 'string' }, { type: 'null' }], description: 'Photo de profil de l’auteur (chemin média public), sinon null.' };
    if (item.required) item.required.push('avatar');
  }
  writeFileSync(file, JSON.stringify(api8, null, 2) + '\n');
}

// Public contact shortcuts (floating WhatsApp button): from the site settings, else the configured number.
{
  const api3 = JSON.parse(readFileSync(file, 'utf8'));
  const nullable = { anyOf: [{ type: 'string' }, { type: 'null' }] };
  api3.paths['/api/v1/content/contact'] = {
    get: {
      operationId: 'get_api_v1_content_contact',
      tags: ['Content'],
      summary: 'Raccourcis de contact publics (WhatsApp, téléphone, e-mail)',
      responses: {
        200: {
          description: 'Contact',
          content: { 'application/json': { schema: { type: 'object', required: ['data'], properties: { data: {
            type: 'object', required: ['whatsapp', 'phone', 'email'],
            properties: { whatsapp: { ...nullable, description: 'Lien https WhatsApp, ou null si aucun numéro.' }, phone: nullable, email: nullable },
          } } } } },
        },
      },
    },
  };
  writeFileSync(file, JSON.stringify(api3, null, 2) + '\n');
}

// Favourites: the heart on products pins to the member's private "Favourites" board (flagged in the board list).
{
  const api4 = JSON.parse(readFileSync(file, 'utf8'));
  const summary = api4.components.schemas.BoardSummary;
  if (!summary.properties.is_favorites) {
    summary.properties.is_favorites = { type: 'boolean', description: 'Tableau « Mes favoris » du membre (le cœur sur les produits).' };
    summary.required = [...(summary.required ?? []), 'is_favorites'];
  }
  const json = (schema) => ({ 'application/json': { schema } });
  api4.paths['/api/v1/boards/favorites'] = {
    get: {
      operationId: 'get_api_v1_boards_favorites',
      tags: ['Boards'],
      summary: 'Offres dans les favoris du membre',
      security: [{ sessionCookie: [] }],
      'x-resource-owner': 'account',
      responses: {
        200: { description: 'Favoris', content: json({ type: 'object', required: ['data'], properties: { data: { type: 'object', required: ['offer_ids'], properties: { offer_ids: { type: 'array', items: { type: 'string', format: 'uuid' } } } } } }) },
        401: { description: 'Non connecté' },
      },
    },
  };
  api4.paths['/api/v1/boards/favorites/toggle'] = {
    post: {
      operationId: 'post_api_v1_boards_favorites_toggle',
      tags: ['Boards'],
      summary: 'Ajouter une offre aux favoris, ou l’en retirer',
      security: [{ sessionCookie: [] }],
      'x-resource-owner': 'account',
      parameters: [{ $ref: '#/components/parameters/IdempotencyKey' }],
      requestBody: { required: true, content: json({ type: 'object', required: ['offer_id'], properties: { offer_id: { type: 'string', format: 'uuid' } } }) },
      responses: {
        200: { description: 'État du cœur', content: json({ type: 'object', required: ['data'], properties: { data: { type: 'object', required: ['favorite', 'board_id'], properties: { favorite: { type: 'boolean' }, board_id: { type: 'string', format: 'uuid' } } } } }) },
        401: { description: 'Non connecté' },
        422: { description: 'Offre indisponible' },
      },
    },
  };
  writeFileSync(file, JSON.stringify(api4, null, 2) + '\n');
}

// Facets scoped to one shop (the shop page shows the same filters as the catalogue).
{
  const api5 = JSON.parse(readFileSync(file, 'utf8'));
  const params = api5.paths['/api/v1/catalogue/facets'].get.parameters;
  if (!params.some((p) => p.name === 'shop')) {
    params.push({ name: 'shop', in: 'query', required: false, description: 'Slug(s) de boutique, séparés par des virgules.', schema: { type: 'string', maxLength: 500 } });
  }
  writeFileSync(file, JSON.stringify(api5, null, 2) + '\n');
}

// Shop cover photo (wide image at the top of the shop page), edited in the back office next to the profile photo.
{
  const api6 = JSON.parse(readFileSync(file, 'utf8'));
  const nullable = { anyOf: [{ type: 'string' }, { type: 'null' }] };
  const shop = api6.components.schemas.Shop;
  if (!shop.properties.cover) {
    shop.properties.cover = { ...nullable, description: 'Photo de couverture de la boutique (chemin média public), sinon null.' };
    shop.required.push('cover');
  }
  for (const schema of Object.values(api6.components.schemas)) {
    if (schema?.properties?.avatar_path && !schema.properties.cover_path) schema.properties.cover_path = { ...nullable };
  }
  writeFileSync(file, JSON.stringify(api6, null, 2) + '\n');
}

// "Our shops": filters, counts, sort and pages on /api/v1/shops; liked shops (hearts).
{
  const api7 = JSON.parse(readFileSync(file, 'utf8'));
  const s7 = api7.components.schemas;
  const json = (schema) => ({ 'application/json': { schema } });
  const option = { type: 'object', required: ['value', 'label', 'count'], properties: { value: { type: 'string' }, label: { type: 'string' }, count: { type: 'integer' } }, additionalProperties: false };
  s7.ShopListing = {
    type: 'object',
    required: [...s7.Shop.required, 'product_count', 'on_sale_count', 'aisles'],
    properties: {
      ...s7.Shop.properties,
      product_count: { type: 'integer', description: 'Produits publiés (une fiche par produit).' },
      on_sale_count: { type: 'integer', description: 'Produits en promotion en ce moment.' },
      aisles: { type: 'array', items: { type: 'object', required: ['slug', 'name'], properties: { slug: { type: 'string' }, name: { type: 'string' } }, additionalProperties: false } },
    },
    additionalProperties: false,
  };
  const q = (name, schema, description) => ({ name, in: 'query', required: false, description, schema });
  api7.paths['/api/v1/shops'].get.parameters = [
    q('q', { type: 'string', maxLength: 80 }, 'Recherche : nom, identifiant, quartier, ville, spécialité.'),
    q('category', { type: 'string', maxLength: 500 }, 'Rayons (slugs séparés par des virgules).'),
    q('district', { type: 'string', maxLength: 500 }, 'Quartiers (séparés par des virgules).'),
    q('city', { type: 'string', maxLength: 500 }, 'Villes (séparées par des virgules).'),
    q('on_sale', { type: 'boolean' }, 'Seulement les boutiques avec des promotions en cours.'),
    q('sort', { type: 'string', enum: ['name', 'products', 'deals', 'newest'] }, 'Tri.'),
    q('page', { type: 'integer', minimum: 1 }, 'Page (avec per_page).'),
    q('per_page', { type: 'string', enum: ['12', '24', '48'] }, 'Boutiques par page ; sans lui, toutes les boutiques.'),
  ];
  api7.paths['/api/v1/shops'].get.responses['200'].content['application/json'].schema = {
    type: 'object',
    required: ['data', 'meta'],
    properties: {
      data: { type: 'array', items: { $ref: '#/components/schemas/ShopListing' } },
      meta: {
        type: 'object',
        required: ['total', 'page', 'last_page', 'facets'],
        properties: {
          total: { type: 'integer' },
          page: { type: 'integer' },
          last_page: { type: 'integer' },
          facets: {
            type: 'object',
            required: ['categories', 'districts', 'cities', 'on_sale'],
            properties: { categories: { type: 'array', items: option }, districts: { type: 'array', items: option }, cities: { type: 'array', items: option }, on_sale: { type: 'integer' } },
            additionalProperties: false,
          },
        },
        additionalProperties: false,
      },
    },
    additionalProperties: false,
  };
  api7.paths['/api/v1/shops-liked'] = {
    get: {
      operationId: 'get_api_v1_shops_liked',
      tags: ['Catalogue'],
      summary: 'Boutiques aimées par le membre',
      security: [{ sessionCookie: [] }],
      responses: {
        200: { description: 'Boutiques aimées', content: json({ type: 'object', required: ['data'], properties: { data: { type: 'object', required: ['shop_ids'], properties: { shop_ids: { type: 'array', items: { type: 'string' } } } } } }) },
        401: { description: 'Non connecté' },
      },
    },
  };
  api7.paths['/api/v1/shops/{slug}/like'] = {
    post: {
      operationId: 'post_api_v1_shops_slug_like',
      tags: ['Catalogue'],
      summary: 'Aimer une boutique, ou retirer le cœur',
      security: [{ sessionCookie: [] }],
      parameters: [{ name: 'slug', in: 'path', required: true, schema: { type: 'string' } }],
      responses: {
        200: { description: 'État du cœur', content: json({ type: 'object', required: ['data'], properties: { data: { type: 'object', required: ['liked', 'shop_id'], properties: { liked: { type: 'boolean' }, shop_id: { type: 'string' } } } } }) },
        401: { description: 'Non connecté' },
        404: { description: 'Boutique introuvable' },
      },
    },
  };
  writeFileSync(file, JSON.stringify(api7, null, 2) + '\n');
}

// Inspirations: public boards carry their author, cover images, available products and current total; search by text.
{
  const api8 = JSON.parse(readFileSync(file, 'utf8'));
  const s8 = api8.components.schemas;
  const showcase = {
    author: { type: 'object', required: ['name', 'avatar'], properties: { name: { type: 'string' }, avatar: { anyOf: [{ type: 'string' }, { type: 'null' }] } }, additionalProperties: false },
    covers: { type: 'array', maxItems: 4, items: { type: 'string' }, description: 'Images des produits épinglés (jusqu’à 4) pour la couverture.' },
    available_count: { type: 'integer', description: 'Produits épinglés encore en vente.' },
    total: { type: 'object', required: ['amount', 'currency'], properties: { amount: { type: 'integer' }, currency: { type: 'string', enum: ['XAF'] } }, additionalProperties: false, description: 'Total au prix du jour des produits encore en vente.' },
  };
  const sum = s8.PublicBoardSummary;
  for (const [k, v] of Object.entries(showcase)) if (!sum.properties[k]) { sum.properties[k] = v; sum.required = [...(sum.required ?? []), k]; }
  s8.PublicBoard = { ...s8.Board, properties: { ...s8.Board.properties, ...showcase }, required: [...new Set([...(s8.Board.required ?? []), ...Object.keys(showcase)])] };
  api8.paths['/api/v1/boards/public/{id}'].get.responses['200'].content['application/json'].schema.properties.data = { $ref: '#/components/schemas/PublicBoard' };
  const params = api8.paths['/api/v1/boards/public'].get.parameters ?? [];
  if (!params.some((p) => p.name === 'q')) params.push({ name: 'q', in: 'query', required: false, description: 'Recherche dans le titre et la description.', schema: { type: 'string', maxLength: 80 } });
  api8.paths['/api/v1/boards/public'].get.parameters = params;
  writeFileSync(file, JSON.stringify(api8, null, 2) + '\n');
}

// Board cover photo (chosen by the owner): on boards, public cards and public pages; editable with the board.
{
  const api9 = JSON.parse(readFileSync(file, 'utf8'));
  const s9 = api9.components.schemas;
  const cover = { anyOf: [{ type: 'string' }, { type: 'null' }], description: 'Photo de couverture du tableau (chemin média public), sinon null.' };
  for (const name of ['Board', 'PublicBoard', 'PublicBoardSummary']) {
    if (s9[name] && !s9[name].properties.cover) { s9[name].properties.cover = cover; s9[name].required = [...(s9[name].required ?? []), 'cover']; }
  }
  const upd = api9.paths['/api/v1/boards/{id}']?.put?.requestBody?.content?.['application/json']?.schema;
  if (upd?.properties && !upd.properties.cover_path) upd.properties.cover_path = { anyOf: [{ type: 'string', maxLength: 200 }, { type: 'null' }] };
  writeFileSync(file, JSON.stringify(api9, null, 2) + '\n');
}

// Inspirations listing: aisles, shops, deals and stock per board; filters, facets, sort and pages.
{
  const a10 = JSON.parse(readFileSync(file, 'utf8'));
  const s10 = a10.components.schemas;
  const ref = (n) => ({ type: 'object', required: ['slug', 'name'], properties: { slug: { type: 'string' }, name: { type: 'string' } }, additionalProperties: false });
  const extra = {
    aisles: { type: 'array', items: ref() },
    shops: { type: 'array', items: { type: 'object', required: ['slug', 'name', 'district'], properties: { slug: { type: 'string' }, name: { type: 'string' }, district: { anyOf: [{ type: 'string' }, { type: 'null' }] } }, additionalProperties: false } },
    on_sale_count: { type: 'integer' },
    all_in_stock: { type: 'boolean' },
  };
  const sum = s10.PublicBoardSummary;
  for (const [k, v] of Object.entries(extra)) if (!sum.properties[k]) { sum.properties[k] = v; sum.required.push(k); }
  const option = { type: 'object', required: ['value', 'label', 'count'], properties: { value: { type: 'string' }, label: { type: 'string' }, count: { type: 'integer' } }, additionalProperties: false };
  const get = a10.paths['/api/v1/boards/public'].get;
  const q = (name, schema, description) => ({ name, in: 'query', required: false, description, schema });
  get.parameters = [
    q('q', { type: 'string', maxLength: 80 }, 'Recherche : titre, description, rayons, auteur.'),
    q('category', { type: 'string', maxLength: 500 }, 'Rayons (séparés par des virgules).'),
    q('shop', { type: 'string', maxLength: 500 }, 'Boutiques (slugs).'),
    q('district', { type: 'string', maxLength: 500 }, 'Quartiers des boutiques.'),
    q('author', { type: 'string', maxLength: 500 }, 'Auteurs (clés opaques données par les facettes).'),
    q('size', { type: 'string', maxLength: 40 }, 'Taille : small, medium, large.'),
    q('on_sale', { type: 'boolean' }, 'Avec au moins un produit en promotion.'),
    q('in_stock', { type: 'boolean' }, 'Tous les produits en stock.'),
    q('price_min', { type: 'integer', minimum: 0 }, 'Budget total minimum (XAF).'),
    q('price_max', { type: 'integer', minimum: 0 }, 'Budget total maximum (XAF).'),
    q('sort', { type: 'string', enum: ['newest', 'price_asc', 'price_desc', 'products'] }, 'Tri.'),
    q('page', { type: 'integer', minimum: 1 }, 'Page.'),
    q('per_page', { type: 'string', enum: ['12', '24', '48'] }, 'Tableaux par page.'),
  ];
  get.responses['200'].content['application/json'].schema.properties.meta = {
    type: 'object',
    required: ['page', 'last_page', 'total', 'facets'],
    properties: {
      page: { type: 'integer' }, last_page: { type: 'integer' }, total: { type: 'integer' },
      facets: {
        type: 'object',
        required: ['categories', 'shops', 'districts', 'authors', 'sizes', 'on_sale', 'in_stock', 'price'],
        properties: {
          categories: { type: 'array', items: option }, shops: { type: 'array', items: option }, districts: { type: 'array', items: option },
          authors: { type: 'array', items: option }, sizes: { type: 'array', items: option },
          on_sale: { type: 'integer' }, in_stock: { type: 'integer' },
          price: { type: 'object', required: ['min', 'max'], properties: { min: { type: 'integer' }, max: { type: 'integer' } }, additionalProperties: false },
        },
        additionalProperties: false,
      },
    },
    additionalProperties: false,
  };
  writeFileSync(file, JSON.stringify(a10, null, 2) + '\n');
}

// National scope: delivery in the shop's city or shipping across Cameroon (chosen at payment); zones belong to a city.
{
  const a11 = JSON.parse(readFileSync(file, 'utf8'));
  const svc = a11.components.schemas.Offer.properties.service;
  const target = svc.anyOf ? svc.anyOf.find((x) => x.properties) : svc;
  const dl = target.properties.delivery;
  if (!dl.properties.modes) {
    dl.properties.modes = { type: 'array', items: { type: 'object', required: ['key', 'label', 'text'], properties: { key: { type: 'string', enum: ['local', 'shipping'] }, label: { type: 'string' }, text: { type: 'string' } }, additionalProperties: false } };
    dl.required = [...(dl.required ?? []), 'modes'];
  }
  if (!target.properties.zones_city) {
    target.properties.zones_city = { type: 'string', description: 'Ville des quartiers desservis par les livreurs KORA.' };
    target.required = [...(target.required ?? []), 'zones_city'];
  }
  writeFileSync(file, JSON.stringify(a11, null, 2) + '\n');
}

// Inspirations by city: the shops of a board carry their city; filter by city or "all deliverable in my city".
{
  const a12 = JSON.parse(readFileSync(file, 'utf8'));
  const get = a12.paths['/api/v1/boards/public'].get;
  const q = (name, schema, description) => ({ name, in: 'query', required: false, description, schema });
  if (!get.parameters.some((p) => p.name === 'city')) get.parameters.push(q('city', { type: 'string', maxLength: 500 }, 'Villes des boutiques.'));
  if (!get.parameters.some((p) => p.name === 'local_city')) get.parameters.push(q('local_city', { type: 'string', maxLength: 80 }, 'Seulement les tableaux entièrement livrables dans cette ville.'));
  const shop = a12.components.schemas.PublicBoardSummary.properties.shops.items;
  if (!shop.properties.city) { shop.properties.city = { type: 'string' }; shop.required.push('city'); }
  const f = get.responses['200'].content['application/json'].schema.properties.meta.properties.facets;
  if (!f.properties.cities) { f.properties.cities = f.properties.districts; f.required.push('cities'); }
  writeFileSync(file, JSON.stringify(a12, null, 2) + '\n');
}

// Delivery zones belong to a city (Douala, Yaoundé…): public and managed zones carry it; a zone can be saved with it.
{
  const a13 = JSON.parse(readFileSync(file, 'utf8'));
  for (const name of ['DeliveryZonePublic', 'DeliveryZone']) {
    const z = a13.components.schemas[name];
    if (z && !z.properties.city) { z.properties.city = { type: 'string' }; z.required = [...(z.required ?? []), 'city']; }
  }
  for (const [path, item] of Object.entries(a13.paths)) {
    if (!path.includes('delivery') || !path.includes('zone')) continue;
    for (const op of Object.values(item)) {
      const body = op?.requestBody?.content?.['application/json']?.schema;
      if (body?.properties?.districts && !body.properties.city) body.properties.city = { type: 'string', maxLength: 80 };
    }
  }
  writeFileSync(file, JSON.stringify(a13, null, 2) + '\n');
}

// Creator profile: the author of a public board carries an opaque key (link to /createurs/{key}).
{
  const a14 = JSON.parse(readFileSync(file, 'utf8'));
  for (const name of ['PublicBoardSummary', 'PublicBoard']) {
    const au = a14.components.schemas[name]?.properties?.author;
    if (au && !au.properties.key) { au.properties.key = { type: 'string', description: 'Clé publique opaque du créateur.' }; au.required = [...(au.required ?? []), 'key']; }
  }
  writeFileSync(file, JSON.stringify(a14, null, 2) + '\n');
}

// National delivery: addresses have a city; a quote splits lines into local delivery and shipping groups.
{
  const a15 = JSON.parse(readFileSync(file, 'utf8'));
  const s15 = a15.components.schemas;
  const addr = s15.CustomerAddress;
  if (!addr.properties.city) { addr.properties.city = { type: 'string' }; addr.required.push('city'); }
  for (const [path, item] of Object.entries(a15.paths)) {
    if (!path.startsWith('/api/v1/account/addresses')) continue;
    for (const op of Object.values(item)) {
      const body = op?.requestBody?.content?.['application/json']?.schema;
      if (body?.properties?.district && !body.properties.city) body.properties.city = { type: 'string', maxLength: 80 };
    }
  }
  const q = s15.DeliveryQuote;
  if (!q.properties.address.properties.city) { q.properties.address.properties.city = { type: 'string' }; q.properties.address.required.push('city'); }
  const item = q.properties.breakdown.items;
  if (!item.properties.code.enum.includes('shipping')) item.properties.code.enum.push('shipping');
  if (!item.properties.from) item.properties.from = { type: 'string', description: 'Ville de départ (expédition).' };
  if (!q.properties.groups) {
    const nn = (t) => ({ anyOf: [t, { type: 'null' }] });
    q.properties.groups = {
      type: 'array',
      description: 'Lignes livrées dans la ville de l’adresse (local) ou expédiées depuis une autre ville (shipping).',
      items: {
        type: 'object',
        required: ['mode', 'city', 'line_ids', 'fee'],
        properties: {
          mode: { type: 'string', enum: ['local', 'shipping'] },
          from: { type: 'string' },
          city: { type: 'string' },
          line_ids: { type: 'array', items: { type: 'string', format: 'uuid' } },
          fee: nn({ type: 'string', pattern: '^(0|[1-9][0-9]*)$' }),
          days_min: nn({ type: 'integer' }),
          days_max: nn({ type: 'integer' }),
          to_door: nn({ type: 'boolean' }),
        },
        additionalProperties: false,
      },
    };
    q.properties.needs_slot = { type: 'boolean', description: 'Un créneau est requis (livraison locale).' };
    q.required.push('groups', 'needs_slot');
  }
  writeFileSync(file, JSON.stringify(a15, null, 2) + '\n');
}

// Shipping routes between cities (team settings, permission delivery.read / delivery.settings).
{
  const a16 = JSON.parse(readFileSync(file, 'utf8'));
  const json = (schema) => ({ 'application/json': { schema } });
  a16.components.schemas.ShippingRoute = {
    type: 'object',
    required: ['id', 'origin_city', 'destination_city', 'fee', 'days_min', 'days_max', 'to_door', 'active', 'version', 'updated_at'],
    properties: {
      id: { type: 'string', format: 'uuid' }, origin_city: { type: 'string' },
      destination_city: { type: 'string', description: 'Ville d’arrivée, ou « * » pour toute autre ville.' },
      fee: { type: 'integer', minimum: 0, description: 'Frais d’expédition (XAF).' }, days_min: { type: 'integer' }, days_max: { type: 'integer' },
      to_door: { type: 'boolean', description: 'Livré à la porte (sinon retrait en agence).' }, active: { type: 'boolean' },
      version: { type: 'integer' }, updated_at: { type: 'string', format: 'date-time' },
    },
    additionalProperties: false,
  };
  const input = (update) => ({
    type: 'object',
    required: [...(update ? ['version'] : []), 'origin_city', 'destination_city', 'fee', 'days_min', 'days_max', 'to_door', 'active'],
    properties: {
      ...(update ? { version: { type: 'integer', minimum: 1 } } : {}),
      origin_city: { type: 'string', maxLength: 80 }, destination_city: { type: 'string', maxLength: 80 },
      fee: { type: 'integer', minimum: 0 }, days_min: { type: 'integer', minimum: 0, maximum: 60 }, days_max: { type: 'integer', minimum: 0, maximum: 60 },
      to_door: { type: 'boolean' }, active: { type: 'boolean' },
    },
    additionalProperties: false,
  });
  const one = { description: 'Route', content: json({ type: 'object', required: ['data'], properties: { data: { $ref: '#/components/schemas/ShippingRoute' } } }) };
  const sec = [{ sessionCookie: [] }];
  const key = { name: 'Idempotency-Key', in: 'header', required: true, schema: { type: 'string', format: 'uuid' } };
  a16.paths['/api/v1/operations/delivery/shipping-routes'] = {
    get: {
      operationId: 'get_api_v1_operations_delivery_shipping_routes', tags: ['Delivery'], summary: 'Tarifs d’expédition entre villes', security: sec,
      parameters: [{ name: 'origin_city', in: 'query', required: false, schema: { type: 'string', maxLength: 80 } }, { name: 'page', in: 'query', required: false, schema: { type: 'integer', minimum: 1 } }],
      responses: { 200: { description: 'Routes', content: json({ type: 'object', required: ['data', 'meta'], properties: { data: { type: 'array', items: { $ref: '#/components/schemas/ShippingRoute' } }, meta: { $ref: '#/components/schemas/PageMeta' } } }) }, 401: { description: 'Non connecté' }, 403: { description: 'Permission delivery.read requise' } },
    },
    post: {
      operationId: 'post_api_v1_operations_delivery_shipping_routes', tags: ['Delivery'], summary: 'Créer un tarif d’expédition', security: sec, parameters: [key],
      requestBody: { required: true, content: json(input(false)) },
      responses: { 201: one, 409: { description: 'Cette route existe déjà' }, 422: { description: 'Données invalides' } },
    },
  };
  a16.paths['/api/v1/operations/delivery/shipping-routes/{id}'] = {
    put: {
      operationId: 'put_api_v1_operations_delivery_shipping_routes_id', tags: ['Delivery'], summary: 'Modifier un tarif d’expédition', security: sec,
      parameters: [{ name: 'id', in: 'path', required: true, schema: { type: 'string', format: 'uuid' } }, key],
      requestBody: { required: true, content: json(input(true)) },
      responses: { 200: one, 404: { description: 'Introuvable' }, 409: { description: 'Version périmée ou route en double' }, 422: { description: 'Données invalides' } },
    },
  };
  writeFileSync(file, JSON.stringify(a16, null, 2) + '\n');
}

// Account: each order line carries the offer's current approved photo (order list thumbnails).
{
  const a17 = JSON.parse(readFileSync(file, 'utf8'));
  const line = a17.components.schemas.Order.properties.lines.items;
  if (!line.properties.image) {
    line.properties.image = { anyOf: [{ type: 'string' }, { type: 'null' }], description: 'Photo publique actuelle de l’offre, sinon null.' };
    line.required.push('image');
  }
  writeFileSync(file, JSON.stringify(a17, null, 2) + '\n');
}

// Change an unpaid order (cancelled, items back to the cart) and the member's profile photo.
{
  const api9 = JSON.parse(readFileSync(file, 'utf8'));
  const json = (schema) => ({ 'application/json': { schema } });
  api9.paths['/api/v1/orders/{id}/reopen'] = {
    post: {
      operationId: 'post_orders__id__reopen',
      tags: ['Commandes et paiements'],
      summary: 'Modifier une commande non payée : elle est annulée, ses articles reviennent dans le panier',
      description: 'Refusé si la commande est payée, annulée ou expirée (ORDER_NOT_EDITABLE) ou si un paiement est en cours (ORDER_PAYMENT_IN_PROGRESS). Réservations, créneau, offre de livraison et points sont rendus.',
      security: [{ sessionCookie: [] }],
      'x-resource-owner': 'account',
      parameters: [{ name: 'id', in: 'path', required: true, schema: { type: 'string', format: 'uuid' } }, { $ref: '#/components/parameters/IdempotencyKey' }],
      responses: {
        200: { description: 'Commande annulée, panier complété', content: json({ type: 'object', required: ['data'], properties: { data: { type: 'object', required: ['order_id', 'status', 'skipped_line_ids'], properties: { order_id: { type: 'string', format: 'uuid' }, status: { type: 'string', enum: ['cancelled'] }, skipped_line_ids: { type: 'array', items: { type: 'string', format: 'uuid' } } } } } }) },
        401: { description: 'Non connecté' },
        404: { description: 'Commande inconnue' },
        422: { description: 'Commande non modifiable' },
      },
    },
  };
  api9.paths['/api/v1/me/avatar'] = {
    post: {
      operationId: 'post_me_avatar',
      tags: ['Compte'],
      summary: 'Photo de profil du membre (image publique, 4 Mo au plus)',
      security: [{ sessionCookie: [] }],
      'x-resource-owner': 'account',
      requestBody: { required: true, content: { 'multipart/form-data': { schema: { type: 'object', required: ['file'], properties: { file: { type: 'string', format: 'binary' } } } } } },
      responses: { 200: { description: 'Profil mis à jour' }, 401: { description: 'Non connecté' }, 422: { description: 'Image refusée' } },
    },
    delete: {
      operationId: 'delete_me_avatar',
      tags: ['Compte'],
      summary: 'Retirer la photo de profil',
      security: [{ sessionCookie: [] }],
      'x-resource-owner': 'account',
      responses: { 200: { description: 'Profil mis à jour' }, 401: { description: 'Non connecté' } },
    },
  };
  writeFileSync(file, JSON.stringify(api9, null, 2) + '\n');
}
