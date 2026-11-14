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
