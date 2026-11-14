// Run after the CMS script: photo search, choice assistant, comparator and product form assistant.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const count = { type: 'integer', minimum: 0 };
const version = { type: 'integer', minimum: 1 };
const dateTime = { type: 'string', format: 'date-time' };
const string = { type: 'string' };
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const translated = object({ fr: string, en: string });
const free = { type: 'object', additionalProperties: true };
const level = { type: 'string', enum: ['match', 'similar', 'none'] };

schemas.PhotoSearch = object({
  id: uuid,
  status: { type: 'string', enum: ['completed', 'unavailable'] },
  unavailable_reason: nullable(string),
  level: nullable(level),
  results: array(
    object({
      offer: ref('Offer'),
      score: { type: 'number', minimum: -1, maximum: 1 },
      label: { type: 'string', enum: ['match', 'similar'] },
    }),
  ),
  image: object({ width: version, height: version }),
  product_request_id: nullable(uuid),
  next: object({ refine: { type: 'boolean' }, request_product: { type: 'boolean', enum: [true] } }),
  expires_at: dateTime,
});
schemas.PhotoSearch.description =
  'Offres publiques existantes avec leur similarité. « match » = similarité forte et nettement devant la suivante, jamais une identification certaine ; « similar » sinon ; « none » propose la demande de produit. Sans IA configurée : « unavailable », recherche texte, filtres et demande restent disponibles.';
const reason = object({
  key: string,
  label: translated,
  unit: nullable(string),
  status: { type: 'string', enum: ['met', 'not_met', 'unknown'] },
  value: {},
  wanted: {},
});
schemas.ChoiceRecommendation = object({
  results: array(
    object({
      offer: ref('Offer'),
      met: count,
      unknown: count,
      criteria: count,
      reasons: array(reason),
      explanation: string,
    }),
  ),
  request_product: { type: 'boolean' },
});
schemas.ChoiceRecommendation.description =
  'Uniquement des offres publiées, disponibles ou à confirmer, dans le budget. Chaque raison cite une valeur connue ; une valeur absente est « unknown », jamais devinée. Le prix est celui du catalogue.';
schemas.ChoiceQuestions = object({
  category: object({ slug: string, name: translated }),
  questions: array(
    object(
      {
        key: string,
        type: { type: 'string', enum: ['budget', 'number', 'choice', 'boolean'] },
        label: translated,
        unit: nullable(string),
        options: nullable(array(object({ value: string, label: translated }))),
      },
      ['key', 'type', 'label', 'unit'],
    ),
  ),
  offers: count,
});
schemas.OfferComparison = object({
  offers: array(ref('Offer')),
  unavailable: array(uuid),
  characteristics: array(
    object({
      key: string,
      label: translated,
      unit: nullable(string),
      comparable: { type: 'boolean' },
      values: {
        type: 'object',
        additionalProperties: object({
          state: { type: 'string', enum: ['known', 'unknown', 'not_applicable'] },
          value: {},
        }),
      },
    }),
  ),
  share_path: string,
});
schemas.OfferComparison.description =
  'Comparaison par caractéristiques de catégorie. Même caractéristique avec type ou unité différents : affichée, non comparée. Lien partageable sans donnée personnelle.';
schemas.AiSuggestion = object({
  id: uuid,
  product_id: uuid,
  product_version: version,
  fields: array({
    type: 'string',
    enum: ['name', 'description', 'category', 'attributes', 'alt_text'],
  }),
  proposal: object({
    fields: free,
    missing: array(string),
    rejected: array(object({ field: string, reason: string })),
  }),
  status: { type: 'string', enum: ['proposed', 'applied', 'discarded'] },
  model: string,
  requested_by: { type: 'integer' },
  applied_by: nullable({ type: 'integer' }),
  applied_fields: nullable(array(string)),
  applied_at: nullable(dateTime),
  created_at: dateTime,
});
schemas.AiSuggestion.description =
  'Proposition champ par champ (avant/après, confiance, source). Une valeur déduite d’une photo reste « uncertain ». Aucun prix, stock, garantie ni publication.';
schemas.AiUsage = object({
  ready: object({ embeddings: { type: 'boolean' }, generator: { type: 'boolean' } }),
  providers: object({ embeddings: string, generator: string }),
  monthly_budget_usd: string,
  month_estimated_usd: string,
  by_feature: array(
    object({ feature: string, status: string, calls: count, estimated_usd: string }),
  ),
});
schemas.PhotoIndexStatus = object({
  ready: { type: 'boolean' },
  model: string,
  indexed_offers: count,
  queued: count,
  failed: count,
  oldest_queued_at: nullable(dateTime),
});

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue ou appartenant à quelqu’un d’autre.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte (débit ou quota journalier).' },
};
function op(path, method, opts) {
  const {
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
    paths = {},
    extra = {},
  } = opts;
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: paths[m[1]] ?? uuid,
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
          ? ' Public (session invité ou compte).'
          : permission
            ? ' Permission serveur : ' + permission + '. Session avec MFA obligatoire.'
            : ' Compte connecté, ses propres données seulement.'),
      responses: {
        [status]: file
          ? {
              description: 'Image privée',
              content: { 'image/webp': { schema: { type: 'string', format: 'binary' } } },
            }
          : { description: 'Succès', content: { 'application/json': { schema: response } } },
        ...errors,
        ...extra,
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
const filterQuery = [
  { name: 'q', in: 'query', required: false, schema: { type: 'string', maxLength: 150 } },
  { name: 'category', in: 'query', required: false, schema: { type: 'string', maxLength: 120 } },
];
const unavailable = {
  503: {
    description:
      'Assistant IA indisponible (non configuré, quota, budget ou prestataire) ; la saisie manuelle reste possible.',
    content: {
      'application/json': {
        schema: object({
          error: object({
            code: { type: 'string', enum: ['AI_UNAVAILABLE'] },
            reason: string,
            message: string,
          }),
        }),
      },
    },
  },
};
const P = 'Recherche photo et assistants';

op('search/photo', 'post', {
  tag: P,
  open: true,
  multipart: true,
  status: 201,
  response: data(ref('PhotoSearch')),
  body: object(
    {
      file: { type: 'string', format: 'binary' },
      q: { type: 'string', maxLength: 150 },
      category: { type: 'string', maxLength: 120 },
    },
    ['file'],
  ),
  description:
    'Photo JPEG/PNG/WebP de 8 Mo et 12,6 mégapixels au plus, réencodée côté serveur (orientation, 1024 px, métadonnées retirées) et gardée en privé 24 h. Limite journalière par session ou compte.',
});
op('search/photo/{id}', 'get', {
  tag: P,
  open: true,
  query: filterQuery,
  response: data(ref('PhotoSearch')),
  description:
    'Affine par mots ou catégorie sans renvoyer la photo ; les offres sont relues publiques à l’instant.',
});
op('search/photo/{id}/image', 'get', {
  tag: P,
  open: true,
  file: true,
  description: 'La photo réencodée, seulement pour sa session ou son compte.',
});
op('search/photo/{id}/product-request', 'post', {
  tag: P,
  open: true,
  status: 201,
  response: data(ref('ProductRequest')),
  body: object({
    consent: { type: 'boolean', enum: [true] },
    locale: { type: 'string', enum: ['fr', 'en'] },
    details: ref('ProductRequestDetails'),
  }),
  description:
    'Avec l’accord du client, crée le brouillon de demande de produit avec la photo (privée) et ses précisions ; une seule fois par recherche. Ni commande ni paiement.',
});
op('assistant/choice/{slug}', 'get', {
  tag: P,
  open: true,
  paths: { slug: { type: 'string', pattern: '^[a-z0-9-]{1,120}$' } },
  response: data(ref('ChoiceQuestions')),
  description: 'Questions utiles : budget et caractéristiques filtrables de la catégorie.',
});
op('assistant/choice', 'post', {
  tag: P,
  open: true,
  response: data(ref('ChoiceRecommendation')),
  body: object(
    {
      category: { type: 'string', maxLength: 120 },
      budget: nullable({ type: 'integer', minimum: 1 }),
      answers: { type: 'object', additionalProperties: true },
    },
    ['category'],
  ),
  description:
    'Cinq offres au plus, classées par critères remplis, puis inconnus, puis prix ; explication courte en FR ou EN.',
});
op('compare', 'get', {
  tag: P,
  open: true,
  query: [
    {
      name: 'offers[]',
      in: 'query',
      required: true,
      schema: { type: 'array', minItems: 2, maxItems: 4, items: uuid },
    },
  ],
  response: data(ref('OfferComparison')),
  description: 'Compare deux à quatre offres publiques.',
});
op('operations/catalogue/products/{id}/assistant', 'post', {
  tag: P,
  permission: 'catalogue.write',
  status: 201,
  idempotent: false,
  response: data(ref('AiSuggestion')),
  body: object(
    {
      fields: array({
        type: 'string',
        enum: ['name', 'description', 'category', 'attributes', 'alt_text'],
      }),
      note: nullable({ type: 'string', maxLength: 500 }),
      supplier_text: nullable({ type: 'string', maxLength: 8000 }),
    },
    ['fields'],
  ),
  extra: unavailable,
  description:
    'Proposition de l’assistant pour les champs choisis. Le texte fournisseur est traité comme donnée non fiable ; ni prix, ni marge, ni donnée client n’est envoyé. Quotas par rôle et budget mensuel.',
});
op('operations/catalogue/products/{id}/assistant', 'get', {
  tag: P,
  permission: 'catalogue.read',
  query: [pageQuery],
  response: page(ref('AiSuggestion')),
  description: 'Historique des propositions du produit.',
});
op('operations/ai-suggestions/{id}/apply', 'post', {
  tag: P,
  permission: 'catalogue.write',
  response: data(object({ suggestion: ref('AiSuggestion'), product: ref('CatalogueRecord') })),
  body: object(
    {
      version,
      accept: array({ type: 'string', maxLength: 120 }),
      edits: { type: 'object', additionalProperties: true },
      variant_ids: nullable(array(uuid)),
    },
    ['version', 'accept'],
  ),
  description:
    'Applique au brouillon les champs acceptés (corrigés au besoin : name.fr, description.en, category, attributes.<clé>, alt_text.<média>) avec la version vue ; ne publie pas. Le journal garde les champs issus de l’assistant et ceux corrigés.',
});
op('operations/ai-suggestions/{id}/discard', 'post', {
  tag: P,
  permission: 'catalogue.write',
  response: data(ref('AiSuggestion')),
  description: 'Écarte la proposition.',
});
op('operations/ai/usage', 'get', {
  tag: P,
  permission: 'system.read',
  response: data(ref('AiUsage')),
  description:
    'Consommation estimée du mois (heure de Douala) face au budget, par fonction ; état de configuration des prestataires.',
});
op('operations/search/photo-index', 'get', {
  tag: P,
  permission: 'catalogue.read',
  response: data(ref('PhotoIndexStatus')),
  description: 'État de l’index photo : offres indexées, en file, en échec.',
});
op('operations/search/photo-index/reindex', 'post', {
  tag: P,
  permission: 'catalogue.publish',
  response: data(object({ queued: count })),
  description:
    'Remet toutes les offres en file ; le service planifié recalcule sans bloquer les pages.',
});

api.info.version = '0.41.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
