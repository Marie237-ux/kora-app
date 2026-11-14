// Run after the delivery missions script: courier rounds and the step-by-step day of a courier.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const version = { type: 'integer', minimum: 1 };
const count = { type: 'integer', minimum: 0 };
const userId = { type: 'integer', minimum: 1 };
const string = { type: 'string' };
const date = { type: 'string', format: 'date' };
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const state = { type: 'string', enum: ['todo', 'waiting', 'done', 'removed'] };

schemas.DeliveryRoundStep = object(
  {
    step: version,
    kind: { type: 'string', enum: ['pickup', 'delivery'] },
    state,
    current: { type: 'boolean' },
    instruction: {
      ...string,
      description:
        'Consigne lisible dans la langue de la session : quoi faire, où, pour quelles missions.',
    },
    missions: array(
      object(
        {
          mission_id: uuid,
          number: string,
          mission_status: string,
          version,
          packages: version,
          state,
          action: nullable({
            type: 'string',
            enum: [
              'accept',
              'pickup',
              'pickup_first',
              'handover',
              'wait_dispatch',
              'wait_approval',
            ],
          }),
          stop_id: uuid,
        },
        ['mission_id', 'number', 'mission_status', 'version', 'packages', 'state', 'action'],
      ),
    ),
    place: {
      type: 'object',
      description:
        'Dépôt (code, nom, adresse, zone), livreur détenteur des colis, ou client (quartier, zone ; repère, consignes, coordonnées et contacts seulement tant que la mission est en cours pour ce livreur).',
    },
    order_number: string,
    slot: nullable(object({ date, starts_at: string, ends_at: string })),
  },
  ['step', 'kind', 'state', 'current', 'instruction', 'missions', 'place'],
);
schemas.DeliveryRound = object({
  id: uuid,
  number: string,
  date,
  status: { type: 'string', enum: ['open', 'cancelled'] },
  version,
  courier: object({ user_id: userId, public_name: string, badge: string }, ['user_id']),
  progress: object({
    steps_total: count,
    steps_done: count,
    deliveries_total: count,
    deliveries_done: count,
    parcels_on_board: count,
  }),
  current_step: nullable(version),
  next_step: nullable(version),
  steps: array(ref('DeliveryRoundStep')),
  cancel_reason: nullable(string),
});
schemas.DeliveryRound.description =
  'Journée d’un livreur. L’état de chaque étape est lu sur les missions (colis à bord, remise enregistrée), jamais coché à la main. Ordre proposé par zone sans service de distance, modifiable par le dispatch.';

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Tournée inconnue.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function operation(
  space,
  method,
  url,
  { response, body, status = 200, description, query, permission },
) {
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths['/api/v1/' + space + '/' + url] = {
    ...api.paths['/api/v1/' + space + '/' + url],
    [method]: {
      operationId: (method + '_' + space + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [space === 'courier' ? 'Espace livreur' : 'Tournées de livraison'],
      security: [{ sessionCookie: [] }],
      parameters,
      'x-permission': permission,
      description:
        description +
        ' Permission serveur : ' +
        permission +
        (space === 'courier' ? '.' : '. Session avec MFA obligatoire.') +
        (method === 'get' ? '' : ' CSRF et Idempotency-Key UUID requis.'),
      responses: {
        [status]: { description: 'Succès', content: { 'application/json': { schema: response } } },
        ...errors,
      },
      ...(body
        ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
        : {}),
    },
  };
}
const data = (schema) => object({ data: schema });
const round = data(ref('DeliveryRound'));
const missionIds = { type: 'array', minItems: 1, maxItems: 60, items: uuid };

operation('operations', 'get', 'rounds', {
  permission: 'delivery.read',
  response: object({
    data: array(ref('DeliveryRound')),
    meta: object({ page: version, last_page: version, total: count }),
  }),
  query: [
    { name: 'date', in: 'query', required: false, schema: date },
    { name: 'courier_id', in: 'query', required: false, schema: userId },
    {
      name: 'status',
      in: 'query',
      required: false,
      schema: { type: 'string', enum: ['open', 'cancelled'] },
    },
    { name: 'page', in: 'query', required: false, schema: version },
  ],
  description:
    'Tournées, date la plus récente d’abord, avec étapes, progression et identifiants d’arrêts.',
});
operation('operations', 'get', 'rounds/{id}', {
  permission: 'delivery.read',
  response: round,
  description: 'Détail d’une tournée et de ses étapes.',
});
operation('operations', 'post', 'rounds', {
  permission: 'delivery.assign',
  status: 201,
  response: round,
  body: object({ courier_id: userId, date, mission_ids: missionIds }),
  description:
    'Crée la tournée d’un livreur pour une date à partir de ses missions en cours (une tournée ouverte par livreur et par date, une mission dans une seule tournée ouverte). L’ordre proposé respecte enlèvement avant livraison et regroupe le travail par zone.',
});
operation('operations', 'put', 'rounds/{id}/missions', {
  permission: 'delivery.assign',
  response: round,
  body: object({ version, mission_ids: missionIds }),
  description:
    'Remplace l’ensemble des missions : les étapes déjà faites restent en tête, le reste est replanifié depuis la situation réelle (colis déjà à bord non récupérés deux fois).',
});
operation('operations', 'put', 'rounds/{id}/stops', {
  permission: 'delivery.assign',
  response: round,
  body: object({ version, stop_ids: { type: 'array', minItems: 1, maxItems: 240, items: uuid } }),
  description:
    'Ordre manuel : tous les arrêts une seule fois, chaque enlèvement avant la livraison de sa mission.',
});
operation('operations', 'post', 'rounds/{id}/cancel', {
  permission: 'delivery.assign',
  response: round,
  body: object({ version, reason: { type: 'string', minLength: 1, maxLength: 1000 } }),
  description:
    'Annule la tournée ; ses missions restent affectées et peuvent rejoindre une autre tournée.',
});
operation('courier', 'get', 'day', {
  permission: 'missions.own',
  response: data(nullable(ref('DeliveryRound'))),
  query: [{ name: 'date', in: 'query', required: false, schema: date }],
  description:
    'Journée du livreur connecté (aujourd’hui à Douala par défaut) : étapes numérotées, consigne de chacune, étape en cours et suivante, colis à bord, action attendue par mission. null sans tournée ouverte. Ni code de remise, ni prix, ni identifiant d’arrêt.',
});

api.info.version = '0.32.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
