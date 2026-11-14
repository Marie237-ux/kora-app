// Delivery campaigns, their public quote conditions and the reserved/consumed budget.
// Run after the delivery, orders and customer-document contract scripts.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const str = { type: 'string' };
const uuid = { type: 'string', format: 'uuid' };
const dateTime = { type: 'string', format: 'date-time' };
const version = { type: 'integer', minimum: 1 };
const xaf = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const coverage = { type: 'string', enum: ['full', 'base'] };
const threshold = {
  type: 'string',
  const: 'paid_unit_price',
  description: 'Prix unitaire effectivement payé après remises, parmi les lignes sélectionnées.',
};
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const translatedName = object({
  fr: { type: 'string', minLength: 1, maxLength: 150 },
  en: { type: 'string', minLength: 1, maxLength: 150 },
});
const fields = {
  name: translatedName,
  starts_at: dateTime,
  ends_at: nullable(dateTime),
  zone_ids: { ...array(uuid), minItems: 1, maxItems: 200, uniqueItems: true },
  min_unit_price: xaf,
  uses_per_customer: { type: 'integer', minimum: 1, maximum: 100 },
  coverage,
  budget: xaf,
  funded_by: { type: 'string', const: 'kora' },
};

schemas.DeliveryOffer = object({
  id: uuid,
  ...fields,
  status: { type: 'string', enum: ['draft', 'active', 'paused', 'ended'] },
  threshold_basis: threshold,
  consumed: xaf,
  reserved: xaf,
  available: xaf,
  version,
});
schemas.DeliveryPromotion = object({
  offer_id: uuid,
  name: str,
  coverage,
  min_unit_price: xaf,
  threshold_basis: threshold,
  discount: xaf,
  remaining_delivery: xaf,
  uses_left_after: { type: 'integer', minimum: 0 },
});
const quote = schemas.DeliveryQuote;
quote.properties.promotion = nullable(ref('DeliveryPromotion'));
quote.properties.promotion_applied = { type: 'boolean' };
quote.properties.payable_delivery = nullable(xaf);
quote.properties.total.description =
  'Livraison brute avant promotion ; null si le devis reste à confirmer.';
quote.properties.payable_delivery.description =
  'Livraison nette à payer après promotion, suppléments compris.';
quote.required = [...new Set([...quote.required, 'promotion', 'payable_delivery'])];

const delivery = schemas.Order.properties.delivery;
delivery.properties.promotion = nullable(
  object({
    name: str,
    coverage,
    discount: xaf,
    min_unit_price: xaf,
    threshold_basis: threshold,
  }),
);
delivery.required = [...new Set([...delivery.required, 'promotion'])];
schemas.Order.properties.delivery_total.description =
  'Livraison nette acceptée, après l’avantage réservé.';
const kinds = schemas.PaymentIncident.properties.kind.enum;
if (!kinds.includes('promotion_budget_exceeded')) kinds.push('promotion_budget_exceeded');

function operation(
  method,
  suffix,
  { body, status = 200, paged = false, sensitive = false, description },
) {
  const url = '/api/v1/operations/promotions/delivery-offers' + suffix;
  const permission = method === 'get' ? 'promotions.read' : 'promotions.manage';
  const parameters = [...suffix.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  parameters.push({ name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } });
  if (paged) {
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  }
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const schema = paged
    ? object({ data: array(ref('DeliveryOffer')), meta: ref('PageMeta') })
    : object({ data: ref('DeliveryOffer') });
  const op = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Promotions de livraison'],
    security: [{ sessionCookie: [] }],
    parameters,
    'x-permission': permission,
    ...(sensitive ? { 'x-recent-password-seconds': 900 } : {}),
    description: [
      description,
      'MFA obligatoire. Permission serveur : ' + permission + '.',
      method !== 'get' ? 'Session HttpOnly, CSRF et Idempotency-Key UUID requis.' : '',
      sensitive ? 'Mot de passe reconfirmé depuis 15 minutes au plus.' : '',
    ]
      .filter(Boolean)
      .join(' '),
    responses: {
      [status]: { description: 'Succès', content: { 'application/json': { schema } } },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: { description: 'Offre inconnue.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite atteinte.' },
    },
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
  };
  api.paths[url] ??= {};
  api.paths[url][method] = op;
}

operation('get', '', {
  paged: true,
  description:
    'Campagnes et coût engagé : avantages consommés, réservations de paiement actives et budget restant.',
});
const required = Object.keys(fields).filter((key) => key !== 'ends_at');
operation('post', '', {
  body: object(fields, required),
  status: 201,
  description: 'Crée un brouillon. Aucun tarif ni campagne n’est activé automatiquement.',
});
operation('put', '/{id}', {
  body: object({ version, ...fields }, ['version', ...required]),
  description:
    'Brouillon ou offre en pause seulement. Refuse un budget inférieur aux avantages déjà engagés ; conserve les commandes existantes.',
});
for (const [action, description] of Object.entries({
  activate:
    'Active un brouillon ou une offre en pause ; seules les dates et zones admissibles ouvrent des droits.',
  pause: 'Suspend les nouveaux avantages sans retirer ceux réservés par les commandes en attente.',
  end: 'Termine définitivement la campagne sans modifier les commandes ni factures passées.',
})) {
  operation('post', '/{id}/' + action, {
    body: object({ version }),
    sensitive: true,
    description,
  });
}

const promotionNote =
  'La promotion affichée est revérifiée sous verrou (identité, version, couverture, remise), avec le prix unitaire payé, le quota et le budget. Tout changement exige un nouveau devis ; budget et utilisation sont réservés jusqu’au paiement ou à l’expiration.';
if (!api.paths['/api/v1/orders'].post.description.includes(promotionNote)) {
  api.paths['/api/v1/orders'].post.description += ' ' + promotionNote;
}
api.info.version = '0.13.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
