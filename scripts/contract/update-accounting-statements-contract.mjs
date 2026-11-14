// Run after the supplier portal script: statement layouts, statements, exports and contribution margin.
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
const signed = { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' };
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
const kind = { type: 'string', enum: ['income_statement', 'balance_sheet'] };
const prefix = { type: 'string', pattern: '^\\d{1,12}$' };
const lineCode = { type: 'string', pattern: '^[A-Z0-9_]{1,12}$' };
const layoutLine = {
  type: 'object',
  properties: {
    code: lineCode,
    label: translated,
    type: { type: 'string', enum: ['accounts', 'total'] },
    accounts: array(prefix),
    sign: nullable({ type: 'string', enum: ['debit', 'credit'] }),
    side: nullable({ type: 'string', enum: ['assets', 'liabilities'] }),
    components: array(object({ code: lineCode, factor: { type: 'integer', enum: [1, -1] } })),
  },
  required: ['code', 'label', 'type'],
  additionalProperties: false,
};

schemas.AccountingStatementLayout = object({
  id: uuid,
  kind,
  version,
  label: translated,
  scope: array(prefix),
  lines: array(layoutLine),
  sha256: { type: 'string', pattern: '^[a-f0-9]{64}$' },
  chart_version: count,
  reviewed_at: nullable(dateTime),
  review_note: nullable(string),
  created_at: dateTime,
});
schemas.AccountingStatementLayout.description =
  'Modèle d’état écrit par l’entreprise et examiné par son comptable : préfixes de comptes par rubrique, sans chevauchement, totaux de rubriques antérieures. Rien n’est déduit du plan.';
schemas.AccountingStatement = object({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  kind,
  currency: { type: 'string', enum: ['XAF'] },
  year_id: uuid,
  from: date,
  to: date,
  layout: object({ id: uuid, version, label: translated, reviewed: { type: 'boolean' } }),
  lines: array(
    object({
      code: lineCode,
      label: translated,
      type: { type: 'string', enum: ['accounts', 'total'] },
      amount: signed,
    }),
  ),
  sides: nullable(object({ assets: signed, liabilities: signed, difference: signed })),
  unassigned_accounts: array(
    object({ code: string, label: nullable(translated), balance: signed }),
  ),
  status: { type: 'string', enum: ['provisional', 'definitive'] },
  provisional_reasons: array(
    object(
      {
        code: {
          type: 'string',
          enum: [
            'layout_not_reviewed',
            'chart_not_reviewed',
            'periods_open',
            'unposted_sources',
            'unreconciled_statement_lines',
          ],
        },
        count,
      },
      ['code'],
    ),
  ),
  regulatory_certification: { type: 'boolean', enum: [false] },
});
schemas.AccountingStatement.description =
  'Écritures validées seulement. Compte de résultat : mouvements de la période ; bilan : position à la date de fin depuis le début de l’exercice. Comptes du périmètre non affectés listés ; définitif seulement si périodes closes, sources comptabilisées, relevés rapprochés, modèle et plan examinés.';
schemas.AccountingExport = object({
  id: uuid,
  report: {
    type: 'string',
    enum: ['trial-balance', 'ledger', 'journal', 'income-statement', 'balance-sheet'],
  },
  format: { type: 'string', enum: ['csv', 'xlsx', 'pdf'] },
  parameters: object({ year_id: uuid, from: date, to: date }),
  rows: count,
  bytes: count,
  sha256: string,
  user_id: { type: 'integer' },
  created_at: dateTime,
});
const marginGroup = object({
  key: string,
  label: {},
  orders: count,
  quantity: count,
  revenue: signed,
  refunds: signed,
  goods_cost_actual: signed,
  goods_cost_estimated: signed,
  promotion_subsidies: signed,
  delivery_collected: signed,
  contribution: signed,
  cost_basis: { type: 'string', enum: ['actual', 'partly_estimated', 'estimated'] },
});
schemas.ContributionMargin = object({
  from: date,
  to: date,
  group: { type: 'string', enum: ['order', 'product', 'shop', 'campaign'] },
  currency: { type: 'string', enum: ['XAF'] },
  groups: array(marginGroup),
  total: marginGroup,
  unknown_costs: array({
    type: 'string',
    enum: ['payment_fees', 'delivery_cost', 'loyalty_rewards'],
  }),
  definition: string,
  accounting_result: { type: 'boolean', enum: [false] },
});
schemas.ContributionMargin.description =
  'Marge contributive opérationnelle des commandes payées : distincte du résultat comptable et du cash. Coût réel des unités tracées (lots du dépôt, lignes d’achat), sinon estimé depuis l’accord à la vente ; coûts encore inconnus nommés.';

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function op(
  path,
  method,
  {
    permission,
    response,
    body,
    status = 200,
    description,
    query,
    idempotent = true,
    file,
    paths = {},
  },
) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: paths[m[1]] ?? uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get' && idempotent)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths['/api/v1/' + path] = {
    ...api.paths['/api/v1/' + path],
    [method]: {
      operationId: (method + '_' + path).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: ['Comptabilité — états et exports'],
      security: [{ sessionCookie: [] }],
      parameters,
      'x-permission': permission,
      description:
        description + ' Permission serveur : ' + permission + '. Session avec MFA obligatoire.',
      responses: {
        [status]: file
          ? {
              description: 'Fichier exporté (en-tête X-Export-Sha256)',
              content: {
                'text/csv': { schema: { type: 'string', format: 'binary' } },
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': {
                  schema: { type: 'string', format: 'binary' },
                },
                'application/pdf': { schema: { type: 'string', format: 'binary' } },
              },
            }
          : { description: 'Succès', content: { 'application/json': { schema: response } } },
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
const periodQuery = [
  { name: 'year_id', in: 'query', required: true, schema: uuid },
  { name: 'from', in: 'query', required: true, schema: date },
  { name: 'to', in: 'query', required: true, schema: date },
];
const root = 'operations/accounting/books/{book}';

op(root + '/statement-layouts', 'get', {
  permission: 'accounting.read',
  query: [{ name: 'kind', in: 'query', required: false, schema: kind }, pageQuery],
  response: page(ref('AccountingStatementLayout')),
  description: 'Versions des modèles d’états.',
});
op(root + '/statement-layouts/{kind}', 'post', {
  permission: 'accounting.settings',
  status: 201,
  paths: { kind },
  response: data(ref('AccountingStatementLayout')),
  body: object({ label: translated, scope: array(prefix), lines: array(layoutLine) }),
  description:
    'Nouvelle version du modèle (confirmation récente de l’identité) ; les versions précédentes restent.',
});
op(root + '/statement-layouts/{id}/review', 'post', {
  permission: 'accounting.review',
  response: data(ref('AccountingStatementLayout')),
  body: object({
    sha256: { type: 'string', pattern: '^[a-f0-9]{64}$' },
    note: { type: 'string', maxLength: 2000 },
  }),
  description:
    'Examen du comptable sur l’empreinte vue (confirmation récente) ; le modèle examiné le plus récent sert aux états.',
});
op(root + '/statements/{kind}', 'get', {
  permission: 'accounting.read',
  paths: { kind },
  query: periodQuery,
  response: data(ref('AccountingStatement')),
  description: 'Compte de résultat ou bilan selon le modèle ; refusé sans modèle.',
});
op(root + '/exports', 'get', {
  permission: 'accounting.read',
  query: [pageQuery],
  response: page(ref('AccountingExport')),
  description: 'Trace des exports : qui, quoi, période, lignes, empreinte.',
});
op(root + '/exports/{report}', 'get', {
  permission: 'accounting.read',
  file: true,
  paths: {
    report: {
      type: 'string',
      enum: ['trial-balance', 'ledger', 'journal', 'income-statement', 'balance-sheet'],
    },
  },
  query: [
    ...periodQuery,
    {
      name: 'format',
      in: 'query',
      required: true,
      schema: { type: 'string', enum: ['csv', 'xlsx', 'pdf'] },
    },
  ],
  description:
    'Export sensible (confirmation récente) : CSV point-virgule UTF-8, XLSX ou PDF (3 000 lignes au plus). Texte pouvant être lu comme formule neutralisé ; montants exacts ; journal en colonnes de type FEC, format réglementaire à confirmer par le comptable.',
});
op('operations/finance/margins', 'get', {
  permission: 'finance.read',
  query: [
    { name: 'from', in: 'query', required: true, schema: date },
    { name: 'to', in: 'query', required: true, schema: date },
    {
      name: 'group',
      in: 'query',
      required: true,
      schema: { type: 'string', enum: ['order', 'product', 'shop', 'campaign'] },
    },
  ],
  response: data(ref('ContributionMargin')),
  description:
    'Marge contributive par commande, produit, boutique ou campagne de livraison (dates Africa/Douala).',
});

api.info.version = '0.43.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
