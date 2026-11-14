import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const S = api.components.schemas;
const ref = (name) => ({ $ref: '#/components/schemas/' + name });
const obj = (properties) => ({
  type: 'object',
  properties,
  required: Object.keys(properties),
  additionalProperties: false,
});
const arr = (items) => ({ type: 'array', items });
const nullable = (schema) => ({ anyOf: [schema, { type: 'null' }] });
const str = { type: 'string' },
  date = { type: 'string', format: 'date' },
  uuid = { type: 'string', format: 'uuid' };
const amount = {
  type: 'string',
  pattern: '^(0|[1-9][0-9]*)$',
  description:
    'Total XAF exact, sans limite agrégée de 10^18 et sans flottant. Les limites des opérations restent inchangées.',
};
const count = { type: 'integer', minimum: 0 },
  nullValue = { type: 'null' },
  falseValue = { type: 'boolean', const: false };
const amounts = (keys) => Object.fromEntries(keys.map((key) => [key, structuredClone(amount)]));
// Widen only the existing read-model totals and day aggregates, never shared input money schemas.
for (const key of Object.keys(S.SupplierPayableForecast.properties.totals.properties)) {
  S.SupplierPayableForecast.properties.totals.properties[key] = structuredClone(amount);
}
S.SupplierPayableForecast.properties.days.items.properties.amount = structuredClone(amount);
S.TreasuryForecastSuppliers = obj({
  totals: structuredClone(S.SupplierPayableForecast.properties.totals),
  days: structuredClone(S.SupplierPayableForecast.properties.days),
});
const day = obj({ due_on: date, amount });
S.TreasuryForecastExpenses = obj({
  ...amounts([
    'before_window',
    'within_window',
    'after_window',
    'committed',
    'pending_budget',
    'not_yet_effective',
    'net_spent',
    'cancelled_unspent',
    'past_due',
  ]),
  effective_on: date,
  days: arr(day),
});
S.TreasuryForecastRefunds = obj({
  ...amounts(['to_fund', 'paid']),
  requested_count: count,
  paid_count: count,
  non_xaf_count: count,
});
S.TreasuryForecastCollections = obj({
  confirmed_collected: amount,
  confirmed_count: count,
  unresolved_attempt_count: count,
  test_attempt_count: count,
  duplicate_transaction_count: count,
});
S.TreasuryForecastPosition = obj({
  treasury_account_id: uuid,
  code: str,
  name: str,
  kind: str,
  active: { type: 'boolean' },
  latest_closed_statement: nullable(
    obj({
      id: uuid,
      as_of: date,
      closing_balance: { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' },
      sha256: { type: 'string', pattern: '^[a-f0-9]{64}$' },
    }),
  ),
  coverage: ref('TreasuryCoverageReport'),
  available_funds: nullValue,
  availability_verified: falseValue,
});
S.TreasuryForecast = obj({
  book_id: uuid,
  mode: { type: 'string', enum: ['live', 'demo'] },
  currency: { type: 'string', const: 'XAF' },
  timezone: { type: 'string', const: 'Africa/Douala' },
  from: date,
  to: date,
  calculated_at: { type: 'string', format: 'date-time' },
  state_basis: { type: 'string', const: 'current_operational_snapshot' },
  scope: { type: 'string', enum: ['known_operational_commitments', 'book_expenses_only'] },
  suppliers: nullable(ref('TreasuryForecastSuppliers')),
  expenses: ref('TreasuryForecastExpenses'),
  refunds: nullable(ref('TreasuryForecastRefunds')),
  collections: nullable(ref('TreasuryForecastCollections')),
  totals: obj(
    amounts([
      'before_window',
      'within_window',
      'after_window',
      'past_due',
      'undated',
      'known_outflows',
    ]),
  ),
  days: arr(obj({ due_on: date, ...amounts(['supplier_payables', 'expenses', 'total']) })),
  cash_positions: arr(ref('TreasuryForecastPosition')),
  cash: obj(
    Object.fromEntries(
      [
        'available_funds',
        'provider_held_funds',
        'withdrawable_funds',
        'pending_withdrawals',
        'free_cash',
      ].map((key) => [key, nullValue]),
    ),
  ),
  coverage: obj({
    business_sources_included: { type: 'boolean' },
    complete: falseValue,
    missing: arr({
      type: 'string',
      enum: ['verified_external_balances', 'withdrawals', 'other_commitments'],
    }),
  }),
  availability_verified: falseValue,
  provisional: { type: 'boolean', const: true },
});
const path = '/api/v1/operations/accounting/books/{book}/treasury/forecast';
const error = (name) => ({ $ref: '#/components/responses/' + name });
api.paths[path] = {
  get: {
    operationId: 'get_operations_accounting_book_treasury_forecast',
    tags: ['Comptabilité'],
    security: [{ sessionCookie: [] }],
    'x-permission': 'accounting.read',
    parameters: [
      {
        name: 'locale',
        in: 'query',
        required: false,
        schema: { type: 'string', enum: ['fr', 'en'] },
      },
      { name: 'book', in: 'path', required: true, schema: uuid },
      ...['from', 'to'].map((name) => ({ name, in: 'query', required: true, schema: date })),
    ],
    description:
      'Prévisionnel des engagements connus en un snapshot PostgreSQL REPEATABLE READ READ ONLY. Dates inclusives, écart maximal de 366 jours ; état métier courant et dates effectives des dépenses au jour du calcul à Douala, pas un état historique as_of. Filtres as_of/supplier_id/treasury_account_id non vides refusés. Livre réel unique : échéances fournisseurs nettes, achats non facturés et ventes consignées à rapprocher, remboursements demandés sans date inventée, dépenses approuvées de ce livre ; avances/avoirs non affectés jamais soustraits arbitrairement. Démonstration : dépenses et comptes liés à ce livre seulement, sources globales null. Totaux et regroupements quotidiens exacts au-delà de 10^18 et de la capacité int native. Collectes : tentatives confirmées XAF, mode réel explicite, date de confirmation et référence prestataire, sans doublon ambigu ; autres états signalés sans somme de devise inconnue. Derniers relevés clôturés et couverture présentés avec leurs dates, sans les sommer comme cash courant. Fonds disponibles/détenus/retirables, retraits en attente et free_cash restent null ; couverture globale incomplète, aucune émission de paiement, aucun posting ni appel prestataire. Session HttpOnly et MFA.',
    responses: {
      200: {
        description: 'Prévisionnel provisoire des faits connus.',
        content: { 'application/json': { schema: obj({ data: ref('TreasuryForecast') }) } },
      },
      401: error('Unauthorized'),
      403: error('Forbidden'),
      404: { description: 'Livre inconnu.' },
      422: error('ValidationError'),
    },
  },
};
api.paths['/api/v1/operations/payables/forecast'].get.description += api.paths[
  '/api/v1/operations/payables/forecast'
].get.description.includes('Totaux agrégés exacts sans plafond')
  ? ''
  : ' Totaux agrégés exacts sans plafond de 10^18 ; opérations individuelles toujours plafonnées. Consolidation inter-domaines via le prévisionnel du livre réel, sans disponibilité présumée.';
api.info.version = '0.26.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
