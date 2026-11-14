// Loyalty foundation: no cash balance, no automatic programme and no checkout redemption in this increment.
import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const str = { type: 'string' };
const bool = { type: 'boolean' };
const uuid = { type: 'string', format: 'uuid' };
const dt = { type: 'string', format: 'date-time' };
const version = { type: 'integer', minimum: 1 };
const xaf = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const signed = { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' };
const localized = {
  type: 'object',
  required: ['fr', 'en'],
  properties: { fr: str, en: str },
  additionalProperties: false,
};
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const integer = (minimum, maximum) => ({ type: 'integer', minimum, maximum });
schemas.LoyaltyTier = object({
  key: { type: 'string', pattern: '^[a-z0-9_-]{1,40}$' },
  label: localized,
  threshold_xaf: xaf,
});
const publicRules = {
  earn_step_xaf: xaf,
  earn_points: integer(1, 1000000),
  rounding: { type: 'string', enum: ['floor', 'nearest'] },
  max_points_per_order: integer(1, 1000000000),
  excluded_category_ids: array(uuid),
  validation_delay_days: integer(0, 365),
  points_valid_days: nullable(integer(1, 3650)),
  tier_window_days: integer(1, 3650),
  include_professional: bool,
  tiers: array(ref('LoyaltyTier')),
  conditions: localized,
};
schemas.LoyaltyRules = object(publicRules);
schemas.LoyaltyInternalRules = object({ ...publicRules, budget_points: integer(0, 1000000000000) });
schemas.LoyaltyProgram = object({ version, enabled: bool, rules: nullable(ref('LoyaltyRules')) });
schemas.LoyaltyInternalProgram = object({
  version,
  enabled: bool,
  rules: nullable(ref('LoyaltyInternalRules')),
  committed_points: xaf,
});
schemas.LoyaltyProgression = object({
  eligible_spend: xaf,
  window_days: nullable(integer(1, 3650)),
  tier: nullable(ref('LoyaltyTier')),
  next_tier: nullable(ref('LoyaltyTier')),
});
schemas.LoyaltyCard = object(
  {
    id: uuid,
    card_number: str,
    version,
    status: { type: 'string', enum: ['active', 'suspended'] },
    balance: signed,
    available_points: xaf,
    pending_points: xaf,
    progression: ref('LoyaltyProgression'),
    qr_path: {
      type: 'string',
      pattern: '^/api/v1/loyalty/cards/[a-f0-9]{64}$',
      description: 'Uniquement pour le titulaire ; aucun accès de dépense ou connexion.',
    },
  },
  [
    'id',
    'card_number',
    'version',
    'status',
    'balance',
    'available_points',
    'pending_points',
    'progression',
  ],
);
schemas.LoyaltyAccount = object({
  program: ref('LoyaltyProgram'),
  card: nullable(ref('LoyaltyCard')),
});
schemas.LoyaltyConsultation = object({
  brand: { type: 'string', const: 'KORA' },
  card_suffix: { type: 'string', minLength: 4, maxLength: 4 },
  status: { type: 'string', enum: ['active', 'suspended'] },
  program_enabled: bool,
  tier: nullable(object({ key: str, label: localized })),
  capabilities: {
    type: 'array',
    items: { type: 'string', const: 'consult_only' },
    minItems: 1,
    maxItems: 1,
  },
});
schemas.LoyaltyMovement = object({
  id: integer(1, Number.MAX_SAFE_INTEGER),
  kind: {
    type: 'string',
    enum: ['pending', 'acquired', 'adjustment', 'debt_offset', 'expired', 'cancelled', 'returned'],
  },
  points_delta: signed,
  pending_delta: signed,
  grant_id: nullable(uuid),
  refund_id: nullable(uuid),
  explanation: str,
  created_at: dt,
});
schemas.LoyaltyGrant = object({
  id: uuid,
  order_id: nullable(uuid),
  status: { type: 'string', enum: ['funding_required', 'pending', 'acquired', 'expired'] },
  program_version: version,
  rules: ref('LoyaltyRules'),
  points: xaf,
  reversed_points: xaf,
  eligible_amount: xaf,
  returned_amount: xaf,
  award_base_amount: xaf,
  pre_award_returned_amount: xaf,
  available_at: dt,
  expires_at: nullable(dt),
});
schemas.LoyaltyRevision = object({
  version,
  enabled: bool,
  rules: ref('LoyaltyInternalRules'),
  sha256: { type: 'string', pattern: '^[a-f0-9]{64}$' },
  created_at: dt,
});
schemas.LoyaltyReturnAllocation = object({
  refund_id: uuid,
  items_amount: xaf,
  eligible_amount: xaf,
});

function operation(
  method,
  url,
  {
    response,
    body,
    permission,
    sensitive = false,
    publicQr = false,
    paged = false,
    status = 200,
    description,
  },
) {
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: m[1] === 'token' ? { type: 'string', pattern: '^[a-f0-9]{64}$' } : uuid,
  }));
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  if (paged)
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  parameters.push({ name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } });
  const op = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Fidélité'],
    security: publicQr ? [] : [{ sessionCookie: [] }],
    parameters,
    ...(permission
      ? { 'x-permission': permission }
      : { 'x-resource-owner': publicQr ? 'revocable-capability' : 'account' }),
    ...(sensitive ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      (publicQr
        ? ' QR opaque, révocable, consultation minimale, no-store/noindex ; aucune authentification implicite.'
        : ' Session web HttpOnly et droits serveur requis.') +
      (method === 'get' ? '' : ' CSRF et Idempotency-Key UUID requis.') +
      (permission ? ' MFA de gestion et permission ' + permission + '.' : '') +
      (sensitive ? ' Mot de passe reconfirmé depuis 15 minutes au plus.' : ''),
    responses: {
      [status]: {
        description: 'Succès',
        content: {
          'application/json': {
            schema: paged
              ? object({ data: array(response), meta: ref('PageMeta') })
              : object({ data: response }),
          },
        },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: { description: 'Carte, compte ou remboursement inconnu ou non autorisé.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite de débit atteinte.' },
    },
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
  };
  api.paths['/api/v1/' + url] ??= {};
  api.paths['/api/v1/' + url][method] = op;
}
operation('get', 'loyalty/cards/{token}', {
  response: ref('LoyaltyConsultation'),
  publicQr: true,
  description:
    'Affiche marque, suffixe, statut et nom du niveau ; ni solde, ni téléphone, adresse, identité ou référence de compte. Pas de route financière à cette URL.',
});
const account = 'account/loyalty';
operation('get', account, {
  response: ref('LoyaltyAccount'),
  description:
    'Programme public et carte du titulaire uniquement ; aucune carte ni règle commerciale créée implicitement. Les crédits expirés sont exclus avant même le planificateur.',
});
operation('post', account + '/card', {
  response: ref('LoyaltyCard'),
  status: 201,
  description:
    'Crée une seule carte opaque par compte, sans points fictifs, même avec plusieurs clés de requête.',
});
operation('post', account + '/card/rotate-qr', {
  response: ref('LoyaltyCard'),
  body: object({ version }),
  description: 'Révoque immédiatement le QR précédent et remet un nouveau lien au titulaire.',
});
operation('get', account + '/movements', {
  response: ref('LoyaltyMovement'),
  paged: true,
  description:
    'Registre append-only du titulaire, avec explication FR/EN et deltas signés séparant attente et acquis. Points internes non rechargeables et non retirables ; aucun parrainage payable.',
});
operation('get', account + '/grants', {
  response: ref('LoyaltyGrant'),
  paged: true,
  description:
    'Gains par commande avec conditions/version figées, délai, expiration et statut de financement. Un paiement seul ne suffit pas : livraison vérifiée requise.',
});
const ops = 'operations/loyalty';
operation('get', ops + '/program', {
  response: ref('LoyaltyInternalProgram'),
  permission: 'loyalty.read',
  description:
    'Paramètres réels et budget de points engagé ; programme initialement désactivé, sans barème prérempli.',
});
operation('put', ops + '/program', {
  response: ref('LoyaltyInternalProgram'),
  permission: 'loyalty.settings',
  sensitive: true,
  body: object({ version, enabled: bool, rules: ref('LoyaltyInternalRules') }),
  description:
    'Publie une révision immuable des règles de gain. Livraison exclue, catégories explicites, arrondi/plafond/délai/validité configurables. Le budget ne descend pas sous les engagements ; ni remboursement ni expiration ne le recyclent silencieusement. Les anciens gains gardent leurs conditions ; les niveaux actuels suivent la dépense admissible nette sur période glissante, indépendamment des points restants.',
});
operation('get', ops + '/program/revisions', {
  response: ref('LoyaltyRevision'),
  permission: 'loyalty.read',
  paged: true,
  description: 'Historique immuable des paramètres avec empreinte canonique SHA-256 des règles.',
});
operation('get', ops + '/accounts/{id}', {
  response: ref('LoyaltyCard'),
  permission: 'loyalty.read',
  description: 'Consultation support du solde et de la progression ; QR privé exclu.',
});
operation('get', ops + '/accounts/{id}/movements', {
  response: ref('LoyaltyMovement'),
  permission: 'loyalty.read',
  paged: true,
  description: 'Mouvements nécessaires à la consultation support du compte désigné.',
});
operation('post', ops + '/accounts/{id}/adjustments', {
  response: ref('LoyaltyCard'),
  permission: 'loyalty.adjust',
  sensitive: true,
  body: object({
    points: { ...integer(-1000000000, 1000000000), not: { const: 0 } },
    explanation: localized,
  }),
  description:
    'Correction motivée et auditée, jamais un achat/rechargement de points. Crédit financé sous verrou du budget ; débit réparti sur les crédits existants, solde ajusté négatif possible. Les nouveaux gains compensent la dette sans la faire renaître à expiration ; aucune modification de la progression des dépenses.',
});
operation('put', ops + '/accounts/{id}/status', {
  response: ref('LoyaltyCard'),
  permission: 'loyalty.adjust',
  sensitive: true,
  body: object({ version, status: { type: 'string', enum: ['active', 'suspended'] }, reason: str }),
  description:
    'Suspension/réactivation motivée et versionnée ; historique conservé, aucun nouveau gain promis à une carte suspendue. Les engagements de commandes antérieures restent suivis.',
});
operation('post', ops + '/refunds/{id}/lines', {
  response: ref('LoyaltyReturnAllocation'),
  permission: 'loyalty.adjust',
  sensitive: true,
  body: object({
    lines: array(object({ order_line_id: uuid, quantity: integer(1, 1000000) })),
    explanation: localized,
  }),
  description:
    'Attribue une fois un remboursement déjà versé à ses quantités réellement retournées. Plafonds par ligne et montant remboursé ; livraison et trop-perçu non devinés. Reprise proportionnelle cumulée du gain, attente annulée, absence de double reprise des points expirés ; solde négatif sans blocage de l’argent remboursé. Ruptures fournisseur déjà structurées attribuées automatiquement. Aucun virement déclenché.',
});
api.info.version = '0.17.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
