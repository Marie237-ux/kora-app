// Run after the referral and partner script: reviews, alerts, history, notification centre and messaging.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const count = { type: 'integer', minimum: 0 };
const version = { type: 'integer', minimum: 1 };
const dateTime = { type: 'string', format: 'date-time' };
const string = { type: 'string' };
const amount = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const text = (max) => ({ type: 'string', minLength: 1, maxLength: max });
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const reason = {
  type: 'string',
  enum: ['abusive', 'personal_data', 'off_topic', 'spam', 'not_about_product'],
};
const reviewStatus = { type: 'string', enum: ['pending', 'published', 'rejected'] };
const channel = { type: 'string', enum: ['in_app', 'email', 'web_push', 'whatsapp'] };
const channels = object({
  in_app: { type: 'boolean' },
  email: { type: 'boolean' },
  web_push: { type: 'boolean' },
  whatsapp: { type: 'boolean' },
});

schemas.OwnReview = object({
  id: uuid,
  order_line_id: uuid,
  product_id: uuid,
  rating: { type: 'integer', minimum: 1, maximum: 5 },
  title: nullable(string),
  body: string,
  status: reviewStatus,
  rejection_reason: nullable(reason),
  photos: count,
  reply: nullable(string),
  version,
});
schemas.StaffReview = {
  ...structuredClone(schemas.OwnReview),
  properties: {
    ...structuredClone(schemas.OwnReview.properties),
    user_id: { type: 'integer' },
    photo_ids: array({ type: 'object' }),
    reports: array({ type: 'object' }),
    moderated_at: nullable(dateTime),
  },
};
schemas.StaffReview.required = [
  ...schemas.OwnReview.required,
  'user_id',
  'photo_ids',
  'reports',
  'moderated_at',
];
schemas.ProductReviews = object({
  summary: object({ count, average: nullable({ type: 'number' }), ratings: { type: 'object' } }),
  reviews: array(
    object({
      id: uuid,
      author: string,
      verified_purchase: { type: 'boolean', enum: [true] },
      rating: { type: 'integer', minimum: 1, maximum: 5 },
      title: nullable(string),
      body: string,
      locale: { type: 'string', enum: ['fr', 'en'] },
      photos: array(string),
      reply: nullable(string),
      created_at: dateTime,
    }),
  ),
});
schemas.ProductReviews.description =
  'Avis publiés d’achats livrés vérifiés : prénom et initiale seulement, photos approuvées, réponse de l’équipe. Un avis n’est jamais refusé parce qu’il est négatif.';
schemas.ProductAlert = object({
  id: uuid,
  offer_id: uuid,
  kind: { type: 'string', enum: ['price_drop', 'back_in_stock'] },
  baseline_price: amount,
  target_price: nullable(amount),
  status: { type: 'string', enum: ['active', 'triggered', 'cancelled'] },
  triggered_at: nullable(dateTime),
  created_at: dateTime,
});
schemas.UserNotification = object({
  id: uuid,
  category: { type: 'string', enum: ['transactional', 'reminder', 'marketing'] },
  kind: string,
  title: string,
  body: string,
  link: nullable(string),
  sensitive: { type: 'boolean' },
  read_at: nullable(dateTime),
  created_at: dateTime,
  deliveries: array(
    object({
      channel,
      status: {
        type: 'string',
        enum: ['pending', 'sent', 'failed', 'skipped_preference', 'skipped_not_configured'],
      },
    }),
  ),
});
schemas.UserNotification.description =
  'Avis du centre, dans la langue enregistrée. Un canal sans prestataire reste « skipped_not_configured », jamais affiché comme envoyé.';
schemas.NotificationPreferences = object({
  transactional: channels,
  reminder: channels,
  marketing: channels,
});
const message = {
  id: uuid,
  seq: version,
  side: { type: 'string', enum: ['customer', 'staff'] },
  body: nullable(string),
  attachment: { type: 'boolean' },
  client_ref: string,
  created_at: dateTime,
};
schemas.ConversationMessage = object(message);
schemas.StaffConversationMessage = object({
  ...message,
  visibility: { type: 'string', enum: ['public', 'internal'] },
  author_id: { type: 'integer' },
});
const conversation = {
  id: uuid,
  number: string,
  subject: string,
  context: object({
    type: { type: 'string', enum: ['general', 'order', 'product', 'after_sales'] },
    id: nullable(uuid),
  }),
  status: { type: 'string', enum: ['open', 'closed'] },
  case_id: nullable(uuid),
  last_seq: count,
  unread: count,
  updated_at: dateTime,
};
schemas.Conversation = object(conversation);
schemas.StaffConversation = object({
  ...conversation,
  user_id: { type: 'integer' },
  assignee_id: nullable({ type: 'integer' }),
  version,
});

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue ou appartenant à quelqu’un d’autre.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function op(
  path,
  method,
  {
    permission,
    response,
    body,
    multipart,
    status = 200,
    description,
    tag,
    query,
    open,
    idempotent = true,
    file,
  },
) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get' && idempotent && !multipart && !open)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths['/api/v1/' + path] = {
    ...api.paths['/api/v1/' + path],
    [method]: {
      operationId: (method + '_' + path).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [tag],
      security: open ? [] : [{ sessionCookie: [] }],
      parameters,
      ...(permission ? { 'x-permission': permission } : {}),
      description:
        description +
        (open
          ? ' Public.'
          : permission
            ? ' Permission serveur : ' + permission + '. Session avec MFA obligatoire.'
            : ' Compte connecté, ses propres données seulement.'),
      responses: {
        [status]: file
          ? {
              description: 'Fichier privé',
              content: {
                'application/octet-stream': { schema: { type: 'string', format: 'binary' } },
              },
            }
          : { description: 'Succès', content: { 'application/json': { schema: response } } },
        ...errors,
      },
      ...(body
        ? {
            requestBody: {
              required: true,
              content: multipart
                ? { 'multipart/form-data': { schema: body } }
                : { 'application/json': { schema: body } },
            },
          }
        : {}),
    },
  };
}
const data = (s) => object({ data: s });
const page = (s) =>
  object({ data: array(s), meta: object({ page: version, last_page: version, total: count }) });
const pageQuery = { name: 'page', in: 'query', required: false, schema: version };
const afterQuery = { name: 'after', in: 'query', required: false, schema: count };
const fileBody = (extra = {}) => object({ file: { type: 'string', format: 'binary' }, ...extra });
const A = 'Avis et alertes';
const N = 'Notifications';
const M = 'Messagerie';

op('products/{id}/reviews', 'get', {
  tag: A,
  open: true,
  response: data(ref('ProductReviews')),
  description: 'Avis publiés du produit et résumé des notes.',
});
op('account/reviews', 'get', {
  tag: A,
  response: page(ref('OwnReview')),
  query: [pageQuery],
  description: 'Mes avis.',
});
op('account/reviews', 'post', {
  tag: A,
  status: 201,
  response: data(ref('OwnReview')),
  body: object(
    {
      order_line_id: uuid,
      rating: { type: 'integer', minimum: 1, maximum: 5 },
      title: nullable({ type: 'string', maxLength: 150 }),
      body: text(4000),
    },
    ['order_line_id', 'rating', 'body'],
  ),
  description:
    'Avis sur un article livré de ses propres commandes, un par ligne ; modifiable tant qu’il n’est pas modéré.',
});
op('account/reviews/{id}/photos', 'post', {
  tag: A,
  status: 201,
  multipart: true,
  response: data(ref('OwnReview')),
  body: fileBody(),
  description:
    'Photo (4 au plus) réencodée par la médiathèque, métadonnées retirées ; visible seulement après approbation.',
});
op('reviews/{id}/report', 'post', {
  tag: A,
  status: 201,
  response: data(object({ reported: { type: 'boolean' } })),
  body: object({ reason, note: nullable({ type: 'string', maxLength: 1000 }) }, ['reason']),
  description: 'Signale un avis publié d’un autre client, une fois par personne.',
});
op('operations/reviews', 'get', {
  tag: A,
  permission: 'reviews.moderate',
  response: page(ref('StaffReview')),
  query: [{ name: 'status', in: 'query', required: false, schema: reviewStatus }, pageQuery],
  description: 'File de modération.',
});
op('operations/reviews/{id}/moderation', 'post', {
  tag: A,
  permission: 'reviews.moderate',
  response: data(ref('StaffReview')),
  body: object(
    {
      version,
      decision: { type: 'string', enum: ['publish', 'reject'] },
      reason: nullable(reason),
      approved_photos: array(uuid),
    },
    ['version', 'decision'],
  ),
  description:
    'Publie (photos approuvées rendues publiques) ou refuse pour un motif de la liste ; « négatif » n’est pas un motif.',
});
op('operations/reviews/{id}/reply', 'post', {
  tag: A,
  permission: 'reviews.moderate',
  response: data(ref('StaffReview')),
  body: object({ version, body: text(2000) }),
  description: 'Réponse publique de l’équipe sous un avis publié.',
});
op('operations/review-reports/{id}/handle', 'post', {
  tag: A,
  permission: 'reviews.moderate',
  response: data(ref('StaffReview')),
  body: object(
    { action: { type: 'string', enum: ['dismiss', 'withdraw'] }, reason: nullable(reason) },
    ['action'],
  ),
  description: 'Classe le signalement ou retire l’avis pour un motif de la liste.',
});
op('account/alerts', 'get', {
  tag: A,
  response: page(ref('ProductAlert')),
  query: [pageQuery],
  description: 'Mes alertes.',
});
op('account/alerts', 'post', {
  tag: A,
  status: 201,
  response: data(ref('ProductAlert')),
  body: object(
    {
      offer_id: uuid,
      kind: { type: 'string', enum: ['price_drop', 'back_in_stock'] },
      target_price: nullable({ type: 'integer', minimum: 1 }),
    },
    ['offer_id', 'kind'],
  ),
  description:
    'Alerte de baisse de prix (sous le prix du jour ou sous un prix visé) ou de retour en stock (offre actuellement indisponible) ; notifie une fois puis s’arrête.',
});
op('account/alerts/{id}/cancel', 'post', {
  tag: A,
  response: data(ref('ProductAlert')),
  description: 'Annule une alerte.',
});
op('account/viewed', 'post', {
  tag: A,
  idempotent: false,
  response: data(object({ recorded: { type: 'boolean' } })),
  body: object({ product_id: uuid }),
  description: 'Note une consultation si l’historique est activé.',
});
op('account/viewed', 'get', {
  tag: A,
  response: object({
    data: array(object({ product_id: uuid, viewed_at: dateTime })),
    meta: object({ enabled: { type: 'boolean' } }),
  }),
  description: 'Historique consulté, 50 derniers produits.',
});
op('account/viewed', 'delete', {
  tag: A,
  idempotent: false,
  response: data(object({ cleared: { type: 'boolean' } })),
  description: 'Efface l’historique.',
});
op('account/viewed/settings', 'put', {
  tag: A,
  idempotent: false,
  response: data(object({ enabled: { type: 'boolean' } })),
  body: object({ enabled: { type: 'boolean' } }),
  description: 'Active ou coupe l’historique ; le couper l’efface.',
});
op('account/buy-again', 'post', {
  tag: A,
  idempotent: false,
  response: data(object({ added: { type: 'boolean' }, offer_id: uuid, quantity: version })),
  body: object({ order_line_id: uuid }),
  description:
    'Remet au panier un article de ses commandes, aux conditions du jour (différences visibles dans le panier).',
});
op('account/inbox', 'get', {
  tag: N,
  response: page(ref('UserNotification')),
  query: [pageQuery],
  description: 'Centre de notifications.',
});
op('account/inbox/{id}/read', 'post', {
  tag: N,
  idempotent: false,
  response: data(object({ read: { type: 'boolean' } })),
  description: 'Marque comme lu.',
});
op('account/notification-preferences', 'get', {
  tag: N,
  response: data(ref('NotificationPreferences')),
  description: 'Préférences par catégorie et canal ; le transactionnel reste toujours actif.',
});
op('account/notification-preferences', 'put', {
  tag: N,
  idempotent: false,
  response: data(ref('NotificationPreferences')),
  body: object({ reminder: channels, marketing: channels }, []),
  description: 'Règle rappels et campagnes par canal ; les campagnes sont désactivées par défaut.',
});
op('notification-preferences/unsubscribe', 'get', {
  tag: N,
  open: true,
  response: data(object({ unsubscribed: { type: 'boolean' } })),
  query: [
    { name: 'user', in: 'query', required: true, schema: { type: 'integer' } },
    { name: 'channel', in: 'query', required: true, schema: channel },
    {
      name: 'category',
      in: 'query',
      required: true,
      schema: { type: 'string', enum: ['reminder', 'marketing'] },
    },
    {
      name: 'token',
      in: 'query',
      required: true,
      schema: { type: 'string', minLength: 40, maxLength: 40 },
    },
  ],
  description: 'Désinscription en un clic depuis un lien signé, sans connexion.',
});
op('account/push-subscriptions', 'post', {
  tag: N,
  status: 201,
  idempotent: false,
  response: data(
    object({ subscribed: { type: 'boolean' }, sending_configured: { type: 'boolean' } }),
  ),
  body: object({
    endpoint: { type: 'string', format: 'uri' },
    keys: object({ p256dh: string, auth: string }),
  }),
  description:
    'Enregistre un abonnement aux notifications web du navigateur ; indique si un envoi est réellement configuré.',
});
op('account/conversations', 'get', {
  tag: M,
  response: page(ref('Conversation')),
  query: [pageQuery],
  description: 'Mes conversations avec le service client.',
});
op('account/conversations', 'post', {
  tag: M,
  status: 201,
  response: data(ref('Conversation')),
  body: object(
    {
      subject: text(150),
      context_type: { type: 'string', enum: ['general', 'order', 'product', 'after_sales'] },
      context_id: nullable(uuid),
      body: text(4000),
      client_ref: text(64),
    },
    ['subject', 'context_type', 'body', 'client_ref'],
  ),
  description:
    'Ouvre une conversation générale ou liée à sa commande, un produit ou son dossier SAV.',
});
op('account/conversations/{id}/messages', 'get', {
  tag: M,
  response: object({ data: array(ref('ConversationMessage')), meta: ref('Conversation') }),
  query: [afterQuery],
  description: 'Messages après un numéro (synchronisation et reprise) ; jamais les notes internes.',
});
op('account/conversations/{id}/messages', 'post', {
  tag: M,
  status: 201,
  idempotent: false,
  response: data(ref('ConversationMessage')),
  body: object({ body: text(4000), client_ref: text(64) }),
  description:
    'Envoie un message ; la même référence d’appareil renvoie le message déjà enregistré, sans doublon.',
});
op('account/conversations/{id}/attachments', 'post', {
  tag: M,
  status: 201,
  multipart: true,
  response: data(ref('ConversationMessage')),
  body: fileBody({ client_ref: text(64) }),
  description: 'Pièce jointe privée analysée par l’antivirus.',
});
op('account/conversations/{id}/read', 'post', {
  tag: M,
  idempotent: false,
  response: data(ref('Conversation')),
  body: object({ seq: count }),
  description: 'Marque lu jusqu’à un numéro.',
});
op('account/conversations/{id}/attachments/{messageId}', 'get', {
  tag: M,
  file: true,
  description:
    'Pièce d’un message public de sa propre conversation ; un lien copié ne donne rien à quelqu’un d’autre.',
});
op('account/whatsapp-link', 'get', {
  tag: M,
  response: data(
    object(
      {
        available: { type: 'boolean' },
        url: nullable(string),
        history_synced: { type: 'boolean', enum: [false] },
      },
      ['available', 'url'],
    ),
  ),
  query: [
    { name: 'reference', in: 'query', required: false, schema: { type: 'string', maxLength: 40 } },
    { name: 'consent', in: 'query', required: true, schema: { type: 'boolean' } },
  ],
  description:
    'Lien WhatsApp vers le numéro Business réellement configuré, avec une référence préremplie et l’accord du client ; aucun historique WhatsApp n’est synchronisé.',
});
op('operations/conversations', 'get', {
  tag: M,
  permission: 'support.read',
  response: page(ref('StaffConversation')),
  query: [
    {
      name: 'status',
      in: 'query',
      required: false,
      schema: { type: 'string', enum: ['open', 'closed'] },
    },
    { name: 'assignee_id', in: 'query', required: false, schema: { type: 'integer' } },
    pageQuery,
  ],
  description: 'Conversations récentes avec non-lus du service.',
});
op('operations/conversations/{id}/messages', 'get', {
  tag: M,
  permission: 'support.read',
  response: object({
    data: array(ref('StaffConversationMessage')),
    meta: ref('StaffConversation'),
  }),
  query: [afterQuery],
  description: 'Messages et notes internes après un numéro.',
});
op('operations/conversations/{id}/messages', 'post', {
  tag: M,
  permission: 'support.write',
  status: 201,
  idempotent: false,
  response: data(ref('StaffConversationMessage')),
  body: object(
    {
      body: nullable({ type: 'string', maxLength: 4000 }),
      quick_reply_id: nullable(uuid),
      visibility: { type: 'string', enum: ['public', 'internal'] },
      client_ref: text(64),
    },
    ['visibility', 'client_ref'],
  ),
  description:
    'Réponse publique (réponse rapide possible, dans la langue du client ; le client est notifié sans aperçu du contenu) ou note interne.',
});
op('operations/conversations/{id}/read', 'post', {
  tag: M,
  permission: 'support.read',
  idempotent: false,
  response: data(ref('StaffConversation')),
  body: object({ seq: count }),
  description: 'Marque lu pour le service.',
});
op('operations/conversations/{id}/attachments/{messageId}', 'get', {
  tag: M,
  permission: 'support.read',
  file: true,
  description: 'Pièce d’un message de la conversation.',
});
op('operations/conversations/{id}/assign', 'post', {
  tag: M,
  permission: 'support.write',
  response: data(ref('StaffConversation')),
  body: object({ assignee_id: { type: 'integer' } }),
  description: 'Affecte à un agent du service.',
});
op('operations/conversations/{id}/status', 'post', {
  tag: M,
  permission: 'support.write',
  response: data(ref('StaffConversation')),
  body: object({ status: { type: 'string', enum: ['open', 'closed'] } }),
  description: 'Ouvre ou clôt.',
});
op('operations/conversations/{id}/conversion', 'post', {
  tag: M,
  permission: 'support.write',
  response: data(ref('StaffConversation')),
  body: object({
    order_line_id: uuid,
    kind: { type: 'string', enum: ['missing', 'damaged', 'non_conforming', 'breakdown', 'other'] },
    quantity: version,
    identifiers: array(string),
    description: text(4000),
  }),
  description: 'Transforme la conversation en dossier SAV structuré du client, une seule fois.',
});
op('operations/quick-replies', 'get', {
  tag: M,
  permission: 'support.read',
  response: data(
    array(object({ id: uuid, title: string, body: object({ fr: string, en: string }) })),
  ),
  description: 'Réponses rapides.',
});
op('operations/quick-replies', 'post', {
  tag: M,
  permission: 'support.write',
  status: 201,
  idempotent: false,
  response: data(object({ id: uuid, title: string, body: object({ fr: string, en: string }) })),
  body: object({ title: text(100), body: object({ fr: text(2000), en: text(2000) }) }),
  description: 'Crée une réponse rapide FR/EN.',
});

api.info.version = '0.39.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
