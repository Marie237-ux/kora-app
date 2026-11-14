import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const S = api.components.schemas;
const ref = (n) => ({ $ref: '#/components/schemas/' + n });
const str = { type: 'string' };
const uuid = { type: 'string', format: 'uuid' };
const date = { type: 'string', format: 'date' };
const dt = { type: 'string', format: 'date-time' };
const integer = { type: 'integer', minimum: 0 };
const version = { type: 'integer', minimum: 1 };
const bool = { type: 'boolean' };
const nullable = (s) => ({ anyOf: [s, { type: 'null' }] });
const arr = (items) => ({ type: 'array', items });
const obj = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const status = {
  type: 'string',
  enum: ['captured', 'excluded', 'review_required', 'retry_pending', 'failed'],
};
const sourceType = S.AccountingSource.properties.source_type;
const reason = nullable({
  type: 'string',
  enum: [
    'BEFORE_CAPTURE_START',
    'DEMO_SOURCE',
    'TESTMODE_SOURCE',
    'CONSIGNMENT_NOT_OWNED',
    'NO_ACCEPTED_VALUE',
    'SOURCE_CHANGED',
    'SOURCE_NOT_ELIGIBLE',
    'INVALID_EVENT',
    'SOURCE_MISSING',
    'CAPTURE_FAILED',
  ],
});
S.AccountingCaptureSettings = obj({
  version,
  enabled: bool,
  from: nullable(date),
  automatic_posting: { type: 'boolean', const: false },
});
S.AccountingCaptureReceipt = obj({
  id: uuid,
  event_id: uuid,
  source_type: nullable(sourceType),
  resource_id: nullable(uuid),
  event_date: nullable(date),
  source_id: nullable(uuid),
  status,
  reason,
  policy_version: version,
  attempts: integer,
  consecutive_failures: integer,
  next_attempt_at: nullable(dt),
  created_at: dt,
  updated_at: dt,
});
S.AccountingCaptureAttempt = obj({
  attempt: version,
  status,
  reason,
  policy_version: version,
  source_id: nullable(uuid),
  created_at: dt,
});
S.AccountingCoverage = obj({
  book_id: uuid,
  from: date,
  to: date,
  scope: { type: 'string', const: 'seven_supported_fact_families' },
  rows: arr(
    obj({
      source_type: sourceType,
      candidates: integer,
      captured: integer,
      excluded: integer,
      unresolved: integer,
    }),
  ),
  unresolved: integer,
  capture_issues: integer,
  unidentified_events: integer,
  external_reconciliation: { type: 'boolean', const: false },
  regulatory_certification: { type: 'boolean', const: false },
});
function add(schema, fields) {
  Object.assign(schema.properties, fields);
  schema.required = [...new Set([...(schema.required ?? []), ...Object.keys(fields)])];
}
add(S.AccountingBook, { capture: ref('AccountingCaptureSettings') });
add(S.AccountingSource, {
  created_by: nullable({ type: 'integer' }),
  capture_event_id: nullable(uuid),
});
for (const name of ['AccountingTrialBalance', 'AccountingLedger'])
  add(S[name], {
    integration_coverage: {
      type: 'string',
      enum: ['explicit_capture_only', 'configured_supported_families'],
    },
    capture_coverage: nullable(ref('AccountingCoverage')),
  });
const base = '/api/v1/operations/accounting/books/{book}';
function operation(
  method,
  suffix,
  response,
  { body, permission = 'read', paged = false, query = [], description = '' } = {},
) {
  const path = base + suffix;
  const parameters = [
    ...[...path.matchAll(/\{(\w+)\}/g)].map((m) => ({
      name: m[1],
      in: 'path',
      required: true,
      schema: uuid,
    })),
    ...query,
  ];
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  if (paged) parameters.push({ name: 'page', in: 'query', schema: version });
  api.paths[path] ??= {};
  api.paths[path][method] = {
    operationId: (method + path).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Comptabilité'],
    security: [{ sessionCookie: [] }],
    parameters,
    'x-permission': 'accounting.' + permission,
    ...(method !== 'get' ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      ' Session HttpOnly, MFA de gestion et permission accounting.' +
      permission +
      '.' +
      (method !== 'get'
        ? ' CSRF, Idempotency-Key UUID et confirmation du mot de passe depuis 15 minutes au plus requis.'
        : '') +
      ' Aucun posting automatique ni certification comptable.',
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
    responses: {
      200: {
        description: 'Succès',
        content: {
          'application/json': {
            schema: paged
              ? obj({ data: arr(response), meta: ref('PageMeta') })
              : obj({ data: response }),
          },
        },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: { description: 'Livre ou reçu inconnu dans ce livre.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/capture-settings', ref('AccountingCaptureSettings'));
operation('put', '/capture-settings', ref('AccountingCaptureSettings'), {
  permission: 'settings',
  body: obj({ version, enabled: bool, from: nullable(date), note: str }),
  description:
    'Capture initialement désactivée. Activation avec date de début explicite ; date ensuite seulement avançable vers le passé. Une pause conserve le périmètre de clôture. Le job dédié capture les faits vérifiés, récupère les événements manquants et conserve un reçu par livre/événement sans modifier les accusés des autres consommateurs.',
});
operation('get', '/capture-events', ref('AccountingCaptureReceipt'), {
  paged: true,
  query: [{ name: 'status', in: 'query', schema: status }],
  description:
    'Captures, exclusions motivées, sources à examiner et échecs locaux. Aucun contenu brut d’événement ou diagnostic sensible exposé. Cinq échecs transitoires consécutifs au maximum, temporisation croissante puis reprise explicite.',
});
operation('get', '/capture-events/{id}/attempts', ref('AccountingCaptureAttempt'), {
  paged: true,
  description: 'Historique des tentatives, conservé après reprise et limité au livre demandé.',
});
operation('post', '/capture-events/{id}/retry', ref('AccountingCaptureReceipt'), {
  permission: 'settings',
  body: obj({ note: str }),
  description:
    'Reprise motivée auditée ; conserve les tentatives antérieures et ne réécrit aucune source. Un reçu déjà capturé ne peut être repris par cette route.',
});
operation('get', '/coverage', ref('AccountingCoverage'), {
  query: [
    { name: 'from', in: 'query', required: true, schema: date },
    { name: 'to', in: 'query', required: true, schema: date },
  ],
  description:
    'Recense les sept familles depuis les tables métier, y compris sans événement outbox. Les candidats ne valent pas tous source admissible : exclusions et incidents restent distincts. Bloque la clôture du périmètre configuré si candidats non traités ou captures en anomalie. Les écritures restent ensuite à préparer et valider. Les événements tardifs après clôture exigent une décision de date ou réouverture ; aucun rapprochement prestataire ni exhaustivité des domaines encore absents n’est affirmé.',
});
api.paths['/api/v1/operations/accounting/source-types'].get.responses[200].content[
  'application/json'
].schema.properties.data.properties.capture = {
  type: 'string',
  const: 'manual_or_configured_events',
};
for (const path of [
  '/api/v1/operations/accounting/books/{book}/reports/trial-balance',
  '/api/v1/operations/accounting/books/{book}/reports/ledger',
]) {
  const suffix =
    ' capture_coverage expose, pour le périmètre configuré, les candidats non capturés et les incidents du consommateur dédié ; aucun rapprochement externe déduit.';
  if (!api.paths[path].get.description.endsWith(suffix)) api.paths[path].get.description += suffix;
}
api.info.version = '0.20.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
