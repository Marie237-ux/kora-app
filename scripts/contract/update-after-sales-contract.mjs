// Run after the mission proofs script: purchases and warranties, after-sales cases, returns and their control.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const version = { type: 'integer', minimum: 1 };
const count = { type: 'integer', minimum: 0 };
const userId = { type: 'integer', minimum: 1 };
const dateTime = { type: 'string', format: 'date-time' };
const string = { type: 'string' };
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
const status = {
  type: 'string',
  enum: ['open', 'in_review', 'proposed', 'accepted', 'declined', 'resolved', 'rejected'],
};
const kind = {
  type: 'string',
  enum: ['missing', 'damaged', 'non_conforming', 'breakdown', 'other'],
};
const resolution = {
  type: 'string',
  enum: ['exchange', 'repair', 'return_refund', 'refund', 'other'],
};
const responsibility = {
  type: 'string',
  enum: ['supplier', 'carrier', 'kora', 'customer', 'undetermined'],
};
const amount = { type: 'string', pattern: '^[1-9][0-9]*$' };
const message = {
  id: uuid,
  from: { type: 'string', enum: ['customer', 'kora'] },
  author: nullable(string),
  visibility: { type: 'string', enum: ['customer', 'internal'] },
  body: nullable(string),
  attachment: { type: 'boolean' },
  created_at: dateTime,
};
const customerCase = {
  id: uuid,
  number: string,
  status,
  version,
  order_id: uuid,
  order_line_id: uuid,
  product: string,
  kind,
  quantity: version,
  identifiers: array(string),
  description: string,
  contact: nullable(string),
  proposal: nullable(object({ resolution, amount: nullable(amount) })),
  refund_status: nullable({ type: 'string', enum: ['requested', 'paid', 'cancelled'] }),
  messages: array(object(message)),
  created_at: dateTime,
  closed_at: nullable(dateTime),
};
schemas.AfterSalesCase = object(customerCase);
schemas.AfterSalesCase.description =
  'Dossier SAV vu par le client : jamais les notes internes, la responsabilité retenue ni l’identité complète des agents (prénom seulement).';
schemas.AfterSalesStaffCase = object({
  ...customerCase,
  messages: array(object({ ...message, author_id: userId, document_id: nullable(uuid) })),
  order_number: string,
  delivered_at: nullable(dateTime),
  reported_quickly: { type: 'boolean' },
  customer_id: userId,
  assignee_id: nullable(userId),
  responsibility: nullable(responsibility),
  responsibility_note: nullable(string),
  refund_id: nullable(uuid),
  returns: array(
    object({
      id: uuid,
      balance_id: uuid,
      quantity: version,
      identifiers: array(string),
      control: { type: 'string', enum: ['pending', 'resellable', 'defective'] },
      control_note: nullable(string),
      version,
      received_at: dateTime,
      controlled_at: nullable(dateTime),
    }),
  ),
});
schemas.CustomerPurchase = object({
  order_id: uuid,
  number: string,
  delivered_at: nullable(dateTime),
  lines: array(
    object({
      order_line_id: uuid,
      name: string,
      sku: string,
      shop: nullable(string),
      warranty: nullable(string),
      delivered_quantity: count,
      identifiers: array(string),
      open_cases: count,
    }),
  ),
});

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Dossier, ligne ou retour inconnu, ou appartenant à un autre client.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function operation(
  path,
  method,
  { permission, response, body, multipart, status: code = 200, description, tag, query },
) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get' && !multipart)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const staff = path.startsWith('operations/');
  api.paths['/api/v1/' + path] = {
    ...api.paths['/api/v1/' + path],
    [method]: {
      operationId: (method + '_' + path).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [tag ?? (staff ? 'SAV' : 'Compte client')],
      security: [{ sessionCookie: [] }],
      parameters,
      ...(permission ? { 'x-permission': permission } : {}),
      description:
        description +
        (permission
          ? ' Permission serveur : ' + permission + '.'
          : ' Compte client connecté, ses propres données seulement.') +
        (staff ? ' Session avec MFA obligatoire.' : '') +
        (method === 'get'
          ? ''
          : multipart
            ? ' CSRF requis.'
            : ' CSRF et Idempotency-Key UUID requis.'),
      responses: {
        [code]: {
          description: 'Succès',
          content:
            response === 'file'
              ? { 'application/octet-stream': { schema: { type: 'string', format: 'binary' } } }
              : { 'application/json': { schema: response } },
        },
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
const data = (schema) => object({ data: schema });
const page = (schema) =>
  object({
    data: array(schema),
    meta: object({ page: version, last_page: version, total: count }),
  });
const pageQuery = { name: 'page', in: 'query', required: false, schema: version };
const customerCaseData = data(ref('AfterSalesCase'));
const staffCaseData = data(ref('AfterSalesStaffCase'));

operation('account/purchases', 'get', {
  response: data(array(ref('CustomerPurchase'))),
  description:
    'Mes achats et garanties : commandes livrées, garantie vendue avec chaque ligne, quantités et numéros de série remis, dossiers ouverts.',
});
operation('account/after-sales', 'get', {
  response: page(ref('AfterSalesCase')),
  query: [pageQuery],
  description: 'Mes dossiers SAV, plus récents d’abord.',
});
operation('account/after-sales', 'post', {
  status: 201,
  response: customerCaseData,
  body: object({
    order_line_id: uuid,
    kind,
    quantity: { type: 'integer', minimum: 1, maximum: 1000 },
    identifiers: array({ type: 'string', maxLength: 100 }),
    description: text(4000),
  }),
  description:
    'Signale un manquant, un dommage, une non-conformité, une panne ou autre sur une ligne livrée, au plus la quantité remise ; numéros de série exacts pour un article suivi. La remise par code ne vaut pas renonciation ; un signalement dans les 24 h est noté, jamais exigé.',
});
operation('account/after-sales/{id}', 'get', {
  response: customerCaseData,
  description: 'Mon dossier SAV.',
});
operation('account/after-sales/{id}/messages', 'post', {
  status: 201,
  response: customerCaseData,
  body: object({ body: text(4000) }),
  description: 'Message au service client, visible dans l’historique du dossier.',
});
operation('account/after-sales/{id}/attachments', 'post', {
  status: 201,
  multipart: true,
  response: customerCaseData,
  body: object({ file: { type: 'string', format: 'binary' } }),
  description:
    'Photo ou document joint au dossier : pièce privée analysée par l’antivirus. Refusé sur un dossier clos.',
});
operation('account/after-sales/{id}/decision', 'post', {
  response: customerCaseData,
  body: object(
    { version, accept: { type: 'boolean' }, note: nullable({ type: 'string', maxLength: 4000 }) },
    ['version', 'accept'],
  ),
  description: 'Accepte ou refuse la solution proposée ; un refus permet une nouvelle proposition.',
});

operation('operations/after-sales', 'get', {
  permission: 'support.read',
  response: page(ref('AfterSalesStaffCase')),
  query: [
    { name: 'status', in: 'query', required: false, schema: status },
    { name: 'assignee_id', in: 'query', required: false, schema: userId },
    pageQuery,
  ],
  description: 'Dossiers SAV, plus anciens d’abord. Lecture cohérente.',
});
operation('operations/after-sales/{id}', 'get', {
  permission: 'support.read',
  response: staffCaseData,
  description:
    'Dossier complet : conversation et notes internes, responsabilité, remboursement, retours et contrôles.',
});
operation('operations/after-sales/{id}/attachments/{messageId}', 'get', {
  permission: 'support.read',
  response: 'file',
  description: 'Télécharge la pièce jointe d’un message du dossier (fichier privé, sans cache).',
});
operation('operations/after-sales/{id}/messages', 'post', {
  permission: 'support.write',
  status: 201,
  response: staffCaseData,
  body: object({
    body: text(4000),
    visibility: { type: 'string', enum: ['customer', 'internal'] },
  }),
  description: 'Message au client ou note interne jamais montrée au client.',
});
operation('operations/after-sales/{id}/assign', 'post', {
  permission: 'support.write',
  response: staffCaseData,
  body: object({ version, assignee_id: userId }),
  description:
    'Désigne l’interlocuteur du client (droit support.write) ; le client voit son prénom.',
});
operation('operations/after-sales/{id}/proposal', 'post', {
  permission: 'support.write',
  response: staffCaseData,
  body: object(
    {
      version,
      resolution,
      amount: nullable({ type: 'integer', minimum: 1 }),
      responsibility,
      responsibility_note: text(4000),
      message: text(4000),
    },
    ['version', 'resolution', 'responsibility', 'responsibility_note', 'message'],
  ),
  description:
    'Propose échange, réparation, retour avec remboursement, remboursement (total par défaut : prix payé des unités, part de remise fidélité déduite ; partiel si plus bas) ou autre solution, avec la responsabilité retenue sur les faits (jamais de partage automatique).',
});
operation('operations/after-sales/{id}/reject', 'post', {
  permission: 'support.write',
  response: staffCaseData,
  body: object({ version, message: text(4000) }),
  description: 'Refuse le dossier avec un motif communiqué au client.',
});
operation('operations/after-sales/{id}/refund', 'post', {
  permission: 'payments.refund',
  response: staffCaseData,
  body: object({ version }),
  description:
    'Demande le remboursement accepté par le flux habituel (plafonné à l’encaissé, avoir et restitution de points selon les conditions figées) ; après retour réceptionné si la solution l’exige. Confirmation récente du mot de passe requise.',
});
operation('operations/after-sales/{id}/returns', 'post', {
  permission: 'stock.receive',
  status: 201,
  response: staffCaseData,
  body: object(
    {
      version,
      location_id: uuid,
      quantity: { type: 'integer', minimum: 1, maximum: 1000 },
      identifiers: array({ type: 'string', maxLength: 100 }),
      note: nullable({ type: 'string', maxLength: 2000 }),
    },
    ['version', 'location_id', 'quantity', 'identifiers'],
  ),
  description:
    'Réceptionne les articles rendus dans le lot d’origine, à l’état « returned » de l’emplacement choisi : non vendables avant contrôle. Séries exactes du dossier ; lots en dépôt-vente refusés.',
});
operation('operations/after-sales-returns/{id}/control', 'post', {
  permission: 'stock.write',
  response: staffCaseData,
  body: object(
    {
      version,
      result: { type: 'string', enum: ['resellable', 'defective'] },
      location_id: nullable(uuid),
      note: text(2000),
    },
    ['version', 'result', 'note'],
  ),
  description:
    'Contrôle d’un retour : revendable (disponible) ou défectueux (mis de côté, endommagé), avec note.',
});
operation('operations/after-sales/{id}/resolve', 'post', {
  permission: 'support.write',
  response: staffCaseData,
  body: object({ version, message: text(4000) }),
  description:
    'Clôt le dossier accepté seulement quand la solution a réellement eu lieu : remboursement versé, articles rendus réceptionnés et contrôlés.',
});

api.info.version = '0.36.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
