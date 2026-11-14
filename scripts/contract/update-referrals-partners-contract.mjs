// Run after the after-sales logistics script: one-level referral with payouts, partner applications and page.
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
const translated = object({ fr: string, en: string });
const rewardStatus = { type: 'string', enum: ['pending', 'validated', 'cancelled', 'exhausted'] };
const payoutStatus = {
  type: 'string',
  enum: ['requested', 'approved', 'paid', 'rejected', 'cancelled'],
};
const channel = { type: 'string', enum: ['mobile_money', 'bank_transfer', 'cash'] };

schemas.ReferralProgram = object({
  id: uuid,
  version,
  status: { type: 'string', enum: ['draft', 'active', 'retired'] },
  rate_bp: { type: 'integer', minimum: 1, maximum: 10000 },
  purchase_cap: amount,
  purchases: { type: 'integer', minimum: 1, maximum: 5 },
  payout_threshold: amount,
  validation_days: count,
  budget: amount,
  budget_used: amount,
  terms: translated,
  activated_at: nullable(dateTime),
  retired_at: nullable(dateTime),
});
schemas.ReferralPayout = object(
  {
    id: uuid,
    user_id: userId,
    review_note: nullable(string),
    evidence_id: nullable(uuid),
    amount,
    channel,
    destination: string,
    status: payoutStatus,
    reference: nullable(string),
    version,
    paid_at: nullable(dateTime),
    created_at: dateTime,
    balance: amount,
  },
  [
    'id',
    'amount',
    'channel',
    'destination',
    'status',
    'reference',
    'version',
    'paid_at',
    'created_at',
  ],
);
schemas.MyReferral = object({
  code: { type: 'string', pattern: '^K[A-Z0-9]{7}$' },
  program: nullable(
    object({
      version,
      rate_bp: { type: 'integer' },
      purchase_cap: amount,
      purchases: { type: 'integer' },
      payout_threshold: amount,
      validation_days: count,
      terms: nullable(string),
    }),
  ),
  sponsored_by_someone: { type: 'boolean' },
  referees: array(
    object({
      label: string,
      joined_at: dateTime,
      rewards: array(
        object({ sequence: version, status: rewardStatus, amount, available_at: dateTime }),
      ),
    }),
  ),
  balance: amount,
  payouts: array(ref('ReferralPayout')),
});
schemas.MyReferral.description =
  'Parrainage du client : code opaque, conditions actives, filleuls anonymes (ni identité ni numéro de commande), gains et versements. Distinct de la carte de fidélité.';
schemas.Referral = object({
  id: uuid,
  referrer_id: userId,
  referee_id: userId,
  program_version: version,
  signals: array({ type: 'string', enum: ['shared_phone', 'shared_address'] }),
  status: { type: 'string', enum: ['active', 'flagged', 'blocked'] },
  review_note: nullable(string),
  version,
  rewards: array(
    object({
      sequence: version,
      order_id: uuid,
      base_amount: amount,
      amount,
      status: rewardStatus,
      reason: nullable(string),
      available_at: dateTime,
    }),
  ),
  created_at: dateTime,
});

const applicationStatus = {
  type: 'string',
  enum: ['submitted', 'in_review', 'accepted', 'rejected', 'converted'],
};
schemas.PartnerApplication = object({
  id: uuid,
  number: string,
  status: applicationStatus,
  version,
  contact_name: string,
  business_name: string,
  activity: string,
  phone: string,
  email: nullable(string),
  city: string,
  district: nullable(string),
  product_types: array(string),
  message: nullable(string),
  consent: object({ version: string, at: dateTime }),
  locale: { type: 'string', enum: ['fr', 'en'] },
  duplicate_of: nullable(uuid),
  decision_note: nullable(string),
  decided_at: nullable(dateTime),
  supplier_id: nullable(uuid),
  shop_id: nullable(uuid),
  notes: array(
    object({
      id: uuid,
      author_id: userId,
      body: nullable(string),
      document_id: nullable(uuid),
      created_at: dateTime,
    }),
  ),
  created_at: dateTime,
});
const partnerCard = {
  name: string,
  slug: string,
  logo_url: nullable(string),
  website: nullable(string),
  shop: nullable(object({ name: string, slug: string })),
  published_at: nullable(dateTime),
};
schemas.PublicPartner = object({
  ...partnerCard,
  domain: nullable(string),
  presentation: nullable(string),
  advantages: nullable(string),
});
schemas.PublicPartner.description =
  'Partenaire réel publié : faits vérifiables seulement, lien boutique quand la boutique est publiée, jamais de coordonnées privées.';
schemas.Partner = object({
  id: uuid,
  status: { type: 'string', enum: ['draft', 'published', 'archived'] },
  version,
  supplier_id: nullable(uuid),
  application_id: nullable(uuid),
  ...partnerCard,
  domain: translated,
  presentation: translated,
  advantages: translated,
});

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
  429: { description: 'Limite atteinte.' },
};
function operation(
  path,
  method,
  { permission, response, body, multipart, status = 200, description, tag, query, open, idType },
) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((match) => ({
    name: match[1],
    in: 'path',
    required: true,
    schema: idType ?? uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get' && !multipart && !open)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const staff = path.startsWith('operations/');
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
          ? ' Public, sans compte.'
          : permission
            ? ' Permission serveur : ' +
              permission +
              '.' +
              (staff ? ' Session avec MFA obligatoire.' : '')
            : ' Compte client connecté, ses propres données seulement.') +
        (method === 'get' || open
          ? ''
          : multipart
            ? ' CSRF requis.'
            : ' CSRF et Idempotency-Key UUID requis.'),
      responses: {
        [status]: { description: 'Succès', content: { 'application/json': { schema: response } } },
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
const R = 'Parrainage';
const P = 'Partenaires';

operation('account/referral', 'get', {
  tag: R,
  response: data(ref('MyReferral')),
  description: 'Mon parrainage ; le code est créé à la première consultation.',
});
operation('account/referral/join', 'post', {
  tag: R,
  status: 201,
  response: data(object({ joined: { type: 'boolean' }, program_version: version })),
  body: object({ code: { type: 'string', maxLength: 16 } }),
  description:
    'Rattache un nouveau client (sans achat payé) à un parrain, une seule fois ; propre code refusé. Téléphone ou adresse en commun : rattachement accepté mais soumis à revue humaine, jamais traité comme preuve de fraude.',
});
operation('account/referral/payouts', 'post', {
  tag: R,
  status: 201,
  response: data(ref('ReferralPayout')),
  body: object({
    amount: { type: 'integer', minimum: 1 },
    channel,
    destination: { type: 'string', maxLength: 40 },
  }),
  description:
    'Demande de versement : au moins le seuil, au plus le solde validé non déjà demandé.',
});
operation('operations/referral/programs', 'get', {
  tag: R,
  permission: 'referral.read',
  response: page(ref('ReferralProgram')),
  query: [pageQuery],
  description: 'Programmes de parrainage versionnés, avec budget consommé.',
});
operation('operations/referral/programs', 'post', {
  tag: R,
  permission: 'referral.manage',
  status: 201,
  response: data(ref('ReferralProgram')),
  body: object({
    rate_bp: { type: 'integer', minimum: 1, maximum: 10000 },
    purchase_cap: { type: 'integer', minimum: 1 },
    purchases: { type: 'integer', minimum: 1, maximum: 5 },
    payout_threshold: { type: 'integer', minimum: 1 },
    validation_days: { type: 'integer', minimum: 0, maximum: 365 },
    budget: { type: 'integer', minimum: 1 },
    terms: object({ fr: text(4000), en: text(4000) }),
  }),
  description:
    'Nouvelle version en brouillon. Taux en points de base sur les articles payés nets (remise fidélité et remboursements versés déduits, livraison exclue), plafond par achat, nombre d’achats récompensés, seuil de versement, délai après livraison et budget : aucune valeur imposée par défaut.',
});
operation('operations/referral/programs/{id}/activate', 'post', {
  tag: R,
  permission: 'referral.manage',
  response: data(ref('ReferralProgram')),
  description:
    'Active la version (une seule active) ; la précédente est retirée et continue de s’appliquer aux parrainages qu’elle a signés. Confirmation récente du mot de passe requise.',
});
operation('operations/referral/referrals', 'get', {
  tag: R,
  permission: 'referral.read',
  response: page(ref('Referral')),
  query: [
    {
      name: 'status',
      in: 'query',
      required: false,
      schema: { type: 'string', enum: ['active', 'flagged', 'blocked'] },
    },
    pageQuery,
  ],
  description: 'Parrainages, signaux de revue et gains par achat.',
});
operation('operations/referral/referrals/{id}/review', 'post', {
  tag: R,
  permission: 'referral.manage',
  response: data(ref('Referral')),
  body: object({
    version,
    decision: { type: 'string', enum: ['approve', 'block'] },
    note: text(2000),
  }),
  description:
    'Revue humaine d’un parrainage signalé : validé (les gains se règlent) ou bloqué (gains annulés).',
});
operation('operations/referral/payouts', 'get', {
  tag: R,
  permission: 'finance.read',
  response: page(ref('ReferralPayout')),
  query: [{ name: 'status', in: 'query', required: false, schema: payoutStatus }, pageQuery],
  description: 'Demandes de versement avec le solde du parrain.',
});
for (const [action, word] of [
  ['approve', 'Approuve'],
  ['reject', 'Refuse'],
]) {
  operation('operations/referral/payouts/{id}/' + action, 'post', {
    tag: R,
    permission: 'finance.write',
    response: data(ref('ReferralPayout')),
    body: object({ version, note: text(2000) }),
    description:
      word + ' la demande après vérification. Confirmation récente du mot de passe requise.',
  });
}
operation('operations/referral/payouts/{id}/paid', 'post', {
  tag: R,
  permission: 'finance.write',
  response: data(ref('ReferralPayout')),
  body: object({ version, evidence_id: uuid, reference: text(150), paid_at: dateTime }),
  description:
    'Constate le versement réellement effectué, avec preuve privée analysée et référence du canal. Confirmation récente du mot de passe requise.',
});

operation('partners', 'get', {
  tag: P,
  open: true,
  response: object({
    data: array(ref('PublicPartner')),
    meta: object({ has_partners: { type: 'boolean' } }),
  }),
  description:
    'Partenaires réels publiés uniquement. Liste vide : le site affiche une invitation honnête à rejoindre le projet, jamais de marques fictives.',
});
operation('partner-applications', 'post', {
  tag: P,
  open: true,
  status: 202,
  response: data(object({ received: { type: 'boolean' }, reference: string })),
  body: object(
    {
      contact_name: text(150),
      business_name: text(150),
      activity: text(2000),
      phone: { type: 'string', maxLength: 40 },
      email: nullable({ type: 'string', format: 'email' }),
      city: text(120),
      district: nullable({ type: 'string', maxLength: 120 }),
      product_types: {
        type: 'array',
        minItems: 1,
        maxItems: 10,
        items: { type: 'string', maxLength: 80 },
      },
      message: nullable({ type: 'string', maxLength: 4000 }),
      consent: { type: 'boolean', enum: [true] },
      company_site: nullable({
        type: 'string',
        description: 'Champ piège laissé vide par les personnes.',
      }),
    },
    ['contact_name', 'business_name', 'activity', 'phone', 'city', 'product_types', 'consent'],
  ),
  description:
    'Candidature partenaire avec accord sur l’usage des données (version du consentement conservée). Limité à 5 envois par minute ; une candidature antérieure au même téléphone ou e-mail est signalée à l’équipe seulement.',
});
operation('operations/partner-applications', 'get', {
  tag: P,
  permission: 'partners.read',
  response: page(ref('PartnerApplication')),
  query: [{ name: 'status', in: 'query', required: false, schema: applicationStatus }, pageQuery],
  description: 'Candidatures, plus anciennes d’abord.',
});
operation('operations/partner-applications/{id}', 'get', {
  tag: P,
  permission: 'partners.read',
  response: data(ref('PartnerApplication')),
  description: 'Candidature avec notes et pièces.',
});
operation('operations/partner-applications/{id}/notes', 'post', {
  tag: P,
  permission: 'partners.manage',
  status: 201,
  response: data(ref('PartnerApplication')),
  body: object({ body: text(4000) }),
  description: 'Note de suivi ; une première note passe la candidature en examen.',
});
operation('operations/partner-applications/{id}/attachments', 'post', {
  tag: P,
  permission: 'partners.manage',
  multipart: true,
  status: 201,
  response: data(ref('PartnerApplication')),
  body: object({ file: { type: 'string', format: 'binary' } }),
  description: 'Pièce privée de la candidature (« partner_evidence »), analysée par l’antivirus.',
});
operation('operations/partner-applications/{id}/decision', 'post', {
  tag: P,
  permission: 'partners.manage',
  response: data(ref('PartnerApplication')),
  body: object({
    version,
    decision: { type: 'string', enum: ['accept', 'reject'] },
    note: text(4000),
  }),
  description: 'Décision motivée ; une candidature refusée est close.',
});
operation('operations/partner-applications/{id}/conversion', 'post', {
  tag: P,
  permission: 'partners.manage',
  response: data(ref('PartnerApplication')),
  body: object(
    {
      version,
      supplier_id: nullable(uuid),
      shop: nullable(
        object({ slug: string, name: string, description: object({ fr: string, en: string }) }),
      ),
    },
    ['version'],
  ),
  description:
    'Convertit une candidature acceptée : relie un fournisseur existant ou en crée un (outils du catalogue), boutique brouillon facultative. Refusé si un fournisseur au même téléphone ou e-mail existe déjà, ou si ce fournisseur vient déjà d’une autre candidature.',
});
operation('operations/partners', 'get', {
  tag: P,
  permission: 'partners.read',
  response: page(ref('Partner')),
  query: [pageQuery],
  description: 'Fiches partenaires, tous statuts.',
});
const partnerBody = {
  name: text(150),
  slug: { type: 'string', pattern: '^[a-z0-9]+(?:-[a-z0-9]+)*$', maxLength: 120 },
  domain: object({ fr: nullable(string), en: nullable(string) }),
  presentation: object({ fr: nullable(string), en: nullable(string) }),
  advantages: object({ fr: nullable(string), en: nullable(string) }),
  logo_media_id: nullable(uuid),
  website: nullable({ type: 'string', format: 'uri' }),
};
operation('operations/partners', 'post', {
  tag: P,
  permission: 'partners.manage',
  status: 201,
  response: data(ref('Partner')),
  body: object(
    {
      application_id: nullable(uuid),
      supplier_id: nullable(uuid),
      shop_id: nullable(uuid),
      ...partnerBody,
    },
    ['name', 'slug', 'domain', 'presentation', 'advantages'],
  ),
  description:
    'Fiche partenaire en brouillon, depuis une candidature convertie (fournisseur et boutique repris) ou un fournisseur.',
});
operation('operations/partners/{id}', 'put', {
  tag: P,
  permission: 'partners.manage',
  response: data(ref('Partner')),
  body: object({ version, ...partnerBody }, [
    'version',
    'name',
    'slug',
    'domain',
    'presentation',
    'advantages',
  ]),
  description: 'Modifie la fiche ; version attendue.',
});
operation('operations/partners/{id}/status', 'post', {
  tag: P,
  permission: 'partners.manage',
  response: data(ref('Partner')),
  body: object({ version, status: { type: 'string', enum: ['draft', 'published', 'archived'] } }),
  description:
    'Publie (présentation FR et EN exigée, logo public s’il y en a un), repasse en brouillon ou archive.',
});

api.info.version = '0.38.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
