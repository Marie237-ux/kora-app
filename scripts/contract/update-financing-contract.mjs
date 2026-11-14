import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url),
  api = JSON.parse(readFileSync(file, 'utf8')),
  S = api.components.schemas;
const ref = (n) => ({ $ref: '#/components/schemas/' + n }),
  obj = (p, optional = []) => ({
    type: 'object',
    properties: p,
    required: Object.keys(p).filter((k) => !optional.includes(k)),
    additionalProperties: false,
  }),
  nullable = (s) => ({ anyOf: [s, { type: 'null' }] }),
  arr = (items) => ({ type: 'array', items });
const uuid = { type: 'string', format: 'uuid' },
  str = { type: 'string' },
  date = { type: 'string', format: 'date' },
  dt = { type: 'string', format: 'date-time' },
  sha = { type: 'string', pattern: '^[a-f0-9]{64}$' },
  version = { type: 'integer', minimum: 1 },
  amount = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' },
  note = { type: 'string', minLength: 1, maxLength: 2000 },
  reference = { type: 'string', minLength: 1, maxLength: 200 },
  kind = { type: 'string', enum: ['founder_advance', 'capital_contribution', 'loan'] },
  direction = { type: 'string', enum: ['funding', 'repayment'] },
  document = obj({ id: uuid, sha256: sha });
const balance = obj(
  Object.fromEntries(
    [
      'funded_principal',
      'repaid_principal',
      'funded_unrepaid',
      'outstanding_principal',
      'capital_received',
      'undrawn_principal',
      'pending_funding',
      'pending_review_principal',
      'cancelled_undrawn',
    ].map((k) => [k, amount]),
  ),
);
S.FinancingInput = obj({
  kind,
  reference,
  counterparty: reference,
  account_id: uuid,
  principal: {
    type: 'string',
    pattern: '^[1-9][0-9]{0,18}$',
    description: 'Principal entier XAF de 1 à 10^18, validé côté serveur.',
  },
  effective_on: date,
  due_on: nullable(date),
  document_id: uuid,
  note,
});
S.FinancingSnapshot = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { type: 'string', const: 'XAF' },
  kind,
  reference,
  counterparty: reference,
  account: ref('AccountingAccount'),
  chart_version: version,
  principal: amount,
  effective_on: date,
  due_on: nullable(date),
  document,
  note,
  terms_scope: { type: 'string', const: 'non_revolving_principal_only' },
});
S.Financing = obj({
  id: uuid,
  book_id: uuid,
  status: { type: 'string', enum: ['pending', 'approved', 'cancelled'] },
  version,
  snapshot: ref('FinancingSnapshot'),
  sha256: sha,
  created_by: version,
  approved_on: nullable(date),
  approved_by: nullable(version),
  approval_note: nullable(str),
  cancelled_on: nullable(date),
  cancelled_by: nullable(version),
  cancel_note: nullable(str),
  cancel_document_id: nullable(uuid),
  cancel_document_sha256: nullable(sha),
  balance,
});
S.FinancingMovementInput = obj(
  {
    version,
    direction,
    binding_id: uuid,
    entry_id: uuid,
    cash_line_id: version,
    accepted_amount: amount,
    occurred_at: dt,
    reference,
    document_id: uuid,
    note,
    replaces_id: nullable(uuid),
  },
  ['replaces_id'],
);
S.FinancingMovementSnapshot = obj({
  financing_id: uuid,
  financing_sha256: sha,
  book_id: uuid,
  direction,
  binding: ref('TreasuryBinding'),
  entry_id: uuid,
  entry_sha256: sha,
  entry_number: str,
  cash_line_id: version,
  amount,
  posted_on: date,
  occurred_at: dt,
  reference,
  document,
  note,
  replaces: nullable(document),
  verification: { type: 'string', const: 'reviewed_existing_principal_posting' },
});
S.FinancingMovement = obj({
  id: uuid,
  financing_id: uuid,
  status: { type: 'string', enum: ['recorded', 'reversed'] },
  version,
  revision: version,
  snapshot: ref('FinancingMovementSnapshot'),
  sha256: sha,
  created_by: version,
  reversal_entry_id: nullable(uuid),
  reversed_on: nullable(date),
  reversed_by: nullable(version),
  reversal_note: nullable(str),
  reversal_document_id: nullable(uuid),
  reversal_document_sha256: nullable(sha),
});
S.FinancingSummary = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { type: 'string', const: 'XAF' },
  scope: { type: 'string', const: 'registered_principal_financing_only' },
  state_basis: { type: 'string', const: 'current_operational_snapshot' },
  agreement_count: { type: 'integer', minimum: 0 },
  totals: balance,
  by_kind: obj(Object.fromEntries(kind.enum.map((k) => [k, balance]))),
  repayment_schedule: arr(
    obj({
      financing_id: uuid,
      kind: { type: 'string', enum: ['founder_advance', 'loan'] },
      due_on: nullable(date),
      principal: amount,
    }),
  ),
  available_funds: { type: 'null' },
  provisional: { type: 'boolean', const: true },
});
S.FinancingForecast = structuredClone(S.FinancingSummary);
S.FinancingForecast.properties.forecast = obj({
  ...Object.fromEntries(
    ['before_window', 'within_window', 'after_window', 'undated', 'past_due'].map((k) => [
      k,
      amount,
    ]),
  ),
  days: arr(obj({ due_on: date, amount })),
});
S.FinancingForecast.required.push('forecast');
const base = '/api/v1/operations/accounting/books/{book}/treasury';
function operation(
  method,
  suffix,
  schema,
  { permission = 'read', body, paged = false, description = '', filters = [] } = {},
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
      ' Session HttpOnly et MFA.' +
      (mutate
        ? ' CSRF, Idempotency-Key UUID et mot de passe confirmé depuis 900 secondes au plus ; verrou partagé du livre.'
        : ''),
    parameters: [
      { name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } },
      ...[...path.matchAll(/\{(\w+)\}/g)].map((m) => ({
        name: m[1],
        in: 'path',
        required: true,
        schema: uuid,
      })),
      ...filters,
      ...(paged ? [{ name: 'page', in: 'query', schema: version }] : []),
      ...(mutate ? [{ $ref: '#/components/parameters/IdempotencyKey' }] : []),
    ],
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
    responses: {
      [method === 'post' && (suffix === '/financings' || suffix.endsWith('/movements'))
        ? 201
        : 200]: {
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
      404: { description: 'Ressource hors livre/financement.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/financings', ref('Financing'), {
  paged: true,
  filters: [
    { name: 'kind', in: 'query', schema: kind },
    {
      name: 'status',
      in: 'query',
      schema: { type: 'string', enum: ['pending', 'approved', 'cancelled'] },
    },
  ],
  description:
    'Accords de financement documentés, références internes opaques uniques par livre et termes immuables. Filtrage par nature/état.',
});
operation('get', '/financings/{id}', ref('Financing'), {
  description:
    'Termes, preuve, examen, annulation du non-versé et soldes exacts. funded_unrepaid inclut aussi les apports ; seul outstanding_principal désigne une dette de principal remboursable. Un remboursement ne reconstitue jamais la capacité de financement.',
});
operation('post', '/financings', ref('Financing'), {
  permission: 'write',
  body: ref('FinancingInput'),
  description:
    'Déclare une avance du fondateur, un apport ou un emprunt, sans inventer une entrée de fonds, un bénéfice ou une dette déjà financée. Contrepartie explicitement choisie : compte actif/postable sans auxiliaire, liability pour avance/emprunt, equity pour apport, jamais un compte de trésorerie. Plan examiné en réel. Principal 1 à 10^18 ; date effective non future, échéance optionnelle au moins égale à celle-ci et obligatoirement nulle pour un apport. Référence interne réservée définitivement, preuve privée propre et motif. Conditions de principal non renouvelable avec échéance unique ou non datée ; intérêts, frais, échéanciers enrichis et opérations juridiques de capital hors automatisation.',
});
operation('post', '/financings/{id}/approve', ref('Financing'), {
  permission: 'review',
  body: obj({ version, sha256: sha, date, note }),
  description:
    'Examine les termes, la preuve propre et la contrepartie actuelle. Date effective d’approbation non future, au moins égale au début de l’accord et en période ouverte. Auteur distinct du créateur en réel. Aucun fonds ni posting créé.',
});
operation('post', '/financings/{id}/cancel', ref('Financing'), {
  permission: 'review',
  body: obj({ version, sha256: sha, date, note, document_id: uuid, accepted_undrawn: amount }),
  description:
    'Clôt uniquement le financement non reçu, exactement accepté ; 409 si un versement concurrent change ce reste. Dates non futures/non décroissantes et période ouverte, preuve/motif, auteur distinct en réel. Les fonds reçus et leur dette restent conservés et remboursables ; un solde entièrement versé n’est pas annulable par cette route.',
});
operation('get', '/financings/{id}/movements', ref('FinancingMovement'), {
  paged: true,
  description:
    'Versements et remboursements de principal partiels, corrections et références de remplacement conservés, pagination par 24.',
});
operation('post', '/financings/{id}/movements', ref('FinancingMovement'), {
  permission: 'review',
  body: ref('FinancingMovementInput'),
  description:
    'Relie une écriture manuelle validée entière de deux lignes, sans seconde écriture : trésorerie sur actif à entrées au débit et contrepartie de dette/capitaux propres prévue. funding : débit trésorerie, crédit contrepartie ; repayment inverse. Principal seul, sans frais/intérêts, montants exactement acceptés, plafond non-versé ou dette réellement financée. Aucun remboursement ordinaire d’apport. Pas de source métier, contrepassation, autre trésorerie, dépense/retrait ou réutilisation d’écriture, même après correction. En réel, examinateur distinct du créateur de l’accord et de l’auteur du posting. Dates non futures/non décroissantes en période ouverte, instant réel au moins au jour local de l’accord et au plus au jour comptable. Référence opaque unique active par livre/compte de trésorerie ; réutilisation seulement avec dernier mouvement contrepassé explicitement nommé, même direction et date au moins égale à sa correction. Après annulation, un versement ne peut que remplacer celui de ce même accord, sans hausse de montant. Remboursement de principal reçu autorisé après annulation. Preuve propre et snapshots immuables, aucun transfert externe émis.',
});
operation('post', '/financings/{id}/movements/{movement}/reverse', ref('FinancingMovement'), {
  permission: 'reverse',
  body: obj({
    version,
    sha256: sha,
    journal_id: uuid,
    entry_date: date,
    description: obj({ fr: note, en: note }),
    document_id: uuid,
    note,
  }),
  description:
    'Contrepassation exacte liée et correction de mouvement atomiques, avec nouvelle preuve et motif. Corriger d’abord les remboursements si retirer le versement ferait descendre le principal reçu sous le principal remboursé. Annuler tout rapprochement actif de la ligne de trésorerie, après réouverture du relevé si nécessaire. Dates non futures/non décroissantes et calendrier ouvert. La correction d’un versement après annulation ne réactive pas la part non versée ; la correction d’un remboursement rétablit seulement la dette. Gardes domaine/SQL contre les contrepassations ordinaires et la réutilisation en dépense. Aucun retour de fonds externe émis ou certifié.',
});
operation('get', '/financing-summary', ref('FinancingSummary'), {
  description:
    'Totaux exacts de tous les accords de ce livre, indépendants de la pagination, par nature : reçu, remboursé, dette de principal, apports et non-versé. Le remboursement n’ouvre aucune nouvelle capacité. Échéance unique ou absence de date sur le principal réellement dû. État courant connu, as_of non vide refusé, sans archive ni disponibilité de fonds extrapolée.',
});
S.TreasuryForecast.properties.financing = ref('FinancingForecast');
if (!S.TreasuryForecast.required.includes('financing'))
  S.TreasuryForecast.required.push('financing');
S.TreasuryForecast.properties.scope.enum = [
  'known_operational_commitments',
  'book_commitments_only',
];
S.TreasuryForecast.properties.days.items.properties.financing = amount;
if (!S.TreasuryForecast.properties.days.items.required.includes('financing'))
  S.TreasuryForecast.properties.days.items.required.push('financing');
const forecast = api.paths[base + '/forecast'].get;
forecast.description = forecast.description
  .replace(
    'Démonstration : dépenses et comptes liés à ce livre seulement, sources globales null.',
    'Démonstration : dépenses, financements et comptes liés à ce livre seulement, sources globales null.',
  )
  .replace(
    'dépenses approuvées de ce livre ;',
    'dépenses approuvées et principal de financement effectivement reçu encore dû de ce livre ;',
  );
if (!forecast.description.includes('Principal de financement réellement reçu'))
  forecast.description +=
    ' Principal de financement réellement reçu et non remboursé intégré à financing, aux échéances et known_outflows ; apports, financements seulement prévus et parties annulées non versées ne créent aucune dette de remboursement. Intérêts/frais restent des postes distincts à documenter. En démonstration, scope=book_commitments_only réunit dépenses et financement du livre, sans sources globales. Les flux de principal ne deviennent pas du chiffre d’affaires ou des fonds disponibles.';
api.info.version = '0.29.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
