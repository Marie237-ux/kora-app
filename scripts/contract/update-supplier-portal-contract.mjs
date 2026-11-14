// Run after the smart search script: supplier portal (members) and its management by the team.
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
const looseTranslated = object({ fr: nullable(string), en: nullable(string) });
const free = { type: 'object', additionalProperties: true };
const response = object({
  id: uuid,
  decision: { type: 'string', enum: ['confirmed', 'declined', 'ready', 'issue'] },
  reference: nullable(string),
  note: nullable(string),
  created_at: dateTime,
});

schemas.SupplierPortalSettings = object({
  enabled: { type: 'boolean' },
  availability: { type: 'string', enum: ['review', 'direct'] },
  version: count,
});
schemas.SupplierPortalSettings.description =
  'Portail construit et fermé par défaut ; « review » : chaque disponibilité déclarée est vérifiée par l’équipe, « direct » : appliquée à la déclaration. La publication reste toujours à KORA.';
schemas.SupplierMembership = object({
  enabled: { type: 'boolean' },
  suppliers: array(
    object({ id: uuid, name: string, role: { type: 'string', enum: ['manager', 'staff'] } }),
  ),
});
schemas.SupplierMember = object({
  id: uuid,
  supplier_id: uuid,
  user_id: { type: 'integer' },
  name: nullable(string),
  role: { type: 'string', enum: ['manager', 'staff'] },
  status: { type: 'string', enum: ['active', 'revoked'] },
  revoked_at: nullable(dateTime),
  created_at: dateTime,
});
schemas.SupplierInvitation = object(
  {
    id: uuid,
    supplier_id: uuid,
    role: { type: 'string', enum: ['manager', 'staff'] },
    status: { type: 'string', enum: ['pending', 'accepted', 'revoked', 'expired'] },
    expires_at: dateTime,
    accepted_at: nullable(dateTime),
    created_at: dateTime,
    url: string,
  },
  ['id', 'supplier_id', 'role', 'status', 'expires_at', 'accepted_at', 'created_at'],
);
schemas.SupplierOverview = object({
  supplier: object({ id: uuid, name: string }),
  role: { type: 'string', enum: ['manager', 'staff'] },
  shops: array(object({ id: uuid, name: string, slug: string, status: string })),
  counts: object({
    open_proposals: count,
    purchases_awaiting_answer: count,
    collections_open: count,
  }),
});
schemas.SupplierOffer = object({
  id: uuid,
  shop: string,
  product: free,
  sku: string,
  status: string,
  availability: string,
  reference_price: amount,
  minimum_quantity: version,
  warranty: free,
  declared: nullable(
    object({
      quantity: nullable(count),
      valid_until: dateTime,
      preparation_minutes: count,
      verified_at: dateTime,
    }),
  ),
  pending_declaration_id: nullable(uuid),
});
schemas.SupplierOffer.description =
  'Son prix convenu avec KORA ; jamais le prix de vente ni la marge de KORA.';
schemas.SupplierAvailabilityDeclaration = object({
  id: uuid,
  supplier_id: uuid,
  offer_id: uuid,
  quantity: nullable(count),
  valid_until: dateTime,
  preparation_minutes: count,
  note: nullable(string),
  status: { type: 'string', enum: ['pending', 'applied', 'rejected'] },
  declared_by: { type: 'integer' },
  reviewed_at: nullable(dateTime),
  created_at: dateTime,
});
schemas.SupplierPurchase = object({
  id: uuid,
  reference: string,
  status: string,
  mode: string,
  expected_total: amount,
  confirmation_reference: nullable(string),
  confirmed_at: nullable(dateTime),
  shared_at: dateTime,
  lines: array(
    object({
      id: uuid,
      product: free,
      sku: string,
      quantity: version,
      received_quantity: count,
      unit_cost: amount,
    }),
  ),
  responses: array(response),
});
schemas.SupplierPurchase.description =
  'Achat partagé explicitement par l’équipe. Sa réponse informe l’équipe ; elle ne confirme pas l’achat ni une dette.';
schemas.SupplierCollection = object({
  id: uuid,
  number: string,
  status: string,
  direct: { type: 'boolean' },
  point: nullable(object({ name: string, address: string })),
  courier: nullable(object({ public_name: string, badge: string })),
  packages: nullable(count),
  collected_at: nullable(dateTime),
  created_at: dateTime,
  lines: array(
    object({
      product: free,
      sku: string,
      expected_quantity: version,
      collected_quantity: nullable(count),
    }),
  ),
  responses: array(response),
});
schemas.SupplierCollection.description =
  'Quoi remettre, où et à quel livreur ; jamais le client ni la commande d’une livraison directe.';
schemas.SupplierWarranties = object({
  cases: array(
    object({
      number: string,
      product: free,
      sku: string,
      kind: string,
      quantity: version,
      identifiers: array(string),
      status: string,
      resolution: nullable(string),
      finding: nullable(string),
      decided_at: nullable(dateTime),
      created_at: dateTime,
    }),
  ),
});
schemas.SupplierContract = object({
  id: uuid,
  reference: string,
  status: { type: 'string', enum: ['approved', 'accepted', 'terminated'] },
  starts_on: { type: 'string', format: 'date' },
  ends_on: nullable({ type: 'string', format: 'date' }),
  terms: free,
  text: translated,
  acceptance: nullable(free),
  signed_document_id: nullable(uuid),
});
schemas.SupplierSharedDocument = object({
  id: uuid,
  document_id: uuid,
  title: string,
  direction: { type: 'string', enum: ['inbound', 'outbound'] },
  mime: string,
  bytes: count,
  scan_status: string,
  created_at: dateTime,
});
schemas.SupplierProposal = object({
  id: uuid,
  number: string,
  supplier_id: uuid,
  kind: { type: 'string', enum: ['product', 'offer_update', 'profile'] },
  shop_id: nullable(uuid),
  offer_id: nullable(uuid),
  payload: free,
  status: { type: 'string', enum: ['submitted', 'in_review', 'accepted', 'rejected', 'withdrawn'] },
  review_note: nullable(string),
  reviewed_at: nullable(dateTime),
  product_id: nullable(uuid),
  version,
  created_at: dateTime,
});
schemas.SupplierProposal.description =
  'Proposition en texte simple, décidée par l’équipe ; une fiche acceptée devient un brouillon catalogue, jamais publiée ni tarifée automatiquement.';

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { description: 'Droit absent ou portail fermé (SUPPLIER_PORTAL_DISABLED).' },
  404: { description: 'Ressource inconnue ou d’un autre fournisseur.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function op(path, method, opts) {
  const {
    permission,
    response: schema,
    body,
    multipart,
    status = 200,
    description,
    tag,
    query,
    idempotent = true,
    file,
    paths = {},
  } = opts;
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: paths[m[1]] ?? uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get' && idempotent && !multipart)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths['/api/v1/' + path] = {
    ...api.paths['/api/v1/' + path],
    [method]: {
      operationId: (method + '_' + path).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: [tag],
      security: [{ sessionCookie: [] }],
      parameters,
      ...(permission ? { 'x-permission': permission } : {}),
      description:
        description +
        (permission
          ? ' Permission serveur : ' + permission + '. Session avec MFA obligatoire.'
          : ' Membre actif de ce fournisseur, portail ouvert.'),
      responses: {
        [status]: file
          ? {
              description: 'Document privé',
              content: {
                'application/octet-stream': { schema: { type: 'string', format: 'binary' } },
              },
            }
          : { description: 'Succès', content: { 'application/json': { schema } } },
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
const S = 'Portail fournisseur';
const T = 'Portail fournisseur — équipe';
const base = 'supplier-portal/{supplier}';

op('supplier-portal', 'get', {
  tag: S,
  response: data(ref('SupplierMembership')),
  description:
    'Fournisseurs dont la personne est membre active, et état d’ouverture du portail. Compte connecté.',
});
op('supplier-invitations/{token}/accept', 'post', {
  tag: S,
  idempotent: false,
  paths: { token: { type: 'string', pattern: '^[a-f0-9]{64}$' } },
  response: data(ref('SupplierMembership')),
  description:
    'Accepte une invitation avec le compte de l’adresse invitée (e-mail vérifié) ; une seule fois, 7 jours. Même réponse pour toute invitation inconnue, utilisée, retirée, expirée ou destinée à une autre adresse.',
});
op(base, 'get', {
  tag: S,
  response: data(ref('SupplierOverview')),
  description: 'Accueil : boutiques et actions en attente.',
});
op(base + '/offers', 'get', {
  tag: S,
  query: [pageQuery],
  response: page(ref('SupplierOffer')),
  description: 'Ses offres et disponibilités déclarées.',
});
op(base + '/offers/{offer}/availability', 'post', {
  tag: S,
  status: 201,
  response: data(ref('SupplierAvailabilityDeclaration')),
  body: object(
    {
      quantity: nullable({ type: 'integer', minimum: 0 }),
      valid_until: dateTime,
      preparation_minutes: { type: 'integer', minimum: 0, maximum: 525600 },
      note: nullable({ type: 'string', maxLength: 500 }),
    },
    ['valid_until', 'preparation_minutes'],
  ),
  description: 'Déclare une disponibilité ; vérifiée par l’équipe ou appliquée selon le réglage.',
});
op(base + '/purchases', 'get', {
  tag: S,
  query: [pageQuery],
  response: page(ref('SupplierPurchase')),
  description: 'Achats que l’équipe lui a partagés.',
});
op(base + '/purchases/{id}/response', 'post', {
  tag: S,
  status: 201,
  response: data(ref('SupplierPurchase')),
  body: object(
    {
      decision: { type: 'string', enum: ['confirmed', 'declined'] },
      reference: nullable({ type: 'string', maxLength: 150 }),
      note: nullable({ type: 'string', maxLength: 1000 }),
    },
    ['decision'],
  ),
  description:
    'Confirme (avec sa référence) ou refuse (avec motif) ; réponse conservée telle quelle.',
});
op(base + '/collections', 'get', {
  tag: S,
  query: [pageQuery],
  response: page(ref('SupplierCollection')),
  description: 'Collectes prévues dans ses boutiques.',
});
op(base + '/collections/{id}/response', 'post', {
  tag: S,
  status: 201,
  response: data(ref('SupplierCollection')),
  body: object(
    {
      decision: { type: 'string', enum: ['ready', 'issue'] },
      note: nullable({ type: 'string', maxLength: 1000 }),
    },
    ['decision'],
  ),
  description: 'Signale la marchandise prête ou un problème (motif obligatoire).',
});
op(base + '/warranties', 'get', {
  tag: S,
  response: data(ref('SupplierWarranties')),
  description:
    'Dossiers SAV attribués au fournisseur : numéros de série et constat, sans données client.',
});
op(base + '/statement', 'get', {
  tag: S,
  query: [{ name: 'as_of', in: 'query', required: false, schema: dateTime }, pageQuery],
  response: object({
    data: array(ref('SupplierLedgerEvent')),
    meta: object({ page: version, last_page: version, total: count }),
    balances: ref('SupplierBalances'),
    known_as_of: dateTime,
  }),
  description:
    'Relevé : achats confirmés, réceptions, dettes, règlements vérifiés, avoirs, retours ; sans révisions internes.',
});
op(base + '/contracts', 'get', {
  tag: S,
  response: data(array(ref('SupplierContract'))),
  description: 'Contrats approuvés, acceptés ou terminés ; jamais les brouillons.',
});
op(base + '/documents', 'get', {
  tag: S,
  response: data(array(ref('SupplierSharedDocument'))),
  description: 'Documents partagés dans les deux sens.',
});
op(base + '/documents', 'post', {
  tag: S,
  status: 201,
  multipart: true,
  response: data(ref('PrivateDocument')),
  body: object({
    file: { type: 'string', format: 'binary' },
    title: { type: 'string', maxLength: 150 },
  }),
  description: 'Envoie un document à KORA (PDF ou image, 10 Mo, analysé par l’antivirus).',
});
op(base + '/documents/{document}', 'get', {
  tag: S,
  file: true,
  description: 'Document partagé non retiré, ou exemplaire signé d’un de ses contrats.',
});
op(base + '/proposals', 'get', {
  tag: S,
  query: [pageQuery],
  response: page(ref('SupplierProposal')),
  description: 'Ses propositions.',
});
op(base + '/proposals', 'post', {
  tag: S,
  status: 201,
  response: data(ref('SupplierProposal')),
  body: object(
    {
      kind: { type: 'string', enum: ['product', 'offer_update', 'profile'] },
      shop_id: uuid,
      offer_id: uuid,
      payload: free,
    },
    ['kind', 'payload'],
  ),
  description:
    'Nouvelle fiche (nom, description, SKU, prix proposé, garantie, caractéristiques), changement d’offre ou de profil ; texte simple, sans balises.',
});
op(base + '/proposals/{id}/withdraw', 'post', {
  tag: S,
  response: data(ref('SupplierProposal')),
  body: object({ version }),
  description: 'Retire une proposition encore en attente.',
});

op('operations/supplier-portal/settings', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(ref('SupplierPortalSettings')),
  description: 'Réglages du portail.',
});
op('operations/supplier-portal/settings', 'put', {
  tag: T,
  permission: 'suppliers.portal_settings',
  response: data(ref('SupplierPortalSettings')),
  body: object({
    enabled: { type: 'boolean' },
    availability: { type: 'string', enum: ['review', 'direct'] },
    version: count,
  }),
  description:
    'Ouvre ou ferme le portail ; décision du propriétaire avec confirmation récente de l’identité.',
});
op('operations/suppliers/{id}/portal-access', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(
    object({
      members: array(ref('SupplierMember')),
      invitations: array(ref('SupplierInvitation')),
    }),
  ),
  description: 'Membres et invitations du fournisseur.',
});
op('operations/suppliers/{id}/portal-invitations', 'post', {
  tag: T,
  permission: 'suppliers.portal',
  status: 201,
  response: data(ref('SupplierInvitation')),
  body: object({
    email: { type: 'string', format: 'email' },
    role: { type: 'string', enum: ['manager', 'staff'] },
  }),
  description:
    'Crée une invitation de 7 jours ; le lien est rendu à l’équipe (aucun e-mail envoyé sans canal configuré).',
});
op('operations/supplier-invitations/{id}/revoke', 'post', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(ref('SupplierInvitation')),
  description: 'Retire une invitation en attente.',
});
op('operations/supplier-members/{id}/revoke', 'post', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(ref('SupplierMember')),
  description: 'Retire l’accès ; effet à la requête suivante.',
});
op('operations/supplier-proposals', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  query: [
    {
      name: 'status',
      in: 'query',
      required: false,
      schema: {
        type: 'string',
        enum: ['submitted', 'in_review', 'accepted', 'rejected', 'withdrawn'],
      },
    },
    { name: 'supplier_id', in: 'query', required: false, schema: uuid },
    pageQuery,
  ],
  response: page(ref('SupplierProposal')),
  description: 'File des propositions.',
});
op('operations/supplier-proposals/{id}/review', 'post', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(ref('SupplierProposal')),
  body: object(
    {
      version,
      decision: { type: 'string', enum: ['in_review', 'accept', 'reject'] },
      note: nullable({ type: 'string', maxLength: 2000 }),
      category_id: nullable(uuid),
      slug: nullable({ type: 'string', pattern: '^[a-z0-9]+(?:-[a-z0-9]+)*$' }),
    },
    ['version', 'decision'],
  ),
  description:
    'Étude, acceptation ou refus motivé. Accepter une fiche (droit catalogue.write) crée un brouillon produit avec la catégorie et l’adresse choisies ; prix, offre et publication restent séparés.',
});
op('operations/supplier-availability-declarations', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  query: [
    {
      name: 'status',
      in: 'query',
      required: false,
      schema: { type: 'string', enum: ['pending', 'applied', 'rejected'] },
    },
    pageQuery,
  ],
  response: page(ref('SupplierAvailabilityDeclaration')),
  description: 'Disponibilités déclarées.',
});
op('operations/supplier-availability-declarations/{id}/review', 'post', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(ref('SupplierAvailabilityDeclaration')),
  body: object({ decision: { type: 'string', enum: ['apply', 'reject'] } }),
  description:
    'Applique (droit purchases.write) dans la disponibilité fournisseur avec la déclaration en référence, ou refuse.',
});
op('operations/purchase-orders/{id}/share', 'post', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(ref('SupplierPurchase')),
  description:
    'Partage l’achat avec le fournisseur (droit purchases.write) ; visible dans son portail à partir de ce moment.',
});
op('operations/suppliers/{id}/portal-responses', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  query: [pageQuery],
  response: page(
    object({
      id: uuid,
      subject_type: { type: 'string', enum: ['purchase_order', 'collection'] },
      subject_id: uuid,
      decision: { type: 'string', enum: ['confirmed', 'declined', 'ready', 'issue'] },
      reference: nullable(string),
      note: nullable(string),
      responded_by: { type: 'integer' },
      created_at: dateTime,
    }),
  ),
  description: 'Réponses du fournisseur aux achats et collectes.',
});
op('operations/suppliers/{id}/shared-documents', 'get', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(array(ref('SupplierSharedDocument'))),
  description: 'Documents partagés avec ce fournisseur.',
});
op('operations/suppliers/{id}/shared-documents', 'post', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(array(ref('SupplierSharedDocument'))),
  body: object({ document_id: uuid, title: { type: 'string', maxLength: 150 } }),
  description: 'Partage un document sain, rattaché à ce fournisseur ou à aucun.',
});
op('operations/suppliers/{id}/shared-documents/{share}/revoke', 'post', {
  tag: T,
  permission: 'suppliers.portal',
  response: data(array(ref('SupplierSharedDocument'))),
  description: 'Retire le partage ; le document n’est plus accessible au fournisseur.',
});

// Payload helper kept for readers of the contract: translated texts accept a missing English version.
schemas.SupplierTranslatedText = looseTranslated;

api.info.version = '0.42.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
