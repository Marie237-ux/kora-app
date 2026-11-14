// Run after orders and delivery promotions: link external confirmations and customer resolutions to sale lines.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const dateTime = { type: 'string', format: 'date-time' };
const version = { type: 'integer', minimum: 1 };
const quantity = { type: 'integer', minimum: 0 };
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
const stringEnum = (...values) => ({ type: 'string', enum: values });
const choice = stringEnum('wait', 'refund_missing');
schemas.OrderSupplyCase = object({
  id: uuid,
  order_line_id: uuid,
  version,
  status: stringEnum('awaiting_choice', 'waiting', 'refund_requested', 'refunded', 'resolved'),
  missing_quantity: { type: 'integer', minimum: 1 },
  message: { type: 'string' },
  choices: array(choice),
  choice: nullable(choice),
  refund_amount: xaf,
  delivery_refund: xaf,
  refund_id: nullable(uuid),
  chosen_at: nullable(dateTime),
});
schemas.OrderSupplyRequest = object({
  id: uuid,
  order_line_id: uuid,
  status: { type: 'string', const: 'requested' },
  supplier_confirmed: { type: 'boolean', const: false },
});
const order = schemas.Order;
order.properties.closed_at = nullable(dateTime);
order.properties.closed_at.description =
  'Clôture de l’exécution après annulation et remboursement total ; le paiement reste enregistré comme payé.';
order.properties.supply_cases = array(ref('OrderSupplyCase'));
order.required = [...new Set([...order.required, 'closed_at', 'supply_cases'])];
const line = order.properties.lines.items;
line.properties.supply_status = stringEnum(
  'reserved',
  'to_confirm',
  'incident',
  'confirmation_requested',
  'supplier_confirmed',
  'confirmation_expired',
  'unavailable',
  'waiting_supplier',
  'refund_pending',
  'partially_cancelled',
  'cancelled',
);
line.properties.cancelled_quantity = quantity;
line.properties.supplier_confirmation_valid = { type: 'boolean' };
line.required = [
  ...new Set([...line.required, 'cancelled_quantity', 'supplier_confirmation_valid']),
];
const reservation = schemas.SupplierReservation;
reservation.properties.order_line_id = nullable(uuid);
reservation.properties.confirmed_quantity = nullable(quantity);
reservation.properties.status = stringEnum(
  'requested',
  'confirmed',
  'partly_confirmed',
  'rejected',
  'release_requested',
  'released',
  'expired',
  'superseded',
);
reservation.required = [
  ...new Set([...reservation.required, 'order_line_id', 'confirmed_quantity']),
];
api.paths['/api/v1/operations/supplier-reservations/{id}/confirm'].post.requestBody.content[
  'application/json'
].schema.properties.confirmed_quantity = {
  type: 'integer',
  minimum: 1,
  maximum: 1000000,
  description:
    'Confirmation partielle possible pour une réservation liée à une commande ; sinon la quantité complète est obligatoire.',
};

function operation(url, { body, response, permission, status = 200, description }) {
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  parameters.push({ name: 'locale', in: 'query', schema: stringEnum('fr', 'en') });
  api.paths['/api/v1/' + url] = {
    post: {
      operationId: ('post_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: ['Approvisionnement des commandes'],
      security: [{ sessionCookie: [] }],
      parameters,
      ...(permission ? { 'x-permission': permission } : { 'x-resource-owner': 'account' }),
      description:
        description +
        ' Session HttpOnly, CSRF et Idempotency-Key UUID requis.' +
        (permission
          ? ' MFA obligatoire ; permission serveur : ' + permission + '.'
          : ' Commande appartenant au compte connecté.'),
      requestBody: { required: true, content: { 'application/json': { schema: body } } },
      responses: {
        [status]: {
          description: 'Succès',
          content: { 'application/json': { schema: object({ data: response }) } },
        },
        401: { $ref: '#/components/responses/Unauthorized' },
        403: { $ref: '#/components/responses/Forbidden' },
        404: {
          description: 'Commande, ligne ou incident inconnu ou appartenant à un autre compte.',
        },
        409: { $ref: '#/components/responses/OperationalConflict' },
        422: { $ref: '#/components/responses/ValidationError' },
        429: { description: 'Limite atteinte.' },
      },
    },
  };
}
operation('operations/orders/{id}/lines/{lineId}/supplier-reservations', {
  body: object({ version, expires_at: dateTime }),
  response: ref('OrderSupplyRequest'),
  status: 201,
  permission: 'purchases.write',
  description:
    'Commande payée seulement ; version supply_version de la ligne. Demande interne reliée aux unités fournisseur restantes, jamais une confirmation externe ni un achat. Aucune communication fournisseur envoyée.',
});
operation('operations/orders/{id}/lines/{lineId}/unavailable', {
  permission: 'purchases.write',
  response: ref('OrderSupplyCase'),
  body: object({
    version,
    missing_quantity: { type: 'integer', minimum: 1, maximum: 1000000 },
    message: object({
      fr: { type: 'string', maxLength: 1000 },
      en: { type: 'string', maxLength: 1000 },
    }),
    reference: { type: 'string', maxLength: 150 },
  }),
  description:
    'Signale une indisponibilité fournisseur sur une ligne payée, avec message client FR/EN et référence interne privée. Les prix de vente restent figés.',
});
operation('orders/{id}/supply-cases/{caseId}/choice', {
  response: ref('OrderSupplyCase'),
  body: {
    oneOf: [
      object(
        {
          version,
          choice: { type: 'string', const: 'wait' },
          accepted_refund_amount: nullable(xaf),
        },
        ['version', 'choice'],
      ),
      object({
        version,
        choice: { type: 'string', const: 'refund_missing' },
        accepted_refund_amount: xaf,
      }),
    ],
  },
  description:
    'Choix versionné du client : attente ou remboursement des unités manquantes et poursuite du reste. Montant exact accepté obligatoire pour un remboursement ; si toutes les unités sont annulées, la livraison nette est incluse. Crée une demande, sans annoncer de versement : seul le transfert prouvé par la finance la termine. La facture originale est conservée ; un avoir peut être émis après versement. Aucun supplément de livraison ajouté.',
});
api.info.version = '0.14.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
