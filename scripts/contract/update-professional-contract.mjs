// Company permissions, immutable commercial revisions and a single conversion into the regular order flow.
import { readFileSync, writeFileSync } from 'node:fs';

const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const schemas = api.components.schemas;
const str = { type: 'string' };
const bool = { type: 'boolean' };
const uuid = { type: 'string', format: 'uuid' };
const dateTime = { type: 'string', format: 'date-time' };
const version = { type: 'integer', minimum: 1 };
const xaf = { type: 'string', pattern: '^(0|[1-9][0-9]*)$' };
const qty = { type: 'integer', minimum: 1, maximum: 1000000 };
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const array = (items) => ({ type: 'array', items });
const object = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const localized = object({ fr: str, en: str });
const identityFields = {
  legal_name: { type: 'string', minLength: 1, maxLength: 200 },
  billing_address: { type: 'string', minLength: 1, maxLength: 1000 },
  niu: nullable(str),
  rccm: nullable(str),
  email: nullable({ type: 'string', format: 'email' }),
  phone: nullable(str),
};
schemas.ProfessionalIdentity = object(identityFields, ['legal_name', 'billing_address']);
const addressFields = {
  label: str,
  district: str,
  landmark: str,
  instructions: nullable(str),
  latitude: nullable({ type: 'number', minimum: -90, maximum: 90 }),
  longitude: nullable({ type: 'number', minimum: -180, maximum: 180 }),
  contact_name: str,
  contact_phone: str,
  alternate_name: nullable(str),
  alternate_phone: nullable(str),
};
schemas.ProfessionalAddress = object({
  id: uuid,
  version,
  address: object(addressFields),
  served: bool,
});
schemas.ProfessionalProfile = object({
  id: uuid,
  version,
  billing_identity: ref('ProfessionalIdentity'),
  identity_verified: { type: 'boolean', const: false },
  members: array(
    object({
      id: { type: 'integer', minimum: 1 },
      name: str,
      email: str,
      role: { type: 'string', enum: ['owner', 'buyer', 'viewer'] },
    }),
  ),
  addresses: array(ref('ProfessionalAddress')),
});
schemas.ProfessionalConditions = structuredClone(schemas.CartConditions);
schemas.ProfessionalConditions.properties.attributes = {
  anyOf: [
    { type: 'object', additionalProperties: true },
    { type: 'array', maxItems: 0, items: {} },
  ],
};
const requestedLine = {
  offer_id: uuid,
  quantity: qty,
  conditions: ref('ProfessionalConditions'),
  name: localized,
  sku: str,
  shop: object({ id: uuid, name: str, slug: str }),
};
schemas.ProfessionalRequestedLine = object(requestedLine);
schemas.ProfessionalQuotedLine = object({
  ...requestedLine,
  catalogue_unit_price: xaf,
  unit_price: xaf,
  line_total: xaf,
});
const sellerFields = structuredClone(schemas.BillingIdentity.properties);
delete sellerFields.version;
delete sellerFields.missing;
schemas.ProfessionalQuoteSnapshot = object({
  number: str,
  currency: { type: 'string', const: 'XAF' },
  demo: bool,
  buyer: ref('ProfessionalIdentity'),
  seller: object(sellerFields),
  lines: array(ref('ProfessionalQuotedLine')),
  items_total: xaf,
  delivery: object({
    total: xaf,
    breakdown: array(object({ code: str, quantity: { type: 'integer' }, amount: xaf })),
    demo_amounts: bool,
  }),
  totals: object({
    total: xaf,
    vat_rate_basis_points: nullable({ type: 'integer', minimum: 1 }),
    vat: nullable(xaf),
    excluding_vat: nullable(xaf),
  }),
  conditions: localized,
  stock_reserved: { type: 'boolean', const: false },
  supplier_confirmation_required: bool,
  payment_terms: { type: 'string', const: 'prepaid_monetbil' },
  campaigns_combined: { type: 'boolean', const: false },
});
schemas.ProfessionalQuoteRevision = object(
  {
    id: uuid,
    revision: version,
    snapshot: ref('ProfessionalQuoteSnapshot'),
    snapshot_sha256: { type: 'string', pattern: '^[a-f0-9]{64}$' },
    valid_until: dateTime,
    issued_at: dateTime,
    internal: {
      type: 'object',
      additionalProperties: true,
      description:
        'Uniquement pour la gestion disposant de pricing.read ; jamais pour un contact entreprise.',
    },
  },
  ['id', 'revision', 'snapshot', 'snapshot_sha256', 'valid_until', 'issued_at'],
);
schemas.ProfessionalQuote = object({
  id: uuid,
  profile_id: uuid,
  number: nullable(str),
  version,
  status: {
    type: 'string',
    enum: ['requested', 'issued', 'accepted', 'declined', 'expired', 'converted'],
  },
  current_revision_id: nullable(uuid),
  requested_lines: array(ref('ProfessionalRequestedLine')),
  request_notes: str,
  accepted_at: nullable(dateTime),
  decline_reason: nullable(str),
  order_id: nullable(uuid),
  revisions: array(ref('ProfessionalQuoteRevision')),
  created_at: dateTime,
});
schemas.ProfessionalCheckoutPreview = object({
  delivery: ref('DeliveryQuote'),
  accepted_total: xaf,
  stock_reserved: { type: 'boolean', const: false },
});
schemas.Order.properties.professional_quote_revision_id = nullable(uuid);
schemas.Order.required = [
  ...new Set([...schemas.Order.required, 'professional_quote_revision_id']),
];

function operation(
  method,
  url,
  {
    response,
    status = 200,
    body,
    permission,
    roles,
    paged = false,
    sensitive = false,
    description,
  },
) {
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: match[1] === 'userId' ? { type: 'integer', minimum: 1 } : uuid,
  }));
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  if (paged)
    parameters.push({ name: 'page', in: 'query', schema: { type: 'integer', minimum: 1 } });
  parameters.push({ name: 'locale', in: 'query', schema: { type: 'string', enum: ['fr', 'en'] } });
  const op = {
    operationId: (method + '_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Professionnels et devis'],
    security: [{ sessionCookie: [] }],
    parameters,
    ...(permission
      ? { 'x-permission': permission }
      : {
          'x-resource-owner': 'company-member',
          'x-company-roles': roles ?? ['owner', 'buyer', 'viewer'],
        }),
    ...(sensitive ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      ' Session web HttpOnly requise.' +
      (method === 'get' ? '' : ' CSRF et Idempotency-Key UUID requis.') +
      (permission
        ? ' MFA de gestion et permission ' + permission + '.'
        : ' Droits du profil vérifiés côté serveur, sans octroi de rôle KORA.') +
      (sensitive ? ' Mot de passe reconfirmé depuis 15 minutes au plus.' : ''),
    responses: {
      [status]: {
        description: 'Succès',
        content: {
          'application/json': {
            schema: paged
              ? object({ data: array(response), meta: ref('PageMeta') })
              : object({ data: response }),
          },
        },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: { description: 'Profil, contact, adresse ou devis inconnu ou non autorisé.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
      429: { description: 'Limite atteinte.' },
    },
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
  };
  api.paths['/api/v1/' + url] ??= {};
  api.paths['/api/v1/' + url][method] = op;
}
const profiles = 'professional/profiles';
const profile = profiles + '/{id}';
const quotes = profile + '/quotes';
const quote = quotes + '/{quoteId}';
const buyers = ['owner', 'buyer'];
operation('get', profiles, {
  response: array(ref('ProfessionalProfile')),
  description: 'Profils dont le compte est actuellement membre.',
});
operation('post', profiles, {
  response: ref('ProfessionalProfile'),
  body: ref('ProfessionalIdentity'),
  status: 201,
  roles: ['account'],
  description:
    'Crée un profil avec identité déclarée, sans prétendre la vérifier ; le créateur en est propriétaire.',
});
operation('get', profile, {
  response: ref('ProfessionalProfile'),
  description: 'Identité, contacts autorisés et adresses partagées du profil.',
});
operation('put', profile, {
  response: ref('ProfessionalProfile'),
  roles: ['owner'],
  body: object({ version, ...identityFields }, ['version', 'legal_name', 'billing_address']),
  description: 'Identité versionnée ; les devis et factures déjà figés ne sont pas réécrits.',
});
operation('post', profile + '/members', {
  response: ref('ProfessionalProfile'),
  roles: ['owner'],
  body: object({
    version,
    email: { type: 'string', format: 'email' },
    role: { type: 'string', enum: ['buyer', 'viewer'] },
  }),
  description:
    'Autorise explicitement un compte avec e-mail vérifié ; buyer peut demander/accepter/convertir, viewer lit seulement. Aucun message externe envoyé.',
});
operation('delete', profile + '/members/{userId}', {
  response: ref('ProfessionalProfile'),
  roles: ['owner'],
  body: object({ version }),
  description:
    'Révoque le contact ; le propriétaire ne peut pas être retiré. Les routes normales de paiement vérifient aussi la qualité actuelle de membre pour les commandes professionnelles.',
});
const addressRequired = ['label', 'district', 'landmark', 'contact_name', 'contact_phone'];
operation('post', profile + '/addresses', {
  response: ref('ProfessionalAddress'),
  status: 201,
  roles: buyers,
  body: object(addressFields, addressRequired),
  description: 'Ajoute une adresse commune par quartier/repère ; carte facultative.',
});
operation('put', profile + '/addresses/{addressId}', {
  response: ref('ProfessionalAddress'),
  roles: buyers,
  body: object({ version, ...addressFields }, ['version', ...addressRequired]),
  description: 'Modification versionnée ; les anciennes demandes conservent leur adresse exacte.',
});
operation('delete', profile + '/addresses/{addressId}', {
  response: object({ id: uuid, archived: { type: 'boolean', const: true } }),
  roles: buyers,
  body: object({ version }),
  description: 'Archive l’adresse sans toucher les devis ou commandes passés.',
});
operation('get', quotes, {
  response: ref('ProfessionalQuote'),
  paged: true,
  description:
    'Historique partagé des devis, selon les droits actuels du profil ; coûts internes exclus.',
});
operation('post', quotes, {
  response: ref('ProfessionalQuote'),
  status: 201,
  roles: buyers,
  body: object(
    {
      cart_version: version,
      address_id: uuid,
      notes: nullable({ type: 'string', maxLength: 3000 }),
    },
    ['cart_version', 'address_id'],
  ),
  description:
    'Fige les lignes sélectionnées du panier et l’adresse d’entreprise, sans réservation ni paiement. Le panier personnel peut continuer à évoluer.',
});
operation('get', quote, {
  response: ref('ProfessionalQuote'),
  description:
    'Demande et versions commerciales émises ; expiration effective dès lecture, sans dépendre du planificateur.',
});
operation('post', quote + '/accept', {
  response: ref('ProfessionalQuote'),
  roles: buyers,
  body: object({
    version,
    revision_id: uuid,
    accepted_total: xaf,
    supplier_unreserved_acknowledged: { type: 'boolean', const: true },
  }),
  description:
    'Accepte la version et son total exact avec reconnaissance explicite que l’acceptation seule ne garantit pas le stock externe. Acteur et date enregistrés.',
});
operation('post', quote + '/decline', {
  response: ref('ProfessionalQuote'),
  roles: buyers,
  body: object({ version, reason: nullable({ type: 'string', maxLength: 1000 }) }, ['version']),
  description: 'Refuse la proposition sans effacer les versions déjà émises.',
});
operation('post', quote + '/checkout-preview', {
  response: ref('ProfessionalCheckoutPreview'),
  roles: buyers,
  description:
    'Recontrôle les sources physiques, contraintes et créneaux réellement disponibles. Aucun stock/créneau réservé et aucun supplément ajouté au prix proposé.',
});
operation('post', quote + '/convert', {
  response: ref('Order'),
  status: 201,
  roles: buyers,
  body: object({
    version,
    accepted_total: xaf,
    rule_id: uuid,
    date: { type: 'string', format: 'date' },
  }),
  description:
    'Devis accepté et valable seulement ; stock et créneau revérifiés. Prix et identité de facturation acceptés conservés, même si le catalogue ou tarif a changé. Une seule commande par révision ; répétition renvoie cette même commande. Paiement préalable par le circuit Monetbil normal, aucun crédit client.',
});
operation('get', profile + '/orders/{orderId}', {
  response: ref('Order'),
  description:
    'Lecture partagée des commandes issues du profil. Un autre contact ne reçoit pas le lien de paiement du payeur ; les opérations financières restent celles du compte qui a converti.',
});
operation('get', 'operations/professional-quotes', {
  response: ref('ProfessionalQuote'),
  permission: 'professional.read',
  paged: true,
  description:
    'Demandes à traiter et historique de gestion. Détails des coûts uniquement avec pricing.read.',
});
operation('get', 'operations/professional-quotes/{quoteId}', {
  response: ref('ProfessionalQuote'),
  permission: 'professional.read',
  description:
    'Détail du devis, avec coûts et motifs de dérogation seulement pour un lecteur de prix autorisé.',
});
operation('post', 'operations/professional-quotes/{quoteId}/issue', {
  response: ref('ProfessionalQuote'),
  permission: 'professional.manage',
  sensitive: true,
  body: object(
    {
      version,
      valid_until: dateTime,
      conditions: localized,
      lines: array(object({ offer_id: uuid, unit_price: xaf })),
      loss_reason: nullable({ type: 'string', maxLength: 1000 }),
    },
    ['version', 'valid_until', 'conditions', 'lines'],
  ),
  description:
    'Émet une nouvelle version immuable numérotée avec empreinte. Accords fournisseurs réels, volumes et livraison vérifiés ; prix TTC proposés bornés au catalogue courant. Marge négative soumise à pricing.loss_override et motif propriétaire. Devis de démonstration tant que l’identité vendeur est incomplète ; aucune TVA inventée ni cumul automatique de campagnes.',
});
api.info.version = '0.16.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
