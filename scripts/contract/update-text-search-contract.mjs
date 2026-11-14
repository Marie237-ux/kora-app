import { readFileSync, writeFileSync } from 'node:fs';
const p = new URL('../../docs/openapi.json', import.meta.url),
  api = JSON.parse(readFileSync(p, 'utf8')),
  S = api.components.schemas;
const uuid = { type: 'string', format: 'uuid' },
  int = { type: 'integer' },
  str = { type: 'string' },
  bool = { type: 'boolean' },
  ref = (n) => ({ $ref: '#/components/schemas/' + n });
const obj = (properties) => ({
  type: 'object',
  properties,
  required: Object.keys(properties),
  additionalProperties: false,
});
S.SearchSynonym = obj({
  id: uuid,
  locale: { type: 'string', enum: ['fr', 'en'] },
  terms: { type: 'array', minItems: 2, maxItems: 16, items: { type: 'string', maxLength: 50 } },
  active: bool,
  version: { type: 'integer', minimum: 1 },
});
S.SearchMiss = obj({
  day: { type: 'string', format: 'date' },
  locale: { type: 'string', enum: ['fr', 'en'] },
  query_digest: { type: 'string', pattern: '^[a-f0-9]{64}$' },
  token_count: int,
  occurrences: int,
});
function op(method, url, response, { body, permission, status = 200, paged = false } = {}) {
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (paged)
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const operation = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Recherche texte'],
    security: permission ? [{ sessionCookie: [] }] : [],
    parameters,
    ...(permission ? { 'x-permission': permission } : {}),
    description: permission
      ? 'Permission ' +
        permission +
        ', session HttpOnly et MFA ; CSRF et UUID Idempotency-Key pour les écritures.'
      : 'Suggestions du catalogue actuel publié seulement, aucune requête IA.',
    responses: {
      [status]: {
        description: 'Succès',
        content: {
          'application/json': {
            schema: obj({
              data: paged ? { type: 'array', items: response } : response,
              ...(paged ? { meta: ref('PageMeta') } : {}),
            }),
          },
        },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/ReauthenticationRequired' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
  if (body)
    operation.requestBody = { required: true, content: { 'application/json': { schema: body } } };
  api.paths[url] ??= {};
  api.paths[url][method] = operation;
  return operation;
}
const props = S.SearchSynonym.properties,
  body = obj({ locale: props.locale, terms: props.terms, active: bool });
op('get', '/api/v1/operations/search/synonyms', ref('SearchSynonym'), {
  permission: 'catalogue.read',
  paged: true,
});
op('post', '/api/v1/operations/search/synonyms', ref('SearchSynonym'), {
  permission: 'catalogue.write',
  body,
  status: 201,
});
op('put', '/api/v1/operations/search/synonyms/{id}', ref('SearchSynonym'), {
  permission: 'catalogue.write',
  body: obj({ ...body.properties, version: props.version }),
});
op('get', '/api/v1/operations/search/misses', ref('SearchMiss'), {
  permission: 'catalogue.read',
  paged: true,
});
const suggest = op('get', '/api/v1/catalogue/suggestions', {
  type: 'array',
  maxItems: 8,
  items: ref('Offer'),
});
suggest.parameters.push(
  {
    name: 'q',
    in: 'query',
    required: true,
    schema: { type: 'string', minLength: 2, maxLength: 150 },
  },
  { name: 'locale', in: 'query', schema: props.locale },
);
api.paths['/api/v1/catalogue'].get.description =
  'Recherche des champs publiés FR/EN, accents neutralisés, préfixes, synonymes administrés et fautes bornées ; huit mots utiles maximum. Titres, descriptions, catégories héritées, boutiques, SKU et attributs connus. Filtres, tri et pagination combinables. Les résultats vides sont agrégés par HMAC journalier sans texte, IP ou compte.';
api.info.version = '0.6.0';
writeFileSync(p, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
