import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url),
  api = JSON.parse(readFileSync(file, 'utf8')),
  S = api.components.schemas;
const ref = (n) => ({ $ref: '#/components/schemas/' + n }),
  obj = (p) => ({
    type: 'object',
    properties: p,
    required: Object.keys(p),
    additionalProperties: false,
  }),
  nullable = (s) => ({ anyOf: [s, { type: 'null' }] }),
  arr = (items) => ({ type: 'array', items });
const uuid = { type: 'string', format: 'uuid' },
  str = { type: 'string' },
  dt = { type: 'string', format: 'date-time' },
  sha = { type: 'string', pattern: '^[a-f0-9]{64}$' },
  version = { type: 'integer', minimum: 1 },
  sequence = { type: 'integer', minimum: 0 },
  money = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' },
  signed = { type: 'string', pattern: '^(0|-?[1-9][0-9]*)$' },
  note = { type: 'string', minLength: 1, maxLength: 2000 },
  reference = { type: 'string', minLength: 1, maxLength: 200 },
  bool = { type: 'boolean' },
  no = { type: 'boolean', const: false };
S.BalanceObservationInput = obj({
  binding_id: uuid,
  sequence,
  reference,
  reported_balance: signed,
  available_funds: nullable(money),
  withdrawable_funds: nullable(money),
  observed_at: dt,
  expires_at: dt,
  document_id: uuid,
  note,
});
S.BalanceObservationSnapshot = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { type: 'string', const: 'XAF' },
  binding: ref('TreasuryBinding'),
  reference,
  reported_balance: signed,
  available_funds: nullable(money),
  withdrawable_funds: nullable(money),
  observed_at: dt,
  expires_at: dt,
  document: obj({ id: uuid, sha256: sha }),
  note,
  verification_method: { type: 'string', const: 'manual_evidence_review' },
});
S.BalanceObservation = obj({
  id: uuid,
  book_id: uuid,
  treasury_account_id: uuid,
  sequence: version,
  status: { type: 'string', enum: ['pending', 'approved', 'void'] },
  version,
  snapshot: ref('BalanceObservationSnapshot'),
  sha256: sha,
  created_by: version,
  reviewed_by: nullable(version),
  reviewed_at: nullable(dt),
  review_note: nullable(str),
  voided_by: nullable(version),
  voided_at: nullable(dt),
  void_note: nullable(str),
  void_document_id: nullable(uuid),
  void_document_sha256: nullable(sha),
});
S.BalanceObservationSummary = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['demo', 'live'] },
  currency: { type: 'string', const: 'XAF' },
  scope: { type: 'string', enum: ['active_treasury_accounts', 'active_bound_accounts'] },
  verification_method: { type: 'string', const: 'manual_evidence_review' },
  as_of: nullable(dt),
  all_accounts_reviewed: bool,
  same_instant: bool,
  accounts: arr(
    obj({
      treasury_account_id: uuid,
      code: str,
      kind: { type: 'string', enum: ['cash', 'bank', 'mobile_money', 'monetbil'] },
      state: {
        type: 'string',
        enum: [
          'missing',
          'pending_review',
          'void',
          'expired',
          'configuration_changed',
          'evidence_unavailable',
          'reviewed_fresh',
        ],
      },
      observation: nullable(ref('BalanceObservation')),
    }),
  ),
  totals: obj({
    available_funds: nullable(money),
    provider_held_funds: nullable(money),
    withdrawable_funds: nullable(money),
  }),
  current_availability_verified: no,
  provisional: { type: 'boolean', const: true },
});
const base = '/api/v1/operations/accounting/books/{book}/treasury';
function operation(
  method,
  suffix,
  schema,
  { permission = 'read', body, paged = false, description = '' } = {},
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
        ? ' CSRF, Idempotency-Key UUID et mot de passe confirmé depuis 900 secondes au plus ; verrou du livre partagé.'
        : ''),
    parameters: [
      { name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } },
      ...[...path.matchAll(/\{(\w+)\}/g)].map((m) => ({
        name: m[1],
        in: 'path',
        required: true,
        schema: uuid,
      })),
      ...(paged
        ? [
            { name: 'page', in: 'query', schema: version },
            { name: 'treasury_account_id', in: 'query', schema: uuid },
          ]
        : []),
      ...(mutate ? [{ $ref: '#/components/parameters/IdempotencyKey' }] : []),
    ],
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
    responses: {
      [method === 'post' && suffix === '/balance-observations' ? 201 : 200]: {
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
      404: { description: 'Ressource hors livre.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/balance-observations', ref('BalanceObservation'), {
  paged: true,
  description:
    'Historique paginé par 24 des constats externes, y compris corrigés ou expirés ; filtre par compte de trésorerie.',
});
operation('get', '/balance-observations/{id}', ref('BalanceObservation'), {
  description:
    'Constat immuable et historique de son examen/correction. Le statut approved ne garantit pas que sa preuve soit encore exploitable ; consulter le résumé courant.',
});
operation('post', '/balance-observations', ref('BalanceObservation'), {
  permission: 'write',
  body: ref('BalanceObservationInput'),
  description:
    'Consigne une preuve externe déjà obtenue, sans appel prestataire ni écriture comptable. Correspondance actuelle/examinée ; sequence accepte exactement le dernier numéro de ce compte, zéro initialement. Solde signé borné à ±10^18 XAF. Pour Monetbil : solde non négatif, available_funds=null et withdrawable_funds entre zéro et le solde. Pour les autres comptes : withdrawable_funds=null et available_funds propre, hors découvert/crédit, entre zéro et le solde positif (zéro si négatif). Instant UTC non futur et non antérieur au constat précédent ; expiration explicitement choisie, après observation et au plus 24 heures plus tard. Les instants sont normalisés à la seconde. Pièce accounting_evidence propre et motif. Le nouveau constat, même non examiné, empêche tout repli sur un ancien solde.',
});
operation('post', '/balance-observations/{id}/approve', ref('BalanceObservation'), {
  permission: 'review',
  body: obj({ version, sha256: sha, note }),
  description:
    'Examine uniquement le dernier constat encore non expiré, sa preuve actuelle et les correspondances en vigueur. En réel, examinateur distinct du créateur. Examen manuel des justificatifs, sans certification du prestataire ou du solde actuel.',
});
operation('post', '/balance-observations/{id}/void', ref('BalanceObservation'), {
  permission: 'review',
  body: obj({ version, sha256: sha, note, document_id: uuid }),
  description:
    'Corrige un constat en attente ou approuvé avec nouvelle preuve propre et motif, sans modifier le snapshot initial. En réel, auteur distinct du créateur. Aucun retour automatique à un solde antérieur ; saisir un nouveau constat avec la dernière séquence.',
});
operation('get', '/balance-observation-summary', ref('BalanceObservationSummary'), {
  description:
    'Dernier constat par compte actif : tous les comptes actifs en réel, seuls les comptes liés au livre en démonstration. Zéro compte ne vaut pas couverture complète. Configuration, preuve propre et expiration sont réévaluées. Aucun repli sur un ancien constat après attente, correction ou expiration. Totaux exacts seulement si tous les comptes ont une preuve examinée encore valide ET portent strictement le même observed_at ; sinon totaux et as_of null. Montants propres disponibles distincts des fonds détenus chez Monetbil et de leur sous-ensemble retirable. Les valeurs sont celles de cet instant explicite, sans extrapolation après mouvements ultérieurs, sans compensation des retraits/engagements ni free_cash. Aucun as_of non vide accepté, aucune certification automatisée ou actuelle.',
});
S.TreasuryForecast.properties.balance_observations = ref('BalanceObservationSummary');
if (!S.TreasuryForecast.required.includes('balance_observations'))
  S.TreasuryForecast.required.push('balance_observations');
const forecast = api.paths[base + '/forecast'].get;
if (!forecast.description.includes('Constats externes datés'))
  forecast.description +=
    ' Constats externes datés et examinés dans balance_observations, avec expiration explicite et agrégats uniquement sur un instant commun. Ces justificatifs ne certifient pas le solde actuel : les champs cash.available_funds/provider_held_funds/withdrawable_funds et free_cash restent inconnus.';
api.info.version = '0.28.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
