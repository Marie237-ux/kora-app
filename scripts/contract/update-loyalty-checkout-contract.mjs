import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const S = api.components.schemas;
const ref = (n) => ({ $ref: '#/components/schemas/' + n });
const str = { type: 'string' };
const bool = { type: 'boolean' };
const uuid = { type: 'string', format: 'uuid' };
const xaf = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const signed = { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' };
const dt = { type: 'string', format: 'date-time' };
const version = { type: 'integer', minimum: 1 };
const nullable = (s) => ({ anyOf: [s, { type: 'null' }] });
const arr = (items) => ({ type: 'array', items });
const obj = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const localized = obj({ fr: str, en: str });
const rules = {
  points_per_unit: { type: 'integer', minimum: 1, maximum: 1000000000 },
  value_per_unit_xaf: xaf,
  max_discount_basis_points: { type: 'integer', minimum: 1, maximum: 10000 },
  minimum_payable_xaf: xaf,
  excluded_category_ids: arr(uuid),
  restore_points_on_refund: bool,
  restore_valid_days: nullable({ type: 'integer', minimum: 1, maximum: 3650 }),
  combine_delivery_promotions: bool,
  allow_negative_margin: bool,
  conditions: localized,
};
S.LoyaltyRedemptionRules = obj(rules);
S.LoyaltyRedemptionInternalRules = obj({ ...rules, budget_xaf: xaf, loss_reason: nullable(str) });
S.LoyaltyRedemptionPolicy = obj({
  version,
  enabled: bool,
  rules: nullable(ref('LoyaltyRedemptionRules')),
});
S.LoyaltyRedemptionInternalPolicy = obj({
  version,
  enabled: bool,
  rules: nullable(ref('LoyaltyRedemptionInternalRules')),
  used_budget: xaf,
  reserved_budget: xaf,
});
S.LoyaltyRedemptionRevision = obj({
  version,
  enabled: bool,
  rules: ref('LoyaltyRedemptionInternalRules'),
  sha256: { type: 'string', pattern: '^[a-f0-9]{64}$' },
  created_at: dt,
});
S.LoyaltyCheckoutQuote = obj({
  id: uuid,
  policy_version: version,
  points: xaf,
  discount: xaf,
  items_total: xaf,
  delivery_total: xaf,
  total: xaf,
  rules: ref('LoyaltyRedemptionRules'),
  expires_at: dt,
  lines: arr(obj({ cart_line_id: uuid, discount: xaf, points: xaf })),
  delivery_promotion: nullable(obj({ name: localized, discount: xaf })),
  points_reserved: { type: 'boolean', const: false },
});
S.LoyaltyAppliedDiscount = obj({
  status: {
    type: 'string',
    enum: ['held', 'released', 'consumed', 'review_required', 'covered', 'review_resolved'],
  },
  points: xaf,
  discount: xaf,
  consumed_points: xaf,
  restored_points: xaf,
  rules: ref('LoyaltyRedemptionRules'),
});
function add(schema, properties) {
  Object.assign(schema.properties, properties);
  schema.required = [...new Set([...(schema.required ?? []), ...Object.keys(properties)])];
}
add(S.Order, { loyalty_discount: xaf, loyalty: nullable(ref('LoyaltyAppliedDiscount')) });
add(S.Order.properties.lines.items, { loyalty_discount: xaf, loyalty_points_used: xaf });
add(S.LoyaltyCard, { reserved_points: xaf });
add(S.LoyaltyMovement, { reserved_delta: signed, redemption_id: nullable(uuid) });
S.LoyaltyMovement.properties.kind.enum = [
  ...new Set([
    ...S.LoyaltyMovement.properties.kind.enum,
    'reserved',
    'used',
    'released',
    'restored',
  ]),
];
add(S.LoyaltyGrant, {
  redemption_id: nullable(uuid),
  source: { type: 'string', enum: ['order', 'adjustment', 'restored'] },
  restoration: nullable(
    obj({
      order_id: uuid,
      conditions: localized,
      points_valid_days: nullable({ type: 'integer', minimum: 1, maximum: 3650 }),
    }),
  ),
});
add(S.LoyaltyReturnAllocation, { restored_points: xaf });
api.paths['/api/v1/orders'].post.requestBody.content[
  'application/json'
].schema.properties.loyalty_quote_id = nullable(uuid);
const orderDescription =
  ' Optionnel : loyalty_quote_id désigne un devis serveur privé, encore valable, pour ce panier et ce devis de livraison. Le total net exact est accepté ; points et budget sont réservés atomiquement. Aucun montant de remise envoyé par le navigateur ne fait autorité.';
if (!api.paths['/api/v1/orders'].post.description.includes('loyalty_quote_id'))
  api.paths['/api/v1/orders'].post.description += orderDescription;

function operation(
  method,
  path,
  { response, body, permission, paged = false, sensitive = false, status = 200, description },
) {
  const parameters = [
    { name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } },
  ];
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  if (paged)
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  api.paths[path] ??= {};
  api.paths[path][method] = {
    operationId: (method + path).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Fidélité'],
    security: [{ sessionCookie: [] }],
    parameters,
    ...(permission ? { 'x-permission': permission } : { 'x-resource-owner': 'account' }),
    ...(sensitive ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      ' Session HttpOnly requise.' +
      (method === 'get' ? '' : ' CSRF et Idempotency-Key UUID requis.') +
      (permission ? ' MFA de gestion et permission ' + permission + '.' : '') +
      (sensitive ? ' Mot de passe reconfirmé depuis 15 minutes au plus.' : ''),
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
      404: { description: 'Compte, panier ou devis inconnu ou non autorisé.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite de débit atteinte.' },
    },
  };
}
operation('get', '/api/v1/account/loyalty/redemption-policy', {
  response: ref('LoyaltyRedemptionPolicy'),
  description:
    'Conditions publiques de l’échange, sans budget interne ni motif de perte ; initialement désactivé et sans taux prérempli.',
});
operation('post', '/api/v1/account/loyalty/checkout-quotes', {
  response: ref('LoyaltyCheckoutQuote'),
  status: 201,
  body: obj({
    cart_version: version,
    delivery_quote_id: uuid,
    points: { type: 'integer', minimum: 1, maximum: 1000000000 },
  }),
  description:
    'Crée un devis de remise immuable, privé, valable au plus dix minutes et jamais au-delà du devis de livraison. Créneau déjà choisi, panier revu, carte et programmes actifs requis. Aucun point ni budget réservé ici. Taux/paliers, plafond de remise, minimum à payer, catégories et coûts fournisseurs réels contrôlés. Une perte doit être autorisée et motivée par le propriétaire. Répartition exacte par ligne, avec au moins 1 XAF par unité remboursable ; conditions de cumul de livraison explicites et seuil testé sur le prix unitaire payé après remise. Total net et point quote transmis ensuite à POST orders. Les devis professionnels conservent leur prix accepté, sans ajout implicite de cette remise.',
});
operation('get', '/api/v1/operations/loyalty/redemption-policy', {
  response: ref('LoyaltyRedemptionInternalPolicy'),
  permission: 'loyalty.budget.read',
  description:
    'Règles réelles et budget XAF : consommations cumulées exactes, engagements non expirés. Le budget n’est pas recyclé silencieusement après retour.',
});
operation('get', '/api/v1/operations/loyalty/redemption-policy/revisions', {
  response: ref('LoyaltyRedemptionRevision'),
  permission: 'loyalty.budget.read',
  paged: true,
  description:
    'Révisions immuables et empreinte canonique des règles de conversion, financement et restitution.',
});
operation('put', '/api/v1/operations/loyalty/redemption-policy', {
  response: ref('LoyaltyRedemptionInternalPolicy'),
  permission: 'loyalty.settings',
  sensitive: true,
  body: obj({ version, enabled: bool, rules: ref('LoyaltyRedemptionInternalRules') }),
  description:
    'Version et taux explicites, budget jamais inférieur aux remises déjà consommées/réservées. Dérogation à marge négative : pricing.loss_override et motif obligatoires. La modification impose un nouveau devis pour les commandes non créées ; les ventes et restitutions antérieures gardent leurs conditions.',
});
// Existing return API now attributes frozen net unit ranges and restores actually consumed points once.
api.paths['/api/v1/operations/loyalty/refunds/{id}/lines'].post.description =
  'Attribue une fois un remboursement versé et vérifié à ses unités ; contrôle quantités, montant net et tranches déjà réservées pour une rupture fournisseur. Remise et points répartis par ligne, arrondi inférieur cumulé ; aucun remboursement en argent de la partie payée par points. Restitution des seuls points réellement consommés selon les conditions figées, lot de restitution identifiable et validité explicite. Reprise des gains distincte, solde négatif sans blocage du remboursement. Session, MFA, permission loyalty.adjust, CSRF, Idempotency-Key et mot de passe reconfirmé depuis 15 minutes requis.';
api.info.version = '0.18.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
