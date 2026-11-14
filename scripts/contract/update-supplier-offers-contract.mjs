// Run after the accounting statements script: optional supplier subscriptions and paid visibility.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const count = { type: 'integer', minimum: 0 };
const version = { type: 'integer', minimum: 1 };
const dateTime = { type: 'string', format: 'date-time' };
const date = { type: 'string', format: 'date' };
const string = { type: 'string' };
const amount = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const translated = object({ fr: string, en: string });
const placement = { type: 'string', enum: ['search_top', 'category_top', 'home_selection'] };
const benefits = {
  type: 'object',
  properties: {
    placement,
    max_offers: { type: 'integer', minimum: 1, maximum: 12 },
    items: array(translated),
  },
  additionalProperties: false,
};

schemas.SupplierOfferSettings = object({
  subscriptions_enabled: { type: 'boolean' },
  visibility_enabled: { type: 'boolean' },
  slots_per_placement: { type: 'integer', minimum: 1, maximum: 6 },
  renewal_notice_days: { type: 'integer', minimum: 1, maximum: 30 },
  version: count,
});
schemas.SupplierOfferSettings.description =
  'Abonnements et visibilité payante fermés par défaut ; aucun abonnement obligatoire.';
schemas.SupplierPlan = object({
  id: uuid,
  code: string,
  kind: { type: 'string', enum: ['subscription', 'visibility'] },
  name: translated,
  description: translated,
  price: amount,
  period_days: { type: 'integer', minimum: 7, maximum: 366 },
  benefits,
  status: { type: 'string', enum: ['draft', 'active', 'retired'] },
  version,
});
schemas.SupplierPlan.description =
  'Formule définie par l’entreprise (prix en XAF saisi par elle, 0 = gratuite) ; une formule proposée ne se modifie pas, elle se retire.';
const billing = {
  id: uuid,
  number: string,
  subscription_id: uuid,
  supplier_id: uuid,
  amount,
  period_days: { type: 'integer', minimum: 1 },
  status: { type: 'string', enum: ['issued', 'paid', 'cancelled'] },
  reference: nullable(string),
  paid_at: nullable(dateTime),
  cancel_reason: nullable(string),
  version,
  created_at: dateTime,
};
schemas.SupplierBillingRequest = object(billing);
schemas.SupplierBillingRequest.description =
  'Demande de paiement identifiée, réglée seulement sur vérification avec référence et preuve ; aucun prélèvement récurrent.';
schemas.SupplierSubscription = object({
  id: uuid,
  number: string,
  supplier_id: uuid,
  plan: object({
    code: string,
    kind: { type: 'string', enum: ['subscription', 'visibility'] },
    name: translated,
    price: count,
    period_days: count,
    benefits,
    plan_version: version,
  }),
  offer_ids: array(uuid),
  status: {
    type: 'string',
    enum: ['proposed', 'awaiting_payment', 'active', 'expired', 'cancelled'],
  },
  consent: nullable(
    object({
      method: { type: 'string', enum: ['portal', 'signed_document'] },
      at: dateTime,
      terms_sha256: string,
    }),
  ),
  renew: { type: 'boolean' },
  starts_on: nullable(date),
  ends_on: nullable(date),
  cancelled_at: nullable(dateTime),
  cancel_reason: nullable(string),
  version,
  payment_requests: array(ref('SupplierBillingRequest')),
});
schemas.SupplierSubscription.description =
  'Consentement explicite à la formule telle que présentée (prix, période, avantages figés) ; période démarrée au paiement vérifié ; résiliation sans renouvellement.';
schemas.SponsoredPlacement = object({
  offer: ref('Offer'),
  sponsored: { type: 'boolean', enum: [true] },
  sponsor: string,
  label: { type: 'string', enum: ['Sponsorisé', 'Sponsored'] },
});
schemas.SponsoredPlacement.description =
  'Bloc séparé et étiqueté ; l’ordre organique du catalogue n’est jamais modifié par un paiement.';

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue ou d’un autre fournisseur.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function op(
  path,
  method,
  { permission, response, body, status = 200, description, query, open, tag },
) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get' && !open)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths['/api/v1/' + path] = {
    ...api.paths['/api/v1/' + path],
    [method]: {
      operationId: (method + '_' + path).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [tag],
      security: open ? [] : [{ sessionCookie: [] }],
      parameters,
      ...(permission ? { 'x-permission': permission } : {}),
      description:
        description +
        (open
          ? ' Public.'
          : permission
            ? ' Permission serveur : ' + permission + '. Session avec MFA obligatoire.'
            : ' Membre actif du fournisseur, portail ouvert.'),
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
const data = (s) => object({ data: s });
const page = (s) =>
  object({ data: array(s), meta: object({ page: version, last_page: version, total: count }) });
const pageQuery = { name: 'page', in: 'query', required: false, schema: version };
const T = 'Offres aux fournisseurs';
const subscriptionBody = (extra = {}) =>
  object({ plan_id: uuid, offer_ids: array(uuid), renew: { type: 'boolean' }, ...extra }, [
    'plan_id',
    ...Object.keys(extra),
  ]);
const planBody = (withVersion) =>
  object(
    {
      code: { type: 'string', pattern: '^[A-Z0-9_-]+$', maxLength: 30 },
      kind: { type: 'string', enum: ['subscription', 'visibility'] },
      name: translated,
      description: translated,
      price: { type: 'integer', minimum: 0 },
      period_days: { type: 'integer', minimum: 7, maximum: 366 },
      benefits,
      ...(withVersion ? { version } : {}),
    },
    [
      'code',
      'kind',
      'name',
      'description',
      'price',
      'period_days',
      'benefits',
      ...(withVersion ? ['version'] : []),
    ],
  );

op('catalogue/sponsored', 'get', {
  tag: T,
  open: true,
  query: [
    { name: 'placement', in: 'query', required: true, schema: placement },
    { name: 'category', in: 'query', required: false, schema: { type: 'string', maxLength: 120 } },
  ],
  response: data(array(ref('SponsoredPlacement'))),
  description:
    'Mises en avant payées en vigueur, parmi les offres publiques, limitées par emplacement et étiquetées.',
});
op('supplier-portal/{supplier}/plans', 'get', {
  tag: T,
  response: data(array(ref('SupplierPlan'))),
  description: 'Formules ouvertes aux fournisseurs.',
});
op('supplier-portal/{supplier}/subscriptions', 'get', {
  tag: T,
  query: [pageQuery],
  response: page(ref('SupplierSubscription')),
  description: 'Souscriptions du fournisseur.',
});
op('supplier-portal/{supplier}/subscriptions', 'post', {
  tag: T,
  status: 201,
  response: data(ref('SupplierSubscription')),
  body: subscriptionBody({ accept_terms: { type: 'boolean', enum: [true] } }),
  description:
    'Souscription par un gérant du fournisseur, avec acceptation explicite de la formule ; première demande de paiement émise.',
});
op('supplier-portal/{supplier}/subscriptions/{id}/accept', 'post', {
  tag: T,
  response: data(ref('SupplierSubscription')),
  body: object({ accept_terms: { type: 'boolean', enum: [true] } }),
  description: 'Accepte une proposition de l’équipe (gérant).',
});
op('supplier-portal/{supplier}/subscriptions/{id}/cancel', 'post', {
  tag: T,
  response: data(ref('SupplierSubscription')),
  body: object({ reason: { type: 'string', maxLength: 1000 } }),
  description: 'Résiliation (gérant) : la période payée va à son terme, rien n’est renouvelé.',
});
op('supplier-portal/{supplier}/subscriptions/{id}/renewal', 'post', {
  tag: T,
  response: data(ref('SupplierSubscription')),
  body: object({ renew: { type: 'boolean' } }),
  description:
    'Demande ou arrête le renouvellement (une demande de paiement est émise avant la fin, jamais de prélèvement).',
});
op('operations/supplier-offers/settings', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(ref('SupplierOfferSettings')),
  description: 'Réglages.',
});
op('operations/supplier-offers/settings', 'put', {
  tag: T,
  permission: 'suppliers.plans',
  response: data(ref('SupplierOfferSettings')),
  body: ref('SupplierOfferSettings'),
  description: 'Ouvre ou ferme abonnements et visibilité (propriétaire, confirmation récente).',
});
op('operations/supplier-plans', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  query: [pageQuery],
  response: page(ref('SupplierPlan')),
  description: 'Formules.',
});
op('operations/supplier-plans', 'post', {
  tag: T,
  permission: 'suppliers.plans',
  status: 201,
  response: data(ref('SupplierPlan')),
  body: planBody(false),
  description: 'Crée une formule en brouillon (confirmation récente).',
});
op('operations/supplier-plans/{id}', 'put', {
  tag: T,
  permission: 'suppliers.plans',
  response: data(ref('SupplierPlan')),
  body: planBody(true),
  description: 'Modifie un brouillon.',
});
op('operations/supplier-plans/{id}/status', 'post', {
  tag: T,
  permission: 'suppliers.plans',
  response: data(ref('SupplierPlan')),
  body: object({ action: { type: 'string', enum: ['activate', 'retire'] }, version }),
  description:
    'Propose ou retire la formule ; les souscriptions acceptées gardent leurs conditions.',
});
op('operations/supplier-subscriptions', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  query: [
    {
      name: 'status',
      in: 'query',
      required: false,
      schema: {
        type: 'string',
        enum: ['proposed', 'awaiting_payment', 'active', 'expired', 'cancelled'],
      },
    },
    { name: 'supplier_id', in: 'query', required: false, schema: uuid },
    pageQuery,
  ],
  response: page(ref('SupplierSubscription')),
  description: 'Souscriptions.',
});
op('operations/suppliers/{id}/subscriptions', 'post', {
  tag: T,
  permission: 'suppliers.plans',
  status: 201,
  response: data(ref('SupplierSubscription')),
  body: subscriptionBody(),
  description: 'Proposition de l’équipe, sans effet avant le consentement du fournisseur.',
});
op('operations/supplier-subscriptions/{id}/consent', 'post', {
  tag: T,
  permission: 'suppliers.plans',
  response: data(ref('SupplierSubscription')),
  body: object({ document_id: uuid, signer_name: { type: 'string', maxLength: 150 } }),
  description:
    'Enregistre un consentement donné hors portail, prouvé par un document signé sain du fournisseur.',
});
op('operations/supplier-subscriptions/{id}/cancel', 'post', {
  tag: T,
  permission: 'suppliers.plans',
  response: data(ref('SupplierSubscription')),
  body: object({ reason: { type: 'string', maxLength: 1000 }, immediate: { type: 'boolean' } }),
  description: 'Résiliation par l’équipe, à terme ou immédiate avec motif.',
});
op('operations/supplier-billing-requests', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  query: [
    {
      name: 'status',
      in: 'query',
      required: false,
      schema: { type: 'string', enum: ['issued', 'paid', 'cancelled'] },
    },
    pageQuery,
  ],
  response: page(ref('SupplierBillingRequest')),
  description: 'Demandes de paiement.',
});
op('operations/supplier-billing-requests/{id}/paid', 'post', {
  tag: T,
  permission: 'finance.write',
  response: data(ref('SupplierBillingRequest')),
  body: object({
    channel: { type: 'string', enum: ['mobile_money', 'bank_transfer', 'cash', 'monetbil'] },
    reference: { type: 'string', maxLength: 150 },
    paid_at: dateTime,
    evidence_id: uuid,
  }),
  description:
    'Paiement vérifié avec référence réelle et preuve du fournisseur (confirmation récente) ; démarre ou prolonge la période.',
});
op('operations/supplier-billing-requests/{id}/cancel', 'post', {
  tag: T,
  permission: 'suppliers.plans',
  response: data(ref('SupplierBillingRequest')),
  body: object({ reason: { type: 'string', maxLength: 1000 } }),
  description: 'Annule une demande non réglée.',
});

api.info.version = '0.44.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
