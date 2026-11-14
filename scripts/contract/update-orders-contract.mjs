// Adds orders, payment attempts, the provider notification and internal reconciliation to the contract.
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
const version = { type: 'integer', minimum: 1 };
const xaf = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const arr = (items) => ({ type: 'array', items });
const obj = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const orderStatus = {
  type: 'string',
  enum: ['awaiting_payment', 'payment_review', 'paid', 'expired', 'cancelled'],
};
const attemptStatus = {
  type: 'string',
  enum: ['starting', 'pending', 'confirmed', 'failed', 'cancelled', 'to_verify', 'reversed'],
};
const incidentKind = {
  type: 'string',
  enum: [
    'amount_mismatch',
    'verification_incomplete',
    'testmode_payment',
    'reference_mismatch',
    'transaction_already_used',
    'overpayment',
    'stock_unavailable_after_payment',
    'slot_unavailable_after_payment',
  ],
};

S.PaymentAttempt = obj({
  id: uuid,
  order_id: uuid,
  provider: str,
  status: attemptStatus,
  amount: xaf,
  currency: { type: 'string', const: 'XAF' },
  payment_url: nullable({ type: 'string', format: 'uri' }),
  created_at: dt,
});
S.Order = obj({
  id: uuid,
  number: { type: 'string', pattern: '^CMD-[0-9]{7}$' },
  status: orderStatus,
  currency: { type: 'string', const: 'XAF' },
  items_total: xaf,
  delivery_total: xaf,
  total: xaf,
  payment_expires_at: dt,
  paid_at: nullable(dt),
  payable: bool,
  lines: arr(
    obj({
      id: uuid,
      offer_id: uuid,
      name: str,
      sku: str,
      shop: obj({ name: str, slug: str }),
      warranty: str,
      quantity: int,
      unit_price: xaf,
      line_total: xaf,
      supply_status: { type: 'string', enum: ['reserved', 'to_confirm', 'incident'] },
    }),
  ),
  delivery: obj({
    breakdown: arr(obj({ code: str, quantity: int, amount: xaf })),
    demo_amounts: bool,
    address: obj({ label: str, district: str, landmark: str }),
    slot: nullable(obj({ date, starts_at: str, ends_at: str })),
  }),
  payment: nullable(
    obj({
      attempt_id: uuid,
      status: attemptStatus,
      payment_url: nullable({ type: 'string', format: 'uri' }),
    }),
  ),
  refunds: arr(
    obj({
      amount: xaf,
      status: { type: 'string', enum: ['requested', 'paid'] },
      paid_at: nullable(dt),
    }),
  ),
  created_at: dt,
});
S.PaymentIncident = obj({
  id: uuid,
  order_id: uuid,
  attempt_id: nullable(uuid),
  order_line_id: nullable(uuid),
  kind: incidentKind,
  status: { type: 'string', enum: ['open', 'resolved'] },
  details: { type: 'object', additionalProperties: true },
  resolution: nullable({
    type: 'string',
    enum: ['refunded', 'confirmed_after_review', 'dismissed'],
  }),
  resolution_note: nullable(str),
  resolved_at: nullable(dt),
  created_at: dt,
});
S.CustomerRefund = obj({
  id: uuid,
  order_id: uuid,
  incident_id: nullable(uuid),
  amount: xaf,
  reason: str,
  status: { type: 'string', enum: ['requested', 'paid', 'cancelled'] },
  channel: nullable({ type: 'string', enum: ['mobile_money', 'bank_transfer', 'cash'] }),
  reference: nullable(str),
  paid_at: nullable(dt),
  version,
  created_at: dt,
});
S.OrderInternal = {
  allOf: [
    ref('Order'),
    {
      type: 'object',
      properties: {
        internal_lines: arr({ type: 'object', additionalProperties: true }),
        attempts: arr({ type: 'object', additionalProperties: true }),
        incidents: arr(ref('PaymentIncident')),
        events: arr({ type: 'object', additionalProperties: true }),
      },
      required: ['internal_lines', 'attempts', 'incidents', 'events'],
    },
  ],
};

const responses = (status, schema, { anonymous = false, text = false } = {}) => ({
  [status]: text
    ? {
        description: 'Accusé de réception',
        content: { 'text/plain': { schema: { type: 'string', const: 'received' } } },
      }
    : { description: 'Succès', content: { 'application/json': { schema } } },
  ...(anonymous
    ? { 403: { description: 'Signature invalide : rien n’est traité.' } }
    : {
        401: { $ref: '#/components/responses/Unauthorized' },
        403: { $ref: '#/components/responses/Forbidden' },
        409: { $ref: '#/components/responses/OperationalConflict' },
        419: { description: 'Session/CSRF expiré.' },
        422: { $ref: '#/components/responses/ValidationError' },
      }),
  404: { description: 'Commande ou tentative inconnue, ou appartenant à un autre compte.' },
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
    idempotent = true,
    params = [],
    sensitive = false,
  } = options;
  const anonymous = owner === 'provider-signed';
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (!anonymous)
    parameters.push({
      name: 'locale',
      in: 'query',
      schema: { type: 'string', enum: ['fr', 'en'] },
    });
  if (paged)
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  parameters.push(...params);
  if (method !== 'get' && idempotent)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const notes = permission
    ? ['Permission serveur : ' + permission + '. MFA obligatoire.']
    : anonymous
      ? []
      : ['Compte authentifié ; ressources propres au compte.'];
  if (method !== 'get' && !anonymous) notes.push('Session web HttpOnly et CSRF requis.');
  const schema = paged
    ? obj({ data: arr(response), meta: ref('PageMeta') })
    : obj({ data: response });
  const op = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Commandes et paiements'],
    security: anonymous ? [] : [{ sessionCookie: [] }],
    parameters,
    description: [description, ...notes].filter(Boolean).join(' '),
    ...(permission ? { 'x-permission': permission } : { 'x-resource-owner': owner }),
    ...(sensitive ? { 'x-recent-password-seconds': 900 } : {}),
    responses: responses(status, schema, { anonymous, text: anonymous }),
  };
  if (body)
    op.requestBody = {
      required: true,
      content: {
        [anonymous ? 'application/x-www-form-urlencoded' : 'application/json']: { schema: body },
      },
    };
  api.paths['/api/v1/' + url] ??= {};
  api.paths['/api/v1/' + url][method] = op;
}

operation('get', 'orders', ref('Order'), { owner: 'account', paged: true });
operation('post', 'orders', ref('Order'), {
  owner: 'account',
  status: 201,
  body: obj({ quote_id: uuid, quote_version: version, accepted_total: xaf }),
  description:
    'Commande figée depuis un devis valable avec créneau réservé ; accepted_total doit égaler exactement articles + livraison recalculés. Un devis ne produit qu’une commande. Stock KORA réservé pour la fenêtre de paiement ; quantités fournisseur à confirmer.',
});
operation('get', 'orders/{id}', ref('Order'), { owner: 'account' });
operation('post', 'orders/{id}/payments', ref('PaymentAttempt'), {
  owner: 'account',
  status: 201,
  description:
    'Démarre ou reprend une tentative. Une tentative récente non clarifiée est proposée à nouveau au lieu d’un second encaissement ; une tentative à vérifier bloque toute nouvelle demande. Appel prestataire hors transaction ; prestataire non configuré ou indisponible : 422 sans débit.',
});
operation('post', 'orders/{id}/payments/{attemptId}/check', ref('Order'), {
  owner: 'account',
  idempotent: false,
  body: obj({ transaction_id: { type: 'string', pattern: '^[A-Za-z0-9_.-]{1,120}$' } }),
  description:
    'Retour navigateur : l’identifiant de transaction n’est qu’un indice, revérifié auprès du prestataire. Relecture sans effet de bord : rejouable.',
});
operation('post', 'payments/monetbil/notify', null, {
  owner: 'provider-signed',
  idempotent: false,
  body: {
    type: 'object',
    properties: { transaction_id: str, payment_ref: str, sign: str },
    required: ['sign'],
    additionalProperties: true,
  },
  description:
    'Notification serveur à serveur de Monetbil. Signature MD5 du SDK officiel vérifiée ; réception enregistrée sous forme d’empreinte, sans téléphone ni nom ; transaction revérifiée via checkPayment avant tout effet. Rejouable.',
});
operation('get', 'operations/orders', ref('Order'), {
  permission: 'orders.read',
  paged: true,
  params: [{ name: 'status', in: 'query', schema: orderStatus }],
});
operation('get', 'operations/orders/{id}', ref('OrderInternal'), {
  permission: 'orders.read',
  description:
    'Vue interne : coûts et accords figés, sources, tentatives, incidents et historique.',
});
operation('get', 'operations/payment-incidents', ref('PaymentIncident'), {
  permission: 'payments.read',
  paged: true,
  params: [{ name: 'status', in: 'query', schema: { type: 'string', enum: ['open', 'resolved'] } }],
});
operation('post', 'operations/payments/{attemptId}/check', obj({ outcome: str }), {
  permission: 'payments.reconcile',
  idempotent: false,
  description:
    'Rapprochement manuel d’une tentative dont la transaction est connue ; relecture rejouable sans nouvel encaissement.',
});

const recent =
  'Confirmation du mot de passe datant de 15 minutes au plus, via POST /api/v1/auth/user/confirm-password.';
operation('post', 'operations/payment-incidents/{id}/resolve', ref('PaymentIncident'), {
  permission: 'payments.refund',
  sensitive: true,
  body: obj(
    {
      outcome: { type: 'string', enum: ['confirmed_after_review', 'dismissed'] },
      note: { type: 'string', maxLength: 2000 },
      evidence_id: nullable(uuid),
    },
    ['outcome', 'note'],
  ),
  description:
    'Décision explicite et auditée. Un montant reçu (écart, trop-perçu) ne se clôt que par un remboursement vérifié ; confirmed_after_review exige une preuve et ne vaut que pour une réponse prestataire incomplète. ' +
    recent,
});
operation('get', 'operations/refunds', ref('CustomerRefund'), {
  permission: 'payments.read',
  paged: true,
  params: [
    {
      name: 'status',
      in: 'query',
      schema: { type: 'string', enum: ['requested', 'paid', 'cancelled'] },
    },
  ],
});
operation('post', 'operations/orders/{id}/refunds', ref('CustomerRefund'), {
  permission: 'payments.refund',
  sensitive: true,
  status: 201,
  body: obj(
    { amount: xaf, reason: { type: 'string', maxLength: 300 }, incident_id: nullable(uuid) },
    ['amount', 'reason'],
  ),
  description:
    'Demande sous verrou de commande : demandes et remboursements versés ne dépassent jamais l’argent réellement reçu (hors mode test). Une demande n’est pas un remboursement. ' +
    recent,
});
operation('post', 'operations/refunds/{id}/verify', ref('CustomerRefund'), {
  permission: 'payments.refund',
  sensitive: true,
  body: obj({
    amount: xaf,
    channel: { type: 'string', enum: ['mobile_money', 'bank_transfer', 'cash'] },
    reference: { type: 'string', maxLength: 150 },
    evidence_id: uuid,
    paid_at: dt,
  }),
  description:
    'Versement réel prouvé : montant identique à la demande, référence unique par canal, pièce customer_payment_evidence analysée. Clôt l’incident lié. ' +
    recent,
});
operation('post', 'operations/refunds/{id}/cancel', ref('CustomerRefund'), {
  permission: 'payments.refund',
  body: obj({ reason: { type: 'string', maxLength: 300 } }),
  description: 'Annule une demande non versée et libère son montant.',
});

// Customer payment evidence concerns no supplier.
const upload =
  api.paths['/api/v1/operations/documents']?.post?.requestBody?.content?.['multipart/form-data']
    ?.schema;
if (upload) {
  upload.properties.purpose.enum = [
    ...new Set([...upload.properties.purpose.enum, 'customer_payment_evidence']),
  ];
  upload.required = upload.required.filter((field) => field !== 'supplier_id');
  upload.description =
    'supplier_id requis sauf pour customer_payment_evidence, où il est interdit.';
}

api.info.version = '0.11.0';
api.info.title =
  'KORA — API implémentée : catalogue, approvisionnement, demandes, panier, tableaux, livraison et commandes';
api.info.description =
  'Sessions HttpOnly et CSRF, permissions/MFA sur la gestion, confirmation récente finance. Commandes idempotentes et versions serveur. Catalogue, stock/achats/dettes, demandes privées, recherche texte, panier partagé, tableaux collaboratifs, livraison, commandes figées et paiements Monetbil vérifiés côté serveur implémentés. Aucun compte marchand configuré ni encaissement réel effectué ; le reste du lancement demeure obligatoire dans EXIGENCES.md.';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
