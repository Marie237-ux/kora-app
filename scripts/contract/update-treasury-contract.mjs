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
const arr = (items, extra = {}) => ({ type: 'array', items, ...extra });
const obj = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const balance = { type: 'string', pattern: '^(0|-?[1-9][0-9]{0,35})$' };
const exact = { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' };
const ledgerId = { type: 'string', pattern: '^[1-9][0-9]{0,17}$' };
const direction = { type: 'string', enum: ['in', 'out'] };
const status = { type: 'string', enum: ['open', 'closed', 'void'] };
const note = { type: 'string', minLength: 1, maxLength: 2000 };
const sha = { type: 'string', pattern: '^[a-f0-9]{64}$' };
const kind = { type: 'string', enum: ['bank', 'mobile_money', 'monetbil', 'cash'] };
S.TreasuryAccount.properties.kind = kind;
api.paths['/api/v1/operations/treasury/accounts'].post.requestBody.content[
  'application/json'
].schema.properties.kind = kind;
S.TreasuryBindingInput = obj({
  treasury_account_id: uuid,
  account_id: uuid,
  inflow_side: { type: 'string', enum: ['debit', 'credit'] },
  version: integer,
  note,
});
S.TreasuryBindingSnapshot = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { const: 'XAF', type: 'string' },
  treasury: obj({ id: uuid, code: str, name: str, kind }),
  account: ref('AccountingAccount'),
  inflow_side: S.TreasuryBindingInput.properties.inflow_side,
  chart_version: version,
  version,
  note,
});
S.TreasuryBinding = obj({
  id: uuid,
  book_id: uuid,
  treasury_account_id: uuid,
  account_id: uuid,
  inflow_side: S.TreasuryBindingInput.properties.inflow_side,
  version,
  chart_version: version,
  snapshot: ref('TreasuryBindingSnapshot'),
  sha256: sha,
  reviewed_by: nullable(integer),
  reviewed_at: nullable(dt),
});
S.TreasuryStatementLineInput = obj({
  external_id: { type: 'string', minLength: 1, maxLength: 120 },
  booked_on: date,
  reference: { type: 'string', minLength: 1, maxLength: 200 },
  direction,
  amount: {
    type: 'string',
    pattern: '^[1-9][0-9]{0,18}$',
    description: 'XAF entier positif, au plus 1000000000000000000.',
  },
});
S.TreasuryStatementInput = obj({
  binding_id: uuid,
  reference: { type: 'string', minLength: 1, maxLength: 120 },
  starts_on: date,
  ends_on: date,
  opening_balance: balance,
  closing_balance: balance,
  document_id: uuid,
  lines: arr(ref('TreasuryStatementLineInput'), { maxItems: 1000 }),
});
S.TreasuryReconciliationTotals = obj({
  unmatched_statement_lines: integer,
  unmatched_ledger_lines: integer,
  unmatched_statement_net: exact,
  unmatched_ledger_net: exact,
  available_funds: { type: 'null' },
  availability_verified: { const: false, type: 'boolean' },
  scope: { type: 'string', const: 'registered_statement_movements' },
  provisional: { type: 'boolean', const: true },
});
S.TreasuryStatementSnapshot = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { type: 'string', const: 'XAF' },
  binding: ref('TreasuryBinding'),
  reference: str,
  starts_on: date,
  ends_on: date,
  opening_balance: balance,
  closing_balance: balance,
  document: obj({ id: uuid, sha256: sha }),
  input_method: { type: 'string', const: 'manual_transcription' },
  lines: arr(ref('TreasuryStatementLineInput')),
});
S.TreasuryStatement = obj({
  id: uuid,
  book_id: uuid,
  binding_id: uuid,
  treasury_account_id: uuid,
  currency: { type: 'string', const: 'XAF' },
  reference: str,
  starts_on: date,
  ends_on: date,
  opening_balance: balance,
  closing_balance: balance,
  document_id: uuid,
  snapshot: ref('TreasuryStatementSnapshot'),
  sha256: sha,
  status,
  version,
  reconciliation: ref('TreasuryReconciliationTotals'),
});
S.TreasuryStatementLine = obj({
  ...S.TreasuryStatementLineInput.properties,
  id: uuid,
  position: version,
  match_id: nullable(uuid),
});
S.TreasuryLedgerLine = obj({
  id: ledgerId,
  entry_id: uuid,
  number: str,
  entry_date: date,
  reference: str,
  entry_sha256: sha,
  debit: exact,
  credit: exact,
  net_amount: exact,
  match_id: nullable(uuid),
});
S.TreasuryMatchInput = obj({
  version,
  note,
  statement_line_ids: arr(uuid, { minItems: 1, maxItems: 200, uniqueItems: true }),
  ledger_line_ids: arr(ledgerId, { minItems: 1, maxItems: 500, uniqueItems: true }),
});
S.TreasuryMatch = obj({
  id: uuid,
  statement_id: uuid,
  net_amount: exact,
  status: { type: 'string', enum: ['active', 'void'] },
  note,
  created_by: integer,
  created_at: dt,
  statement_line_ids: arr(uuid),
  ledger_line_ids: arr(ledgerId),
});
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
      ' Aucun transfert, frais automatique, solde disponible déduit ou certification comptable.',
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
      404: { description: 'Ressource inconnue dans ce livre ou ce relevé.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/bindings', ref('TreasuryBinding'), { paged: true });
operation('post', '/bindings', ref('TreasuryBinding'), {
  body: ref('TreasuryBindingInput'),
  permission: 'settings',
  created: true,
  description:
    'Correspondance versionnée explicite avec le plan courant ; sens des entrées configurable. Un compte comptable représente une seule trésorerie dans un livre. Compte et sens protégés dès qu’un relevé non annulé utilise la trésorerie.',
});
operation('post', '/bindings/{id}/review', ref('TreasuryBinding'), {
  body: obj({ sha256: sha, note }),
  permission: 'review',
  description:
    'Examen du hash par le rôle comptable avant import réel, en plus de l’examen du plan. Une nouvelle version de plan exige une nouvelle correspondance examinée ; les anciennes restent lisibles.',
});
operation('get', '/statements', ref('TreasuryStatement'), {
  paged: true,
  query: [
    { name: 'treasury_account_id', in: 'query', schema: uuid },
    { name: 'status', in: 'query', schema: status },
  ],
  description:
    'Relevés enregistrés seulement, y compris annulations. Les soldes sont déclarés sur pièce, jamais qualifiés de fonds disponibles ; les périodes sans relevé ne sont pas couvertes.',
});
operation('post', '/statements', ref('TreasuryStatement'), {
  body: ref('TreasuryStatementInput'),
  permission: 'write',
  created: true,
  description:
    'Transcription manuelle structurée XAF avec pièce accounting_evidence propre, empreinte et contenu immuables. Contrôle solde initial + mouvements = solde final en entiers exacts, identifiants de ligne distincts et dates bornées. Après le premier relevé : précédent clôturé, dates contiguës et reprise exacte du solde. Pas de parseur PDF/CSV ni lecture bancaire automatique.',
});
operation('get', '/statements/{id}', ref('TreasuryStatement'));
operation('get', '/statements/{id}/rows', ref('TreasuryStatementLine'), { paged: true });
operation('get', '/statements/{id}/ledger', ref('TreasuryLedgerLine'), {
  paged: true,
  description:
    'Lignes comptables validées du compte et de l’intervalle figés, avec identifiants stables sous forme de chaînes et empreinte de l’écriture. Les brouillons ne sont jamais des candidats.',
});
operation('get', '/statements/{id}/matches', ref('TreasuryMatch'), { paged: true });
operation('post', '/statements/{id}/matches', ref('TreasuryMatch'), {
  body: ref('TreasuryMatchInput'),
  permission: 'write',
  created: true,
  description:
    'Groupe manuel plusieurs lignes de relevé/plusieurs lignes comptables ; égalité stricte des nets selon le sens configuré, sans allocation partielle. Les frais d’un versement net doivent déjà être comptabilisés explicitement. Chaque ligne ne peut appartenir qu’à un groupe actif. Verrou de livre et version de relevé.',
});
operation('post', '/statements/{id}/matches/{match}/void', ref('TreasuryMatch'), {
  body: obj({ version, note }),
  permission: 'write',
  description:
    'Annulation motivée d’un groupe actif sur relevé ouvert, avec conservation des membres et de l’audit ; libère les lignes pour une correction explicite.',
});
for (const [action, permission] of Object.entries({
  close: 'close',
  reopen: 'reopen',
  void: 'write',
}))
  operation('post', '/statements/{id}/' + action, ref('TreasuryStatement'), {
    body: obj({ version, note }),
    permission,
    description:
      action === 'close'
        ? 'Clôture stricte : toutes les lignes du relevé et toutes les lignes validées du compte dans l’intervalle sont rapprochées, pièce encore propre. Tout posting/contrepassation dans ce compte et cet intervalle exige ensuite réouverture, également protégée en SQL. Un relevé ouvert bloque la clôture comptable des dates recouvertes ; aucune couverture des relevés manquants affirmée.'
        : action === 'reopen'
          ? 'Réouverture propriétaire motivée, calendrier comptable ouvert ; rouvrir les successeurs clôturés en premier. Les rapprochements et empreintes historiques sont conservés.'
          : 'Annulation d’un relevé ouvert sans groupe actif ni successeur non annulé ; pièce, lignes et empreinte conservées. Toute correction nécessite un nouvel import.',
  });
api.info.version = '0.21.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
