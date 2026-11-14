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
const amount = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const signed = { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' };
const sha = { type: 'string', pattern: '^[a-f0-9]{64}$' };
const note = { type: 'string', minLength: 1, maxLength: 2000 };
const nullable = (s) => ({ anyOf: [s, { type: 'null' }] });
const arr = (items) => ({ type: 'array', items });
const enumeration = (...values) => ({ type: 'string', enum: values });
const obj = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const allocation = obj({ ledger_line_id: version, amount });
S.AccountingLetteringInput = obj({
  matched_on: date,
  reference: { type: 'string', minLength: 1, maxLength: 200 },
  note,
  document_id: uuid,
  lines: {
    type: 'array',
    minItems: 2,
    maxItems: 200,
    items: allocation,
    description:
      'Identifiants de lignes distincts. Montants positifs, au plus le reliquat ; total par côté au plus 10^18 XAF.',
  },
});
S.AccountingLetteringLine = obj({
  ledger_line_id: version,
  entry_id: uuid,
  entry_sha256: sha,
  number: str,
  entry_date: date,
  position: version,
  account: ref('AccountingAccount'),
  party: ref('AccountingParty'),
  debit: amount,
  credit: amount,
  side: enumeration('debit', 'credit'),
  amount,
});
S.AccountingLetteringSnapshot = obj({
  book_id: uuid,
  mode: enumeration('demo', 'live'),
  currency: { type: 'string', const: 'XAF' },
  account_id: uuid,
  party: ref('AccountingParty'),
  matched_on: date,
  reference: str,
  note,
  document: obj({ id: uuid, sha256: sha }),
  lines: arr(ref('AccountingLetteringLine')),
  debit_total: amount,
  credit_total: amount,
});
S.AccountingLettering = obj({
  id: uuid,
  book_id: uuid,
  account_id: uuid,
  party_type: enumeration('customer', 'supplier'),
  party_id: str,
  matched_on: date,
  status: enumeration('active', 'void'),
  version,
  snapshot: ref('AccountingLetteringSnapshot'),
  sha256: sha,
  created_by: version,
  created_at: dt,
  voided_on: nullable(date),
  voided_by: nullable(version),
  voided_at: nullable(dt),
  void_note: nullable(str),
});
S.AccountingLetteringItem = obj({
  ledger_line_id: version,
  entry_id: uuid,
  entry_sha256: sha,
  number: str,
  entry_date: date,
  position: version,
  account: ref('AccountingAccount'),
  party: ref('AccountingParty'),
  debit: amount,
  credit: amount,
  allocated: amount,
  remaining: amount,
  status: enumeration('unmatched', 'partial', 'matched'),
});
S.AccountingLetteringReport = obj({
  book_id: uuid,
  mode: enumeration('demo', 'live'),
  currency: { type: 'string', const: 'XAF' },
  as_of: date,
  account_id: uuid,
  party_id: nullable(str),
  status: enumeration('open', 'all'),
  opening_scope: { type: 'string', const: 'all_posted_years' },
  totals_scope: { type: 'string', const: 'all_eligible_lines' },
  provisional: { type: 'boolean', const: true },
  business_settlement_verified: { type: 'boolean', const: false },
  rows: arr(ref('AccountingLetteringItem')),
  totals: obj({
    debit: amount,
    credit: amount,
    allocated_debit: amount,
    allocated_credit: amount,
    remaining_debit: amount,
    remaining_credit: amount,
    remaining_balance: signed,
  }),
  counts: obj({ unmatched: integer, partial: integer, matched: integer }),
  meta: ref('PageMeta'),
});
const base = '/api/v1/operations/accounting/books/{book}';
const query = (name, schema, required = false) => ({ name, in: 'query', schema, required });
function operation(
  method,
  suffix,
  schema,
  { body, paged = false, params = [], description = '' } = {},
) {
  const path = base + suffix;
  api.paths[path] ??= {};
  const mutate = method !== 'get';
  api.paths[path][method] = {
    operationId: (method + path).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Comptabilité'],
    security: [{ sessionCookie: [] }],
    'x-permission': 'accounting.' + (mutate ? 'write' : 'read'),
    ...(mutate ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      ' Session HttpOnly, MFA.' +
      (mutate
        ? ' CSRF, Idempotency-Key UUID, mot de passe confirmé depuis 15 minutes au plus et verrou du livre.'
        : ' Lecture en snapshot transactionnel.'),
    parameters: [
      ...[...path.matchAll(/\{(\w+)\}/g)].map((m) => ({
        name: m[1],
        in: 'path',
        required: true,
        schema: uuid,
      })),
      ...params,
      ...(paged ? [query('page', version)] : []),
      ...(mutate ? [{ $ref: '#/components/parameters/IdempotencyKey' }] : []),
    ],
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
    responses: {
      [method === 'post' && suffix === '/letterings' ? 201 : 200]: {
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
      404: { description: 'Livre ou ressource hors périmètre.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/letterings', ref('AccountingLettering'), {
  paged: true,
  params: [
    query('account_id', uuid),
    query('party_id', str),
    query('status', enumeration('active', 'void')),
  ],
  description:
    'Historique conservé des lettrages, filtrable par compte, tiers et état actuel. Les constructions internes ne sont pas publiées.',
});
operation('get', '/letterings/{id}', ref('AccountingLettering'), {
  description:
    'Preuve immuable du lettrage et métadonnées datées d’annulation éventuelle. Aucune écriture ni dette métier modifiée.',
});
operation('post', '/letterings', ref('AccountingLettering'), {
  body: ref('AccountingLetteringInput'),
  description:
    'Lettrage partiel ou total de lignes validées du même livre/compte auxiliaire/tiers, y compris d’exercices clôturés. Débit et crédit affectés exactement égaux, sans dépasser les reliquats. Date en période ouverte, au moins les dates des lignes et les dernières créations/annulations de lettrage du livre. Pièce accounting_evidence propre et motif. Aucune déduction de paiement réel ou de disponibilité, aucune modification des encaissements, dettes, factures ou du journal.',
});
operation('post', '/letterings/{id}/void', ref('AccountingLettering'), {
  body: obj({ version, sha256: sha, voided_on: date, note }),
  description:
    'Annulation unique motivée avec version et empreinte, à date en période ouverte respectant la chronologie du livre. Libère les montants à partir de cette date, sans effacer lignes/snapshot/empreinte ni changer les rapports à une date antérieure. Une nouvelle affectation est explicite.',
});
operation('get', '/lettering-items', ref('AccountingLetteringReport'), {
  params: [
    query('account_id', uuid, true),
    query('party_id', str),
    query('as_of', date, true),
    query('status', enumeration('open', 'all')),
    query('page', version),
  ],
  description:
    'Lignes auxiliaires validées connues, cumulées sur tous les exercices jusqu’à as_of. Affectations effectives au jour inclus ; une annulation au même jour est déjà déduite. Défaut open exclut seulement les lignes entièrement lettrées, sans tronquer les totaux : ceux-ci portent sur toutes les lignes admissibles du compte/tiers. Détails paginés par 24, reliquats et totaux exacts en chaînes XAF. Provisoire : ni échéancier métier, ni preuve d’encaissement ou certification. Une écriture comptabilisée ultérieurement à date ancienne peut enrichir cette consultation ; ce rapport n’est pas une archive figée.',
});
api.info.version = '0.24.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
