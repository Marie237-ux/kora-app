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
