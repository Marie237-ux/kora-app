// Adds billing identity, invoices, credit notes and private PDF downloads to the contract.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const S = api.components.schemas;

const str = { type: 'string' };
const bool = { type: 'boolean' };
const uuid = { type: 'string', format: 'uuid' };
const dt = { type: 'string', format: 'date-time' };
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
const localized = obj({ fr: nullable(str), en: nullable(str) });
const regime = { type: 'string', enum: ['unset', 'vat', 'no_vat'] };
const recent =
  'Confirmation du mot de passe datant de 15 minutes au plus, via POST /api/v1/auth/user/confirm-password.';

S.BillingIdentity = obj({
  version: nullable(version),
  legal_name: nullable(str),
  trade_name: str,
  legal_form: nullable(str),
  niu: nullable(str),
  rccm: nullable(str),
  address: nullable(str),
  city: nullable(str),
  phone: nullable(str),
  email: nullable(str),
  tax_regime: regime,
  vat_rate_basis_points: nullable({ type: 'integer', minimum: 1, maximum: 5000 }),
  mentions: localized,
  missing: arr(str),
});
S.CustomerDocument = obj({
  id: uuid,
  order_id: uuid,
  kind: { type: 'string', enum: ['invoice', 'credit_note'] },
  status: { type: 'string', enum: ['draft', 'issued'] },
  number: nullable(str),
  invoice_id: nullable(uuid),
  refund_id: nullable(uuid),
  total: xaf,
  demo: bool,
  snapshot_sha256: nullable({ type: 'string', pattern: '^[a-f0-9]{64}$' }),
  issued_at: nullable(dt),
  version,
  created_at: dt,
});

const pdf = {
  description: 'Document PDF privé',
  content: { 'application/pdf': { schema: { type: 'string', format: 'binary' } } },
};
const json = (schema, paged) => ({
  description: 'Succès',
  content: {
    'application/json': {
      schema: paged ? obj({ data: arr(schema), meta: ref('PageMeta') }) : obj({ data: schema }),
    },
  },
});

function operation(method, url, options) {
  const {
    response,
    status = 200,
    paged = false,
    permission,
    owner,
    body,
    description = '',
    sensitive = false,
    binary = false,
  } = options;
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  parameters.push({ name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } });
  if (paged)
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const notes = [
    permission
      ? 'Permission serveur : ' + permission + '. MFA obligatoire.'
      : 'Compte authentifié ; commandes propres au compte.',
    sensitive ? recent : '',
    method === 'get' ? '' : 'Session web HttpOnly et CSRF requis.',
  ];
  const op = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Factures et avoirs'],
    security: [{ sessionCookie: [] }],
    parameters,
    description: [description, ...notes].filter(Boolean).join(' '),
    ...(permission ? { 'x-permission': permission } : { 'x-resource-owner': owner }),
    ...(sensitive ? { 'x-recent-password-seconds': 900 } : {}),
    responses: {
      [status]: binary ? pdf : json(response, paged),
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: {
        description: 'Document ou commande inconnu, non émis ou appartenant à un autre compte.',
      },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite atteinte.' },
    },
  };
  if (body) op.requestBody = { required: true, content: { 'application/json': { schema: body } } };
  api.paths['/api/v1/' + url] ??= {};
  api.paths['/api/v1/' + url][method] = op;
}

operation('get', 'orders/{id}/documents', {
  owner: 'account',
  response: arr(ref('CustomerDocument')),
  description: 'Documents émis de la commande ; les brouillons ne sont jamais montrés au client.',
});
operation('get', 'orders/{id}/documents/{documentId}/pdf', {
  owner: 'account',
  binary: true,
  description:
    'PDF rendu depuis l’instantané figé : même document et même numéro en français ou en anglais. Contrôle d’accès à chaque téléchargement.',
});
operation('get', 'operations/settings/billing', {
  permission: 'billing.read',
  response: ref('BillingIdentity'),
});
operation('put', 'operations/settings/billing', {
  permission: 'billing.settings',
  sensitive: true,
  response: ref('BillingIdentity'),
  body: obj(
    {
      version: nullable(version),
      legal_name: nullable(str),
      trade_name: str,
      legal_form: nullable(str),
      niu: nullable(str),
      rccm: nullable(str),
      address: nullable(str),
      city: nullable(str),
      phone: nullable(str),
      email: nullable({ type: 'string', format: 'email' }),
      tax_regime: regime,
      vat_rate_basis_points: nullable({ type: 'integer', minimum: 1, maximum: 5000 }),
      mentions: localized,
    },
    ['trade_name', 'tax_regime', 'mentions'],
  ),
  description:
    'Identité réelle de l’exploitant. Tant que missing n’est pas vide, les documents sont des démonstrations numérotées à part. Aucune TVA sans régime TVA explicite. Les documents déjà émis ne changent pas.',
});
operation('get', 'operations/customer-documents', {
  permission: 'billing.read',
  paged: true,
  response: ref('CustomerDocument'),
});
operation('post', 'operations/orders/{id}/invoice', {
  permission: 'billing.issue',
  status: 201,
  response: ref('CustomerDocument'),
  description: 'Brouillon de facture d’une commande payée ; une seule facture par commande.',
});
operation('post', 'operations/refunds/{id}/credit-note', {
  permission: 'billing.issue',
  status: 201,
  response: ref('CustomerDocument'),
  description:
    'Brouillon d’avoir pour un remboursement versé, lié à la facture émise et limité à son montant non crédité.',
});
operation('post', 'operations/customer-documents/{id}/issue', {
  permission: 'billing.issue',
  sensitive: true,
  response: ref('CustomerDocument'),
  body: obj({ version }),
  description:
    'Numéro alloué sous verrou et contenu figé avec empreinte SHA-256 ; ensuite ni modification ni suppression.',
});
operation('get', 'operations/customer-documents/{id}/pdf', {
  permission: 'billing.read',
  binary: true,
  description: 'Document émis ou aperçu d’un brouillon marqué comme tel.',
});

api.info.version = '0.12.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
