import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const S = api.components.schemas;
const ref = (n) => ({ $ref: '#/components/schemas/' + n });
const str = { type: 'string' },
  uuid = { type: 'string', format: 'uuid' },
  date = { type: 'string', format: 'date' },
  dt = { type: 'string', format: 'date-time' };
const integer = { type: 'integer', minimum: 0 },
  version = { type: 'integer', minimum: 1 };
const amount = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' },
  sha = { type: 'string', pattern: '^[a-f0-9]{64}$' };
const note = { type: 'string', minLength: 1, maxLength: 2000 },
  label = {
    type: 'object',
    properties: { fr: note, en: note },
    required: ['fr', 'en'],
    additionalProperties: false,
  };
const nullable = (s) => ({ anyOf: [s, { type: 'null' }] });
const arr = (items) => ({ type: 'array', items });
const enumeration = (...values) => ({ type: 'string', enum: values });
const obj = (properties) => ({
  type: 'object',
  properties,
  required: Object.keys(properties),
  additionalProperties: false,
});
const balance = obj(
  Object.fromEntries(
    ['recorded', 'reversed', 'net_spent', 'remaining', 'committed', 'cancelled_unspent'].map(
      (key) => [key, amount],
    ),
  ),
);
const category = enumeration('provider_fee', 'delivery_cost', 'operating_expense');
S.ExpensePlanInput = obj({
  binding_id: uuid,
  amount,
  category,
  description: label,
  payee: { type: 'string', minLength: 1, maxLength: 200 },
  reference: { type: 'string', minLength: 1, maxLength: 200 },
  planned_on: date,
  due_on: date,
  document_id: uuid,
  note,
});
S.ExpensePlanSnapshot = obj({
  book_id: uuid,
  mode: enumeration('demo', 'live'),
  currency: { type: 'string', const: 'XAF' },
  binding: ref('TreasuryBinding'),
  amount,
  category,
  description: label,
  payee: str,
  reference: str,
  planned_on: date,
  due_on: date,
  note,
  document: obj({ id: uuid, sha256: sha }),
});
S.ExpensePlan = obj({
  id: uuid,
  book_id: uuid,
  treasury_account_id: uuid,
  status: enumeration('pending', 'approved', 'cancelled'),
  version,
  snapshot: ref('ExpensePlanSnapshot'),
  sha256: sha,
  created_by: version,
  approved_on: nullable(date),
  approved_by: nullable(version),
  approval_note: nullable(str),
  cancelled_on: nullable(date),
  cancelled_by: nullable(version),
  cancel_note: nullable(str),
  balance,
});
S.ExpensePaymentInput = obj({
  version,
  binding_id: uuid,
  entry_id: uuid,
  ledger_line_id: version,
  accepted_amount: amount,
  document_id: uuid,
  paid_at: dt,
  note,
});
S.ExpensePaymentSnapshot = obj({
  plan_id: uuid,
  plan_sha256: sha,
  book_id: uuid,
  binding: ref('TreasuryBinding'),
  entry_id: uuid,
  entry_sha256: sha,
  entry_number: str,
  ledger_line_id: version,
  amount,
  posted_on: date,
  paid_at: dt,
  document: obj({ id: uuid, sha256: sha }),
  note,
  verification: { type: 'string', const: 'reviewed_manual_cash_posting' },
});
S.ExpensePayment = obj({
  id: uuid,
  plan_id: uuid,
  book_id: uuid,
  status: enumeration('recorded', 'reversed'),
  version,
  snapshot: ref('ExpensePaymentSnapshot'),
  sha256: sha,
  created_by: version,
  reversal_entry_id: nullable(uuid),
  reversed_on: nullable(date),
  reversed_by: nullable(version),
  reversal_note: nullable(str),
});
S.ExpenseSummary = obj({
  book_id: uuid,
  mode: enumeration('demo', 'live'),
  as_of: date,
  treasury_account_id: nullable(uuid),
  currency: { type: 'string', const: 'XAF' },
  scope: { type: 'string', const: 'expense_plans_only' },
  date_basis: { type: 'string', const: 'posted_cash_entry' },
  plans: integer,
  totals: balance,
  available_funds: { type: 'null' },
  availability_verified: { type: 'boolean', const: false },
  external_payment_verified: { type: 'boolean', const: false },
  provisional: { type: 'boolean', const: true },
});
const base = '/api/v1/operations/accounting/books/{book}';
const query = (name, schema, required = false) => ({ name, in: 'query', schema, required });
function operation(
  method,
  suffix,
  schema,
  { permission = 'read', body, paged = false, params = [], created = false, description = '' } = {},
) {
  const path = base + suffix,
    mutate = method !== 'get';
  api.paths[path] ??= {};
  api.paths[path][method] = {
    operationId: (method + path).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Comptabilité'],
    security: [{ sessionCookie: [] }],
    'x-permission': 'accounting.' + permission,
    ...(mutate ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      ' Session HttpOnly, MFA.' +
      (mutate
        ? ' CSRF, Idempotency-Key UUID et confirmation du mot de passe depuis 15 minutes au plus ; verrou du livre.'
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
      404: { description: 'Ressource hors livre ou hors dépense.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/expenses', ref('ExpensePlan'), {
  paged: true,
  params: [
    query('status', enumeration('pending', 'approved', 'cancelled')),
    query('treasury_account_id', uuid),
  ],
  description:
    'Budgets de dépenses/frais documentés, triés par échéance ; ce sont des engagements, sans création de dette fournisseur ou d’écriture automatique.',
});
operation('get', '/expenses/{id}', ref('ExpensePlan'), {
  description:
    'Preuve du budget, examen, annulation éventuelle et soldes courants dérivés des règlements.',
});
operation('post', '/expenses', ref('ExpensePlan'), {
  permission: 'write',
  created: true,
  body: ref('ExpensePlanInput'),
  description:
    'Prépare un budget immuable de 1 à 10^18 XAF, référence unique par livre, pièce accounting_evidence propre et compte de trésorerie configuré. Date en période ouverte, échéance au moins égale. Aucun montant engagé avant approbation et aucun plan de comptes, taxe ou classification fiscale déduit de la catégorie.',
});
operation('post', '/expenses/{id}/approve', ref('ExpensePlan'), {
  permission: 'review',
  body: obj({ version, sha256: sha, date, note }),
  description:
    'Examen comptable du budget et de sa preuve actuelle, à date en période ouverte. En réel, auteur distinct de l’approbateur. Engage le reliquat au titre de ce budget seulement, sans certification de disponibilité ou comptabilisation automatique.',
});
operation('post', '/expenses/{id}/cancel', ref('ExpensePlan'), {
  permission: 'write',
  body: obj({ version, sha256: sha, date, note, accepted_remaining: amount }),
  description:
    'Annule uniquement le reste non dépensé explicitement accepté ; mismatch concurrent = 409. Conserve les paiements, leur historique et les engagements des dates précédentes. Date non antérieure aux événements du budget, en période ouverte. Ne supprime aucune dette métier.',
});
operation('get', '/expenses/{id}/payments', ref('ExpensePayment'), {
  paged: true,
  description: 'Règlements et contrepassations conservés avec preuves et liens au journal.',
});
operation('post', '/expenses/{id}/payments', ref('ExpensePayment'), {
  permission: 'review',
  created: true,
  body: ref('ExpensePaymentInput'),
  description:
    'Relie une sortie manuelle déjà validée au budget approuvé, sans nouveau posting ni appel de paiement. Même livre/trésorerie, ligne entière et montant exactement accepté dans la limite du reliquat. Écriture sans source métier, non contrepassée et sans autre mouvement de trésorerie ; une écriture ne peut servir qu’une fois, même après correction. Preuve propre, paid_at non futur ; date comptable en période ouverte et non antérieure aux événements du budget. En réel, examinateur distinct de l’auteur du budget et de l’auteur du posting. Une sortie qui dépasse le budget reste au journal et doit être traitée explicitement.',
});
operation('post', '/expenses/{id}/payments/{payment}/reverse', ref('ExpensePayment'), {
  permission: 'reverse',
  body: obj({ version, sha256: sha, journal_id: uuid, entry_date: date, description: label, note }),
  description:
    'Contrepassation exacte liée et mise à jour du règlement atomiques. Calendrier, chronologie et relevés rapprochés restent protégés ; rollback intégral en cas de refus. Réouvre le reliquat à la date de la contrepassation si le budget reste approuvé. Préserve snapshots/empreintes. Le retour de fonds externe n’est pas certifié ; aucun remboursement ou transfert émis. La route ordinaire de contrepassation est bloquée pour le paiement lié et sa contrepassation.',
});
operation('get', '/expense-summary', ref('ExpenseSummary'), {
  params: [query('as_of', date, true), query('treasury_account_id', uuid)],
  description:
    'Totaux exacts cumulés à date des seuls budgets de dépenses : règlements enregistrés, contrepassations, net dépensé, reste engagé ou annulé. Base de dates = journal de trésorerie, distincte du paid_at déclaré. Dates d’approbation/annulation et de correction respectées ; une consultation antérieure ne perd pas le paiement initial. Pas une archive figée ni une prévision globale : achats/dettes fournisseurs, remboursements, retraits et autres fonds engagés ne sont pas ajoutés ; available_funds reste nul et disponibilités/paiements externes non vérifiés.',
});
api.info.version = '0.25.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
