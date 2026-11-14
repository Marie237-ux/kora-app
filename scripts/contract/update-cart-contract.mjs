import { readFileSync, writeFileSync } from 'node:fs';
const p = new URL('../../docs/openapi.json', import.meta.url),
  api = JSON.parse(readFileSync(p, 'utf8')),
  S = api.components.schemas;
const str = { type: 'string' },
  int = { type: 'integer' },
  bool = { type: 'boolean' },
  uuid = { type: 'string', format: 'uuid' },
  dt = { type: 'string', format: 'date-time' };
const ref = (n) => ({ $ref: '#/components/schemas/' + n }),
  nullable = (s) => ({ anyOf: [s, { type: 'null' }] }),
  arr = (items) => ({ type: 'array', items }),
  obj = (properties, required = Object.keys(properties)) => ({
    type: 'object',
    properties,
    required,
    additionalProperties: false,
  });
const version = { type: 'integer', minimum: 1 },
  qty = { type: 'integer', minimum: 1, maximum: 1000000 },
  hash = { type: 'string', pattern: '^[a-f0-9]{64}$' },
  no = { type: 'boolean', const: false };
S.CartConditions = obj({
  offer_id: uuid,
  variant_id: uuid,
  product_id: uuid,
  shop_id: uuid,
  unit_price: { type: 'integer', minimum: 0, maximum: 1000000000000 },
  currency: { type: 'string', const: 'XAF' },
  minimum_quantity: qty,
  availability: str,
  attributes: { type: 'object', additionalProperties: true },
  warranty: ref('Localized'),
});
S.CartLine = obj({
  id: uuid,
  offer_id: uuid,
  quantity: qty,
  selected: bool,
  added_at: dt,
  conditions_saved_at: dt,
  saved_conditions: ref('CartConditions'),
  current_conditions: nullable(ref('CartConditions')),
  conditions_hash: nullable(hash),
  changes: arr(str),
  issues: arr(str),
  current_line_total: nullable(ref('XafAmount')),
  offer: nullable(ref('Offer')),
});
S.Cart = obj(
  {
    id: uuid,
    version,
    currency: { type: 'string', const: 'XAF' },
    lines: arr(ref('CartLine')),
    selected_subtotal: nullable(ref('XafAmount')),
    needs_review: bool,
    delivery_quote: { type: 'null' },
    payable_total: { type: 'null' },
    checkout_ready: no,
    stock_reserved: no,
    guest_merge_available: bool,
  },
  [
    'id',
    'version',
    'currency',
    'lines',
    'selected_subtotal',
    'needs_review',
    'delivery_quote',
    'payable_total',
    'checkout_ready',
    'stock_reserved',
  ],
);
S.CartMerge = obj({
  cart: ref('Cart'),
  merged: bool,
  conflicts: arr(
    obj({ offer_id: uuid, kind: str, account_quantity: qty, guest_quantity: qty, quantity: qty }, [
      'offer_id',
      'kind',
    ]),
  ),
});
S.CartShare = obj({ id: uuid, url: str, expires_at: dt });
S.CartShareStatus = obj({ id: uuid, expires_at: dt, revoked_at: nullable(dt), created_at: dt });
S.CartSharedView = obj({
  currency: { type: 'string', const: 'XAF' },
  expires_at: dt,
  lines: arr(obj({ offer_id: uuid, quantity: qty, offer: nullable(ref('Offer')) })),
  stock_reserved: no,
});
S.CartShareCopy = obj({
  cart: ref('Cart'),
  copied: bool,
  skipped: arr(obj({ offer_id: uuid, reason: { type: 'string', enum: ['unavailable'] } })),
});
function op(
  method,
  url,
  response,
  { body, status = 200, publicLink = false, account = false, description = '' } = {},
) {
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: m[1] === 'token' ? hash : uuid,
  }));
  parameters.push({ name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } });
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const operation = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Panier persistant'],
    security: publicLink ? [] : [{ sessionCookie: [] }],
    'x-resource-owner': publicLink
      ? 'revocable-capability'
      : account
        ? 'account'
        : 'session-or-account',
    parameters,
    description: [
      description,
      publicLink
        ? 'Lien opaque, révocable et expirant ; références et quantités seulement.'
        : account
          ? 'Compte authentifié ; panier propre au compte.'
          : 'Session opaque ou compte authentifié ; aucun identifiant propriétaire fourni par le client.',
      method === 'get' ? '' : 'Version attendue, CSRF et UUID Idempotency-Key.',
      'Aucune réservation ni paiement déclenché ; les devis de livraison et le checkout restent à intégrer.',
    ].join(' '),
    responses: {
      [status]: {
        description: 'Succès',
        content: { 'application/json': { schema: obj({ data: response }) } },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      404: { description: 'Ressource inconnue, autre panier, lien révoqué ou expiré.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      419: { description: 'Session/CSRF expiré.' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite atteinte.' },
    },
  };
  if (body)
    operation.requestBody = { required: true, content: { 'application/json': { schema: body } } };
  api.paths['/api/v1/' + url] ??= {};
  api.paths['/api/v1/' + url][method] = operation;
}
op('get', 'cart', ref('Cart'), {
  description:
    'Lignes sauvegardées, prix actuel et différences avec les conditions initiales. Sous-total exact, null si une ligne choisie est indisponible ou hors limite de montant ; frais de livraison et total payable inconnus.',
});
op('post', 'cart/lines', ref('Cart'), {
  status: 201,
  body: obj({ version, offer_id: uuid, quantity: qty, selected: bool }),
  description:
    'Ajout explicite ; même offre déjà présente : cumul de quantité, conservation des conditions et sélection initiales. Maximum 200 offres.',
});
op('put', 'cart/lines/{id}', ref('Cart'), {
  body: obj({ version, offer_id: uuid, quantity: qty, selected: bool }, [
    'version',
    'quantity',
    'selected',
  ]),
  description:
    'Modification explicite de quantité/sélection/variante. Une variante déjà dans le panier produit un conflit.',
});
op('delete', 'cart/lines/{id}', ref('Cart'), { body: obj({ version }) });
op('post', 'cart/accept-changes', ref('Cart'), {
  body: obj({
    version,
    lines: {
      type: 'array',
      minItems: 1,
      maxItems: 200,
      items: obj({ id: uuid, conditions_hash: hash }),
    },
  }),
  description:
    'Recalcule les conditions sous verrou et refuse un hash périmé. Accepter ne rend pas la commande payable : origine physique, livraison et réservation restent à confirmer.',
});
op('post', 'cart/merge', ref('CartMerge'), {
  account: true,
  body: obj({ version }),
  description:
    'Fusion du panier visiteur de cette session vers le compte, une seule fois. Quantités cumulées, sélection du compte conservée, variantes distinctes conservées et conflits signalés. Dépassement : transaction refusée, deux paniers intacts.',
});
op('get', 'cart/shares', arr(ref('CartShareStatus')), {
  description: 'Cent liens récents au maximum, sans jeton en clair.',
});
op('post', 'cart/shares', ref('CartShare'), {
  status: 201,
  body: obj({
    version,
    line_ids: { type: 'array', minItems: 1, maxItems: 200, items: uuid },
    expires_in_days: { type: 'integer', minimum: 1, maximum: 90 },
  }),
  description:
    'Snapshot des références/quantités choisies, vingt partages actifs au maximum. Le jeton est retourné une fois et stocké uniquement sous forme SHA-256 dans le registre des partages.',
});
op(
  'post',
  'cart/shares/{id}/revoke',
  obj({ id: uuid, revoked: { type: 'boolean', const: true } }),
  { body: obj({ version }) },
);
op('get', 'cart-shares/{token}', ref('CartSharedView'), { publicLink: true });
op('post', 'cart-shares/{token}/copy', ref('CartShareCopy'), {
  body: obj({ version }),
  description:
    'Copie dans le panier du destinataire avec prix/conditions actuels. Offres archivées sautées et rapportées. Un même partage ne s’importe qu’une fois dans un panier, même avec un nouvel UUID d’action.',
});
api.info.version = '0.7.0';
api.info.title = 'KORA — API implémentée : catalogue, approvisionnement, demandes et panier';
api.info.description =
  'Sessions HttpOnly et CSRF, permissions/MFA sur la gestion, confirmation récente finance. Commandes idempotentes et versions serveur. Catalogue, stock/achats/dettes, demandes privées et indicateurs, recherche texte et panier partagé implémentés. Le reste du lancement demeure obligatoire dans EXIGENCES.md ; aucun prestataire ni paiement réel simulé.';
writeFileSync(p, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
