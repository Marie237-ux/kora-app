// Run after the supplier collections script: units bought at a shop and received there on the way to the customer.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const version = { type: 'integer', minimum: 1 };
const count = { type: 'integer', minimum: 0 };
const userId = { type: 'integer', minimum: 1 };
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
const addRequired = (schema, props) => {
  Object.assign(schema.properties, props);
  schema.required = [...new Set([...schema.required, ...Object.keys(props)])];
};

// Missions: depot parcels apart from the total, which grows with shop receipts.
const mission = schemas.DeliveryMission;
mission.properties.packages = { type: 'integer', minimum: 0, maximum: 100 };
addRequired(mission, {
  depot_packages: { type: 'integer', minimum: 0, maximum: 100 },
  depot_picked_at: nullable(dateTime),
  direct_collections: array(object({ id: uuid, number: string, status: string })),
});
const shopReceipt = object({
  id: uuid,
  number: string,
  version,
  pickup: { type: 'object' },
  lines: array({ type: 'object' }),
  receiver: { type: 'string', enum: ['courier', 'supervisor'] },
  supervisor_name: nullable(string),
});
const courierMission = schemas.CourierMission;
courierMission.properties.packages = { type: 'integer', minimum: 0, maximum: 100 };
Object.assign(courierMission.properties, {
  depot: object({ packages: count, picked: { type: 'boolean' } }),
  shop_receipts: array(shopReceipt),
});

// Preparation: units covered by a direct shop collection; zero depot parcels allowed only then.
const line = schemas.OrderPreparation.properties.lines.items;
addRequired(line, { direct_collection_quantity: count });
const ready = api.paths['/api/v1/operations/orders/{id}/preparation/ready'].post;
ready.requestBody.content['application/json'].schema.properties.packages = {
  type: 'integer',
  minimum: 0,
  maximum: 100,
};
const readyNote =
  ' Les unités d’une collecte directe en boutique non encore reçue comptent comme couvertes ; zéro colis au dépôt seulement si rien n’y a été sorti et qu’une collecte directe est prévue.';
ready.description = (ready.description ?? '').split(readyNote).join('') + readyNote;

// Collections: depot or customer destination, manager receiving, slip photo.
const collection = schemas.SupplierCollection;
collection.properties.destination = {
  anyOf: [
    collection.properties.destination,
    object({ type: { type: 'string', enum: ['customer'] }, order_id: uuid, order_number: string }),
  ],
};
addRequired(collection, {
  supervisor_id: nullable(userId),
  received_by: nullable(userId),
  document_id: nullable(uuid),
});

// Rounds: shop receipts on the way of a mission.
const step = schemas.DeliveryRoundStep;
step.properties.receiver = { type: 'string', enum: ['courier', 'supervisor'] };
const task = step.properties.missions.items;
task.properties.type = { type: 'string', enum: ['mission', 'collection', 'shop_receipt'] };
task.properties.action.anyOf[0].enum = [
  ...new Set([
    ...task.properties.action.anyOf[0].enum,
    'shop_receipt',
    'wait_supervisor',
    'take_over_first',
  ]),
];

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Achat, collecte ou mission inconnu, ou non affecté à ce livreur.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function operation(
  path,
  method,
  { permission, response, body, multipart, status = 200, description, courier },
) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (!multipart) parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths['/api/v1/' + path] = {
    ...api.paths['/api/v1/' + path],
    [method]: {
      operationId: (method + '_' + path).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [courier ? 'Espace livreur' : 'Collectes fournisseur'],
      security: [{ sessionCookie: [] }],
      parameters,
      'x-permission': permission,
      description:
        description +
        ' Permission serveur : ' +
        permission +
        (courier ? '. Affectation actuelle revérifiée.' : '. Session avec MFA obligatoire.') +
        ' CSRF' +
        (multipart ? ' requis.' : ' et Idempotency-Key UUID requis.'),
      responses: {
        [status]: { description: 'Succès', content: { 'application/json': { schema: response } } },
        ...errors,
      },
      requestBody: {
        required: true,
        content: multipart
          ? { 'multipart/form-data': { schema: body } }
          : { 'application/json': { schema: body } },
      },
    },
  };
}
const data = (schema) => object({ data: schema });
const receiptLines = array(
  object(
    {
      purchase_line_id: uuid,
      quantity: count,
      identifiers: array({ type: 'string', maxLength: 100 }),
      lot_number: nullable({ type: 'string', maxLength: 100 }),
      expires_on: nullable({ type: 'string', format: 'date' }),
    },
    ['purchase_line_id', 'quantity', 'identifiers'],
  ),
);
const receiptBody = {
  version,
  document_id: uuid,
  packages: { type: 'integer', minimum: 1, maximum: 100 },
  lines: receiptLines,
  note: nullable({ type: 'string', maxLength: 2000 }),
};
const receiptRequired = ['version', 'document_id', 'packages', 'lines'];

operation('operations/purchases/{id}/direct-collections', 'post', {
  permission: 'delivery.assign',
  status: 201,
  response: data(ref('SupplierCollection')),
  body: object(
    { order_id: uuid, shop_id: uuid, transit_location_id: uuid, supervisor_id: nullable(userId) },
    ['order_id', 'shop_id', 'transit_location_id'],
  ),
  description:
    'Collecte directe pour une commande payée : unités de cet achat confirmé, affectées à ses lignes et pas encore reçues, chez le point actif de la boutique. Elles seront reçues sur place dans l’emplacement « en route » indiqué, puis sorties pour la commande et remises au client par sa mission. Responsable facultatif (droit stock.receive) : c’est alors lui qui réceptionne.',
});
operation('operations/collections/{id}/direct-receipt', 'post', {
  permission: 'stock.receive',
  response: data(ref('SupplierCollection')),
  body: object(receiptBody, receiptRequired),
  description:
    'Réception en boutique par le responsable désigné : même contrôle que celle du livreur (photo du bon, quantités, séries) ; les colis rejoignent le chargement de la mission.',
});
operation('courier/missions/{id}/shop-photos', 'post', {
  permission: 'missions.own',
  courier: true,
  multipart: true,
  status: 201,
  response: data({ type: 'object' }),
  body: object({ collection_id: uuid, file: { type: 'string', format: 'binary' } }),
  description:
    'Photo (ou PDF) du bon de la boutique pour une collecte directe de la commande de cette mission ; pièce privée analysée par l’antivirus avant usage.',
});
operation('courier/missions/{id}/shop-receipts', 'post', {
  permission: 'missions.own',
  courier: true,
  response: data(ref('CourierMission')),
  body: object({ collection_id: uuid, ...receiptBody }, ['collection_id', ...receiptRequired]),
  description:
    'Réception en boutique par le livreur (sans responsable désigné), en une opération : photo du bon vérifiée, unités reçues avec leurs séries dans l’emplacement « en route », retenues pour la ligne du client, sorties pour la commande, colis ajoutés au chargement. Un manque exige une note ; la remise reste impossible tant qu’une unité manque.',
});
const pickup = api.paths['/api/v1/courier/missions/{id}/pickup'].post;
pickup.description =
  'Enlèvement des colis préparés au dépôt (une fois, nombre préparé) ou reprise auprès du livreur précédent après réaffectation. Les unités achetées en boutique rejoignent le chargement par la réception en boutique. Permission serveur : missions.own. Affectation actuelle revérifiée avant tout rejeu idempotent. CSRF et Idempotency-Key UUID requis.';
const handover = api.paths['/api/v1/courier/missions/{id}/handover'].post;
const handoverNote =
  ' Refusée tant que des colis du dépôt, une réception en boutique ou une unité de la commande manquent.';
handover.description = (handover.description ?? '').split(handoverNote).join('') + handoverNote;

api.info.version = '0.34.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
