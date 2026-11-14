// Run after the after-sales script: replacement of an issued unit in preparation and after-sales courier trips.
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
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});

// Preparation: replacement origin and units sent back per pick.
const prepLine = schemas.OrderPreparation.properties.lines.items;
const origin = { type: 'string', enum: ['warehouse', 'receipt', 'replacement'] };
prepLine.properties.sources.items.properties.origin = origin;
prepLine.properties.picks.items.properties.origin = origin;
const pick = prepLine.properties.picks.items;
pick.properties.returned_quantity = count;
pick.required = [...new Set([...pick.required, 'returned_quantity'])];

// After-sales trips.
const direction = { type: 'string', enum: ['pickup', 'delivery'] };
const tripStatus = {
  type: 'string',
  enum: ['unassigned', 'accepted', 'in_transit', 'completed', 'cancelled'],
};
const items = object({ name: string, sku: string, quantity: version, identifiers: array(string) });
const warehouse = object({ code: string, name: string, address: string });
schemas.AfterSalesTrip = object({
  id: uuid,
  number: string,
  case: object({ id: uuid, number: string }),
  direction,
  status: tripStatus,
  version,
  courier: nullable(object({ user_id: userId, public_name: string, badge: string }, ['user_id'])),
  custody: { type: 'string', enum: ['start', 'courier', 'done'] },
  warehouse,
  customer: { type: 'object' },
  items,
  packages: nullable(packages),
  code: nullable(object({ expires_at: dateTime, attempts_left: count })),
  picked_at: nullable(dateTime),
  completed_at: nullable(dateTime),
  cancel_reason: nullable(string),
});
schemas.AfterSalesTrip.description =
  'Déplacement SAV : reprise chez le client vers un dépôt, ou article d’échange (déjà sorti du stock pour le dossier) du dépôt au client. Jamais la valeur du code de remise.';
schemas.CourierTrip = object(
  {
    id: uuid,
    number: string,
    direction,
    status: tripStatus,
    version,
    holding: { type: 'boolean' },
    warehouse,
    customer: { type: 'object' },
    items,
    packages: nullable(packages),
  },
  ['id', 'number', 'direction', 'status', 'version'],
);
const sav = schemas.AfterSalesCase;
const tripForCustomer = object({
  number: string,
  direction,
  status: tripStatus,
  courier: nullable(object({ public_name: string, badge: string })),
  handover_code: nullable({ type: 'string', pattern: '^[0-9]{6}$' }),
  completed_at: nullable(dateTime),
});
sav.properties.trips = array(tripForCustomer);
sav.required = [...new Set([...sav.required, 'trips'])];
schemas.AfterSalesStaffCase.properties.trips = array(tripForCustomer);
schemas.AfterSalesStaffCase.required = [
  ...new Set([...schemas.AfterSalesStaffCase.required, 'trips']),
];

// Rounds: trips as tasks.
const ids = { type: 'array', maxItems: 60, items: uuid };
for (const [url, method] of [
  ['/api/v1/operations/rounds', 'post'],
  ['/api/v1/operations/rounds/{id}/missions', 'put'],
]) {
  const body = api.paths[url][method].requestBody.content['application/json'].schema;
  body.properties.trip_ids = ids;
  body.description = 'Au moins une mission, une collecte ou un déplacement SAV.';
}
const task = schemas.DeliveryRoundStep.properties.missions.items;
task.properties.type = { type: 'string', enum: ['mission', 'collection', 'shop_receipt', 'trip'] };
task.properties.trip_id = uuid;
task.properties.direction = direction;
task.properties.action.anyOf[0].enum = [
  ...new Set([...task.properties.action.anyOf[0].enum, 'take', 'take_first']),
];
const progress = schemas.DeliveryRound.properties.progress;
Object.assign(progress.properties, { trips_total: count, trips_done: count });
progress.required = [...new Set([...progress.required, 'trips_total', 'trips_done'])];

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue ou non affectée à ce livreur.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function operation(
  path,
  method,
  { permission, response, body, status = 200, description, tag, query, courier },
) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths['/api/v1/' + path] = {
    ...api.paths['/api/v1/' + path],
    [method]: {
      operationId: (method + '_' + path).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [tag],
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

operation('operations/orders/{id}/preparation/picks/{pickId}/replace', 'post', {
  permission: 'stock.write',
  tag: 'Préparation des commandes',
  status: 201,
  response: data(ref('OrderPreparation')),
  body: object({
    version,
    quantity: { type: 'integer', minimum: 1 },
    identifiers: array({ type: 'string', maxLength: 100 }),
    condition: { type: 'string', enum: ['damaged', 'quarantine'] },
    location_id: uuid,
    note: { type: 'string', minLength: 1, maxLength: 2000 },
  }),
  description:
    'Unité sortie trouvée abîmée ou non conforme avant la remise : elle revient en stock mise de côté (endommagée ou quarantaine) dans son lot, et un remplaçant vendu aux mêmes conditions est retenu pour la ligne (origine « replacement »), à sortir comme les autres. La commande n’est plus prête. Refusé pendant une mission en cours, en dépôt-vente ou sans stock conforme disponible.',
});
operation('operations/after-sales-trips', 'get', {
  permission: 'delivery.read',
  tag: 'SAV',
  response: page(ref('AfterSalesTrip')),
  query: [
    { name: 'status', in: 'query', required: false, schema: tripStatus },
    { name: 'courier_id', in: 'query', required: false, schema: userId },
    { name: 'page', in: 'query', required: false, schema: version },
  ],
  description: 'Déplacements SAV, plus anciens d’abord.',
});
operation('operations/after-sales/{id}/pickup-trips', 'post', {
  permission: 'delivery.assign',
  tag: 'SAV',
  status: 201,
  response: data(ref('AfterSalesTrip')),
  body: object({ warehouse_id: uuid }),
  description:
    'Reprise chez le client des articles encore à rendre pour un retour, un échange ou une réparation acceptés, vers le dépôt indiqué. Une seule reprise en cours par dossier.',
});
operation('operations/after-sales/{id}/exchange-shipments', 'post', {
  permission: 'stock.write',
  tag: 'SAV',
  status: 201,
  response: data(ref('AfterSalesTrip')),
  body: object({ warehouse_id: uuid, packages }),
  description:
    'Échange accepté : l’équipe stock sort aussitôt du stock des unités vendues aux mêmes conditions que celles livrées (référence : numéro du dossier) et prépare le déplacement vers le client, avec un code de remise à usage unique pour lui.',
});
operation('operations/after-sales-trips/{id}/assign', 'post', {
  permission: 'delivery.assign',
  tag: 'SAV',
  response: data(ref('AfterSalesTrip')),
  body: object({ version, courier_id: userId }),
  description: 'Affecte un livreur actif au déplacement.',
});
operation('operations/after-sales-trips/{id}/cancel', 'post', {
  permission: 'delivery.assign',
  tag: 'SAV',
  response: data(ref('AfterSalesTrip')),
  body: object({ version, reason: { type: 'string', minLength: 1, maxLength: 1000 } }),
  description:
    'Annule une reprise tant que rien n’a été pris ; un envoi d’échange déjà sorti du stock ne s’annule pas ici.',
});
operation('courier/trips', 'get', {
  permission: 'missions.own',
  tag: 'Espace livreur',
  courier: true,
  response: page(ref('CourierTrip')),
  query: [{ name: 'page', in: 'query', required: false, schema: version }],
  description: 'Déplacements SAV en cours du livreur connecté.',
});
operation('courier/trips/{id}', 'get', {
  permission: 'missions.own',
  tag: 'Espace livreur',
  courier: true,
  response: data(ref('CourierTrip')),
  description: 'Déplacement SAV affecté au livreur connecté ; introuvable sinon.',
});
operation('courier/trips/{id}/take', 'post', {
  permission: 'missions.own',
  tag: 'Espace livreur',
  courier: true,
  response: data(ref('CourierTrip')),
  body: object(
    {
      version,
      packages,
      quantity: nullable({ type: 'integer', minimum: 1 }),
      identifiers: nullable(array({ type: 'string', maxLength: 100 })),
    },
    ['version', 'packages'],
  ),
  description:
    'Reprise : contrôle à la porte de la quantité et des séries attendues. Échange : enlèvement des colis au dépôt, au nombre préparé.',
});
operation('courier/trips/{id}/drop', 'post', {
  permission: 'missions.own',
  tag: 'Espace livreur',
  courier: true,
  response: data(ref('CourierTrip')),
  body: object({ version, packages }),
  description: 'Reprise : dépôt des colis au dépôt ; l’équipe stock réceptionne ensuite le retour.',
});
operation('courier/trips/{id}/handover', 'post', {
  permission: 'missions.own',
  tag: 'Espace livreur',
  courier: true,
  response: data(ref('CourierTrip')),
  body: object({ version, code: { type: 'string', pattern: '^[0-9]{6}$' } }),
  description:
    'Échange : remise au client avec son code à usage unique (visible dans son dossier SAV). Un code erroné est compté ; après la limite, le code est bloqué.',
});

api.info.version = '0.37.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
