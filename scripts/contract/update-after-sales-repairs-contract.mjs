// Run after the supplier offers script: repairs of customers' units and supplier warranty returns.
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
const repairStatus = {
  type: 'string',
  enum: ['open', 'at_repairer', 'back', 'returning', 'returned'],
};
const outcome = nullable({ type: 'string', enum: ['repaired', 'not_repairable'] });
const decision = {
  type: 'string',
  enum: ['pending', 'credit', 'replacement', 'repair', 'refused'],
};

schemas.AfterSalesRepair = object({
  id: uuid,
  number: string,
  case_id: uuid,
  return_id: uuid,
  quantity: version,
  identifiers: array(string),
  repairer_kind: { type: 'string', enum: ['supplier', 'third_party', 'internal'] },
  supplier_id: nullable(uuid),
  repairer_name: nullable(string),
  status: repairStatus,
  outcome,
  outcome_note: nullable(string),
  repairer_reference: nullable(string),
  cost: nullable({ type: 'string', pattern: '^(0|[1-9][0-9]*)$' }),
  cost_bearer: nullable({ type: 'string', enum: ['supplier', 'kora', 'customer'] }),
  sent_at: nullable(dateTime),
  back_at: nullable(dateTime),
  returned_at: nullable(dateTime),
  version,
});
schemas.AfterSalesRepair.description =
  'L’unité du client pendant sa réparation : réparateur, bons, issue et prise en charge du coût ; jamais contrôlée pour la revente.';
schemas.AfterSalesSupplierReturn = object({
  id: uuid,
  case_id: uuid,
  return_id: uuid,
  supplier_return_id: uuid,
  supplier_id: uuid,
  quantity: version,
  supplier_reference: string,
  decision,
  decision_note: nullable(string),
  decided_at: nullable(dateTime),
  credit: nullable(object({ id: uuid, amount: string, reference: nullable(string) })),
  version,
  created_at: dateTime,
});
schemas.AfterSalesSupplierReturn.description =
  'Unités défectueuses renvoyées au fournisseur et sa réponse ; un avoir éventuel passe par les dettes fournisseurs.';

// Case views gain their repairs (customer: status and outcome only) and supplier returns (team).
schemas.AfterSalesCase.properties.repairs = array(
  object({ number: string, status: repairStatus, outcome, returned_at: nullable(dateTime) }),
);
schemas.AfterSalesCase.required = [...new Set([...schemas.AfterSalesCase.required, 'repairs'])];
if (schemas.AfterSalesStaffCase.properties) {
  schemas.AfterSalesStaffCase.properties.repairs = array(ref('AfterSalesRepair'));
  schemas.AfterSalesStaffCase.properties.supplier_returns = array(ref('AfterSalesSupplierReturn'));
  schemas.AfterSalesStaffCase.required = [
    ...new Set([...schemas.AfterSalesStaffCase.required, 'repairs', 'supplier_returns']),
  ];
}
schemas.SupplierWarranties.properties.repairs = array(
  object({
    number: string,
    case: string,
    status: repairStatus,
    outcome,
    quantity: version,
    identifiers: array(string),
    reference: nullable(string),
    sent_at: nullable(dateTime),
    back_at: nullable(dateTime),
  }),
);
schemas.SupplierWarranties.properties.returns = array(
  object({
    case: string,
    quantity: version,
    reference: string,
    decision,
    decided_at: nullable(dateTime),
    sent_at: dateTime,
  }),
);
schemas.SupplierWarranties.required = ['cases', 'repairs', 'returns'];

const errors = {
  401: { $ref: '#/components/responses/Unauthorized' },
  403: { $ref: '#/components/responses/Forbidden' },
  404: { description: 'Ressource inconnue.' },
  409: { $ref: '#/components/responses/OperationalConflict' },
  422: { $ref: '#/components/responses/ValidationError' },
};
function op(path, method, { permission, response, body, status = 200, description, query }) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  if (query) parameters.push(...query);
  if (method !== 'get') parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  api.paths['/api/v1/' + path] = {
    ...api.paths['/api/v1/' + path],
    [method]: {
      operationId: (method + '_' + path).replace(/[^a-zA-Z0-9]/g, '_'),
      tags: ['SAV — réparations et retours fournisseur'],
      security: [{ sessionCookie: [] }],
      parameters,
      'x-permission': permission,
      description:
        description + ' Permission serveur : ' + permission + '. Session avec MFA obligatoire.',
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

op('operations/after-sales-repairs', 'get', {
  permission: 'support.read',
  query: [
    { name: 'status', in: 'query', required: false, schema: repairStatus },
    { name: 'supplier_id', in: 'query', required: false, schema: uuid },
    { name: 'page', in: 'query', required: false, schema: version },
  ],
  response: page(ref('AfterSalesRepair')),
  description: 'Réparations en cours et passées.',
});
op('operations/after-sales/{id}/repairs', 'post', {
  permission: 'stock.write',
  status: 201,
  response: data(ref('AfterSalesRepair')),
  body: object(
    {
      return_id: uuid,
      repairer_kind: { type: 'string', enum: ['supplier', 'third_party', 'internal'] },
      supplier_id: nullable(uuid),
      repairer_name: nullable({ type: 'string', maxLength: 150 }),
    },
    ['return_id', 'repairer_kind'],
  ),
  description: 'Ouvre la réparation d’une unité reçue d’un dossier « réparation » accepté.',
});
op('operations/after-sales-repairs/{id}/send', 'post', {
  permission: 'stock.write',
  response: data(ref('AfterSalesRepair')),
  body: object(
    {
      version,
      location_id: uuid,
      document_id: uuid,
      reference: nullable({ type: 'string', maxLength: 150 }),
    },
    ['version', 'location_id', 'document_id'],
  ),
  description:
    'Remise au réparateur avec bon ; l’unité passe à l’emplacement « chez le réparateur ».',
});
op('operations/after-sales-repairs/{id}/back', 'post', {
  permission: 'stock.write',
  response: data(ref('AfterSalesRepair')),
  body: object(
    {
      version,
      location_id: uuid,
      outcome: { type: 'string', enum: ['repaired', 'not_repairable'] },
      note: { type: 'string', maxLength: 2000 },
      document_id: nullable(uuid),
      cost: nullable({ type: 'integer', minimum: 0 }),
      cost_bearer: nullable({ type: 'string', enum: ['supplier', 'kora', 'customer'] }),
    },
    ['version', 'location_id', 'outcome', 'note'],
  ),
  description:
    'Retour au dépôt (bon exigé s’il était chez le réparateur) avec issue et prise en charge du coût.',
});
op('operations/after-sales/{id}/repair-returns', 'post', {
  permission: 'stock.write',
  status: 201,
  response: data(ref('AfterSalesTrip')),
  body: object({
    repair_id: uuid,
    warehouse_id: uuid,
    packages: { type: 'integer', minimum: 1, maximum: 100 },
  }),
  description:
    'L’unité revient au client : sortie du stock vers son propriétaire, remise par un livreur avec code à usage unique ; le dossier se clôt ensuite.',
});
op('operations/after-sales-returns/{id}/supplier-return', 'post', {
  permission: 'stock.write',
  status: 201,
  response: data(ref('AfterSalesSupplierReturn')),
  body: object({
    balance_id: uuid,
    quantity: version,
    reason: { type: 'string', maxLength: 1000 },
    supplier_reference: { type: 'string', maxLength: 150 },
    document_id: uuid,
  }),
  description:
    'Renvoie au fournisseur les unités de ce retour contrôlées défectueuses (stock « damaged » du même lot), avec bon.',
});
op('operations/after-sales-supplier-returns/{id}/decision', 'post', {
  permission: 'purchases.write',
  response: data(ref('AfterSalesSupplierReturn')),
  body: object(
    {
      version,
      decision: { type: 'string', enum: ['credit', 'replacement', 'repair', 'refused'] },
      note: { type: 'string', maxLength: 2000 },
      document_id: nullable(uuid),
    },
    ['version', 'decision', 'note'],
  ),
  description: 'Réponse du fournisseur sur la garantie, enregistrée une fois avec motif et preuve.',
});

api.info.version = '0.45.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
