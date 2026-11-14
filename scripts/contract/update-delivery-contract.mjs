// Adds the delivery module (zones, collection points, tariff grids, slots, addresses, quotes) to the contract.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const S = api.components.schemas;

const str = { type: 'string' };
const int = { type: 'integer' };
const bool = { type: 'boolean' };
const uuid = { type: 'string', format: 'uuid' };
const dt = { type: 'string', format: 'date-time' };
const date = { type: 'string', format: 'date' };
const time = { type: 'string', pattern: '^([01][0-9]|2[0-3]):[0-5][0-9]$' };
const version = { type: 'integer', minimum: 1 };
const amount = { type: 'integer', minimum: 0, maximum: 100000000 };
const xaf = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const no = { type: 'boolean', const: false };
const yes = { type: 'boolean', const: true };
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const arr = (items, extra = {}) => ({ type: 'array', items, ...extra });
const obj = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const localized = obj({ fr: str, en: str });

S.DeliveryZonePublic = obj({ id: uuid, name: str, districts: arr(str) });
S.DeliveryZone = obj({
  id: uuid,
  code: str,
  name: localized,
  districts: arr(str),
  active: bool,
  version,
  updated_at: dt,
});
S.CollectionPoint = obj({
  id: uuid,
  zone_id: uuid,
  shop_id: nullable(uuid),
  warehouse_id: nullable(uuid),
  name: str,
  address: str,
  priority: int,
  active: bool,
  version,
});
S.DeliveryGridRules = obj({
  demo: bool,
  zones: arr(obj({ zone_id: uuid, base_fee: amount }), { minItems: 1, maxItems: 200 }),
  extra_collection_fee: amount,
  route_supplements: arr(obj({ from_zone_id: uuid, to_zone_id: uuid, amount }), { maxItems: 2000 }),
  mass_brackets: arr(obj({ max_grams: { type: 'integer', minimum: 1 }, amount }), {
    minItems: 1,
    maxItems: 50,
  }),
  bulky_fee: amount,
  fragile_fee: amount,
  validity_minutes: { type: 'integer', minimum: 5, maximum: 1440 },
  warehouse_preparation_minutes: { type: 'integer', minimum: 0, maximum: 10080 },
});
S.DeliveryGrid = obj({
  id: uuid,
  number: int,
  status: { type: 'string', enum: ['draft', 'published', 'retired'] },
  rules: ref('DeliveryGridRules'),
  notes: str,
  version,
  published_at: nullable(dt),
  retired_at: nullable(dt),
  created_at: dt,
});
S.DeliverySlotRule = obj({
  id: uuid,
  zone_id: nullable(uuid),
  weekday: { type: 'integer', minimum: 1, maximum: 7 },
  starts_at: time,
  ends_at: time,
  capacity: int,
  cutoff_minutes: int,
  active: bool,
  version,
});
S.DeliverySlotClosure = obj({
  id: uuid,
  date,
  rule_id: nullable(uuid),
  capacity: int,
  reason: str,
  created_at: dt,
});
S.DeliverySlot = obj({
  rule_id: uuid,
  date,
  starts_at: time,
  ends_at: time,
  starts_at_utc: dt,
  capacity: int,
  remaining: int,
});
S.CustomerAddress = obj({
  id: uuid,
  label: str,
  district: str,
  landmark: str,
  instructions: str,
  latitude: nullable({ type: 'number' }),
  longitude: nullable({ type: 'number' }),
  contact_name: str,
  contact_phone: str,
  alternate_name: nullable(str),
  alternate_phone: nullable(str),
  served: bool,
  zone: nullable(ref('DeliveryZonePublic')),
  version,
  updated_at: dt,
});
const reason = {
  type: 'string',
  enum: [
    'zone_not_served',
    'zone_not_in_grid',
    'grid_missing',
    'mass_unknown',
    'mass_above_grid',
    'transport_unavailable',
    'supplier_unconfirmed',
    'supplier_quantity_short',
    'collection_point_missing',
  ],
};
S.DeliveryQuote = obj({
  id: uuid,
  status: { type: 'string', enum: ['quoted', 'confirmation_required', 'unserviceable'] },
  reasons: arr(reason),
  version,
  cart_version: version,
  stale: bool,
  expired: bool,
  expires_at: dt,
  currency: { type: 'string', const: 'XAF' },
  total: nullable(xaf),
  breakdown: arr(
    obj({
      code: {
        type: 'string',
        enum: ['base', 'extra_collections', 'route', 'mass', 'bulky', 'fragile'],
      },
      quantity: int,
      amount: xaf,
    }),
  ),
  demo_amounts: bool,
  zone: nullable(ref('DeliveryZonePublic')),
  address: obj({ id: uuid, label: str, district: str }),
  collection_count: int,
  ready_after_minutes: nullable(int),
  lines: arr(
    obj({
      cart_line_id: uuid,
      offer_id: uuid,
      quantity: int,
      from_warehouse: int,
      from_supplier: int,
      issues: arr(reason),
    }),
  ),
  slot: nullable(
    obj({
      rule_id: uuid,
      date,
      starts_at: time,
      ends_at: time,
      status: { type: 'string', enum: ['held', 'expired', 'confirmed', 'released'] },
    }),
  ),
  slots: arr(ref('DeliverySlot')),
  promotion_applied: no,
  stock_reserved: no,
  created_at: dt,
});

const responses = (status, schema, anonymous) => ({
  [status]: { description: 'Succès', content: { 'application/json': { schema } } },
  ...(anonymous
    ? {}
    : {
        401: { $ref: '#/components/responses/Unauthorized' },
        403: { $ref: '#/components/responses/Forbidden' },
      }),
  404: { description: 'Ressource inconnue ou appartenant à un autre compte.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  419: { description: 'Session/CSRF expiré.' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
});

function operation(method, url, response, options = {}) {
  const {
    body,
    status = 200,
    paged = false,
    permission,
    owner,
    description = '',
    params = [],
  } = options;
  const anonymous = owner === 'public';
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  parameters.push({ name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } });
  if (paged)
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  parameters.push(...params);
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const schema = paged
    ? obj({ data: arr(response), meta: ref('PageMeta') })
    : obj({ data: response });
  const notes = permission
    ? ['Permission serveur : ' + permission + '. MFA obligatoire.']
    : [
        anonymous
          ? 'Public, sans donnée personnelle.'
          : 'Compte authentifié ; ressources propres au compte.',
      ];
  if (method !== 'get') notes.push('Session web HttpOnly, CSRF et UUID Idempotency-Key requis.');
  const op = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Livraison'],
    security: anonymous ? [] : [{ sessionCookie: [] }],
    parameters,
    description: [description, ...notes].filter(Boolean).join(' '),
    ...(permission ? { 'x-permission': permission } : { 'x-resource-owner': owner }),
    responses: responses(status, schema, anonymous),
  };
  if (body) op.requestBody = { required: true, content: { 'application/json': { schema: body } } };
  api.paths['/api/v1/' + url] ??= {};
  api.paths['/api/v1/' + url][method] = op;
}

// Customer side.
const addressBody = (update) =>
  obj(
    {
      ...(update ? { version } : {}),
      label: { type: 'string', maxLength: 80 },
      district: { type: 'string', maxLength: 120 },
      landmark: { type: 'string', maxLength: 300 },
      instructions: nullable({ type: 'string', maxLength: 1000 }),
      latitude: nullable({ type: 'number', minimum: -90, maximum: 90 }),
      longitude: nullable({ type: 'number', minimum: -180, maximum: 180 }),
      contact_name: { type: 'string', maxLength: 150 },
      contact_phone: { type: 'string', pattern: '^\\+?[0-9 ]{6,20}$' },
      alternate_name: nullable({ type: 'string', maxLength: 150 }),
      alternate_phone: nullable({ type: 'string', pattern: '^\\+?[0-9 ]{6,20}$' }),
    },
    [
      ...(update ? ['version'] : []),
      'label',
      'district',
      'landmark',
      'contact_name',
      'contact_phone',
    ],
  );
operation('get', 'delivery/zones', arr(ref('DeliveryZonePublic')), {
  owner: 'public',
  description: 'Zones actives et quartiers desservis, pour vérifier une adresse avant inscription.',
});
operation('get', 'account/addresses', arr(ref('CustomerAddress')), { owner: 'account' });
operation('post', 'account/addresses', ref('CustomerAddress'), {
  owner: 'account',
  status: 201,
  body: addressBody(false),
  description:
    'Quartier et repère suffisent ; la carte est facultative. served indique la desserte. Vingt adresses actives au maximum.',
});
operation('put', 'account/addresses/{id}', ref('CustomerAddress'), {
  owner: 'account',
  body: addressBody(true),
});
operation('post', 'account/addresses/{id}/archive', obj({ id: uuid, archived: yes }), {
  owner: 'account',
  body: obj({ version }),
  description:
    'Archivée et non supprimée : les devis et commandes passés gardent leur adresse exacte.',
});
operation('post', 'cart/delivery-quotes', ref('DeliveryQuote'), {
  owner: 'account',
  status: 201,
  body: obj({ cart_version: version, address_id: uuid }),
  description:
    'Devis serveur sur les lignes sélectionnées : sources physiques du plan de stock, points de collecte réels, poids et classe de transport, grille publiée enregistrée. Aucun montant par défaut : total null si une donnée manque. Panier à revoir refusé.',
});
operation('get', 'cart/delivery-quotes/{id}', ref('DeliveryQuote'), {
  owner: 'account',
  description:
    'stale si le panier a changé depuis le devis ; créneaux proposés seulement pour un devis valable.',
});
operation('post', 'cart/delivery-quotes/{id}/slot', ref('DeliveryQuote'), {
  owner: 'account',
  body: obj({ version, rule_id: uuid, date }),
  description:
    'Réserve une place du créneau jusqu’à expiration du devis, sous verrou par créneau : la dernière place ne peut être prise deux fois. Aucun paiement ni stock réservé.',
});

// Operations side.
const read = { permission: 'delivery.read' };
const write = { permission: 'delivery.settings' };
const zoneBody = (update) =>
  obj({
    ...(update ? { version } : {}),
    code: { type: 'string', maxLength: 40, pattern: '^[A-Z0-9_-]+$' },
    name: localized,
    districts: arr({ type: 'string', maxLength: 120 }, { minItems: 1, maxItems: 200 }),
    active: bool,
  });
const pointBody = (update) =>
  obj(
    {
      ...(update ? { version } : {}),
      zone_id: uuid,
      shop_id: nullable(uuid),
      warehouse_id: nullable(uuid),
      name: { type: 'string', maxLength: 150 },
      address: { type: 'string', maxLength: 500 },
      priority: { type: 'integer', minimum: 0, maximum: 1000 },
      active: bool,
    },
    [...(update ? ['version'] : []), 'zone_id', 'name', 'address', 'priority', 'active'],
  );
const gridBody = (update) =>
  obj(
    {
      ...(update ? { version } : {}),
      notes: nullable({ type: 'string', maxLength: 2000 }),
      rules: ref('DeliveryGridRules'),
    },
    [...(update ? ['version'] : []), 'rules'],
  );
const ruleBody = (update) =>
  obj({
    ...(update ? { version } : {}),
    zone_id: nullable(uuid),
    weekday: { type: 'integer', minimum: 1, maximum: 7 },
    starts_at: time,
    ends_at: time,
    capacity: { type: 'integer', minimum: 0, maximum: 10000 },
    cutoff_minutes: { type: 'integer', minimum: 0, maximum: 10080 },
    active: bool,
  });
operation('get', 'operations/delivery/zones', ref('DeliveryZone'), { ...read, paged: true });
operation('post', 'operations/delivery/zones', ref('DeliveryZone'), {
  ...write,
  status: 201,
  body: zoneBody(false),
  description:
    'Un quartier appartient à une seule zone active ; comparaison sans accents ni casse.',
});
operation('put', 'operations/delivery/zones/{id}', ref('DeliveryZone'), {
  ...write,
  body: zoneBody(true),
});
operation('get', 'operations/delivery/collection-points', ref('CollectionPoint'), {
  ...read,
  paged: true,
});
operation('post', 'operations/delivery/collection-points', ref('CollectionPoint'), {
  ...write,
  status: 201,
  body: pointBody(false),
  description: 'Boutique partenaire ou dépôt KORA, exactement l’un des deux.',
});
operation('put', 'operations/delivery/collection-points/{id}', ref('CollectionPoint'), {
  ...write,
  body: pointBody(true),
});
operation('get', 'operations/delivery/grids', ref('DeliveryGrid'), { ...read, paged: true });
operation('post', 'operations/delivery/grids', ref('DeliveryGrid'), {
  ...write,
  status: 201,
  body: gridBody(false),
  description: 'Brouillon. demo signale des montants fictifs, affichés comme tels.',
});
operation('put', 'operations/delivery/grids/{id}', ref('DeliveryGrid'), {
  ...write,
  body: gridBody(true),
  description: 'Brouillon seulement ; une grille publiée est figée.',
});
operation('post', 'operations/delivery/grids/{id}/publish', ref('DeliveryGrid'), {
  ...write,
  body: obj({ version, reviewed: yes }),
  description:
    'Publie après relecture et retire la grille précédente ; les devis existants gardent la leur.',
});
operation('get', 'operations/delivery/slot-rules', ref('DeliverySlotRule'), {
  ...read,
  paged: true,
});
operation('post', 'operations/delivery/slot-rules', ref('DeliverySlotRule'), {
  ...write,
  status: 201,
  body: ruleBody(false),
  description: 'Créneau hebdomadaire en heure de Douala ; zone null pour toutes les zones.',
});
operation('put', 'operations/delivery/slot-rules/{id}', ref('DeliverySlotRule'), {
  ...write,
  body: ruleBody(true),
  description: 'Les places déjà réservées restent valables.',
});
operation('get', 'operations/delivery/slot-closures', ref('DeliverySlotClosure'), {
  ...read,
  paged: true,
  params: [{ name: 'from', in: 'query', schema: date }],
});
operation('post', 'operations/delivery/slot-closures', ref('DeliverySlotClosure'), {
  ...write,
  status: 201,
  body: obj({
    date,
    rule_id: nullable(uuid),
    capacity: { type: 'integer', minimum: 0, maximum: 10000 },
    reason: { type: 'string', maxLength: 300 },
  }),
  description:
    'Capacité réduite ou nulle pour une date ; la fermeture la plus restrictive s’applique.',
});
operation('get', 'operations/delivery/slots', arr(ref('DeliverySlot')), {
  ...read,
  params: [{ name: 'zone_id', in: 'query', required: true, schema: uuid }],
  description: 'Occupation des créneaux à venir, complets compris.',
});

api.info.version = '0.9.0';
api.info.title =
  'KORA — API implémentée : catalogue, approvisionnement, demandes, panier, tableaux et livraison';
api.info.description =
  'Sessions HttpOnly et CSRF, permissions/MFA sur la gestion, confirmation récente finance. Commandes idempotentes et versions serveur. Catalogue, stock/achats/dettes, demandes privées et indicateurs, recherche texte, panier partagé, tableaux collaboratifs, adresses, devis de livraison et créneaux implémentés. Le reste du lancement demeure obligatoire dans EXIGENCES.md ; aucun prestataire ni paiement réel simulé.';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
