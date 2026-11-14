import { readFileSync, writeFileSync } from 'node:fs';
const target = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(target, 'utf8'));
const S = api.components.schemas;
const str = { type: 'string' },
  int = { type: 'integer' },
  bool = { type: 'boolean' },
  uuid = { type: 'string', format: 'uuid' },
  date = { type: 'string', format: 'date' },
  dt = { type: 'string', format: 'date-time' };
const en = (...values) => ({ type: 'string', enum: values }),
  num = (min, max) => ({ type: 'integer', minimum: min, maximum: max });
const nullable = (s) => ({ anyOf: [s, { type: 'null' }] });
const arr = (items, min, max) => ({
  type: 'array',
  items,
  ...(min === undefined ? {} : { minItems: min }),
  ...(max === undefined ? {} : { maxItems: max }),
});
const obj = (properties, required = Object.keys(properties)) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});
const ref = (n) => ({ $ref: '#/components/schemas/' + n });
const money = {
  type: 'string',
  pattern: '^(0|[1-9][0-9]{0,18})$',
  description: 'Entier XAF exact, maximum 1000000000000000000. Aucun flottant.',
};
const text = (max) => ({ type: 'string', maxLength: max, minLength: 1 });
const snapshot = {
  type: 'object',
  additionalProperties: true,
  description:
    'Snapshot historique versionné conservant les conditions effectivement utilisées ; jamais un objet modifiable par cette réponse.',
};
const version = num(1, 2147483647),
  qty = num(1, 1000000),
  cost = num(0, 1000000000000),
  reason = text(1000),
  reference = text(150),
  tracking = en('none', 'lot', 'serial', 'imei'),
  quality = en('available', 'quarantine', 'damaged', 'returned');
const f = () => ({ supplier_id: uuid });
S.XafAmount = money;
S.PrivateDocument = obj({
  id: uuid,
  supplier_id: nullable(uuid),
  purpose: en('supplier_contract', 'supplier_invoice', 'payment_evidence', 'stock_evidence'),
  mime: en('application/pdf', 'image/jpeg', 'image/png', 'image/webp'),
  bytes: int,
  sha256: { type: 'string', pattern: '^[a-f0-9]{64}$' },
  scan_status: en('pending', 'clean', 'infected'),
  scanned_at: nullable(dt),
  created_at: dt,
});
S.SupplyTerms = obj(
  {
    mode: en('cash', 'credit', 'consignment'),
    due_basis: en('invoice_date', 'first_receipt', 'fixed_date'),
    fixed_due_on: nullable(date),
    credit_limit: nullable(money),
    consignment_basis: nullable(en('approved_statement')),
    schedule: arr(obj({ basis_points: num(1, 10000), offset_days: num(0, 3660) }), 1, 24),
  },
  ['mode', 'due_basis', 'schedule'],
);
S.SupplyContractInput = obj(
  {
    ...f(),
    reference,
    starts_on: date,
    ends_on: nullable(date),
    text: ref('Localized'),
    terms: ref('SupplyTerms'),
  },
  ['supplier_id', 'reference', 'starts_on', 'text', 'terms'],
);
S.SupplyContract = obj({
  ...S.SupplyContractInput.properties,
  id: uuid,
  version,
  status: en('draft', 'reviewed', 'approved', 'accepted', 'terminated'),
  signed_document_id: nullable(uuid),
  acceptance: nullable(
    obj({
      signed_document_id: uuid,
      signer_name: str,
      method: en('signed_copy', 'recorded_acceptance'),
      reference: str,
      accepted_at: dt,
      document_sha256: str,
      recorded_by: int,
    }),
  ),
  approved_by: nullable(int),
  created_at: dt,
  updated_at: dt,
});
S.OperationalRevision = obj({ version, action: str, snapshot, created_at: dt });
S.PurchaseLine = obj({
  id: uuid,
  offer_id: uuid,
  variant_id: uuid,
  quantity: int,
  received_quantity: int,
  invoiced_quantity: int,
  approved_overage_quantity: int,
  unit_cost: cost,
  tracking_mode: tracking,
  requires_expiry: bool,
  commercial_snapshot: snapshot,
});
S.PurchaseOrder = obj({
  id: uuid,
  ...f(),
  contract_id: uuid,
  contract_snapshot: ref('SupplyContract'),
  mode: en('cash', 'credit', 'consignment'),
  purpose: en('warehouse_stock', 'customer_needs'),
  reference,
  status: en('draft', 'confirmed'),
  expected_total: money,
  version,
  supplier_confirmation_reference: nullable(str),
  confirmed_at: nullable(dt),
  lines: arr(ref('PurchaseLine')),
  created_at: dt,
});
S.InvoiceLineInput = obj(
  { purchase_line_id: uuid, quantity: qty, unit_payable: cost, variance_reason: nullable(reason) },
  ['purchase_line_id', 'quantity', 'unit_payable'],
);
S.InvoiceInput = obj({
  purchase_order_id: uuid,
  number: reference,
  issued_on: date,
  document_id: uuid,
  lines: arr(ref('InvoiceLineInput'), 1, 50),
  charges: arr(obj({ label: text(150), amount: money }), 0, 20),
});
S.PurchaseInvoice = obj({
  id: uuid,
  ...f(),
  ...S.InvoiceInput.properties,
  amount: money,
  status: en('registered', 'approved', 'cancelled'),
  version,
  approved_at: nullable(dt),
  created_at: dt,
});
S.Warehouse = obj({ id: uuid, code: text(40), name: text(150), address: text(500), active: bool });
S.StockLocation = obj({
  id: uuid,
  warehouse_id: uuid,
  code: text(40),
  name: text(150),
  active: bool,
});
S.InventoryProfileInput = obj(
  {
    version: nullable(version),
    tracking_mode: tracking,
    requires_expiry: bool,
    logistics: obj(
      {
        mass_grams: nullable(num(1, 1000000000)),
        length_mm: nullable(num(1, 1000000)),
        width_mm: nullable(num(1, 1000000)),
        height_mm: nullable(num(1, 1000000)),
        transport_class: en(
          'standard',
          'bulky',
          'fragile',
          'temperature_controlled',
          'unavailable',
        ),
        handling: obj({ fr: nullable(str), en: nullable(str) }),
      },
      ['transport_class', 'handling'],
    ),
  },
  ['tracking_mode', 'requires_expiry', 'logistics'],
);
S.InventoryProfile = obj({
  ...S.InventoryProfileInput.properties,
  id: uuid,
  variant_id: uuid,
  version,
});
S.InventoryPolicy = obj({ version, reservation_ttl_seconds: num(30, 3600) });
S.InventoryBalance = obj({
  id: uuid,
  lot_id: uuid,
  location_id: uuid,
  condition: quality,
  offer_id: uuid,
  ownership: en('kora', 'supplier'),
  expires_on: nullable(date),
  on_hand: num(0, 1000000000),
  reserved: num(0, 1000000000),
  available: num(0, 1000000000),
});
S.InventoryUnit = obj({
  id: uuid,
  lot_id: uuid,
  balance_id: nullable(uuid),
  identifier_type: en('serial', 'imei'),
  identifier: str,
  status: en('on_hand', 'reserved', 'missing', 'consumed', 'returned_to_supplier'),
});
S.InventoryMovement = obj({
  id: uuid,
  balance_id: uuid,
  quantity: int,
  kind: str,
  origin_type: str,
  origin_id: uuid,
  reason: str,
  document_id: nullable(uuid),
  units: arr(uuid),
  created_at: dt,
});
S.InventoryReservation = obj({
  id: uuid,
  offer_id: uuid,
  purpose_id: uuid,
  quantity: qty,
  status: en('active', 'released', 'expired', 'consumed'),
  expires_at: dt,
});
S.InventoryPlan = obj({
  offer_id: uuid,
  requested: qty,
  warehouse_sources: arr(
    obj({ kind: en('warehouse'), balance_id: uuid, location_id: uuid, quantity: qty }),
  ),
  supplier_quantity_needed: num(0, 1000000),
  supplier_quantity_reported: nullable(num(0, 1000000000)),
  supplier_last_verified_at: nullable(dt),
  supplier_confirmation_required: bool,
  preparation_minutes: nullable(int),
});
S.SupplierAvailability = obj({
  id: uuid,
  offer_id: uuid,
  quantity: nullable(num(0, 1000000000)),
  verified_at: dt,
  valid_until: dt,
  fresh: bool,
  preparation_minutes: int,
  contact: str,
  reference: str,
  version,
});
S.SupplierReservation = obj({
  id: uuid,
  offer_id: uuid,
  purpose_id: uuid,
  quantity: qty,
  status: en('requested', 'confirmed', 'rejected', 'release_requested', 'released', 'expired'),
  supplier_confirmed: bool,
  confirmation_reference: nullable(str),
  confirmed_at: nullable(dt),
  expires_at: dt,
});
S.PayableInstallment = obj({
  id: uuid,
  obligation_id: uuid,
  ...f(),
  amount: money,
  settled: money,
  outstanding: money,
  due_on: date,
  sequence: int,
  version,
  status: en('settled', 'pending', 'past_due'),
});
S.SupplierObligation = obj({
  id: uuid,
  ...f(),
  purchase_order_id: uuid,
  source_type: en('invoice', 'consignment_statement'),
  source_id: uuid,
  amount: money,
  outstanding: money,
  terms_snapshot: snapshot,
  due_state: en('awaiting_receipt', 'scheduled'),
  schedule_version: version,
  schedule: arr(ref('PayableInstallment')),
  created_at: dt,
});
S.TreasuryAccount = obj({
  id: uuid,
  code: text(50),
  name: text(150),
  kind: en('bank', 'mobile_money', 'cash'),
  active: bool,
});
S.SupplierPayment = obj({
  id: uuid,
  ...f(),
  account_id: uuid,
  reference: nullable(str),
  amount: money,
  requested_amount: money,
  request_reference: str,
  status: en('requested', 'verified'),
  allocated: money,
  advance: money,
  paid_at: nullable(dt),
  verified_at: nullable(dt),
  evidence_id: nullable(uuid),
});
S.SupplierCredit = obj({ id: uuid, amount: money, applied: money, unapplied: money });
S.SupplierBalances = obj({
  currency: en('XAF'),
  known_as_of: dt,
  ...Object.fromEntries(
    [
      'recognized_total',
      'invoiced_total',
      'consignment_statement_total',
      'outstanding_total',
      'past_due_total',
      'awaiting_receipt_schedule_total',
      'verified_paid_total',
      'advance_total',
      'unapplied_credit_total',
    ].map((k) => [k, money]),
  ),
});
S.SupplierLedgerEvent = obj({
  event_id: str,
  kind: en(
    'purchase_created',
    'purchase_confirmed',
    'goods_received',
    'obligation_recognized',
    'payment_verified',
    'payment_allocated',
    'credit_approved',
    'credit_allocated',
    'goods_returned',
    'schedule_renegotiated',
    'contract_revision',
    'invoice_revision',
    'overage_accepted',
  ),
  label: str,
  resource_id: uuid,
  purchase_order_id: nullable(uuid),
  reference: nullable(str),
  amount: nullable(money),
  quantity: nullable(int),
  document_id: nullable(uuid),
  occurred_at: dt,
});
const schemaError = {
  description: 'Erreur sans données privées ni SQL.',
  content: { 'application/json': { schema: ref('Error') } },
};
api.components.responses.OperationalConflict = {
  ...schemaError,
  description:
    'Version ou référence unique en conflit, ou clé d’idempotence réutilisée avec un contenu différent.',
};
api.components.responses.ReauthenticationRequired = {
  ...schemaError,
  description:
    'Permission/MFA manquant, ou confirmation du mot de passe absente/expirée (REAUTHENTICATION_REQUIRED).',
};
api.components.parameters ??= {};
api.components.parameters.IdempotencyKey = {
  name: 'Idempotency-Key',
  in: 'header',
  required: true,
  schema: uuid,
  description:
    'UUID propre à cette action. Rejouer la même clé et le même contenu effectif renvoie le résultat original ; un contenu différent est rejeté (409).',
};
const query = (name, schema = str, required = false) => ({ name, in: 'query', schema, required });
const page = query('page', num(1, 2147483647));
const supplier = query('supplier_id', uuid),
  po = query('purchase_order_id', uuid);
const asof = query('as_of', dt);
const envelope = (s) => obj({ data: s });
const paged = (s) => obj({ data: arr(s), meta: ref('PageMeta') });
function operation(
  method,
  url,
  response,
  {
    body,
    status = 200,
    paged: pagination = false,
    params = [],
    permission,
    description = '',
    idempotent = true,
    contentType = 'application/json',
    sensitive = false,
  } = {},
) {
  const full = '/api/v1/operations/' + url;
  const parameters = [...url.matchAll(/\{([^}]+)\}/g)].map((m) => ({
    name: m[1],
    in: 'path',
    required: true,
    schema: uuid,
  }));
  parameters.push(query('locale', en('fr', 'en')), ...(pagination ? [page] : []), ...params);
  if (!['get', 'head'].includes(method) && idempotent)
    parameters.push({ $ref: '#/components/parameters/IdempotencyKey' });
  const op = {
    operationId: (method + '_operations_' + url).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Stock et approvisionnement'],
    security: [{ sessionCookie: [] }],
    parameters,
    description: [
      description,
      'Permission serveur : ' + permission + '. MFA obligatoire.',
      sensitive
        ? 'Confirmation du mot de passe datant de 15 minutes au plus, via POST /api/v1/auth/user/confirm-password.'
        : '',
      method === 'get' ? '' : 'Session web HttpOnly et CSRF requis.',
    ]
      .filter(Boolean)
      .join(' '),
    'x-permission': permission,
    ...(sensitive ? { 'x-recent-password-seconds': 900 } : {}),
    responses: {
      [status]: {
        description: 'Succès',
        content: {
          'application/json': { schema: pagination ? paged(response) : envelope(response) },
        },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/ReauthenticationRequired' },
      404: schemaError,
      409: { $ref: '#/components/responses/OperationalConflict' },
      419: schemaError,
      422: { $ref: '#/components/responses/ValidationError' },
      429: schemaError,
    },
  };
  if (body) op.requestBody = { required: true, content: { [contentType]: { schema: body } } };
  api.paths[full] ??= {};
  api.paths[full][method] = op;
  return op;
}
const get = (url, s, opts) => operation('get', url, s, opts),
  post = (url, s, body, opts) => operation('post', url, s, { body, ...opts }),
  put = (url, s, body, opts) => operation('put', url, s, { body, ...opts });
post(
  'documents',
  ref('PrivateDocument'),
  obj({
    file: { type: 'string', format: 'binary' },
    purpose: en('supplier_contract', 'supplier_invoice', 'payment_evidence', 'stock_evidence'),
    supplier_id: uuid,
  }),
  {
    permission: 'documents.write',
    status: 201,
    idempotent: false,
    contentType: 'multipart/form-data',
    description:
      'PDF/JPEG/PNG/WebP, 10 Mio au maximum. Conservation privée des octets originaux. Scanner manquant ou en échec : pending ; fichier infecté refusé. Droits supplémentaires selon le type de pièce.',
  },
);
get('documents/{id}', ref('PrivateDocument'), {
  permission: 'documents.read',
  description:
    'Droits supplémentaires selon le type de pièce ; justificatif de paiement réservé à finance.read.',
});
const dl = get(
  'documents/{id}/download',
  { type: 'string', format: 'binary' },
  {
    permission: 'documents.read',
    description:
      'Document clean uniquement, pièce jointe privée, SHA-256 contrôlé ; aucun lien public.',
  },
);
dl.responses[200].content = {
  'application/octet-stream': { schema: { type: 'string', format: 'binary' } },
};
post('documents/{id}/rescan', ref('PrivateDocument'), undefined, {
  permission: 'documents.write',
  idempotent: false,
});
get('contracts', ref('SupplyContract'), {
  permission: 'purchases.read',
  paged: true,
  params: [
    supplier,
    query('status', en('draft', 'reviewed', 'approved', 'accepted', 'terminated')),
  ],
});
post('contracts', ref('SupplyContract'), ref('SupplyContractInput'), {
  permission: 'purchases.write',
  status: 201,
  description:
    'Somme des parts = 10000 ; contraintes selon cash/crédit/dépôt-vente et point de départ des échéances.',
});
get('contracts/{id}', ref('SupplyContract'), { permission: 'purchases.read' });
put(
  'contracts/{id}',
  ref('SupplyContract'),
  obj({ ...S.SupplyContractInput.properties, version }, [
    ...S.SupplyContractInput.required,
    'version',
  ]),
  { permission: 'purchases.write', description: 'Brouillon uniquement ; fournisseur immuable.' },
);
get('contracts/{id}/revisions', ref('OperationalRevision'), {
  permission: 'purchases.read',
  paged: true,
});
for (const action of ['review', 'approve', 'accept', 'terminate']) {
  const b = {
    version,
    ...(action === 'accept'
      ? {
          signed_document_id: uuid,
          signer_name: text(150),
          method: en('signed_copy', 'recorded_acceptance'),
          reference: text(200),
          accepted_at: dt,
        }
      : {}),
    ...(action === 'terminate' ? { reason } : {}),
  };
  post('contracts/{id}/' + action, ref('SupplyContract'), obj(b), {
    permission: 'purchases.write',
    description:
      (['approve', 'terminate'].includes(action) ? 'contracts.approve également requis. ' : '') +
      (action === 'accept'
        ? 'Pièce contractuelle clean et preuve d’acceptation réellement constatée requises. Aucun envoi ni signature électronique automatique.'
        : ''),
  });
}
get('purchases', ref('PurchaseOrder'), {
  permission: 'purchases.read',
  paged: true,
  params: [supplier, query('status', en('draft', 'confirmed'))],
});
post(
  'purchases',
  ref('PurchaseOrder'),
  obj({
    ...f(),
    contract_id: uuid,
    purpose: en('warehouse_stock', 'customer_needs'),
    reference,
    lines: arr(obj({ offer_id: uuid, quantity: qty }), 1, 50),
  }),
  {
    permission: 'purchases.write',
    status: 201,
    description:
      'Contrat accepté effectif et profil physique explicite requis ; conditions figées au serveur.',
  },
);
get('purchases/{id}', ref('PurchaseOrder'), { permission: 'purchases.read' });
S.GoodsReceiptLine = obj(
  {
    id: uuid,
    purchase_line_id: uuid,
    quantity: int,
    quality,
    excess_quantity: int,
    variance_reason: nullable(str),
    overage_approval_id: nullable(uuid),
    unit_cost: cost,
  },
  [
    'id',
    'purchase_line_id',
    'quantity',
    'quality',
    'excess_quantity',
    'variance_reason',
    'overage_approval_id',
  ],
);
get(
  'purchases/{id}/receipts',
  obj({
    id: uuid,
    purchase_order_id: uuid,
    location_id: uuid,
    reference,
    received_at: dt,
    document_id: uuid,
    created_at: dt,
    lines: arr(ref('GoodsReceiptLine')),
  }),
  {
    permission: 'stock.receive',
    paged: true,
    description:
      'Coût visible uniquement avec purchases.read ; identifiant des lignes utilisable pour le rapprochement des écarts.',
  },
);
post(
  'purchases/{id}/confirm',
  ref('PurchaseOrder'),
  obj(
    {
      version,
      supplier_confirmation_reference: reference,
      credit_override_reason: nullable(reason),
    },
    ['version', 'supplier_confirmation_reference'],
  ),
  {
    permission: 'purchases.write',
    description: 'Contrôle du plafond de crédit ; dérogation motivée réservée à contracts.approve.',
  },
);
post(
  'purchases/{id}/receipts',
  obj({
    id: uuid,
    purchase_order_id: uuid,
    lot_ids: arr(uuid),
    received_at: dt,
    purchase_status: en('draft', 'confirmed'),
    supplier_payment_implied: { type: 'boolean', const: false },
  }),
  obj({
    location_id: uuid,
    document_id: uuid,
    reference,
    received_at: dt,
    lines: arr(
      obj(
        {
          purchase_line_id: uuid,
          quantity: qty,
          unit_cost: nullable(cost),
          variance_reason: nullable(reason),
          quality,
          lot_number: nullable(text(100)),
          expires_on: nullable(date),
          identifiers: arr(text(100), 0, 10000),
        },
        ['purchase_line_id', 'quantity', 'quality', 'identifiers'],
      ),
      1,
      50,
    ),
  }),
  {
    permission: 'stock.receive',
    status: 201,
    description:
      'Réception physique distincte de facture et règlement. Coût omis : coût figé de la ligne. Coût différent : achats, ou finance pour dépôt-vente. Écart motivé, excédent en quarantaine ; série/IMEI/lot/péremption selon profil figé.',
  },
);
get('purchase-invoices', ref('PurchaseInvoice'), {
  permission: 'purchases.read',
  paged: true,
  params: [po, supplier],
});
post('purchase-invoices', ref('PurchaseInvoice'), ref('InvoiceInput'), {
  permission: 'purchases.write',
  status: 201,
  description:
    'Saisie contrôlée, sans reconnaissance de dette avant approbation. Dépôt-vente exclu ; utiliser un relevé des ventes.',
});
get('purchase-invoices/{id}', ref('PurchaseInvoice'), { permission: 'purchases.read' });
put(
  'purchase-invoices/{id}',
  ref('PurchaseInvoice'),
  obj({ ...S.InvoiceInput.properties, version, correction_reason: reason }),
  {
    permission: 'purchases.write',
    description:
      'Correction de saisie avant approbation ; document original et versions antérieures conservés. Même commande fournisseur.',
  },
);
post(
  'purchase-invoices/{id}/cancel-registration',
  ref('PurchaseInvoice'),
  obj({ version, reason }),
  {
    permission: 'purchases.write',
    description:
      'Annule uniquement la saisie KORA non approuvée ; ne vaut pas annulation légale de la pièce fournisseur.',
  },
);
get('purchase-invoices/{id}/revisions', ref('OperationalRevision'), {
  permission: 'purchases.read',
  paged: true,
});
post(
  'purchase-invoices/{id}/approve',
  obj({ invoice: ref('PurchaseInvoice'), obligation: nullable(ref('SupplierObligation')) }),
  obj({ version }),
  {
    permission: 'finance.write',
    sensitive: true,
    description:
      'Version attendue obligatoire ; dette unique et échéancier exact. Facture nulle : aucune obligation de montant nul.',
  },
);
post(
  'receipt-lines/{id}/approve-overage',
  obj({
    id: uuid,
    receipt_line_id: uuid,
    approved_quantity: int,
    purchase_order: ref('PurchaseOrder'),
  }),
  obj({ version, document_id: uuid, reason }),
  {
    permission: 'contracts.approve',
    description:
      'Avenant contractuel clean, motif et version de la commande requis ; quantité commandée originale conservée.',
  },
);
post(
  'purchases/{id}/consignment-statements',
  obj({
    id: uuid,
    amount: money,
    quantity_sold: int,
    obligation: nullable(ref('SupplierObligation')),
  }),
  obj({ starts_on: date, ends_on: date, document_id: uuid, reference }),
  {
    permission: 'finance.write',
    sensitive: true,
    status: 201,
    description:
      'Ventes non encore rapprochées de la période civile Africa/Douala, coût figé sans seconde remise. Aucun dû sur les invendus.',
  },
);
for (const [url, name, input, params] of [
  [
    'inventory/warehouses',
    'Warehouse',
    obj({ code: text(40), name: text(150), address: text(500), active: bool }),
    [],
  ],
  [
    'inventory/locations',
    'StockLocation',
    obj({ warehouse_id: uuid, code: text(40), name: text(150), active: bool }),
    [query('warehouse_id', uuid)],
  ],
]) {
  get(url, ref(name), { permission: 'stock.read', paged: true, params });
  post(url, ref(name), input, { permission: 'stock.write', status: 201 });
}
get('inventory/profiles/{variantId}', nullable(ref('InventoryProfile')), {
  permission: 'inventory.profile.read',
});
put('inventory/profiles/{variantId}', ref('InventoryProfile'), ref('InventoryProfileInput'), {
  permission: 'inventory.profile.write',
  description:
    'Version requise pour modifier un profil existant ; mesures inconnues nulles, classe unavailable admise. Les lignes d’achat déjà figées conservent leur profil.',
});
get('settings/inventory', nullable(ref('InventoryPolicy')), { permission: 'inventory.settings' });
put(
  'settings/inventory',
  ref('InventoryPolicy'),
  obj({ version: nullable(version), reservation_ttl_seconds: num(30, 3600), reason }, [
    'reservation_ttl_seconds',
    'reason',
  ]),
  {
    permission: 'inventory.settings',
    description: 'Politique obligatoire avant réservation ; aucune durée commerciale par défaut.',
  },
);
get('inventory/balances', ref('InventoryBalance'), {
  permission: 'stock.read',
  paged: true,
  params: [query('lot_id', uuid), query('location_id', uuid)],
});
get('inventory/units', ref('InventoryUnit'), {
  permission: 'stock.read',
  paged: true,
  params: [query('lot_id', uuid, true)],
});
get('inventory/movements', ref('InventoryMovement'), {
  permission: 'stock.read',
  paged: true,
  params: [query('balance_id', uuid)],
});
get(
  'inventory/receivable-purchases',
  obj({
    id: uuid,
    ...f(),
    reference,
    status: en('draft', 'confirmed'),
    lines: arr(
      obj({
        id: uuid,
        offer_id: uuid,
        quantity: int,
        received_quantity: int,
        tracking_mode: tracking,
        requires_expiry: bool,
      }),
    ),
  }),
  {
    permission: 'stock.receive',
    paged: true,
    description: 'Vue magasinier sans coûts ni conditions financières.',
  },
);
get('inventory/offers/{id}/plan', ref('InventoryPlan'), {
  permission: 'stock.read',
  params: [query('quantity', qty, true)],
  description:
    'Plan indicatif en lecture seule. Stock conforme à l’offre exacte ; disponibilité fournisseur fraîche distincte d’une confirmation.',
});
post(
  'inventory/reservations',
  ref('InventoryReservation'),
  obj({ offer_id: uuid, purpose_id: uuid, quantity: qty }),
  {
    permission: 'stock.write',
    status: 201,
    description:
      'Réservation interne atomique ; ne constitue pas une confirmation fournisseur. Pas encore reliée au checkout client.',
  },
);
post('inventory/reservations/{id}/release', ref('InventoryReservation'), undefined, {
  permission: 'stock.write',
});
post(
  'inventory/reservations/{id}/issue',
  ref('InventoryReservation'),
  obj({
    sale_reference: reference,
    sale_verified: { type: 'boolean', const: true },
    document_id: uuid,
  }),
  {
    permission: 'finance.write',
    sensitive: true,
    description:
      'Sortie manuelle justifiée d’une réservation active ; ne remplace pas la future vérification de paiement Monetbil ni l’orchestration de commande.',
  },
);
const movementBase = { balance_id: uuid, reason, document_id: uuid };
post(
  'inventory/transfers',
  obj({ source: ref('InventoryBalance'), target: ref('InventoryBalance') }),
  obj({
    ...movementBase,
    location_id: uuid,
    condition: quality,
    quantity: qty,
    unit_ids: arr(uuid, 0, 10000),
  }),
  {
    permission: 'stock.write',
    description:
      'Unités exactes non réservées. Excédent non accepté et lot périmé exclus du stock disponible.',
  },
);
post(
  'inventory/adjustments',
  ref('InventoryBalance'),
  obj({ ...movementBase, delta: num(-1000000, 1000000) }),
  {
    permission: 'stock.adjust',
    description: 'Ajustement documenté des stocks non sérialisés, sans consommer les réservations.',
  },
);
post(
  'inventory/counts',
  ref('InventoryBalance'),
  obj({
    ...movementBase,
    counted_quantity: num(0, 1000000000),
    observed_unit_ids: arr(uuid, 0, 10000),
  }),
  {
    permission: 'stock.adjust',
    description:
      'Inventaire physique. Séries : observation des unités réservées obligatoire ; récupération des seuls manquants connus du même lot ; aucune unité vendue ne peut être réintroduite.',
  },
);
post(
  'inventory/supplier-returns',
  obj({ id: uuid, lot_id: uuid, quantity: qty }),
  obj({
    ...movementBase,
    quantity: qty,
    supplier_reference: reference,
    unit_ids: arr(uuid, 0, 10000),
  }),
  {
    permission: 'stock.write',
    status: 201,
    description: 'Retour physique tracé ; ne crée aucun avoir automatique.',
  },
);
get('supplier-availability/{offerId}', nullable(ref('SupplierAvailability')), {
  permission: 'purchases.read',
});
put(
  'supplier-availability/{offerId}',
  ref('SupplierAvailability'),
  obj(
    {
      version: nullable(version),
      quantity: nullable(num(0, 1000000000)),
      verified_at: dt,
      valid_until: dt,
      preparation_minutes: num(0, 525600),
      contact: text(150),
      reference,
    },
    ['verified_at', 'valid_until', 'preparation_minutes', 'contact', 'reference'],
  ),
  { permission: 'purchases.write' },
);
get('supplier-reservations', ref('SupplierReservation'), {
  permission: 'purchases.read',
  paged: true,
  params: [query('offer_id', uuid)],
});
post(
  'supplier-reservations',
  ref('SupplierReservation'),
  obj({ offer_id: uuid, purpose_id: uuid, quantity: qty, expires_at: dt }),
  {
    permission: 'purchases.write',
    status: 201,
    description: 'Demande enregistrée, sans garantie ni message externe automatique.',
  },
);
for (const action of ['confirm', 'reject', 'request-release', 'confirm-release'])
  post('supplier-reservations/{id}/' + action, ref('SupplierReservation'), obj({ reference }), {
    permission: 'purchases.write',
    description:
      'Référence d’une action réellement constatée obligatoire. Les demandes de libération restent déduites jusqu’à confirmation ou expiration.',
  });
get('treasury/accounts', ref('TreasuryAccount'), {
  permission: 'payments.request',
  paged: true,
  description: 'Référentiel des comptes ; aucun solde bancaire externe inventé.',
});
post(
  'treasury/accounts',
  ref('TreasuryAccount'),
  obj({ code: text(50), name: text(150), kind: en('bank', 'mobile_money', 'cash') }),
  { permission: 'finance.write', sensitive: true, status: 201 },
);
get('payables/obligations', ref('SupplierObligation'), {
  permission: 'purchases.read',
  paged: true,
  params: [supplier, po],
});
get('payables/schedule', ref('PayableInstallment'), {
  permission: 'purchases.read',
  paged: true,
  params: [
    supplier,
    query('contract_id', uuid),
    query('status', en('pending', 'past_due', 'settled')),
    query('from', date),
    query('to', date),
  ],
  description:
    'Couverture par ordre d’échéance puis séquence, calculée avant filtres/pagination dans un snapshot cohérent en lecture.',
});
post(
  'payables/obligations/{id}/reschedule',
  ref('SupplierObligation'),
  obj({
    version,
    document_id: uuid,
    reason,
    installments: arr(obj({ amount: money, due_on: date }), 1, 24),
  }),
  {
    permission: 'finance.write',
    sensitive: true,
    description:
      'Nouvelle version portant le montant nominal intégral de la dette ; montants totalisés exactement, affectations conservées. Avenant clean requis.',
  },
);
get('supplier-payments', ref('SupplierPayment'), {
  permission: 'payments.read',
  paged: true,
  params: [supplier, query('status', en('requested', 'verified'))],
});
post(
  'supplier-payments',
  ref('SupplierPayment'),
  obj({ ...f(), account_id: uuid, amount: money, reference }),
  {
    permission: 'payments.request',
    status: 201,
    description: 'Intention, sans règlement ni transfert réel. Montant strictement positif.',
  },
);
const allocations = arr(obj({ obligation_id: uuid, amount: money }), 0, 50);
post(
  'supplier-payments/{id}/verify',
  ref('SupplierPayment'),
  obj({
    amount: money,
    reference,
    evidence_id: uuid,
    paid_at: dt,
    allow_advance: bool,
    allocations,
  }),
  {
    permission: 'finance.write',
    sensitive: true,
    description:
      'Constat manuel sur pièce clean : montant réel et référence de transaction unique par compte. Même fournisseur, pas de suraffectation ; avance explicite. Aucun ordre de virement envoyé.',
  },
);
post('supplier-payments/{id}/allocate', ref('SupplierPayment'), obj({ allocations }), {
  permission: 'finance.write',
  sensitive: true,
  description:
    'Affectations append-only d’une avance vérifiée, plafonnées aux disponibilités et dettes du fournisseur.',
});
post(
  'supplier-credits',
  ref('SupplierCredit'),
  obj(
    {
      invoice_id: uuid,
      return_id: nullable(uuid),
      amount: money,
      document_id: uuid,
      reference,
      reason,
    },
    ['invoice_id', 'amount', 'document_id', 'reference', 'reason'],
  ),
  { permission: 'finance.write', sensitive: true, status: 201 },
);
get(
  'supplier-credits',
  obj({
    ...S.SupplierCredit.properties,
    ...f(),
    invoice_id: uuid,
    return_id: nullable(uuid),
    reference,
    reason,
    document_id: uuid,
    created_at: dt,
  }),
  { permission: 'purchases.read', paged: true, params: [supplier, query('invoice_id', uuid)] },
);
post('supplier-credits/{id}/allocate', ref('SupplierCredit'), obj({ allocations }), {
  permission: 'finance.write',
  sensitive: true,
  description:
    'Affectation ultérieure du crédit non utilisé au même fournisseur, sans réécriture des affectations passées.',
});
get(
  'suppliers/{id}/statement',
  obj({
    supplier_id: uuid,
    locale: en('fr', 'en'),
    labels: obj({ title: str, balance: str, paid: str, advance: str }),
    balances: ref('SupplierBalances'),
    details_url: str,
    ledger_url: str,
  }),
  {
    permission: 'purchases.read',
    params: [asof],
    description:
      'Solde selon les événements connus à la date demandée. La date réelle d’une pièce ne réécrit pas rétroactivement la connaissance de KORA.',
  },
);
const ledger = get('suppliers/{id}/ledger', ref('SupplierLedgerEvent'), {
  permission: 'purchases.read',
  paged: true,
  params: [asof],
  description:
    'Chronologie achats, réceptions, dette, règlements/affectations, avoirs, retours, versions de contrats/factures, renégociations et avenants. Les montants des événements ne doivent pas être additionnés comme un solde ; utiliser le relevé.',
});
ledger.responses[200].content['application/json'].schema.properties.known_as_of = dt;
ledger.responses[200].content['application/json'].schema.required.push('known_as_of');
S.PayableReminderPolicy = obj({
  version,
  enabled: bool,
  due_soon_days: num(0, 365),
  local_time: { type: 'string', pattern: '^([01][0-9]|2[0-3]):[0-5][0-9]$' },
  weekdays: { ...arr(num(1, 7), 1, 7), uniqueItems: true },
  repeat_after_days: num(1, 365),
});
S.PayableAlert = obj({
  id: uuid,
  kind: en('supplier_installment', 'supplier_credit_limit'),
  severity: en('due_soon', 'due_today', 'past_due', 'credit_limit'),
  state: en('active', 'resolved'),
  supplier_id: uuid,
  obligation_id: nullable(uuid),
  installment_id: nullable(uuid),
  contract_id: nullable(uuid),
  amount: money,
  due_on: nullable(date),
  context: {
    oneOf: [
      obj({ schedule_version: version, sequence: int, purchase_order_id: uuid }),
      obj({ contract_version: version, limit: money, commitment: money }),
    ],
  },
  cycle: version,
  first_detected_at: dt,
  last_checked_at: dt,
  resolved_at: nullable(dt),
  last_queued_at: nullable(dt),
});
S.OperationalNotification = obj({
  id: uuid,
  kind: en('payable_reminder'),
  title: str,
  alert_id: uuid,
  alert_state: en('active', 'resolved'),
  alert_severity: en('due_soon', 'due_today', 'past_due', 'credit_limit'),
  resource_url: str,
  read_at: nullable(dt),
  created_at: dt,
});
S.OutboxStatus = obj({
  id: uuid,
  type: str,
  supported: bool,
  state: en('pending', 'processed', 'failed', 'unsupported'),
  attempts: num(0, 2147483647),
  consecutive_failures: num(0, 2147483647),
  last_error_code: nullable(en('INVALID_PAYLOAD', 'HANDLER_FAILED')),
  next_attempt_at: nullable(dt),
  failed_at: nullable(dt),
  processed_at: nullable(dt),
  created_at: dt,
});
S.SupplierPayableForecast = obj({
  currency: en('XAF'),
  calculated_at: dt,
  timezone: en('Africa/Douala'),
  from: date,
  to: date,
  supplier_id: nullable(uuid),
  totals: obj(
    Object.fromEntries(
      [
        'before_window',
        'within_window',
        'after_window',
        'undated',
        'recognized_outstanding',
        'past_due',
        'unrecognized_commitment',
        'unallocated_advances',
        'unapplied_credits',
        'consignment_sales_to_reconcile',
      ].map((k) => [k, money]),
    ),
  ),
  days: arr(obj({ due_on: date, amount: money, installment_count: num(1, 2147483647) })),
  treasury: obj({
    available_funds: { type: 'null' },
    provider_held_funds: { type: 'null' },
    refunds_to_fund: { type: 'null' },
    free_cash: { type: 'null' },
  }),
  coverage: en('supplier_payables_only'),
});
get('settings/payable-reminders', nullable(ref('PayableReminderPolicy')), {
  permission: 'payables.settings',
  description:
    'Aucune politique commerciale inventée. Sans configuration, indicateurs échus/du jour disponibles, aucun rappel mis en file.',
});
const policyInput = { ...S.PayableReminderPolicy.properties, version: nullable(version), reason };
put(
  'settings/payable-reminders',
  ref('PayableReminderPolicy'),
  obj(
    policyInput,
    Object.keys(policyInput).filter((k) => k !== 'version'),
  ),
  {
    permission: 'payables.settings',
    description:
      'Version obligatoire pour modification. Heure et jours ISO (1=lundi, 7=dimanche) Africa/Douala déterminent la mise en file interne ; remise dépend de l’exécution du worker. Aucun envoi externe.',
  },
);
const alerts = get('payables/alerts', ref('PayableAlert'), {
  permission: 'purchases.read',
  paged: true,
  params: [
    supplier,
    query('contract_id', uuid),
    query('state', en('active', 'resolved')),
    query('severity', en('due_soon', 'due_today', 'past_due', 'credit_limit')),
  ],
  description:
    'État actif par défaut. Indicateurs rafraîchis par payables:scan-alerts ; last_checked_at indique leur fraîcheur. Le règlement se vérifie dans les obligations actuelles.',
});
alerts.responses[200].content['application/json'].schema.properties.reminder_policy_configured =
  bool;
alerts.responses[200].content['application/json'].schema.required.push(
  'reminder_policy_configured',
);
get('payables/alerts/{id}', ref('PayableAlert'), { permission: 'purchases.read' });
get('payables/forecast', ref('SupplierPayableForecast'), {
  permission: 'purchases.read',
  params: [query('from', date, true), query('to', date, true), supplier],
  description:
    'Prévisionnel fournisseurs en lecture cohérente ; écart maximal entre dates 366 jours. Paiements/avoirs réduisent le reliquat avant filtre. Avances, crédits et ventes consignées à rapprocher séparés. Fonds disponibles/détenus par prestataire/remboursements/free_cash inconnus (null) tant que le rapprochement de trésorerie complet manque.',
});
const notifications = get('notifications', ref('OperationalNotification'), {
  permission: 'purchases.read',
  paged: true,
  params: [query('unread', en('0', '1'))],
  description:
    'Notifications internes sans montant ni nom fournisseur en aperçu. Permissions actuelles, lecture propre à l’utilisateur connecté et langue demandée ; aucun paramètre user_id.',
});
notifications.responses[200].content['application/json'].schema.properties.unread_total = num(
  0,
  2147483647,
);
notifications.responses[200].content['application/json'].schema.required.push('unread_total');
post('notifications/{id}/read', obj({ id: uuid, read_at: dt }), undefined, {
  permission: 'purchases.read',
});
get('system/outbox', ref('OutboxStatus'), {
  permission: 'system.read',
  paged: true,
  params: [query('state', en('pending', 'processed', 'failed', 'unsupported'))],
  description:
    'Métadonnées sans payload privé. Seul SupplierPayableReminder est traité ; les événements sans consommateur restent unsupported et ne sont pas marqués réussis.',
});
get(
  'system/outbox/{id}/attempts',
  obj({
    attempt: version,
    handler: en('internal.payable-reminder'),
    result: en('delivered', 'suppressed', 'failed'),
    error_code: nullable(en('INVALID_PAYLOAD', 'HANDLER_FAILED')),
    created_at: dt,
  }),
  { permission: 'system.read', paged: true },
);
post(
  'system/outbox/{id}/retry',
  obj({ id: uuid, retry_requested: bool, prior_attempts: num(0, 2147483647) }),
  obj({ reason }),
  {
    permission: 'system.retry',
    sensitive: true,
    description:
      'Reprise motivée d’un événement interne pris en charge et en échec final, après cinq échecs consécutifs. Historique conservé ; aucune reprise automatique infinie et aucun envoi externe.',
  },
);
api.info.title = 'KORA — API implémentée : catalogue, stock, achats et suivi des échéances';
api.info.version = '0.4.0';
api.info.description =
  'Sessions HttpOnly + CSRF. Gestion protégée par permissions et MFA ; opérations finance avec confirmation récente. UUID Idempotency-Key pour commandes métier de stock/approvisionnement. Seuls les domaines implémentés figurent ici ; exigences de lancement restantes dans EXIGENCES.md.';
writeFileSync(target, JSON.stringify(api, null, 2) + '\n');
console.log(
  'Contrat stock/approvisionnement mis à jour : ' + Object.keys(api.paths).length + ' chemins.',
);
