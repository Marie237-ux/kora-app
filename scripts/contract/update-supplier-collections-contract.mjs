// Run after the delivery rounds script: located collection points, supplier shop collections, mixed rounds.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const version = { type: 'integer', minimum: 1 };
const count = { type: 'integer', minimum: 0 };
const userId = { type: 'integer', minimum: 1 };
const packages = { type: 'integer', minimum: 1, maximum: 100 };
const dateTime = { type: 'string', format: 'date-time' };
const string = { type: 'string' };
const text = (max) => ({ type: 'string', minLength: 1, maxLength: max });
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});

// Located collection points: optional district, landmark and coordinates (sent together).
const location = {
  district: nullable({ type: 'string', maxLength: 120 }),
  landmark: nullable({ type: 'string', maxLength: 300 }),
  latitude: nullable({ type: 'number', minimum: -90, maximum: 90 }),
  longitude: nullable({ type: 'number', minimum: -180, maximum: 180 }),
};
const point = schemas.CollectionPoint;
Object.assign(point.properties, location);
point.required = [...new Set([...point.required, ...Object.keys(location)])];
for (const [url, method] of [
  ['/api/v1/operations/delivery/collection-points', 'post'],
  ['/api/v1/operations/delivery/collection-points/{id}', 'put'],
]) {
  const op = api.paths[url][method];
  Object.assign(op.requestBody.content['application/json'].schema.properties, location);
  const note =
    ' Localisation facultative : quartier, repère et coordonnées (latitude et longitude ensemble). Un champ omis conserve sa valeur ; les coordonnées affinent l’ordre des arrêts des tournées.';
  // Replaying the script must not append the note twice.
  op.description = (op.description ?? '').split(note).join('') + note;
}

const status = {
  type: 'string',
  enum: [
    'unassigned',
    'assigned',
    'accepted',
    'collected',
    'incident',
    'dropped',
    'received',
    'cancelled',
  ],
};
const incidentKind = {
  type: 'string',
  enum: ['shop_closed', 'goods_not_ready', 'goods_mismatch', 'vehicle_unavailable', 'other'],
};
const shopPlace = object({
  type: { type: 'string', enum: ['shop'] },
  shop: string,
  name: string,
  address: string,
  district: nullable(string),
  landmark: nullable(string),
  latitude: nullable({ type: 'number' }),
  longitude: nullable({ type: 'number' }),
});
const warehousePlace = object({
  type: { type: 'string', enum: ['warehouse'] },
  code: string,
  name: string,
  address: string,
});
const line = object({
  purchase_line_id: uuid,
  name: string,
  sku: string,
  expected_quantity: version,
  collected_quantity: nullable(count),
});
schemas.SupplierCollection = object({
  id: uuid,
  number: string,
  status,
  version,
  purchase_order_id: uuid,
  courier: nullable(object({ user_id: userId, public_name: string, badge: string }, ['user_id'])),
  custody: { type: 'string', enum: ['shop', 'courier', 'warehouse'] },
  custodian_id: nullable(userId),
  acceptance_required: { type: 'boolean' },
  pickup: shopPlace,
  destination: warehousePlace,
  lines: array(line),
  packages: nullable(packages),
  shortfall: { type: 'boolean' },
  incident: nullable(object({ kind: incidentKind, note: string })),
  collected_at: nullable(dateTime),
  dropped_at: nullable(dateTime),
  receipt_id: nullable(uuid),
  cancel_reason: nullable(string),
  events: array(
    object({
      action: string,
      actor_id: nullable(userId),
      data: { type: 'object' },
      created_at: dateTime,
    }),
  ),
});
schemas.SupplierCollection.description =
  'Collecte en boutique fournisseur d’unités d’un achat confirmé, déposées dans un dépôt KORA. Jamais de prix d’achat. Ne crée ni stock, ni dette, ni paiement : la réception au dépôt reste celle de l’équipe stock, avec preuve.';
schemas.CourierCollection = object(
  {
    id: uuid,
    number: string,
    status: { anyOf: [status, { type: 'string', enum: ['declined'] }] },
    version,
    holding_goods: { type: 'boolean' },
    take_over_from_courier: { type: 'boolean' },
    pickup: shopPlace,
    destination: warehousePlace,
    lines: array(line),
    packages: nullable(packages),
    shortfall: { type: 'boolean' },
    incident: nullable(object({ kind: incidentKind })),
  },
  ['id', 'number', 'status', 'version'],
);

// Rounds now mix delivery missions and supplier collections.
const step = schemas.DeliveryRoundStep;
step.properties.kind = { type: 'string', enum: ['pickup', 'delivery', 'collect', 'drop'] };
step.properties.items = array(line);
step.properties.missions = array(
  object(
    {
      type: { type: 'string', enum: ['mission', 'collection'] },
      mission_id: uuid,
      collection_id: uuid,
      number: string,
      task_status: string,
      version,
      packages: nullable(version),
      state: { type: 'string', enum: ['todo', 'waiting', 'done', 'removed'] },
      action: nullable({
        type: 'string',
        enum: [
          'accept',
          'pickup',
          'pickup_first',
          'handover',
          'collect',
          'collect_first',
          'drop',
          'wait_dispatch',
          'wait_approval',
        ],
      }),
      stop_id: uuid,
    },
    ['type', 'number', 'task_status', 'version', 'packages', 'state', 'action'],
  ),
);
step.properties.missions.description =
  'Tâches de l’étape : missions de livraison (mission_id) ou collectes fournisseur (collection_id), avec l’action attendue d’après leur état réel.';
const progress = schemas.DeliveryRound.properties.progress;
Object.assign(progress.properties, { collections_total: count, collections_done: count });
progress.required = [...new Set([...progress.required, 'collections_total', 'collections_done'])];
const ids = { type: 'array', maxItems: 60, items: uuid };
for (const [url, method] of [
  ['/api/v1/operations/rounds', 'post'],
  ['/api/v1/operations/rounds/{id}/missions', 'put'],
]) {
  const body = api.paths[url][method].requestBody.content['application/json'].schema;
  body.properties.mission_ids = ids;
  body.properties.collection_ids = ids;
  body.required = body.required.filter((name) => name !== 'mission_ids');
  body.description = 'Au moins une mission ou une collecte.';
}

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Achat ou collecte inconnu, ou collecte non affectée à ce livreur.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function operation(
  space,
  method,
  url,
  { response, body, status = 200, description, query, permission },
) {
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const courier = space === 'courier';
  api.paths['/api/v1/' + space + '/' + url] = {
    ...api.paths['/api/v1/' + space + '/' + url],
    [method]: {
      operationId: (method + '_' + space + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [courier ? 'Espace livreur' : 'Collectes fournisseur'],
      security: [{ sessionCookie: [] }],
      parameters,
      'x-permission': permission,
      description:
        description +
        ' Permission serveur : ' +
        permission +
        (courier
          ? '. Affectation actuelle revérifiée avant tout rejeu idempotent.'
          : '. Session avec MFA obligatoire.') +
        (method === 'get' ? '' : ' CSRF et Idempotency-Key UUID requis.'),
      responses: {
        [status]: { description: 'Succès', content: { 'application/json': { schema: response } } },
        ...errors,
      },
      ...(body
        ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
        : {}),
    },
  };
}
const data = (schema) => object({ data: schema });
const page = (schema) =>
  object({
    data: array(schema),
    meta: object({ page: version, last_page: version, total: count }),
  });
const collection = data(ref('SupplierCollection'));
const courierCollection = data(ref('CourierCollection'));
const lines = array(object({ purchase_line_id: uuid, quantity: count }));
const ops = (method, url, opts) => operation('operations', method, url, opts);
const courierOp = (method, url, opts) =>
  operation('courier', method, url, { permission: 'missions.own', ...opts });

ops('get', 'collections', {
  permission: 'delivery.read',
  response: page(ref('SupplierCollection')),
  query: [
    { name: 'status', in: 'query', required: false, schema: status },
    { name: 'courier_id', in: 'query', required: false, schema: userId },
    { name: 'warehouse_id', in: 'query', required: false, schema: uuid },
    { name: 'page', in: 'query', required: false, schema: version },
  ],
  description: 'Collectes fournisseur, plus anciennes d’abord. Lecture cohérente.',
});
ops('get', 'collections/{id}', {
  permission: 'delivery.read',
  response: collection,
  description:
    'Détail d’une collecte : lieux, articles attendus et comptés, garde, incident et journal.',
});
ops('post', 'purchases/{id}/collections', {
  permission: 'delivery.assign',
  status: 201,
  response: collection,
  body: object({ shop_id: uuid, warehouse_id: uuid, lines }, ['shop_id', 'warehouse_id']),
  description:
    'Crée la collecte, chez le point actif de la boutique, des unités d’un achat confirmé restant à recevoir et non promises à une autre collecte en cours. Sans lignes : tout le reste de cette boutique. Destination : un dépôt actif.',
});
ops('post', 'collections/{id}/assign', {
  permission: 'delivery.assign',
  response: collection,
  body: object(
    {
      version,
      courier_id: userId,
      acceptance_required: { type: 'boolean' },
      note: nullable(text(1000)),
    },
    ['version', 'courier_id', 'acceptance_required'],
  ),
  description:
    'Affecte ou réaffecte un livreur actif. Marchandises déjà chez un livreur : note de transmission exigée, le nouveau livreur les reprend sans recompter.',
});
ops('post', 'collections/{id}/cancel', {
  permission: 'delivery.assign',
  response: collection,
  body: object({ version, reason: text(1000) }),
  description:
    'Annule une collecte tant que rien n’a été compté en boutique ; les unités redeviennent à collecter.',
});
ops('post', 'collections/{id}/retry', {
  permission: 'delivery.assign',
  response: collection,
  body: object({ version, note: text(2000) }),
  description: 'Clôt l’incident et relance la collecte avec le même livreur.',
});
ops('post', 'collections/{id}/received', {
  permission: 'stock.receive',
  response: collection,
  body: object({ version, receipt_id: uuid }),
  description:
    'L’équipe stock relie sa réception (même achat, emplacement du dépôt de destination, une seule collecte par réception) à la collecte déposée.',
});
courierOp('get', 'collections', {
  response: page(ref('CourierCollection')),
  query: [{ name: 'page', in: 'query', required: false, schema: version }],
  description: 'Collectes en cours du livreur connecté.',
});
courierOp('get', 'collections/{id}', {
  response: courierCollection,
  description: 'Collecte affectée au livreur connecté ; introuvable sinon.',
});
courierOp('post', 'collections/{id}/accept', {
  response: courierCollection,
  body: object({ version }),
  description: 'Accepte une collecte lorsque ce mode a été choisi.',
});
courierOp('post', 'collections/{id}/decline', {
  response: data(
    object({ id: uuid, number: string, status: { type: 'string', enum: ['declined'] } }),
  ),
  body: object({ version, reason: text(1000) }),
  description: 'Refuse une collecte en attente ; elle redevient à affecter.',
});
courierOp('post', 'collections/{id}/collect', {
  response: courierCollection,
  body: object({ version, packages, lines, note: nullable(text(2000)) }, ['version', 'packages']),
  description:
    'En boutique : quantité comptée pour chaque ligne attendue (jamais plus), nombre de colis ; un manque exige une note et reste visible. Après réaffectation : reprise des colis auprès du livreur précédent, sans lignes, avec le même nombre de colis.',
});
courierOp('post', 'collections/{id}/incident', {
  response: courierCollection,
  status: 201,
  body: object({ version, kind: incidentKind, note: text(2000) }),
  description:
    'Boutique fermée, marchandise pas prête ou non conforme, véhicule indisponible ou autre.',
});
courierOp('post', 'collections/{id}/drop', {
  response: courierCollection,
  body: object({ version, packages }),
  description:
    'Dépose au dépôt de destination tous les colis collectés ; la réception reste à faire par l’équipe stock.',
});

api.info.version = '0.33.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
