import { readFileSync, writeFileSync } from 'node:fs';
const p = new URL('../../docs/openapi.json', import.meta.url),
  api = JSON.parse(readFileSync(p, 'utf8')),
  S = api.components.schemas;
const str = { type: 'string' },
  int = { type: 'integer' },
  bool = { type: 'boolean' },
  uuid = { type: 'string', format: 'uuid' },
  dt = { type: 'string', format: 'date-time' },
  date = { type: 'string', format: 'date' };
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
  no = { type: 'boolean', const: false },
  yes = { type: 'boolean', const: true };
const kind = { type: 'string', enum: ['collection', 'gift'] },
  memberRole = { type: 'string', enum: ['editor', 'viewer'] };
S.BoardSection = obj({ id: uuid, title: str, position: int });
S.BoardPin = obj(
  {
    id: uuid,
    offer_id: uuid,
    section_id: nullable(uuid),
    position: int,
    wished_quantity: qty,
    caption: str,
    saved_name: str,
    sku: str,
    price_at_save: ref('XafAmount'),
    price_saved_at: dt,
    offer: nullable(ref('Offer')),
    purchased_quantity: { type: 'null' },
    personal_note: str,
  },
  [
    'id',
    'offer_id',
    'section_id',
    'position',
    'wished_quantity',
    'caption',
    'saved_name',
    'sku',
    'price_at_save',
    'price_saved_at',
    'offer',
    'purchased_quantity',
  ],
);
S.Board = obj({
  id: uuid,
  title: str,
  description: str,
  kind,
  visibility: { type: 'string', enum: ['private', 'unlisted', 'public'] },
  version,
  gift_date: nullable(date),
  hide_gift_purchases: bool,
  cover_pin_id: nullable(uuid),
  role: { type: 'string', enum: ['owner', 'editor', 'viewer', 'public_reader'] },
  sections: arr(ref('BoardSection')),
  pins: arr(ref('BoardPin')),
  purchase_tracking_available: no,
  recipient_delivery_available: no,
  created_at: dt,
  updated_at: dt,
});
S.BoardSummary = obj({
  id: uuid,
  title: str,
  description: str,
  kind,
  visibility: { type: 'string', enum: ['private', 'unlisted', 'public'] },
  version,
  role: { type: 'string', enum: ['owner', 'editor', 'viewer'] },
  pin_count: int,
  updated_at: dt,
});
S.PublicBoardSummary = obj({
  id: uuid,
  title: str,
  description: str,
  kind,
  pin_count: int,
  updated_at: dt,
});
S.BoardPinResult = obj({ board: ref('Board'), pin_id: uuid, already_pinned: bool });
S.BoardShare = obj({ board: ref('Board'), url: str, expires_at: dt });
S.BoardTransfer = obj({ source_version: version, target: ref('Board'), pin_id: uuid });
S.BoardToCart = obj({
  cart: ref('Cart'),
  skipped: arr(obj({ pin_id: uuid, reason: { type: 'string', enum: ['unavailable'] } })),
  collection_fees: { type: 'null' },
  delivery_quote_required: yes,
});
S.BoardFromCart = obj({ board: ref('Board'), cart: ref('Cart'), copied: bool });
S.BoardEvent = obj({
  version,
  actor_id: int,
  action: str,
  data: { type: 'object', additionalProperties: true },
  created_at: dt,
});
S.BoardMember = obj({ user_id: int, name: str, role: memberRole, revoked_at: nullable(dt) });
S.BoardInvitation = obj({
  id: uuid,
  recipient_hint: str,
  role: memberRole,
  expires_at: dt,
  accepted_at: nullable(dt),
  revoked_at: nullable(dt),
});
S.BoardInvitationCreated = obj({
  id: uuid,
  board_version: version,
  url: str,
  role: memberRole,
  expires_at: dt,
  external_message_sent: no,
});
S.BoardInvitationPreview = obj({ board_title: str, role: memberRole, expires_at: dt });
function op(
  method,
  url,
  response,
  { body, status = 200, owner = 'board-member', paged = false, description = '' } = {},
) {
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: m[1] === 'token' ? hash : m[1] === 'userId' ? int : uuid,
  }));
  parameters.push({ name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } });
  if (paged)
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const anonymous = owner === 'public' || owner === 'revocable-capability';
  const access = {
    public: 'Tableau public explicitement publié ; ni notes personnelles ni membres.',
    'revocable-capability':
      'Lien non répertorié opaque, révocable et expirant ; ni notes personnelles ni membres.',
    account: 'Compte authentifié ; tableaux dont il est propriétaire ou membre actif.',
    'board-member': 'Propriétaire ou membre actif ; un lecteur ne modifie rien.',
    'board-owner': 'Propriétaire uniquement.',
    invitee: 'Compte authentifié à adresse vérifiée correspondant à l’invitation.',
  }[owner];
  const operation = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Tableaux et listes cadeaux'],
    security: anonymous ? [] : [{ sessionCookie: [] }],
    'x-resource-owner': owner,
    parameters,
    description: [
      description,
      access,
      method === 'get'
        ? ''
        : 'CSRF et UUID Idempotency-Key ; version attendue lorsque le corps la demande.',
      'Aucune réservation de stock ni message externe envoyé.',
    ]
      .filter(Boolean)
      .join(' '),
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
      ...(anonymous
        ? {}
        : {
            401: { $ref: '#/components/responses/Unauthorized' },
            403: { $ref: '#/components/responses/Forbidden' },
          }),
      404: { description: 'Tableau inconnu, archivé, non autorisé, lien révoqué ou expiré.' },
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
const pinBody = (create) =>
  obj(
    {
      version,
      ...(create ? { offer_id: uuid } : {}),
      section_id: nullable(uuid),
      caption: nullable({ type: 'string', maxLength: 1000 }),
      wished_quantity: qty,
      personal_note: nullable({ type: 'string', maxLength: 2000 }),
    },
    ['version', ...(create ? ['offer_id'] : ['section_id']), 'wished_quantity'],
  );
op('get', 'boards/public', ref('PublicBoardSummary'), { owner: 'public', paged: true });
op('get', 'boards/public/{id}', ref('Board'), {
  owner: 'public',
  description:
    'Prix et disponibilité actuels issus du catalogue publié ; une offre retirée apparaît avec offer null sans casser le tableau.',
});
op('get', 'boards/shared/{id}/{token}', ref('Board'), { owner: 'revocable-capability' });
op('get', 'boards', ref('BoardSummary'), { owner: 'account', paged: true });
op('post', 'boards', ref('Board'), {
  owner: 'account',
  status: 201,
  body: obj(
    {
      title: { type: 'string', maxLength: 150 },
      description: nullable({ type: 'string', maxLength: 2000 }),
      kind,
      gift_date: nullable(date),
      hide_gift_purchases: bool,
    },
    ['title', 'kind', 'hide_gift_purchases'],
  ),
  description: 'Privé par défaut. Cent tableaux actifs au maximum par compte.',
});
op('get', 'boards/{id}', ref('Board'));
op('put', 'boards/{id}', ref('Board'), {
  body: obj({
    version,
    title: { type: 'string', maxLength: 150 },
    description: nullable({ type: 'string', maxLength: 2000 }),
    cover_pin_id: nullable(uuid),
  }),
});
op('post', 'boards/{id}/gift-settings', ref('Board'), {
  owner: 'board-owner',
  body: obj({ version, kind, gift_date: nullable(date), hide_gift_purchases: bool }),
  description:
    'Le suivi des achats cadeaux attend la commande client ; purchase_tracking_available reste false.',
});
op('post', 'boards/{id}/archive', obj({ id: uuid, archived: yes }), {
  owner: 'board-owner',
  body: obj({ version }),
  description:
    'Retire le lien de partage, rend le tableau privé et révoque les invitations en attente.',
});
op('post', 'boards/{id}/visibility', ref('Board'), {
  owner: 'board-owner',
  body: obj({
    version,
    visibility: { type: 'string', enum: ['private', 'public'] },
    reviewed: yes,
  }),
  description: 'Publication explicite après relecture ; révoque tout lien non répertorié.',
});
op('post', 'boards/{id}/share', ref('BoardShare'), {
  owner: 'board-owner',
  status: 201,
  body: obj({
    version,
    expires_in_days: { type: 'integer', minimum: 1, maximum: 90 },
    reviewed: yes,
  }),
  description:
    'Jeton retourné une fois, stocké en SHA-256 ; un nouveau lien remplace le précédent.',
});
op('post', 'boards/{id}/sections', ref('Board'), {
  status: 201,
  body: obj({ version, title: { type: 'string', maxLength: 100 } }),
  description: 'Trente sections au maximum.',
});
op('put', 'boards/{id}/sections/{sectionId}', ref('Board'), {
  body: obj({ version, title: { type: 'string', maxLength: 100 } }),
});
op('delete', 'boards/{id}/sections/{sectionId}', ref('Board'), {
  body: obj({ version }),
  description: 'Les épingles de la section sont conservées hors section.',
});
op('post', 'boards/{id}/sections/reorder', ref('Board'), {
  body: obj({ version, ids: { type: 'array', maxItems: 30, items: uuid } }),
  description: 'La liste doit contenir exactement toutes les sections du tableau.',
});
op('post', 'boards/{id}/pins', ref('BoardPinResult'), {
  status: 201,
  body: pinBody(true),
  description:
    'Offre publiée uniquement ; prix à l’enregistrement conservé. Une offre déjà épinglée retourne already_pinned. Cinq cents épingles au maximum.',
});
op('put', 'boards/{id}/pins/{pinId}', ref('Board'), {
  body: pinBody(false),
  description: 'La note personnelle reste visible de son seul auteur.',
});
op('delete', 'boards/{id}/pins/{pinId}', ref('Board'), { body: obj({ version }) });
op('post', 'boards/{id}/pins/reorder', ref('Board'), {
  body: obj({
    version,
    section_id: nullable(uuid),
    ids: { type: 'array', maxItems: 500, items: uuid },
  }),
  description: 'La liste doit contenir exactement les épingles de la section indiquée.',
});
op('post', 'boards/{id}/pins/{pinId}/transfer', ref('BoardTransfer'), {
  body: obj({
    source_version: version,
    target_version: version,
    target_board_id: uuid,
    section_id: nullable(uuid),
    copy: bool,
    copy_personal_note: bool,
  }),
  description:
    'Déplacement (écriture sur la source) ou copie (lecture sur la source) vers un tableau modifiable ; deux versions vérifiées sous verrou.',
});
op('post', 'boards/{id}/to-cart', ref('BoardToCart'), {
  body: obj({
    cart_version: version,
    pin_ids: { type: 'array', minItems: 1, maxItems: 200, items: uuid },
  }),
  description:
    'Ajoute les quantités souhaitées au panier avec les conditions actuelles ; offres indisponibles sautées et rapportées. Frais de collecte inconnus tant que le devis de livraison n’est pas intégré.',
});
op('post', 'boards/{id}/from-cart', ref('BoardFromCart'), {
  body: obj(
    {
      version,
      cart_version: version,
      line_id: uuid,
      section_id: nullable(uuid),
      copy: bool,
      merge_quantity: bool,
      personal_note: nullable({ type: 'string', maxLength: 2000 }),
    },
    ['version', 'cart_version', 'line_id', 'section_id', 'copy', 'merge_quantity'],
  ),
  description:
    'Déplace ou copie une ligne du panier ; merge_quantity cumule avec une épingle existante.',
});
op('get', 'boards/{id}/events', ref('BoardEvent'), {
  paged: true,
  description: 'Journal versionné des modifications utiles.',
});
op('get', 'boards/{id}/members', ref('BoardMember'), { owner: 'board-owner', paged: true });
op(
  'put',
  'boards/{id}/members/{userId}',
  obj({ user_id: int, role: nullable(memberRole), revoked: bool, board_version: version }),
  {
    owner: 'board-owner',
    body: obj({ version, role: nullable(memberRole) }),
    description: 'role null révoque le membre et ses invitations en attente.',
  },
);
op('get', 'boards/{id}/invitations', ref('BoardInvitation'), {
  owner: 'board-owner',
  paged: true,
  description: 'Adresse jamais stockée en clair : indice partiel et HMAC seulement.',
});
op('post', 'boards/{id}/invitations', ref('BoardInvitationCreated'), {
  owner: 'board-owner',
  status: 201,
  body: obj({
    version,
    email: { type: 'string', format: 'email', maxLength: 254 },
    role: memberRole,
    expires_in_days: { type: 'integer', minimum: 1, maximum: 30 },
  }),
  description:
    'Le lien est retourné une fois au propriétaire, qui le transmet lui-même ; external_message_sent reste false.',
});
op(
  'post',
  'boards/{id}/invitations/{invitationId}/revoke',
  obj({ id: uuid, board_version: version, revoked: yes }),
  { owner: 'board-owner', body: obj({ version }) },
);
op('get', 'board-invitations/{token}', ref('BoardInvitationPreview'), { owner: 'invitee' });
op('post', 'board-invitations/{token}/accept', ref('Board'), {
  owner: 'invitee',
  body: obj({ accepted: yes }),
  description: 'Acceptation explicite ; aucune inscription automatique.',
});
api.info.version = '0.8.0';
api.info.title =
  'KORA — API implémentée : catalogue, approvisionnement, demandes, panier et tableaux';
api.info.description =
  'Sessions HttpOnly et CSRF, permissions/MFA sur la gestion, confirmation récente finance. Commandes idempotentes et versions serveur. Catalogue, stock/achats/dettes, demandes privées et indicateurs, recherche texte, panier partagé et tableaux collaboratifs implémentés. Le reste du lancement demeure obligatoire dans EXIGENCES.md ; aucun prestataire ni paiement réel simulé.';
writeFileSync(p, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
