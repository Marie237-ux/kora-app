// Run after the order preparation script: couriers, delivery missions, courier space and customer handover code.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const version = { type: 'integer', minimum: 1 };
const count = { type: 'integer', minimum: 0 };
const userId = { type: 'integer', minimum: 1 };
const packages = { type: 'integer', minimum: 1, maximum: 100 };
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
const missionStatus = {
  type: 'string',
  enum: [
    'unassigned',
    'assigned',
    'accepted',
    'in_transit',
    'incident',
    'awaiting_approval',
    'delivered',
    'cancelled',
  ],
};
const incidentKind = {
  type: 'string',
  enum: [
    'customer_absent',
    'address_unreachable',
    'item_missing',
    'damaged',
    'vehicle_unavailable',
    'pickup_discrepancy',
    'other',
  ],
};
const alternativeReason = {
  type: 'string',
  enum: ['code_unavailable', 'code_locked', 'recipient_delegate', 'other'],
};
const slot = nullable(
  object({ date: { type: 'string', format: 'date' }, starts_at: string, ends_at: string }),
);
const codeState = object({
  expires_at: dateTime,
  expired: { type: 'boolean' },
  locked: { type: 'boolean' },
  attempts_left: count,
});
const control = object({
  packages,
  items: {
    type: 'object',
    additionalProperties: object({ quantity: count, identifiers: array(string) }),
  },
});

schemas.CourierProfile = object({
  user_id: userId,
  public_name: string,
  badge: string,
  active: { type: 'boolean' },
  version,
  active_missions: count,
});
schemas.DeliveryMissionIncident = object({
  id: uuid,
  kind: incidentKind,
  note: string,
  status: { type: 'string', enum: ['open', 'resolved'] },
  resolution: nullable({
    type: 'string',
    enum: ['retry', 'return_to_warehouse', 'reassigned', 'cancelled'],
  }),
  resolution_note: nullable(string),
  reported_by: userId,
  resolved_by: nullable(userId),
  created_at: dateTime,
  resolved_at: nullable(dateTime),
});
schemas.DeliveryAlternativeRequest = object({
  id: uuid,
  courier_id: userId,
  reason: alternativeReason,
  note: string,
  control,
  status: { type: 'string', enum: ['pending', 'approved', 'rejected', 'withdrawn'] },
  reviewed_by: nullable(userId),
  review_note: nullable(string),
  created_at: dateTime,
  reviewed_at: nullable(dateTime),
});
schemas.DeliveryMission = object({
  id: uuid,
  number: string,
  status: missionStatus,
  version,
  order: object({ id: uuid, number: string, slot, district: string }),
  courier: nullable(object({ user_id: userId, public_name: string, badge: string }, ['user_id'])),
  custody: { type: 'string', enum: ['warehouse', 'courier'] },
  custodian_id: nullable(userId),
  acceptance_required: { type: 'boolean' },
  sequence: nullable({ type: 'integer', minimum: 1 }),
  assignment: count,
  packages,
  code: codeState,
  delivery_method: nullable({ type: 'string', enum: ['code', 'alternative'] }),
  delivered_at: nullable(dateTime),
  cancel_reason: nullable(string),
  incidents: array(ref('DeliveryMissionIncident')),
  alternatives: array(ref('DeliveryAlternativeRequest')),
  events: array(
    object({
      action: string,
      actor_id: nullable(userId),
      data: { type: 'object' },
      created_at: dateTime,
    }),
  ),
  created_at: dateTime,
});
schemas.CourierMission = object(
  {
    id: uuid,
    number: string,
    status: { anyOf: [missionStatus, { type: 'string', enum: ['declined'] }] },
    version,
    packages,
    delivered_at: nullable(dateTime),
    order_number: string,
    sequence: nullable({ type: 'integer', minimum: 1 }),
    holding_parcels: { type: 'boolean' },
    pickup: object(
      {
        from: { type: 'string', enum: ['warehouse', 'courier', 'self'] },
        warehouses: array(string),
      },
      ['from'],
    ),
    slot,
    address: object({
      district: string,
      landmark: string,
      instructions: nullable(string),
      latitude: nullable({ type: 'number' }),
      longitude: nullable({ type: 'number' }),
      contact_name: nullable(string),
      contact_phone: nullable(string),
      alternate_name: nullable(string),
      alternate_phone: nullable(string),
    }),
    items: array(
      object({
        order_line_id: uuid,
        name: string,
        sku: string,
        quantity: count,
        identifiers: array(string),
      }),
    ),
    code: codeState,
    open_incident: nullable(object({ kind: incidentKind, created_at: dateTime })),
  },
  ['id', 'number', 'status'],
);
schemas.CourierMission.description =
  'Adresse, contacts et articles seulement tant que la mission est en cours et affectée à ce livreur ; l’historique garde la référence et l’issue. Jamais le code de remise, les prix ni les coûts.';
schemas.OrderDeliveryMission = object({
  reference: string,
  status: { type: 'string', enum: ['scheduled', 'out_for_delivery', 'delayed', 'delivered'] },
  courier: nullable(object({ public_name: string, badge: string })),
  handover_code: nullable({ type: 'string', pattern: '^[0-9]{6}$' }),
  handover_code_expires_at: nullable(dateTime),
  delivered_at: nullable(dateTime),
});
const delivery = schemas.Order.properties.delivery;
delivery.properties.mission = {
  ...nullable(ref('OrderDeliveryMission')),
  description:
    'Mission en cours ou livrée : référence, identité publique du livreur (nom public et badge) et code de remise à usage unique, affiché seulement au client tant qu’il est valable.',
};
delivery.required = [...new Set([...delivery.required, 'mission'])];

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue ou mission non affectée à ce livreur.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function operation(space, method, url, opts) {
  const { response, body, status = 200, description, query, permission, idType = uuid } = opts;
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: idType,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const courier = space === 'courier';
  api.paths['/api/v1/' + space + '/' + url] = {
    ...api.paths['/api/v1/' + space + '/' + url],
    [method]: {
      operationId: (method + '_' + space + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [courier ? 'Espace livreur' : 'Missions de livraison'],
      security: [{ sessionCookie: [] }],
      parameters,
      'x-permission': permission,
      description:
        description +
        ' Permission serveur : ' +
        permission +
        (courier
          ? '. Affectation actuelle revérifiée avant tout rejeu idempotent.'
          : '. Session avec MFA obligatoire.') +
        (method === 'get' ? '' : ' CSRF et Idempotency-Key UUID requis.'),
      responses: {
        [status]: {
          description: 'Succès',
          content: { 'application/json': { schema: response } },
        },
        ...errors,
        ...(opts.extra ?? {}),
      },
      ...(body
        ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
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
const ops = (method, url, opts) => operation('operations', method, url, opts);
const courierOp = (method, url, opts) =>
  operation('courier', method, url, { permission: 'missions.own', ...opts });
const mission = data(ref('DeliveryMission'));
const courierMission = data(ref('CourierMission'));
const controlBody = {
  packages,
  items: array(
    object({
      order_line_id: uuid,
      quantity: { type: 'integer', minimum: 1 },
      identifiers: array({ type: 'string', maxLength: 100 }),
    }),
  ),
};

ops('get', 'couriers', {
  permission: 'delivery.read',
  response: page(ref('CourierProfile')),
  query: [{ name: 'active', in: 'query', required: false, schema: { type: 'boolean' } }, pageQuery],
  description: 'Livreurs enregistrés avec nom public, badge professionnel et missions en cours.',
});
ops('post', 'couriers', {
  permission: 'delivery.settings',
  status: 201,
  response: data(ref('CourierProfile')),
  body: object({
    user_id: userId,
    public_name: text(80),
    badge: { type: 'string', pattern: '^[A-Za-z0-9-]+$', maxLength: 40 },
  }),
  description:
    'Enregistre l’identité professionnelle publique d’un compte ayant le rôle livreur. Aucun papier d’identité stocké ni publié.',
});
ops('put', 'couriers/{id}', {
  permission: 'delivery.settings',
  idType: userId,
  response: data(ref('CourierProfile')),
  body: object({ version, public_name: text(80), active: { type: 'boolean' } }),
  description:
    'Met à jour le nom public ou suspend un livreur ; un livreur inactif ne reçoit plus de mission.',
});
ops('get', 'missions', {
  permission: 'delivery.read',
  response: page(ref('DeliveryMission')),
  query: [
    { name: 'status', in: 'query', required: false, schema: missionStatus },
    { name: 'courier_id', in: 'query', required: false, schema: userId },
    pageQuery,
  ],
  description:
    'Missions de livraison, plus anciennes d’abord. Jamais le code de remise. Lecture cohérente.',
});
ops('get', 'missions/{id}', {
  permission: 'delivery.read',
  response: mission,
  description:
    'Détail d’une mission : garde des colis, affectations, état du code, incidents, remises alternatives et journal.',
});
ops('post', 'orders/{id}/missions', {
  permission: 'delivery.assign',
  status: 201,
  response: mission,
  body: object({ note: nullable(text(1000)) }, []),
  description:
    'Crée la mission d’une commande payée, ouverte, déclarée prête et non livrée ; une seule mission en cours par commande. Génère le code de remise à usage unique du client.',
});
ops('post', 'missions/{id}/assign', {
  permission: 'delivery.assign',
  response: mission,
  body: object(
    {
      version,
      courier_id: userId,
      acceptance_required: { type: 'boolean' },
      sequence: nullable({ type: 'integer', minimum: 1, maximum: 1000 }),
      note: nullable(text(1000)),
    },
    ['version', 'courier_id', 'acceptance_required'],
  ),
  description:
    'Affecte ou réaffecte un livreur actif. Si un livreur détient déjà les colis, une note de transmission est exigée et le nouveau livreur confirme l’enlèvement auprès de lui. La version change : l’ancien appareil ne peut plus agir.',
});
ops('post', 'missions/{id}/cancel', {
  permission: 'delivery.assign',
  response: mission,
  body: object({ version, reason: text(1000) }),
  description:
    'Annule une mission dont les colis sont au dépôt ; une nouvelle mission pourra être créée.',
});
ops('post', 'missions/{id}/code', {
  permission: 'delivery.assign',
  response: mission,
  body: object({ version }),
  description: 'Remplace un code expiré ou bloqué ; l’ancien code cesse aussitôt de fonctionner.',
});
ops('post', 'missions/{id}/incident-resolution', {
  permission: 'delivery.assign',
  response: mission,
  body: object({
    version,
    action: { type: 'string', enum: ['retry', 'return_to_warehouse'] },
    note: text(2000),
  }),
  description:
    'Traite l’incident ouvert : nouvel essai du même livreur, ou retour des colis au dépôt (mission à réaffecter). Une réaffectation clôt aussi l’incident.',
});
for (const [action, word] of [
  ['approve', 'Valide'],
  ['reject', 'Refuse'],
]) {
  ops('post', 'missions/{id}/alternative/' + action, {
    permission: 'delivery.assign',
    response: mission,
    body: object({ version, note: text(2000) }),
    description:
      word +
      ' la remise alternative demandée par le livreur, avec note de contrôle. Le valideur doit être une autre personne que le livreur.' +
      (action === 'approve' ? ' La commande devient livrée.' : ' La mission reprend en livraison.'),
  });
}

courierOp('get', 'missions', {
  response: page(ref('CourierMission')),
  query: [
    {
      name: 'scope',
      in: 'query',
      required: false,
      schema: { type: 'string', enum: ['current', 'history'] },
    },
    pageQuery,
  ],
  description:
    'Missions en cours du livreur connecté, dans l’ordre des arrêts, ou son historique sans données client.',
});
courierOp('get', 'missions/{id}', {
  response: courierMission,
  description: 'Mission affectée au livreur connecté ; introuvable sinon.',
});
courierOp('post', 'missions/{id}/accept', {
  response: courierMission,
  body: object({ version }),
  description: 'Accepte une affectation lorsque ce mode a été choisi.',
});
courierOp('post', 'missions/{id}/decline', {
  response: data(
    object({ id: uuid, number: string, status: { type: 'string', enum: ['declined'] } }),
  ),
  body: object({ version, reason: text(1000) }),
  description: 'Refuse une affectation en attente ; la mission redevient à affecter.',
});
courierOp('post', 'missions/{id}/pickup', {
  response: courierMission,
  body: object({ version, packages }),
  description:
    'Confirme l’enlèvement de tous les colis (au dépôt ou auprès du livreur précédent). Un nombre différent est refusé : signaler un incident.',
});
courierOp('post', 'missions/{id}/incident', {
  response: courierMission,
  status: 201,
  body: object({ version, kind: incidentKind, note: text(2000) }),
  description:
    'Signale client absent, adresse inaccessible, article manquant, dommage, véhicule indisponible ou écart à l’enlèvement. Le client voit seulement un retard.',
});
courierOp('post', 'missions/{id}/handover', {
  response: courierMission,
  body: object({ version, code: { type: 'string', pattern: '^[0-9]{6}$' }, ...controlBody }),
  description:
    'Remise au client : contrôle par article (quantités, séries/IMEI) et nombre de colis, puis code à usage unique. Un code erroné est enregistré et compté ; après la limite, le code est bloqué. Aucun débit financier.',
  extra: {
    422: {
      description:
        'Contrôle ou code refusé. Pour un code erroné, data.attempts_left et data.mission accompagnent l’erreur ; la tentative reste comptée.',
    },
  },
});
courierOp('post', 'missions/{id}/alternative', {
  response: courierMission,
  status: 201,
  body: object({ version, reason: alternativeReason, note: text(2000), ...controlBody }),
  description:
    'Demande une remise alternative motivée avec le même contrôle par article ; elle attend la validation d’une autre personne.',
});

api.info.version = '0.31.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
