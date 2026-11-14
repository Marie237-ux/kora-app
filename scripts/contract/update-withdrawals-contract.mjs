import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url),
  api = JSON.parse(readFileSync(file, 'utf8')),
  S = api.components.schemas;
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const obj = (properties, optional = []) => ({
  type: 'object',
  properties,
  required: Object.keys(properties).filter((k) => !optional.includes(k)),
  additionalProperties: false,
});
const arr = (items) => ({ type: 'array', items }),
  nullable = (s) => ({ anyOf: [s, { type: 'null' }] });
const str = { type: 'string' },
  uuid = { type: 'string', format: 'uuid' },
  date = { type: 'string', format: 'date' },
  dt = { type: 'string', format: 'date-time' },
  sha = { type: 'string', pattern: '^[a-f0-9]{64}$' },
  version = { type: 'integer', minimum: 1 },
  count = { type: 'integer', minimum: 0 };
const amount = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' },
  note = { type: 'string', minLength: 1, maxLength: 2000 },
  reference = { type: 'string', minLength: 1, maxLength: 200 };
const label = obj({ fr: note, en: note }),
  document = obj({ id: uuid, sha256: sha }),
  predecessor = nullable(document);
const balance = obj(
  Object.fromEntries(
    [
      'recorded_gross',
      'reversed_gross',
      'received_gross',
      'received_net',
      'fees_paid',
      'reconciled_gross',
      'remaining_gross',
      'pending_gross',
      'pending_review_gross',
      'approved_pending_gross',
      'cancelled_gross',
      'unreconciled_received_gross',
    ].map((k) => [k, amount]),
  ),
);
S.WithdrawalInput = obj(
  {
    source_binding_id: uuid,
    destination_binding_id: uuid,
    reference,
    amount: {
      type: 'string',
      pattern: '^[1-9][0-9]{0,18}$',
      description: 'De 1 à 10^18 XAF, vérifié côté serveur.',
    },
    requested_on: date,
    requested_at: dt,
    document_id: uuid,
    note,
    replaces_id: nullable(uuid),
  },
  ['replaces_id'],
);
S.WithdrawalSnapshot = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { type: 'string', const: 'XAF' },
  source: ref('TreasuryBinding'),
  destination: ref('TreasuryBinding'),
  reference,
  amount,
  requested_on: date,
  requested_at: dt,
  document,
  note,
  replaces: predecessor,
  verification: { type: 'string', const: 'manual_request_evidence' },
});
S.Withdrawal = obj({
  id: uuid,
  book_id: uuid,
  status: { type: 'string', enum: ['pending', 'approved', 'cancelled', 'void'] },
  version,
  revision: version,
  snapshot: ref('WithdrawalSnapshot'),
  sha256: sha,
  created_by: version,
  approved_on: nullable(date),
  approved_by: nullable(version),
  approval_note: nullable(str),
  closed_on: nullable(date),
  closed_by: nullable(version),
  close_note: nullable(str),
  close_document_id: nullable(uuid),
  close_document_sha256: nullable(sha),
  balance,
});
S.WithdrawalReceiptInput = obj(
  {
    version,
    source_binding_id: uuid,
    destination_binding_id: uuid,
    entry_id: uuid,
    source_line_id: version,
    destination_line_id: version,
    accepted_gross: amount,
    accepted_net: amount,
    accepted_fee: amount,
    received_at: dt,
    reference,
    document_id: uuid,
    note,
    replaces_id: nullable(uuid),
  },
  ['replaces_id'],
);
S.WithdrawalReceiptSnapshot = obj({
  withdrawal_id: uuid,
  withdrawal_sha256: sha,
  book_id: uuid,
  source: ref('TreasuryBinding'),
  destination: ref('TreasuryBinding'),
  entry_id: uuid,
  entry_sha256: sha,
  entry_number: str,
  source_line_id: version,
  destination_line_id: version,
  gross: amount,
  net: amount,
  fee: amount,
  posted_on: date,
  received_at: dt,
  reference,
  document,
  note,
  replaces: predecessor,
  verification: { type: 'string', const: 'reviewed_manual_transfer_posting' },
});
const match = nullable(
  obj({
    match_id: uuid,
    statement_id: uuid,
    status: { type: 'string', enum: ['open', 'closed'] },
    ends_on: date,
  }),
);
S.WithdrawalReceipt = obj({
  id: uuid,
  withdrawal_id: uuid,
  status: { type: 'string', enum: ['recorded', 'reversed'] },
  version,
  revision: version,
  snapshot: ref('WithdrawalReceiptSnapshot'),
  sha256: sha,
  created_by: version,
  reversal_entry_id: nullable(uuid),
  reversed_on: nullable(date),
  reversed_by: nullable(version),
  reversal_note: nullable(str),
  reversal_document_id: nullable(uuid),
  reversal_document_sha256: nullable(sha),
  reconciliation: obj({
    source: match,
    destination: match,
    both_closed: { type: 'boolean' },
    external_availability_verified: { type: 'boolean', const: false },
  }),
});
S.WithdrawalSummary = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { type: 'string', const: 'XAF' },
  scope: { type: 'string', const: 'registered_withdrawals_only' },
  state_basis: { type: 'string', const: 'current_operational_snapshot' },
  request_count: count,
  totals: balance,
  available_funds: { type: 'null' },
  availability_verified: { type: 'boolean', const: false },
  provisional: { type: 'boolean', const: true },
});
const base = '/api/v1/operations/accounting/books/{book}/treasury';
function operation(
  method,
  suffix,
  schema,
  { permission = 'read', body, paged = false, params = [], description = '' } = {},
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
        ? ' CSRF, Idempotency-Key UUID, mot de passe confirmé depuis 900 secondes au plus ; verrou du livre partagé.'
        : ''),
    parameters: [
      { name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } },
      ...[...path.matchAll(/\{(\w+)\}/g)].map((m) => ({
        name: m[1],
        in: 'path',
        required: true,
        schema: uuid,
      })),
      ...params,
      ...(paged ? [{ name: 'page', in: 'query', schema: version }] : []),
      ...(mutate ? [{ $ref: '#/components/parameters/IdempotencyKey' }] : []),
    ],
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
    responses: {
      [method === 'post' && (suffix === '/withdrawals' || suffix.endsWith('/receipts'))
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
      404: { description: 'Ressource hors livre ou hors retrait.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/withdrawals', ref('Withdrawal'), {
  paged: true,
  params: [
    {
      name: 'status',
      in: 'query',
      schema: { type: 'string', enum: ['pending', 'approved', 'cancelled', 'void'] },
    },
    ...['source_account_id', 'destination_account_id'].map((name) => ({
      name,
      in: 'query',
      schema: uuid,
    })),
  ],
  description:
    'Demandes de retrait déclarées manuellement avec preuve, triées par date décroissante. Références prestataire opaques, sensibles à la casse, et historique de remplacement conservé.',
});
operation('get', '/withdrawals/{id}', ref('Withdrawal'), {
  description:
    'Détail, preuves, examen et soldes exacts du retrait ; remaining_gross est théorique même après annulation/erreur, pending_gross seul indique la part encore attendue.',
});
operation('post', '/withdrawals', ref('Withdrawal'), {
  permission: 'write',
  body: ref('WithdrawalInput'),
  description:
    'Enregistre une demande déjà effectuée auprès du prestataire, sans l’envoyer ni certifier des fonds retirables. Source de type monetbil et destinataire distinct bank/mobile_money/cash, correspondances actuelles/examinées de même orientation. Montant 1 à 10^18 ; date comptable non future en période ouverte et au moins égale au jour local du requested_at non futur. Preuve accounting_evidence propre. Référence unique active par livre/source ; réutilisation seulement après void, avec replaces_id du dernier enregistrement et date non antérieure à sa correction. Toute demande déclarée non clôturée, même en attente d’examen, reste dans les retraits attendus.',
});
operation('post', '/withdrawals/{id}/approve', ref('Withdrawal'), {
  permission: 'review',
  body: obj({ version, sha256: sha, date, note }),
  description:
    'Examine la demande, ses preuves et correspondances actuelles. Auteur distinct du créateur en réel. Ne marque aucun versement reçu et n’exécute aucune demande prestataire.',
});
for (const action of ['cancel', 'void'])
  operation('post', '/withdrawals/{id}/' + action, ref('Withdrawal'), {
    permission: 'review',
    body: obj({ version, sha256: sha, date, note, document_id: uuid, accepted_remaining: amount }),
    description:
      action === 'cancel'
        ? 'Consigne une annulation externe documentée du seul reliquat exact accepté, date non future et chronologie/période ouvertes. Garde les sommes déjà reçues et réserve définitivement la référence ; conflit de reliquat concurrent = 409. Auteur distinct du créateur en réel.'
        : 'Corrige une saisie erronée, sans prétendre annuler un transfert externe. Toutes les réceptions actives doivent déjà être contrepassées ; reliquat exact, preuve et motif, auteur distinct du créateur en réel. Libère seulement la référence pour un nouvel enregistrement qui nomme ce prédécesseur ; historique et journal conservés.',
  });
operation('get', '/withdrawals/{id}/receipts', ref('WithdrawalReceipt'), {
  paged: true,
  description:
    'Réceptions partielles et corrections conservées. Les deux côtés du rapprochement donnent séparément match et relevé ; both_closed exige deux rapprochements actifs sur relevés clôturés. Aucun solde disponible certifié.',
});
operation('post', '/withdrawals/{id}/receipts', ref('WithdrawalReceipt'), {
  permission: 'review',
  body: ref('WithdrawalReceiptInput'),
  description:
    'Relie une écriture manuelle validée entière à une demande examinée, sans seconde écriture. Sortie prestataire brute, entrée destinataire nette et seuls frais non trésorerie de même orientation : brut = net + frais, montants exactement acceptés, plafond du reliquat. Aucun autre compte de trésorerie, source métier, contrepassation ou écriture déjà réutilisée, même corrigée. En réel, examinateur distinct du créateur de demande et du posting. Date non future en période ouverte, received_at non futur après requested_at et au plus tard au jour comptable du versement. Référence de versement unique active par livre/source ; après correction, replaces_id du dernier reçu contrepassé requis. Preuve privée propre, snapshots immuables ; aucun appel prestataire ni vérification externe automatisée.',
});
operation('post', '/withdrawals/{id}/receipts/{receipt}/reverse', ref('WithdrawalReceipt'), {
  permission: 'reverse',
  body: obj({
    version,
    sha256: sha,
    journal_id: uuid,
    entry_date: date,
    description: label,
    document_id: uuid,
    note,
  }),
  description:
    'Contrepassation exacte liée et correction du reçu atomiques avec nouvelle preuve figée. Annuler d’abord les rapprochements actifs des deux côtés, après réouverture si nécessaire. Calendrier et chronologie restent contrôlés. Ne réactive pas une demande annulée ; aucune restitution externe émise ou certifiée. L’écriture initiale ne peut jamais être réutilisée, mais sa référence de versement peut être remplacée explicitement. Les contrepassations ordinaires de l’écriture et de sa correction sont bloquées par domaine/SQL.',
});
operation('get', '/withdrawal-summary', ref('WithdrawalSummary'), {
  description:
    'Totaux exacts de tous les retraits déclarés de ce livre, indépendants de la pagination : brut reçu net des corrections, net destinataire, frais réels, reliquats en attente d’examen/approuvés/annulés et part rapprochée des deux côtés. État courant, as_of non vide refusé ; aucune archive historique figée ni disponibilité extrapolée.',
});
api.paths[base + '/withdrawals/{id}/receipts'].post.description +=
  ' Après annulation, seul le remplacement explicite du dernier reçu contrepassé de cette même demande est admis, pour un brut au plus égal au précédent. Aucun nouveau versement sur le reliquat annulé.';
S.TreasuryForecast.properties.withdrawals = ref('WithdrawalSummary');
if (!S.TreasuryForecast.required.includes('withdrawals'))
  S.TreasuryForecast.required.push('withdrawals');
S.TreasuryForecast.properties.cash.properties.pending_withdrawals = {
  ...amount,
  description:
    'Brut restant des seuls retraits déclarés de ce livre, y compris en attente d’examen. Aucun inventaire automatique des demandes externes.',
};
S.TreasuryForecast.properties.coverage.properties.missing.items.enum = [
  'verified_external_balances',
  'unregistered_external_withdrawals',
  'other_commitments',
];
const forecast = api.paths[base + '/forecast'].get;
forecast.description = forecast.description.replace(
  'Fonds disponibles/détenus/retirables, retraits en attente et free_cash restent null',
  'Fonds disponibles/détenus/retirables et free_cash restent null',
);
if (!forecast.description.includes('Retraits manuels déclarés intégrés'))
  forecast.description +=
    ' Retraits manuels déclarés intégrés au champ withdrawals et cash.pending_withdrawals, en réel comme en démonstration sur le livre sélectionné. Le transfert interne et ses frais déjà comptabilisés ne sont pas ajoutés aux nouveaux engagements known_outflows ; aucune double dépense ni libération d’argent disponible. Demandes externes non déclarées et fonds disponibles restent non vérifiés.';
api.info.version = '0.27.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
