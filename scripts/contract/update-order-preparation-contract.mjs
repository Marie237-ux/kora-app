// Run after financings: picking of paid orders from their retained stock, readiness and preparation anomalies.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const version = { type: 'integer', minimum: 1 };
const quantity = { type: 'integer', minimum: 0 };
const positive = { type: 'integer', minimum: 1, maximum: 1000000 };
const dateTime = { type: 'string', format: 'date-time' };
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
const status = { type: 'string', enum: ['not_started', 'partial', 'anomaly', 'ready'] };
const origin = { type: 'string', enum: ['warehouse', 'receipt'] };
const kind = { type: 'string', enum: ['missing', 'damaged', 'expired', 'mismatch', 'other'] };
schemas.PreparationLocation = object({
  balance_id: uuid,
  warehouse_code: { type: 'string' },
  location_code: { type: 'string' },
  location_name: { type: 'string' },
  lot_id: uuid,
  lot_number: nullable({ type: 'string' }),
  expires_on: nullable({ type: 'string', format: 'date' }),
  condition: { type: 'string' },
  quantity: positive,
});
schemas.PreparationAnomaly = object({
  id: uuid,
  order_id: uuid,
  order_line_id: uuid,
  kind,
  quantity: positive,
  note: { type: 'string' },
  status: { type: 'string', enum: ['open', 'resolved'] },
  resolution_note: nullable({ type: 'string' }),
  reported_by: { type: 'integer' },
  resolved_by: nullable({ type: 'integer' }),
  resolved_at: nullable(dateTime),
  version,
  created_at: dateTime,
});
schemas.OrderPreparation = object({
  order_id: uuid,
  number: { type: 'string' },
  order_status: { type: 'string' },
  status,
  version,
  paid_at: nullable(dateTime),
  prepared_at: nullable(dateTime),
  packages: nullable({ type: 'integer', minimum: 1, maximum: 100 }),
  slot: nullable(
    object({
      date: { type: 'string', format: 'date' },
      starts_at: { type: 'string' },
      ends_at: { type: 'string' },
    }),
  ),
  lines: array(
    object({
      id: uuid,
      name: { type: 'string' },
      sku: { type: 'string' },
      quantity: positive,
      cancelled_quantity: quantity,
      required_quantity: quantity,
      prepared_quantity: quantity,
      held_quantity: quantity,
      awaiting_stock_quantity: quantity,
      sources: array(
        object({
          reservation_id: uuid,
          origin,
          quantity: positive,
          identifiers: array({ type: 'string' }),
          locations: array(ref('PreparationLocation')),
        }),
      ),
      picks: array(
        object({
          id: uuid,
          reservation_id: uuid,
          origin,
          quantity: positive,
          identifiers: array({ type: 'string' }),
          locations: array(ref('PreparationLocation')),
          note: nullable({ type: 'string' }),
          picked_by: { type: 'integer' },
          picked_at: dateTime,
        }),
      ),
    }),
  ),
  anomalies: array(ref('PreparationAnomaly')),
});
schemas.OrderPreparationQueueItem = object({
  order_id: uuid,
  number: { type: 'string' },
  status,
  version,
  paid_at: nullable(dateTime),
  slot_date: nullable({ type: 'string', format: 'date' }),
  required_quantity: quantity,
  prepared_quantity: quantity,
  open_anomalies: quantity,
});
const order = schemas.Order;
order.properties.preparation_status = {
  type: 'string',
  enum: ['not_started', 'in_progress', 'ready'],
  description:
    'Synthèse client : partielle et anomalie interne apparaissent toutes deux comme in_progress, sans détail privé.',
};
order.required = [...new Set([...order.required, 'preparation_status'])];
const line = order.properties.lines.items;
line.properties.prepared_quantity = quantity;
line.required = [...new Set([...line.required, 'prepared_quantity'])];

function operation(method, url, { response, body, status = 200, description, query }) {
  const permission = method === 'get' ? 'stock.read' : 'stock.write';
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const op = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Préparation des commandes'],
    security: [{ sessionCookie: [] }],
    parameters,
    'x-permission': permission,
    description:
      description +
      ' Permission serveur : ' +
      permission +
      '. Session avec MFA obligatoire.' +
      (method === 'get' ? '' : ' CSRF et Idempotency-Key UUID requis.'),
    responses: {
      [status]: {
        description: 'Succès',
        content: { 'application/json': { schema: response } },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: { description: 'Commande, ligne, réservation ou anomalie inconnue.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite atteinte.' },
    },
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
  };
  api.paths['/api/v1/operations/' + url] = {
    ...api.paths['/api/v1/operations/' + url],
    [method]: op,
  };
}
const data = (schema) => object({ data: schema });
operation('get', 'preparations', {
  response: object({
    data: array(ref('OrderPreparationQueueItem')),
    meta: object({ page: version, last_page: version, total: quantity }),
  }),
  query: [
    { name: 'status', in: 'query', required: false, schema: status },
    { name: 'page', in: 'query', required: false, schema: version },
  ],
  description:
    'File des commandes payées encore ouvertes, paiement le plus ancien en premier, avec quantités à préparer, préparées et anomalies ouvertes. Lecture cohérente (snapshot).',
});
operation('get', 'orders/{id}/preparation', {
  response: data(ref('OrderPreparation')),
  description:
    'Bon de préparation interne : réservations retenues par ligne (stock KORA ou réception affectée), dépôts/emplacements, lots, péremption et séries/IMEI à sortir ; unités encore à acheter, recevoir ou collecter ; sorties et anomalies. Sans prix, coûts ni adresse du client.',
});
operation('post', 'orders/{id}/preparation/picks', {
  response: data(ref('OrderPreparation')),
  status: 201,
  body: object(
    {
      version,
      reservation_id: uuid,
      identifiers: array({ type: 'string', maxLength: 100 }),
      note: nullable(text(1000)),
    },
    ['version', 'reservation_id', 'identifiers'],
  ),
  description:
    'Version de préparation de la commande. Commande payée et ouverte. Sort réellement du stock une réservation retenue entière (mouvement sale_issue référencé par le numéro de commande, unités consommées, vente en dépôt-vente le cas échéant). Les séries/IMEI saisis doivent correspondre exactement aux unités réservées ; liste vide pour un article non suivi. Une réservation ne peut être sortie qu’une fois, et pas après sa libération.',
});
operation('post', 'orders/{id}/preparation/ready', {
  response: data(ref('OrderPreparation')),
  body: object(
    {
      version,
      packages: { type: 'integer', minimum: 1, maximum: 100 },
      note: nullable(text(1000)),
    },
    ['version', 'packages'],
  ),
  description:
    'Déclare la commande prête avec le nombre de colis. Exige que toutes les unités non annulées soient sorties et qu’aucune anomalie ne reste ouverte. Ne crée ni mission ni remise au client.',
});
operation('post', 'orders/{id}/preparation/anomalies', {
  response: data(ref('PreparationAnomaly')),
  status: 201,
  body: object({ version, order_line_id: uuid, kind, quantity: positive, note: text(2000) }),
  description:
    'Signale une anomalie (manquant, endommagé, périmé, non conforme, autre) sur une ligne. Bloque la déclaration « prête » et retire une disponibilité déjà déclarée. Le client voit seulement une préparation en cours.',
});
operation('post', 'preparation-anomalies/{id}/resolve', {
  response: data(ref('PreparationAnomaly')),
  body: object({ version, note: text(2000) }),
  description:
    'Version de l’anomalie. Clôt l’anomalie avec une note de résolution conservée ; ne réécrit ni le signalement ni les sorties de stock.',
});
api.info.version = '0.30.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
