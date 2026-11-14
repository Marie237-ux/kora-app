// Run after the direct delivery script: courier photos during a mission and the public courier check by QR code.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const userId = { type: 'integer', minimum: 1 };
const dateTime = { type: 'string', format: 'date-time' };
const string = { type: 'string' };
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const kind = { type: 'string', enum: ['pickup', 'handover', 'damage', 'incident', 'other'] };
schemas.DeliveryMissionPhoto = object({
  id: uuid,
  kind,
  note: nullable(string),
  document_id: uuid,
  taken_by: userId,
  created_at: dateTime,
});
const mission = schemas.DeliveryMission;
mission.properties.photos = array({ $ref: '#/components/schemas/DeliveryMissionPhoto' });
mission.required = [...new Set([...mission.required, 'photos'])];
Object.assign(schemas.CourierMission.properties, {
  verification: nullable(
    object({
      reference: string,
      token: { type: 'string', minLength: 32, maxLength: 32 },
      url: string,
    }),
  ),
  photos: { type: 'integer', minimum: 0 },
});

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Mission ou photo inconnue, ou mission non affectée à ce livreur.' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
api.paths['/api/v1/courier/missions/{id}/photos'] = {
  post: {
    operationId: 'post_courier_missions__id__photos',
    tags: ['Espace livreur'],
    security: [{ sessionCookie: [] }],
    parameters: [{ name: 'id', in: 'path', required: true, schema: uuid }],
    'x-permission': 'missions.own',
    description:
      'Photo facultative pendant une mission en cours (enlèvement, remise, dommage, incident, autre) : pièce privée « delivery_evidence » analysée par l’antivirus, conservée comme preuve sans empêcher un recours ultérieur du client. Refusée après livraison ou annulation. Permission serveur : missions.own. Affectation actuelle revérifiée. CSRF requis.',
    requestBody: {
      required: true,
      content: {
        'multipart/form-data': {
          schema: object(
            {
              kind,
              note: nullable({ type: 'string', maxLength: 1000 }),
              file: { type: 'string', format: 'binary' },
            },
            ['kind', 'file'],
          ),
        },
      },
    },
    responses: {
      201: {
        description: 'Succès',
        content: {
          'application/json': {
            schema: object({ data: { $ref: '#/components/schemas/DeliveryMissionPhoto' } }),
          },
        },
      },
      ...errors,
    },
  },
};
api.paths['/api/v1/operations/missions/{id}/photos/{photoId}'] = {
  get: {
    operationId: 'get_operations_missions__id__photos__photoId_',
    tags: ['Missions de livraison'],
    security: [{ sessionCookie: [] }],
    parameters: [
      { name: 'id', in: 'path', required: true, schema: uuid },
      { name: 'photoId', in: 'path', required: true, schema: uuid },
    ],
    'x-permission': 'delivery.read',
    description:
      'Télécharge une photo de la mission (fichier privé, sans cache, en pièce jointe) pour l’équipe livraison. Permission serveur : delivery.read. Session avec MFA obligatoire.',
    responses: {
      200: {
        description: 'Fichier',
        content: { 'application/octet-stream': { schema: { type: 'string', format: 'binary' } } },
      },
      ...errors,
    },
  },
};
api.paths['/api/v1/courier-verifications'] = {
  get: {
    operationId: 'get_courier_verifications',
    tags: ['Espace livreur'],
    security: [],
    parameters: [
      { name: 'reference', in: 'query', required: true, schema: { type: 'string', maxLength: 20 } },
      {
        name: 'token',
        in: 'query',
        required: true,
        schema: { type: 'string', minLength: 32, maxLength: 32 },
      },
    ],
    description:
      'Public, sans session : vérification du livreur par le QR code qu’il présente. Réponse minimale (nom public, badge, référence de mission). Le code est lié à la mission, au livreur et à l’affectation : il cesse de fonctionner à la réaffectation, la livraison, l’annulation ou la suspension du livreur. Limité à 30 requêtes par minute.',
    responses: {
      200: {
        description: 'Livreur vérifié',
        content: {
          'application/json': {
            schema: object({
              data: object({
                valid: { type: 'boolean', enum: [true] },
                mission_reference: string,
                public_name: string,
                badge: string,
              }),
            }),
          },
        },
      },
      404: { description: 'Code inconnu, expiré ou révoqué.' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite atteinte.' },
    },
  },
};

api.info.version = '0.35.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
