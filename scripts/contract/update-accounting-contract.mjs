import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const S = api.components.schemas;
const ref = (n) => ({ $ref: '#/components/schemas/' + n });
const str = { type: 'string' };
const uuid = { type: 'string', format: 'uuid' };
const date = { type: 'string', format: 'date' };
const dt = { type: 'string', format: 'date-time' };
const bool = { type: 'boolean' };
const version = { type: 'integer', minimum: 1 };
const zero = { type: 'integer', minimum: 0 };
const amount = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const signed = { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' };
const hash = { type: 'string', pattern: '^[a-f0-9]{64}$' };
const nil = (s) => ({ anyOf: [s, { type: 'null' }] });
const arr = (items) => ({ type: 'array', items });
const obj = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const json = { type: 'object', additionalProperties: true };
const loc = obj({ fr: str, en: str });
const en = (...values) => ({ type: 'string', enum: values });
const types = [
  'customer_invoice',
  'customer_credit_note',
  'customer_payment',
  'customer_refund',
  'supplier_obligation',
  'supplier_payment',
  'goods_receipt',
];
S.AccountingAccountInput = obj({
  code: { type: 'string', pattern: '^[0-9]{1,20}$' },
  parent_code: nil(str),
  label: loc,
  kind: en('asset', 'liability', 'equity', 'income', 'expense', 'other'),
  auxiliary_type: en('none', 'customer', 'supplier'),
  postable: bool,
  active: bool,
});
S.AccountingAccount = obj({ id: uuid, ...S.AccountingAccountInput.properties });
S.AccountingChart = obj({
  version: zero,
  sha256: nil(hash),
  reviewed: bool,
  accounts: arr(ref('AccountingAccount')),
});
S.AccountingBook = obj({
  id: uuid,
  mode: en('demo', 'live'),
  label: loc,
  currency: { const: 'XAF', type: 'string' },
  entity: ref('BillingIdentity'),
  chart_version: zero,
  reviewed_chart_version: nil(version),
  draft_count: zero,
  posted_count: zero,
  known_unposted_sources: zero,
  provisional: { const: true, type: 'boolean' },
  regulatory_certification: { const: false, type: 'boolean' },
});
S.AccountingJournal = obj({ id: uuid, code: str, label: loc, active: bool, version });
S.AccountingCalendar = obj({
  id: uuid,
  year_id: nil(uuid),
  label: loc,
  starts_on: date,
  ends_on: date,
  status: en('open', 'closed'),
  version,
});
S.AccountingPartyInput = obj({
  type: en('customer', 'supplier'),
  id: {
    type: 'string',
    description:
      'supplier:<UUID>, user:<integer>, professional:<UUID>; existing party, scoped to the account auxiliary type.',
  },
});
S.AccountingParty = obj({ ...S.AccountingPartyInput.properties, name: str });
S.AccountingLineInput = obj(
  { account_id: uuid, debit: amount, credit: amount, party: nil(ref('AccountingPartyInput')) },
  ['account_id', 'debit', 'credit'],
);
S.AccountingLine = obj({
  account_id: uuid,
  account: ref('AccountingAccount'),
  debit: amount,
  credit: amount,
  party: nil(ref('AccountingParty')),
});
S.AccountingEntryInput = obj({
  journal_id: uuid,
  entry_date: date,
  description: loc,
  reference: { type: 'string', maxLength: 200 },
  document_ids: arr(uuid),
  lines: { type: 'array', minItems: 1, maxItems: 1000, items: ref('AccountingLineInput') },
});
S.AccountingEntry = obj({
  id: uuid,
  book_id: uuid,
  journal_id: uuid,
  year_id: uuid,
  period_id: uuid,
  source_id: nil(uuid),
  mapping_id: nil(uuid),
  reversal_of: nil(uuid),
  chart_version: version,
  status: en('draft', 'posted', 'cancelled'),
  version,
  number: nil(str),
  entry_date: date,
  description: loc,
  reference: str,
  document_ids: arr(uuid),
  lines: arr(ref('AccountingLine')),
  debit_total: amount,
  credit_total: amount,
  balanced: bool,
  sha256: nil(hash),
  posted_at: nil(dt),
  reversals: arr(uuid),
});
S.AccountingMappingLine = obj({
  measure: en('total', 'net', 'vat', 'items', 'delivery', 'loyalty_discount'),
  side: en('debit', 'credit'),
  account_id: uuid,
});
S.AccountingMappingInput = obj({
  source_type: en(...types),
  version: zero,
  journal_id: uuid,
  enabled: bool,
  note: str,
  lines: { type: 'array', minItems: 2, maxItems: 50, items: ref('AccountingMappingLine') },
});
S.AccountingMapping = obj({
  id: uuid,
  source_type: en(...types),
  version,
  chart_version: version,
  journal_id: uuid,
  enabled: bool,
  lines: arr(ref('AccountingMappingLine')),
  sha256: hash,
  note: str,
  reviewed_at: nil(dt),
  reviewed_by: nil({ type: 'integer' }),
});
S.AccountingSourceSnapshot = obj({
  source_type: en(...types),
  resource_id: uuid,
  occurred_at: dt,
  event_date: date,
  currency: { type: 'string', const: 'XAF' },
  reference: str,
  demo: bool,
  measures: { type: 'object', additionalProperties: amount },
  party: ref('AccountingPartyInput'),
  document_ids: arr(uuid),
  metadata: json,
});
S.AccountingSource = obj({
  id: uuid,
  source_type: en(...types),
  resource_id: uuid,
  event_date: date,
  snapshot: ref('AccountingSourceSnapshot'),
  sha256: hash,
  latest_entry_id: nil(uuid),
  posted_entry_id: nil(uuid),
  occurred_at: dt,
});
S.AccountingEntryRevision = obj({
  id: { type: 'integer' },
  entry_id: uuid,
  version,
  action: en('created', 'updated', 'posted', 'cancelled'),
  snapshot: ref('AccountingEntry'),
  actor_id: { type: 'integer' },
  created_at: str,
});
S.AccountingChartRevision = obj({
  id: uuid,
  book_id: uuid,
  version,
  snapshot: arr(ref('AccountingAccountInput')),
  sha256: hash,
  reference: str,
  created_by: { type: 'integer' },
  reviewed_by: nil({ type: 'integer' }),
  reviewed_at: nil(str),
  review_note: nil(str),
  created_at: str,
});
const trialRow = obj({
  account: ref('AccountingAccount'),
  opening_balance: signed,
  debit: amount,
  credit: amount,
  closing_balance: signed,
});
const ledgerRow = obj({
  entry_id: uuid,
  position: version,
  number: str,
  entry_date: date,
  journal_id: uuid,
  reference: str,
  account: ref('AccountingAccount'),
  party: nil(ref('AccountingParty')),
  debit: amount,
  credit: amount,
  running_balance: signed,
});
const report = {
  book_id: uuid,
  mode: en('demo', 'live'),
  currency: { const: 'XAF', type: 'string' },
  provisional: { const: true, type: 'boolean' },
  year_id: uuid,
  from: date,
  to: date,
  opening_scope: { const: 'within_selected_year', type: 'string' },
  account_id: nil(uuid),
  party_id: nil(str),
  opening_balance: signed,
  debit: amount,
  credit: amount,
  closing_balance: signed,
  known_unposted_sources: zero,
  integration_coverage: { const: 'explicit_capture_only', type: 'string' },
  regulatory_certification: { const: false, type: 'boolean' },
};
S.AccountingTrialBalance = obj({ ...report, rows: arr(trialRow), meta: { type: 'null' } });
S.AccountingLedger = obj({ ...report, rows: arr(ledgerRow), meta: ref('PageMeta') });
function op(
  method,
  path,
  response,
  permission = 'read',
  body,
  status = 200,
  paged = false,
  description = '',
  query = [],
) {
  const sensitive = ['settings', 'review', 'post', 'reverse', 'close', 'reopen'].includes(
    permission,
  );
  const parameters = [
    ...[...path.matchAll(/\{(\w+)\}/g)].map((m) => ({
      name: m[1],
      in: 'path',
      required: true,
      schema: uuid,
    })),
    ...query,
  ];
  if (paged) parameters.push({ name: 'page', in: 'query', schema: version });
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths[path] ??= {};
  api.paths[path][method] = {
    operationId: (method + path).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Comptabilité'],
    security: [{ sessionCookie: [] }],
    parameters,
    'x-permission': 'accounting.' + permission,
    ...(sensitive ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      ' Session HttpOnly, MFA de gestion et permission accounting.' +
      permission +
      '. ' +
      (method === 'get' ? '' : 'CSRF et Idempotency-Key UUID requis. ') +
      (sensitive ? 'Mot de passe reconfirmé depuis 15 minutes au plus. ' : '') +
      'Livres réels et de démonstration séparés. Aucun plan, calendrier ou schéma comptable prérempli. Aucune certification réglementaire.',
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
    responses: {
      [status]: {
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
      404: { description: 'Livre ou ressource inconnu dans ce livre.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
const base = '/api/v1/operations/accounting';
const b = base + '/books/{book}';
const noteVersion = obj({ version, note: str });
op('get', base + '/books', ref('AccountingBook'), 'read', undefined, 200, true);
op(
  'post',
  base + '/books',
  ref('AccountingBook'),
  'settings',
  obj({ mode: en('demo', 'live'), label: loc }),
  201,
  false,
  'Un seul livre réel ; identité exploitant et régime fiscal renseignés requis.',
);
op(
  'get',
  base + '/source-types',
  obj({
    capture: { type: 'string', const: 'explicit' },
    automatic_posting: { type: 'boolean', const: false },
    types: arr(obj({ type: en(...types), measures: arr(str) })),
  }),
);
op('get', b, ref('AccountingBook'));
op('get', b + '/chart', ref('AccountingChart'));
op(
  'put',
  b + '/chart',
  ref('AccountingChart'),
  'settings',
  obj({
    version: zero,
    reference: str,
    accounts: { type: 'array', minItems: 1, maxItems: 5000, items: ref('AccountingAccountInput') },
  }),
  200,
  false,
  'Import complet versionné ; comptes omis désactivés, identités historiques conservées ; réexamen comptable requis pour le réel.',
);
op(
  'post',
  b + '/chart/review',
  ref('AccountingChart'),
  'review',
  obj({ version, sha256: hash, note: str }),
  200,
  false,
  'Permission du comptable ; le rôle propriétaire seul ne vaut pas examen comptable.',
);
op('get', b + '/chart/revisions', ref('AccountingChartRevision'), 'read', undefined, 200, true);
op('get', b + '/journals', arr(ref('AccountingJournal')));
op(
  'post',
  b + '/journals',
  ref('AccountingJournal'),
  'settings',
  obj({ code: { type: 'string', pattern: '^[A-Z][A-Z0-9]{0,11}$' }, label: loc, active: bool }),
  201,
);
op(
  'put',
  b + '/journals/{id}',
  ref('AccountingJournal'),
  'settings',
  obj({ version, label: loc, active: bool }),
);
const calendarInput = obj({ label: loc, starts_on: date, ends_on: date });
op('get', b + '/years', arr(ref('AccountingCalendar')));
op('post', b + '/years', ref('AccountingCalendar'), 'settings', calendarInput, 201);
op('get', b + '/years/{year}/periods', arr(ref('AccountingCalendar')));
op('post', b + '/years/{year}/periods', ref('AccountingCalendar'), 'settings', calendarInput, 201);
for (const kind of ['years', 'periods'])
  for (const action of ['close', 'reopen'])
    op(
      'post',
      b + '/' + kind + '/{id}/' + action,
      ref('AccountingCalendar'),
      action,
      noteVersion,
      200,
      false,
      action === 'close'
        ? 'Refuse les brouillons et sources connues non comptabilisées dans cet intervalle. Clôture annuelle : couverture continue de périodes clôturées obligatoire ; le recensement des sources est encore explicite, donc cette action ne certifie pas l’exhaustivité métier.'
        : 'Réouverture motivée réservée au propriétaire ; rouvrir l’exercice avant une période.',
    );
op('get', b + '/entries', ref('AccountingEntry'), 'read', undefined, 200, true, '', [
  { name: 'status', in: 'query', schema: en('draft', 'posted', 'cancelled') },
]);
op('post', b + '/entries', ref('AccountingEntry'), 'write', ref('AccountingEntryInput'), 201);
op('get', b + '/entries/{id}', ref('AccountingEntry'));
op(
  'put',
  b + '/entries/{id}',
  ref('AccountingEntry'),
  'write',
  obj({ ...S.AccountingEntryInput.properties, version }),
  200,
  false,
  'Brouillon manuel seulement ; les brouillons de source sont actualisés par prepare.',
);
op(
  'post',
  b + '/entries/{id}/post',
  ref('AccountingEntry'),
  'post',
  obj({ version }),
  200,
  false,
  'Verrou du livre partagé avec clôtures/imports. Totaux entiers positifs équilibrés, deux comptes distincts, période ouverte, chronologie du journal/exercice, pièces contrôlées et version courante obligatoires. Numéro séquentiel transactionnel. Écriture et lignes ensuite protégées par déclencheurs SQL ; correction par contrepassation. En réel, plan et schéma doivent être examinés ; pièce nécessaire aux écritures manuelles.',
);
op('post', b + '/entries/{id}/cancel', ref('AccountingEntry'), 'write', noteVersion);
op(
  'post',
  b + '/entries/{id}/reverse',
  ref('AccountingEntry'),
  'reverse',
  obj({ version, journal_id: uuid, entry_date: date, description: loc, note: str }),
  201,
  false,
  'Contrepassation unique, immédiate, exacte et liée dans une période ouverte ; original inchangé et snapshots historiques conservés.',
);
op(
  'get',
  b + '/entries/{id}/revisions',
  ref('AccountingEntryRevision'),
  'read',
  undefined,
  200,
  true,
);
op('get', b + '/mappings', arr(ref('AccountingMapping')));
op(
  'post',
  b + '/mappings',
  ref('AccountingMapping'),
  'settings',
  ref('AccountingMappingInput'),
  201,
  false,
  'Versionne un schéma explicite mesures/côtés/comptes ; aucune vente, charge, TVA, commission ou allocation d’avance déduite implicitement.',
);
op(
  'post',
  b + '/mappings/{id}/review',
  ref('AccountingMapping'),
  'review',
  obj({ sha256: hash, note: str }),
);
op('get', b + '/sources', ref('AccountingSource'), 'read', undefined, 200, true);
op(
  'post',
  b + '/sources',
  ref('AccountingSource'),
  'write',
  obj({ source_type: en(...types), resource_id: uuid }),
  201,
  false,
  'Capture immuable unique par livre/type/ressource, à partir de faits admissibles : facture/avoir émis, encaissement confirmé hors test, remboursement payé, dette fournisseur reconnue, paiement fournisseur vérifié, coût réel de réception disponible hors consignation/quarantaine/excédent. Aucune écriture automatique.',
);
op(
  'post',
  b + '/sources/{id}/prepare',
  ref('AccountingEntry'),
  'write',
  obj({ entry_version: nil(version), entry_date: nil(date), note: nil(str) }, []),
  200,
  false,
  'Prépare ou actualise le brouillon avec le schéma courant. Version du brouillon existant requise ; motif obligatoire si date comptable différente du fait. Source déjà validée : retourne l’écriture existante. Empreinte et fraîcheur revérifiées avant comptabilisation, un seul posting par source.',
);
const queries = [
  { name: 'year_id', in: 'query', required: true, schema: uuid },
  { name: 'from', in: 'query', required: true, schema: date },
  { name: 'to', in: 'query', required: true, schema: date },
  { name: 'account_id', in: 'query', schema: uuid },
  { name: 'party_id', in: 'query', schema: str },
];
op(
  'get',
  b + '/reports/trial-balance',
  ref('AccountingTrialBalance'),
  'read',
  undefined,
  200,
  false,
  'Balance provisoire sur écritures validées ; soldes d’ouverture à partir du début de l’exercice sélectionné. Montants signés sous forme de chaînes exactes. Snapshot de lecture cohérent. Ne constitue pas un bilan réglementaire.',
  queries,
);
op(
  'get',
  b + '/reports/ledger',
  ref('AccountingLedger'),
  'read',
  undefined,
  200,
  false,
  'Grand livre et auxiliaires via account_id/party_id ; ouverture, totaux et solde sur tout le filtre, détail paginé par 24 lignes. Montants exacts et snapshot cohérent.',
  [...queries, { name: 'page', in: 'query', schema: version }],
);
const upload =
  api.paths['/api/v1/operations/documents'].post.requestBody.content['multipart/form-data'].schema;
upload.properties.purpose.enum = [
  ...new Set([...upload.properties.purpose.enum, 'accounting_evidence']),
];
upload.description =
  'supplier_id requis pour les pièces fournisseur ; interdit pour customer_payment_evidence et accounting_evidence. accounting_evidence exige accounting.write.';
api.info.version = '0.19.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
