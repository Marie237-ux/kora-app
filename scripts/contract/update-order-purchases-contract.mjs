// Run after order supply: earmark confirmed purchases and retain actual receipt stock for paid orders.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const version = { type: 'integer', minimum: 1 };
const quantity = { type: 'integer', minimum: 0 };
const requestedQuantity = { type: 'integer', minimum: 1, maximum: 1000000 };
const xaf = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
schemas.OrderReceiptAllocation = object({
  id: uuid,
  purchase_allocation_id: uuid,
  receipt_line_id: uuid,
  reservation_id: uuid,
  quantity: requestedQuantity,
  actual_unit_cost: xaf,
  actual_cost: xaf,
  status: { type: 'string', enum: ['held', 'released'] },
  release_reason: nullable({ type: 'string' }),
  version,
});
schemas.OrderPurchaseAllocation = object({
  id: uuid,
  order_line_id: uuid,
  purchase_line_id: uuid,
  quantity: requestedQuantity,
  released_quantity: quantity,
  active_quantity: quantity,
  received_quantity: quantity,
  expected_unit_cost: xaf,
  active_expected_cost: xaf,
  version,
  receipts: array(ref('OrderReceiptAllocation')),
});
schemas.OrderProcurement = object({
  order_id: uuid,
  lines: array(
    object({
      id: uuid,
      supplier_quantity: quantity,
      cancelled_quantity: quantity,
      assigned_quantity: quantity,
      received_quantity: quantity,
      supply_version: version,
      allocations: array(ref('OrderPurchaseAllocation')),
    }),
  ),
});
const reservation = schemas.InventoryReservation;
reservation.properties.order_line_id = nullable(uuid);
reservation.properties.expires_at = nullable({ type: 'string', format: 'date-time' });
reservation.properties.expires_at.description =
  'Null pour un stock réservé à une commande payée : libération/consommation explicite seulement.';
reservation.required = [...new Set([...reservation.required, 'order_line_id'])];
const line = schemas.Order.properties.lines.items;
line.properties.procurement_received_quantity = quantity;
line.required = [...new Set([...line.required, 'procurement_received_quantity'])];
if (!line.properties.supply_status.enum.includes('supplier_stock_received')) {
  line.properties.supply_status.enum.push('supplier_stock_received');
}

function operation(method, url, { response, body, status = 200, description }) {
  const permission = method === 'get' ? 'purchases.read' : 'purchases.write';
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const op = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Achats affectés aux commandes'],
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
        content: { 'application/json': { schema: object({ data: response }) } },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: { description: 'Commande, ligne ou affectation inconnue.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite atteinte.' },
    },
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
  };
  api.paths['/api/v1/operations/' + url] = { [method]: op };
}
operation('get', 'orders/{id}/procurement', {
  response: ref('OrderProcurement'),
  description:
    'Détail interne des quantités affectées aux achats et reçues, coûts prévus/réels figés et historique des libérations. Aucun de ces coûts ne figure dans la vue client.',
});
operation('post', 'orders/{id}/lines/{lineId}/purchase-allocations', {
  response: ref('OrderPurchaseAllocation'),
  status: 201,
  body: object({ version, purchase_line_id: uuid, quantity: requestedQuantity }),
  description:
    'Version supply_version de la ligne. Commande payée ouverte, achat confirmé pour customer_needs et confirmation fournisseur valable. Vérifie les conditions figées, le besoin client et la capacité de la ligne d’achat sous verrou. Ne crée pas de dette ni de réception.',
});
operation('post', 'purchase-allocations/{id}/receipts', {
  response: ref('OrderReceiptAllocation'),
  status: 201,
  body: object({ version, receipt_line_id: uuid, quantity: requestedQuantity }),
  description:
    'Version de l’affectation achat. Réserve physiquement les unités du lot de cette réception, avec leurs séries/IMEI si requis. Quantités, conformité, qualité et excédents approuvés contrôlés ; coût réel pris sur la réception. Réservation de commande payée sans expiration autonome. Une réception reste distincte d’une préparation ou livraison.',
});
const release = object({ version, reason: { type: 'string', minLength: 1, maxLength: 1000 } });
operation('post', 'purchase-allocations/{id}/release', {
  response: ref('OrderPurchaseAllocation'),
  body: release,
  description:
    'Libère uniquement les unités de l’affectation non reçues. Les unités reçues doivent être libérées par leur propre traitement. L’achat, les pièces, la dette fournisseur et les coûts historiques ne sont pas effacés.',
});
operation('post', 'receipt-allocations/{id}/release', {
  response: ref('OrderReceiptAllocation'),
  body: release,
  description:
    'Version de l’affectation réception. Restitue le stock et ses unités non consommées, avec motif et audit, sans supprimer la réception ni la dette. Les routes génériques de libération/sortie de stock ne peuvent pas contourner cette protection.',
});
api.info.version = '0.15.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
