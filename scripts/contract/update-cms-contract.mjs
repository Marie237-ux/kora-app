// Run after the engagement and messaging script: CMS (settings, pages, campaigns) and legal conditions.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' };
const count = { type: 'integer', minimum: 0 };
const version = { type: 'integer', minimum: 1 };
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
const translated = object({ fr: string, en: string });
const legalKind = { type: 'string', enum: ['terms_of_use', 'terms_of_sale', 'privacy', 'returns'] };
const slug = { type: 'string', pattern: '^[a-z0-9-]{1,120}$' };
const hex = { type: 'string', pattern: '^#[0-9a-fA-F]{6}$' };
const tokens = (required) =>
  object(
    { primary: hex, on_primary: hex, background: hex, text: hex, accent: hex },
    required ? undefined : [],
  );
const content = {
  type: 'object',
  description:
    'Données structurées validées par le serveur : réglages du site (entreprise, logos, thème clair/sombre, polices approuvées, menus, réseaux) ou blocs de page (product_selection, shop_selection, image_text, category, service_message, banner, editorial, faq). Jamais de HTML ni de code ; liens internes ou https ; contraste texte/fond ≥ 4,5:1.',
  additionalProperties: true,
};

schemas.CmsVersion = object({
  id: uuid,
  number: version,
  status: { type: 'string', enum: ['draft', 'scheduled', 'published', 'archived'] },
  content,
  publish_at: nullable(dateTime),
  published_at: nullable(dateTime),
  author_id: { type: 'integer' },
  preview_token: string,
  created_at: dateTime,
});
schemas.CmsDocument = object({
  id: uuid,
  kind: { type: 'string', enum: ['settings', 'page'] },
  slug: string,
  title: translated,
  live_number: nullable(version),
  latest_number: nullable(version),
});
const campaignFields = {
  name: text(150),
  starts_at: dateTime,
  ends_at: dateTime,
  priority: { type: 'integer', minimum: 0, maximum: 1000 },
  locales: array({ type: 'string', enum: ['fr', 'en'] }),
  pages: array({ type: 'string', pattern: '^(\\*|[a-z0-9-]+)$' }),
  theme: object({ light: tokens(false), dark: tokens(false) }, []),
  banner: object({ fr: nullable(string), en: nullable(string) }),
  product_ids: array(uuid),
  delivery_offer_id: nullable(uuid),
};
schemas.CmsCampaign = object({
  id: uuid,
  ...campaignFields,
  status: { type: 'string', enum: ['draft', 'active', 'paused'] },
  version,
});
schemas.CmsCampaign.description =
  'Événement du calendrier (période Africa/Douala, priorité explicite, pages et langues ciblées). Sa fin rétablit la configuration de base sans action.';
schemas.CmsRevision = object({ revision: version, next_change_at: nullable(dateTime) });
schemas.CmsRevision.description =
  'Numéro de révision du contenu et prochain changement prévu (version programmée, début ou fin d’événement, conditions entrant en vigueur) : le site rafraîchit ses caches à ces moments.';
schemas.CmsPublicPage = object({
  revision: ref('CmsRevision'),
  settings: nullable({ type: 'object', additionalProperties: true }),
  page: nullable(
    object({
      slug: string,
      version,
      title: translated,
      blocks: array({ type: 'object', additionalProperties: true }),
    }),
  ),
  media: { type: 'object', additionalProperties: string },
  campaign: nullable(
    object({
      id: uuid,
      name: string,
      banner: nullable(string),
      product_ids: array(uuid),
      delivery_offer_id: nullable(uuid),
      ends_at: dateTime,
    }),
  ),
});
schemas.CmsPublicPage.description =
  'Page en vigueur avec les réglages du site, thème fusionné avec l’événement actif. Les sélections ne gardent que les produits et boutiques publiés ; un média retiré disparaît de « media ».';
const legal = {
  id: uuid,
  kind: legalKind,
  number: version,
  body: translated,
  effective_at: dateTime,
  requires_acceptance: { type: 'boolean' },
};
schemas.LegalVersion = object(legal);
schemas.StaffLegalVersion = object({
  ...legal,
  status: { type: 'string', enum: ['draft', 'published'] },
  author_id: { type: 'integer' },
  published_at: nullable(dateTime),
});

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue ou appartenant à quelqu’un d’autre.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
const pathSchemas = {
  slug,
  kind: { type: 'string', pattern: '^[a-z_]{1,20}$' },
  token: { type: 'string', pattern: '^[A-Za-z0-9]{48}$' },
};
function op(
  path,
  method,
  {
    permission,
    response,
    body,
    status = 200,
    description,
    tag,
    query,
    open,
    idempotent = true,
    paths = {},
  },
) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: paths[m[1]] ?? pathSchemas[m[1]] ?? uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get' && idempotent && !open)
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
        [status]: { description: 'Succès', content: { 'application/json': { schema: response } } },
        ...errors,
      },
      ...(body
        ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
        : {}),
    },
  };
}
const data = (s) => object({ data: s });
const page = (s) =>
  object({ data: array(s), meta: object({ page: version, last_page: version, total: count }) });
const pageQuery = { name: 'page', in: 'query', required: false, schema: version };
const C = 'CMS';
const L = 'Conditions légales';

op('content/pages/{slug}', 'get', {
  tag: C,
  open: true,
  response: data(ref('CmsPublicPage')),
  query: [
    {
      name: 'locale',
      in: 'query',
      required: false,
      schema: { type: 'string', enum: ['fr', 'en'] },
    },
  ],
  description:
    'Réglages, page en vigueur et événement actif pour la langue. « home » existe toujours (vide tant que rien n’est publié) ; une autre page non publiée répond 404.',
});
op('content/revision', 'get', {
  tag: C,
  open: true,
  response: data(ref('CmsRevision')),
  description: 'Révision du contenu et prochain changement prévu, pour invalider les caches.',
});
op('operations/cms/documents', 'get', {
  tag: C,
  permission: 'content.read',
  response: page(ref('CmsDocument')),
  query: [
    {
      name: 'kind',
      in: 'query',
      required: false,
      schema: { type: 'string', enum: ['settings', 'page'] },
    },
    pageQuery,
  ],
  description: 'Documents du site avec la version en ligne et la dernière version.',
});
op('operations/cms/documents/{id}/versions', 'get', {
  tag: C,
  permission: 'content.read',
  response: page(ref('CmsVersion')),
  query: [pageQuery],
  description: 'Historique complet des versions, de la plus récente à la plus ancienne.',
});
op('operations/cms/{kind}/{slug}/drafts', 'post', {
  tag: C,
  permission: 'content.write',
  status: 201,
  paths: { kind: { type: 'string', enum: ['settings', 'page'] } },
  response: data(ref('CmsVersion')),
  body: object({ title: translated, content }, ['content']),
  description:
    'Nouveau brouillon (numéro suivant) validé à l’enregistrement ; les réglages n’ont qu’un document, « site ».',
});
op('operations/cms/preview/{token}', 'get', {
  tag: C,
  permission: 'content.read',
  response: data(object({ content, status: string, number: version })),
  description: 'Aperçu d’une version pour l’équipe, jamais indexé (X-Robots-Tag noindex).',
});
op('operations/cms/versions/{id}/publish', 'post', {
  tag: C,
  permission: 'content.publish',
  response: data(ref('CmsVersion')),
  body: object({ publish_at: nullable(dateTime) }, []),
  description:
    'Publie maintenant ou programme (heure Africa/Douala avec décalage). Les médias doivent être publics ; une version publiée n’est plus modifiable.',
});
op('operations/cms/versions/{id}/rollback', 'post', {
  tag: C,
  permission: 'content.publish',
  status: 201,
  response: data(ref('CmsVersion')),
  description:
    'Retour arrière : le contenu de cette version devient une nouvelle version publiée maintenant ; l’historique reste entier.',
});
op('operations/cms/campaigns', 'get', {
  tag: C,
  permission: 'content.read',
  response: page(ref('CmsCampaign')),
  query: [pageQuery],
  description: 'Calendrier des événements.',
});
const campaignBody = (withVersion) =>
  object({ ...campaignFields, ...(withVersion ? { version } : {}) }, [
    'name',
    'starts_at',
    'ends_at',
    'priority',
    'locales',
    'pages',
    'theme',
    'banner',
    ...(withVersion ? ['version'] : []),
  ]);
op('operations/cms/campaigns', 'post', {
  tag: C,
  permission: 'content.write',
  status: 201,
  response: data(ref('CmsCampaign')),
  body: campaignBody(false),
  description:
    'Crée un événement en brouillon : fin après début, bannière pour chaque langue ciblée, couleurs lisibles une fois fusionnées au thème de base.',
});
op('operations/cms/campaigns/{id}', 'put', {
  tag: C,
  permission: 'content.write',
  response: data(ref('CmsCampaign')),
  body: campaignBody(true),
  description: 'Modifie un événement (version attendue).',
});
op('operations/cms/campaigns/{id}/status', 'post', {
  tag: C,
  permission: 'content.publish',
  response: data(ref('CmsCampaign')),
  body: object({ status: { type: 'string', enum: ['active', 'paused'] }, version }),
  description: 'Active ou met en pause ; actif, il s’applique seul pendant sa période.',
});
op('legal/{kind}', 'get', {
  tag: L,
  open: true,
  paths: { kind: legalKind },
  response: object({
    data: ref('LegalVersion'),
    meta: object({ history: array(object({ id: uuid, number: version, effective_at: dateTime })) }),
  }),
  description: 'Version en vigueur et versions antérieures publiées.',
});
op('legal/versions/{id}', 'get', {
  tag: L,
  open: true,
  response: data(ref('LegalVersion')),
  description: 'Texte d’une version publiée, pour relire ce qui a été accepté.',
});
op('operations/cms/legal', 'get', {
  tag: L,
  permission: 'content.read',
  response: page(ref('StaffLegalVersion')),
  query: [pageQuery],
  description: 'Toutes les versions, brouillons compris.',
});
op('operations/cms/legal/{kind}/drafts', 'post', {
  tag: L,
  permission: 'content.write',
  status: 201,
  paths: { kind: legalKind },
  response: data(ref('StaffLegalVersion')),
  body: object({
    body: object({ fr: nullable(string), en: nullable(string) }),
    effective_at: dateTime,
    requires_acceptance: { type: 'boolean' },
  }),
  description: 'Brouillon de conditions avec date d’effet.',
});
op('operations/cms/legal/{id}/publish', 'post', {
  tag: L,
  permission: 'legal.publish',
  response: data(ref('StaffLegalVersion')),
  description:
    'Publie (texte FR et EN obligatoire) après confirmation récente de l’identité ; si la règle des quatre yeux est active, l’auteur ne peut pas publier. Les acceptations passées ne sont jamais réécrites.',
});
op('account/legal/pending', 'get', {
  tag: L,
  response: data(array(ref('LegalVersion'))),
  description: 'Versions en vigueur qui demandent une nouvelle acceptation explicite.',
});
op('account/legal/acceptances', 'post', {
  tag: L,
  status: 201,
  idempotent: false,
  response: data(
    object({ accepted: { type: 'boolean', enum: [true] }, kind: legalKind, number: version }),
  ),
  body: object(
    {
      version_id: uuid,
      context: { type: 'string', enum: ['signup', 'order', 'update'] },
      order_id: nullable(uuid),
    },
    ['version_id', 'context'],
  ),
  description:
    'Acceptation explicite d’une version publiée, conservée avec son contexte ; la même acceptation n’est enregistrée qu’une fois.',
});

api.info.version = '0.40.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
