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
const exact = { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' };
const sha = { type: 'string', pattern: '^[a-f0-9]{64}$' };
const note = { type: 'string', minLength: 1, maxLength: 2000 };
const nullable = (s) => ({ anyOf: [s, { type: 'null' }] });
const arr = (items) => ({ type: 'array', items });
const obj = (properties) => ({
  type: 'object',
  properties,
  required: Object.keys(properties),
  additionalProperties: false,
});
S.TreasuryJournalPosition = obj({ balance: exact, line_count: integer, sha256: sha });
S.TreasuryOpeningPosition = obj({
  from: date,
  statement_id: uuid,
  statement_sha256: sha,
  statement_balance: exact,
  ledger: ref('TreasuryJournalPosition'),
  outstanding_ledger_net: exact,
  outstanding_statement_net: exact,
  adjusted_statement_balance: exact,
  difference: exact,
  previous_closure: nullable(obj({ id: uuid, sha256: sha, version })),
});
S.TreasuryOpeningState = obj({
  valid: bool,
  reason: nullable({
    type: 'string',
    enum: ['anchor_unavailable', 'evidence_changed', 'opening_changed', 'opening_difference'],
  }),
  current: nullable(ref('TreasuryOpeningPosition')),
});
S.TreasuryBalanceReconciliation = obj({
  policy_id: uuid,
  policy_sha256: sha,
  as_of: date,
  ledger: ref('TreasuryJournalPosition'),
  statement_balance: exact,
  outstanding_ledger_net: exact,
  outstanding_statement_net: exact,
  adjusted_statement_balance: exact,
  difference: exact,
  available_funds: { type: 'null' },
  availability_verified: { type: 'boolean', const: false },
  provisional: { type: 'boolean', const: true },
});
S.TreasuryCoverageInput = obj({ statement_id: uuid, document_id: uuid, version: integer, note });
S.TreasuryCoverageSnapshot = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { type: 'string', const: 'XAF' },
  binding: ref('TreasuryBinding'),
  from: date,
  opening: ref('TreasuryOpeningPosition'),
  document: obj({ id: uuid, sha256: sha }),
  note,
  version,
});
S.TreasuryCoveragePolicy = obj({
  id: uuid,
  book_id: uuid,
  treasury_account_id: uuid,
  statement_id: uuid,
  binding_id: uuid,
  from: date,
  version,
  snapshot: ref('TreasuryCoverageSnapshot'),
  sha256: sha,
  status: { type: 'string', enum: ['pending', 'approved', 'void'] },
  current: bool,
  created_by: integer,
  reviewed_by: nullable(integer),
  reviewed_at: nullable(dt),
});
S.TreasuryCoverageReport = obj({
  book_id: uuid,
  treasury_account_id: uuid,
  to: date,
  configured: bool,
  from: nullable(date),
  policy_id: nullable(uuid),
  scope: { type: 'string', const: 'configured_treasury_account' },
  applicable: bool,
  complete: bool,
  opening: nullable(ref('TreasuryOpeningState')),
  gaps: arr(obj({ from: date, to: date })),
  unclosed: arr(uuid),
  unverified_closures: arr(uuid),
  verified_closures: arr(
    obj({
      statement_id: uuid,
      statement_sha256: sha,
      closure_id: uuid,
      closure_sha256: sha,
      version,
      from: date,
      to: date,
    }),
  ),
  closed_statements: integer,
  available_funds: { type: 'null' },
  availability_verified: { type: 'boolean', const: false },
  provisional: { type: 'boolean', const: true },
});
S.TreasuryClosureSnapshot.properties.balance = nullable(ref('TreasuryBalanceReconciliation'));
// Older immutable closures lack this property. Do not require a retroactively invented value.
S.TreasuryStatement.properties.balance_reconciliation = nullable(
  ref('TreasuryBalanceReconciliation'),
);
S.TreasuryStatement.required = [
  ...new Set([...S.TreasuryStatement.required, 'balance_reconciliation']),
];
const base = '/api/v1/operations/accounting/books/{book}/treasury';
function operation(
  method,
  suffix,
  schema,
  { body, permission = 'read', paged = false, created = false, query = [], description = '' } = {},
) {
  const path = base + suffix;
  api.paths[path] ??= {};
  api.paths[path][method] = {
    operationId: (method + path).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Comptabilité'],
    security: [{ sessionCookie: [] }],
    parameters: [
      ...[...path.matchAll(/\{(\w+)\}/g)].map((m) => ({
        name: m[1],
        in: 'path',
        required: true,
        schema: uuid,
      })),
      ...query,
      ...(paged ? [{ name: 'page', in: 'query', schema: version }] : []),
      ...(method !== 'get' ? [{ $ref: '#/components/parameters/IdempotencyKey' }] : []),
    ],
    'x-permission': 'accounting.' + permission,
    ...(method !== 'get' ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      ' Session HttpOnly, MFA et permission accounting.' +
      permission +
      '.' +
      (method !== 'get'
        ? ' CSRF, Idempotency-Key UUID et mot de passe confirmé depuis 15 minutes au plus.'
        : '') +
      ' Aucun fonds disponible ou certificat réglementaire déduit.',
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
    responses: {
      [created ? 201 : 200]: {
        description: 'Succès',
        content: {
          'application/json': {
            schema: paged
              ? obj({ data: arr(schema), meta: ref('PageMeta') })
              : obj({ data: schema }),
          },
        },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: { description: 'Ressource inconnue dans ce livre.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/coverage-policies', ref('TreasuryCoveragePolicy'), {
  paged: true,
  query: [{ name: 'treasury_account_id', in: 'query', schema: uuid }],
  description:
    'Versions des obligations de couverture et preuves d’ouverture. Aucune obligation activée par migration ; drafts, annulations et anciennes versions approuvées restent consultables.',
});
operation('post', '/coverage-policies', ref('TreasuryCoveragePolicy'), {
  body: ref('TreasuryCoverageInput'),
  permission: 'settings',
  created: true,
  description:
    'Prépare une obligation à partir de la date initiale du relevé choisi. Journal validé cumulé avant cette date, empreinte/lignes, solde déclaré et reports hérités sont calculés exactement ; aucun montant d’ouverture saisi ou inventé. Preuve privée propre et motif. Après première approbation, date fixe, sans désactivation ; une correction conserve cette date et exige nouvelle version examinée. Un draft ne remplace pas l’obligation active.',
});
operation('post', '/coverage-policies/{id}/review', ref('TreasuryCoveragePolicy'), {
  body: obj({ version, sha256: sha, note }),
  permission: 'review',
  description:
    'Active la dernière version après examen comptable de la preuve et de l’ouverture : journal = solde déclaré + attentes journal − attentes relevé. Empreinte d’ouverture toujours actuelle, différence nulle, calendrier et relevés couverts rouverts. En réel, créateur distinct de l’approbateur. Toute modification de l’historique avant l’ouverture, même à net nul, exige une nouvelle version examinée. La précédente reste active tant que le nouveau draft n’est pas approuvé.',
});
operation('post', '/coverage-policies/{id}/void', ref('TreasuryCoveragePolicy'), {
  body: obj({ version, note }),
  permission: 'settings',
  description:
    'Retire uniquement un draft pending. Aucune désactivation d’une obligation approuvée, aucun effacement des anciennes versions.',
});
operation('get', '/coverage', ref('TreasuryCoverageReport'), {
  query: [
    { name: 'treasury_account_id', in: 'query', required: true, schema: uuid },
    { name: 'to', in: 'query', required: true, schema: date },
  ],
  description:
    'Couverture depuis la date approuvée jusqu’à to : intervalles manquants, relevés ouverts, clôtures sans preuve de solde à la version courante et actualité de l’ouverture. complete=false sans obligation ou avant son début. La clôture comptable contrôle cumulativement cette couverture jusqu’à sa fin et en conserve la preuve dans l’audit. Le périmètre reste les trésoreries configurées ; pas de couverture globale des comptes inconnus ou non configurés.',
});
const close = api.paths[base + '/statements/{id}/close'].post;
const suffix =
  ' Si une obligation de couverture approuvée s’applique, la clôture vérifie aussi le solde cumulé du journal sur toute l’histoire du livre, ajusté des reports, et fige balance dans son snapshot. Une écriture antérieure à l’ouverture exige réouverture des relevés couverts, protégée aussi en SQL. Les anciens snapshots restent inchangés et balance_reconciliation peut être nul.';
if (!close.description.endsWith(suffix)) close.description += suffix;
api.info.version = '0.23.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
