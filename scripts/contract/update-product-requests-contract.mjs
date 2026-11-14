import { readFileSync, writeFileSync } from 'node:fs';
const target = new URL('../../docs/openapi.json', import.meta.url),
  api = JSON.parse(readFileSync(target, 'utf8')),
  S = api.components.schemas;
const str = { type: 'string' },
  int = { type: 'integer' },
  bool = { type: 'boolean' },
  uuid = { type: 'string', format: 'uuid' },
  dt = { type: 'string', format: 'date-time' };
const en = (...v) => ({ type: 'string', enum: v }),
  num = (min, max) => ({ type: 'integer', minimum: min, maximum: max }),
  text = (max) => ({ type: 'string', maxLength: max });
const nullable = (s) => ({ anyOf: [s, { type: 'null' }] }),
  arr = (items) => ({ type: 'array', items }),
  obj = (properties, required = Object.keys(properties)) => ({
    type: 'object',
    properties,
    required,
    additionalProperties: false,
  }),
  ref = (n) => ({ $ref: '#/components/schemas/' + n });
const version = num(1, 2147483647),
  locale = en('fr', 'en');
const states = [
  'draft',
  'submitted',
  'reviewing',
  'needs_details',
  'sourcing',
  'identified',
  'preparing',
  'published',
  'not_found',
  'closed',
  'withdrawn',
];
S.ProductRequestDetails = obj(
  {
    name: nullable(text(200)),
    description: nullable(text(5000)),
    brand: nullable(text(150)),
    reference: nullable(text(150)),
    variant: nullable(text(500)),
    quantity: nullable(num(1, 1000000)),
    budget: nullable(ref('XafAmount')),
    reference_url: nullable({
      type: 'string',
      format: 'uri',
      maxLength: 2000,
      description: 'HTTPS sans identifiants ; jamais téléchargé automatiquement.',
    }),
  },
  [],
);
S.ProductRequestPhoto = obj({ id: uuid, width: int, height: int, bytes: int, url: str });
S.ProductRequest = obj({
  id: uuid,
  reference: str,
  version,
  locale,
  state: en(...states),
  details: ref('ProductRequestDetails'),
  resolution: nullable(str),
  is_alternative: bool,
  alternative_accepted_at: nullable(dt),
  offer: nullable(ref('Offer')),
  currently_on_site: bool,
  submitted_at: nullable(dt),
  draft_expires_at: nullable(dt),
  closed_at: nullable(dt),
  photos: arr(ref('ProductRequestPhoto')),
  created_at: dt,
  updated_at: dt,
});
S.StaffProductRequest = obj({
  ...S.ProductRequest.properties,
  customer_id: int,
  priority: en('normal', 'high', 'urgent'),
  assignee_id: nullable(int),
  group_id: nullable(uuid),
  draft_product_id: nullable(uuid),
  linked_offer_id: nullable(uuid),
});
S.ProductRequestMessage = obj({
  id: uuid,
  author_kind: en('customer', 'staff'),
  locale,
  body: str,
  created_at: dt,
});
S.StaffProductRequestMessage = obj({
  ...S.ProductRequestMessage.properties,
  author_id: int,
  visibility: en('customer', 'internal'),
});
S.ProductRequestEvent = obj({
  version,
  action: str,
  data: { type: 'object', additionalProperties: true },
  created_at: dt,
});
S.StaffProductRequestEvent = obj({
  ...S.ProductRequestEvent.properties,
  actor_id: nullable(int),
  visibility: en('customer', 'internal'),
});
S.ProductRequestCandidate = obj({
  id: uuid,
  supplier_id: nullable(uuid),
  offer_id: nullable(uuid),
  notes: str,
  reference: nullable(str),
  quoted_amount: nullable(ref('XafAmount')),
  created_at: dt,
});
S.AccountNotification = obj({
  id: uuid,
  kind: en('product_request_available', 'product_request_proposal', 'product_request_message'),
  title: str,
  request_id: uuid,
  resource_url: str,
  offer: nullable(ref('Offer')),
  read_at: nullable(dt),
  created_at: dt,
});
const query = (name, schema = str) => ({ name, in: 'query', required: false, schema });
const page = query('page', num(1, 2147483647)),
  group = ref('ProductRequestGroup');
S.ProductRequestGroup = obj({ id: uuid, title: str });
S.ProductRequestMetrics = obj({
  from: { type: 'string', format: 'date' },
  to: { type: 'string', format: 'date' },
  timezone: { type: 'string', const: 'Africa/Douala' },
  submitted_count: int,
  current_states: { type: 'object', additionalProperties: int },
  group_count: int,
  grouped_request_count: int,
  product_drafts_count: int,
  linked_offer_count: int,
  published_request_count: int,
  supplier_proposal_count: int,
  first_staff_reply: obj({
    answered_count: int,
    unanswered_count: int,
    mean_seconds: nullable(int),
    max_seconds: nullable(int),
  }),
  attributed_order_count: { type: 'null' },
  attributed_purchase_amount: { type: 'null' },
  purchase_attribution_available: { type: 'boolean', const: false },
  known_as_of: dt,
});
function op(
  method,
  url,
  response,
  {
    body,
    status = 200,
    paged = false,
    staff = false,
    guest = false,
    permission,
    params = [],
    multipart = false,
    binary = false,
    idempotent = true,
    description = '',
  } = {},
) {
  const full = '/api/v1/' + (staff ? 'operations/' : '') + url;
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  parameters.push(query('locale', locale), ...(paged ? [page] : []), ...params);
  if (method !== 'get' && idempotent)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const schema = binary
    ? { type: 'string', format: 'binary' }
    : obj({ data: paged ? arr(response) : response, ...(paged ? { meta: ref('PageMeta') } : {}) });
  const out = {
    operationId: (method + '_' + (staff ? 'operations_' : '') + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Demandes de produits'],
    security: [{ sessionCookie: [] }],
    parameters,
    description: [
      description,
      guest
        ? 'Session opaque obligatoire ; brouillon appartenant à cette session ou au compte.'
        : 'Compte authentifié, accès propriétaire uniquement.',
      staff ? 'Permission ' + permission + ' ; MFA obligatoire.' : '',
      method === 'get' ? '' : 'CSRF requis. Aucun paiement, réservation de stock ou envoi externe.',
    ]
      .filter(Boolean)
      .join(' '),
    ...(permission ? { 'x-permission': permission } : {}),
    'x-resource-owner': staff ? 'staff-permission' : guest ? 'session-or-account' : 'account',
    responses: {
      [status]: {
        description: 'Succès',
        content: { [binary ? 'image/webp' : 'application/json']: { schema } },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/ReauthenticationRequired' },
      404: { description: 'Ressource inconnue ou appartenant à un autre compte.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      419: { description: 'Session ou CSRF expiré.' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite atteinte.' },
    },
  };
  if (body)
    out.requestBody = {
      required: true,
      content: { [multipart ? 'multipart/form-data' : 'application/json']: { schema: body } },
    };
  api.paths[full] ??= {};
  api.paths[full][method] = out;
  return out;
}
const customer = ref('ProductRequest'),
  staff = ref('StaffProductRequest'),
  draft = obj({ locale, details: ref('ProductRequestDetails') });
op('get', 'product-request-metrics', ref('ProductRequestMetrics'), {
  staff: true,
  permission: 'requests.read',
  params: [
    query('from', { type: 'string', format: 'date' }),
    query('to', { type: 'string', format: 'date' }),
  ],
  description:
    'Cohorte des demandes envoyées entre ces dates Douala, état connu actuellement. Délai de première réponse client écrite par un agent, notes internes exclues. 366 jours au maximum ; attribution des achats inconnue tant que la commande client est absente.',
});
op('post', 'product-request-drafts', customer, {
  body: draft,
  status: 201,
  guest: true,
  description:
    'Brouillon incomplet permis. Durée technique configurable (7 jours par défaut), photos privées et réponses de session purgées à expiration.',
});
op('put', 'product-request-drafts/{id}', customer, {
  body: obj({ ...draft.properties, version }),
  guest: true,
});
op('get', 'product-requests', customer, { paged: true, params: [query('state', en(...states))] });
op('get', 'product-requests/{id}', customer, { guest: true });
op('post', 'product-requests/{id}/submit', customer, {
  body: obj({ version, reviewed: { type: 'boolean', const: true } }),
  description:
    'Connexion obligatoire ; reprise du brouillon de session. Quantité et description suffisante, ou photo avec nom/description. L’utilisateur relit et confirme.',
});
op('post', 'product-requests/{id}/withdraw', customer, { body: obj({ version }) });
const upload = obj({ photo: ref('ProductRequestPhoto'), request_version: version });
op('post', 'product-requests/{id}/photos', upload, {
  body: obj({ file: { type: 'string', format: 'binary' } }),
  guest: true,
  status: 201,
  multipart: true,
  idempotent: false,
  description:
    'JPEG/PNG/WebP ≤ 2 Mio, ≤ 8 mégapixels, 8 photos actives au maximum ; orientation corrigée, métadonnées supprimées, WebP normalisé privé. Déduplication par SHA-256 sous verrou.',
});
op('get', 'product-requests/{id}/photos/{photoId}', str, {
  guest: true,
  binary: true,
  description:
    'Autorisation propriétaire et SHA-256 contrôlés à chaque lecture ; aucun lien public.',
});
op(
  'post',
  'product-requests/{id}/photos/{photoId}/remove',
  obj({ id: uuid, request_version: version }),
  { guest: true, body: obj({ version }) },
);
op('get', 'product-requests/{id}/messages', ref('ProductRequestMessage'), { paged: true });
op(
  'post',
  'product-requests/{id}/messages',
  obj({ id: uuid, request_version: version, state: en(...states) }),
  { body: obj({ version, body: text(5000) }), status: 201 },
);
op('get', 'product-requests/{id}/events', ref('ProductRequestEvent'), {
  paged: true,
  description: 'Exclut affectations, propositions fournisseur, coûts et notes internes.',
});
op('post', 'product-requests/{id}/alternative', customer, {
  body: obj({ version, accept: bool }),
  description: 'Choix explicite du client ; recontrôle de l’offre publiée lors de l’acceptation.',
});
const notices = op('get', 'notifications', ref('AccountNotification'), {
  paged: true,
  params: [query('unread', en('0', '1'))],
  description:
    'Prévisualisation générique FR/EN ; offre actuelle seulement si elle reste publique.',
});
notices.responses[200].content['application/json'].schema.properties.unread_total = int;
notices.responses[200].content['application/json'].schema.required.push('unread_total');
op('post', 'notifications/{id}/read', obj({ id: uuid, read_at: dt }));
const opts = { staff: true, permission: 'requests.read' };
op('get', 'product-request-groups', group, { ...opts, paged: true });
op('post', 'product-request-groups', group, {
  staff: true,
  permission: 'requests.write',
  body: obj({ title: text(200) }),
  status: 201,
});
op('get', 'product-requests', staff, {
  ...opts,
  paged: true,
  params: [
    query('state', en(...states.slice(1))),
    query('assignee_id', int),
    query('group_id', uuid),
    query('priority', en('normal', 'high', 'urgent')),
    query('q', text(150)),
  ],
  description: 'Brouillons privés exclus de la file équipe.',
});
op('get', 'product-requests/{id}', staff, opts);
op('post', 'product-requests/{id}/state', staff, {
  staff: true,
  permission: 'requests.write',
  body: obj(
    {
      version,
      state: en('reviewing', 'needs_details', 'sourcing', 'preparing', 'not_found', 'closed'),
      message: nullable(text(5000)),
    },
    ['version', 'state'],
  ),
  description:
    'Transitions validées ; précision, absence de résultat et clôture exigent un message client. L’état publié est réservé au worker après publication effective.',
});
op('post', 'product-requests/{id}/assignment', staff, {
  staff: true,
  permission: 'requests.write',
  body: obj({
    version,
    assignee_id: nullable(int),
    priority: en('normal', 'high', 'urgent'),
    group_id: nullable(uuid),
  }),
});
op('get', 'product-requests/{id}/messages', ref('StaffProductRequestMessage'), {
  ...opts,
  paged: true,
});
op(
  'post',
  'product-requests/{id}/messages',
  obj({ id: uuid, request_version: version, state: en(...states) }),
  {
    staff: true,
    permission: 'requests.write',
    status: 201,
    body: obj({ version, body: text(5000), locale, visibility: en('customer', 'internal') }),
  },
);
op('get', 'product-requests/{id}/events', ref('StaffProductRequestEvent'), {
  ...opts,
  paged: true,
});
op('get', 'product-requests/{id}/candidates', ref('ProductRequestCandidate'), {
  staff: true,
  permission: 'requests.source',
  paged: true,
  description: 'Coût communiqué seulement avec purchases.read.',
});
op('post', 'product-requests/{id}/candidates', obj({ id: uuid, request_version: version }), {
  staff: true,
  permission: 'requests.source',
  status: 201,
  body: obj(
    {
      version,
      supplier_id: nullable(uuid),
      offer_id: nullable(uuid),
      quoted_amount: nullable(ref('XafAmount')),
      reference: nullable(text(150)),
      notes: text(5000),
    },
    ['version', 'notes'],
  ),
});
op('post', 'product-requests/{id}/offer', staff, {
  staff: true,
  permission: 'requests.write',
  body: obj({ version, offer_id: uuid, is_alternative: bool, identity_verified: bool }, [
    'version',
    'offer_id',
    'is_alternative',
  ]),
  description:
    'Correspondance exacte : vérification humaine obligatoire. Offre non publiée : catalogue.read requis. Aucun avis de disponibilité avant publication réelle.',
});
op(
  'post',
  'product-requests/{id}/product-draft',
  obj({ request: staff, product: ref('CatalogueRecord') }),
  {
    staff: true,
    permission: 'requests.write',
    status: 201,
    body: obj({ version, product: { type: 'object', additionalProperties: true } }),
    description:
      'catalogue.write également requis. Validation du vrai formulaire produit, une seule création ; texte source repris, aucune traduction ou photo publiée automatiquement.',
  },
);
op('get', 'product-requests/{id}/photos/{photoId}', str, {
  ...opts,
  binary: true,
  description: 'Photo privée réservée à l’équipe autorisée.',
});
const sys = api.paths['/api/v1/operations/system/outbox'].get;
sys.description =
  'Métadonnées sans payload privé. Handlers locaux : rappels fournisseurs, projection des publications vers les demandes, notifications de proposition/disponibilité/message client. Aucun envoi externe. Les autres types restent unsupported.';
api.paths['/api/v1/operations/system/outbox/{id}/attempts'].get.responses[200].content[
  'application/json'
].schema.properties.data.items.properties.handler = en(
  'internal.payable-reminder',
  'catalogue.request-projections',
  'account.request-availability',
  'account.request-message',
);
api.paths['/api/v1/operations/system/outbox/{id}/attempts'].get.responses[200].content[
  'application/json'
].schema.properties.data.items.properties.result = en(
  'delivered',
  'suppressed',
  'projected',
  'failed',
);
api.info.title = 'KORA — API implémentée : catalogue, approvisionnement et demandes de produits';
api.info.version = '0.5.0';
writeFileSync(target, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
