import { readFileSync, writeFileSync } from 'node:fs';
const file = new URL('../../docs/openapi.json', import.meta.url);
const api = JSON.parse(readFileSync(file, 'utf8'));
const S = api.components.schemas;
const ref = (n) => ({ $ref: '#/components/schemas/' + n });
const str = { type: 'string' };
const uuid = { type: 'string', format: 'uuid' };
const ledgerId = { type: 'string', pattern: '^[1-9][0-9]{0,17}$' };
const date = { type: 'string', format: 'date' };
const dt = { type: 'string', format: 'date-time' };
const integer = { type: 'integer', minimum: 0 };
const version = { type: 'integer', minimum: 1 };
const exact = { type: 'string', pattern: '^-?(0|[1-9][0-9]*)$' };
const sha = { type: 'string', pattern: '^[a-f0-9]{64}$' };
const note = { type: 'string', minLength: 1, maxLength: 2000 };
const nullable = (s) => ({ anyOf: [s, { type: 'null' }] });
const arr = (items) => ({ type: 'array', items });
const obj = (properties) => ({
  type: 'object',
  properties,
  required: Object.keys(properties),
  additionalProperties: false,
});
const side = { type: 'string', enum: ['ledger', 'statement'] };
const lineId = { oneOf: [uuid, ledgerId] };
function add(schema, fields) {
  Object.assign(schema.properties, fields);
  schema.required = [...new Set([...schema.required, ...Object.keys(fields)])];
}
add(S.TreasuryStatementLine, { statement_id: uuid });
add(S.TreasuryReconciliationTotals, {
  unresolved_statement_lines: integer,
  unresolved_ledger_lines: integer,
  outstanding_statement_net: exact,
  outstanding_ledger_net: exact,
  timing_net_difference: exact,
  pending_decisions: integer,
});
S.TreasuryCarryItem = obj({
  item_id: uuid,
  side,
  line_id: lineId,
  net_amount: exact,
  expected_on: date,
  sha256: sha,
  reviewed_by: integer,
  reviewed_at: dt,
});
S.TreasuryOutstandingInput = {
  oneOf: ['ledger', 'statement'].map((kind) =>
    obj({
      version,
      note,
      side: { type: 'string', const: kind },
      line_id: kind === 'ledger' ? ledgerId : uuid,
      document_id: uuid,
      expected_on: date,
    }),
  ),
};
S.TreasuryOutstandingSnapshot = obj({
  book_id: uuid,
  statement_id: uuid,
  statement_sha256: sha,
  side,
  line_id: lineId,
  line: {
    oneOf: [
      ref('TreasuryLedgerLine'),
      obj({ ...S.TreasuryStatementLine.properties, net_amount: exact }),
    ],
  },
  expected_on: date,
  note,
  document: obj({ id: uuid, sha256: sha }),
  incoming: arr(ref('TreasuryCarryItem')),
});
S.TreasuryOutstanding = obj({
  id: uuid,
  statement_id: uuid,
  side,
  line_id: lineId,
  expected_on: date,
  note,
  document_id: uuid,
  snapshot: ref('TreasuryOutstandingSnapshot'),
  sha256: sha,
  status: { type: 'string', enum: ['pending', 'approved', 'void'] },
  created_by: integer,
  reviewed_by: nullable(integer),
  reviewed_at: nullable(dt),
});
S.TreasuryClosureSnapshot = obj({
  statement_id: uuid,
  statement_sha256: sha,
  version,
  totals: ref('TreasuryReconciliationTotals'),
  outstanding: arr(ref('TreasuryCarryItem')),
  matches: arr(ref('TreasuryMatch')),
});
S.TreasuryClosure = obj({
  id: uuid,
  statement_id: uuid,
  version,
  snapshot: ref('TreasuryClosureSnapshot'),
  sha256: sha,
  created_by: integer,
  created_at: dt,
});
add(S.TreasuryStatement, { closure: nullable(ref('TreasuryClosure')) });
const base = '/api/v1/operations/accounting/books/{book}/treasury/statements/{id}';
function operation(
  method,
  suffix,
  response,
  { body, permission = 'read', paged = false, created = false, description = '' } = {},
) {
  const path = base + suffix;
  api.paths[path] ??= {};
  api.paths[path][method] = {
    operationId: (method + path).replace(/[^a-zA-Z0-9]/g, '_'),
    tags: ['Comptabilité'],
    security: [{ sessionCookie: [] }],
    parameters: [
      ...[...path.matchAll(/\{(\w+)\}/g)].map((m) => ({
        name: m[1],
        in: 'path',
        required: true,
        schema: uuid,
      })),
      ...(paged ? [{ name: 'page', in: 'query', schema: version }] : []),
      ...(method !== 'get' ? [{ $ref: '#/components/parameters/IdempotencyKey' }] : []),
    ],
    'x-permission': 'accounting.' + permission,
    ...(method !== 'get' ? { 'x-recent-password-seconds': 900 } : {}),
    description:
      description +
      ' Session HttpOnly, MFA et permission accounting.' +
      permission +
      '.' +
      (method !== 'get'
        ? ' CSRF, Idempotency-Key UUID et mot de passe confirmé depuis 15 minutes au plus.'
        : '') +
      ' Aucun report ne vaut règlement, nouveau frais, fonds disponible ou certification.',
    ...(body
      ? { requestBody: { required: true, content: { 'application/json': { schema: body } } } }
      : {}),
    responses: {
      [created ? 201 : 200]: {
        description: 'Succès',
        content: {
          'application/json': {
            schema: paged
              ? obj({ data: arr(response), meta: ref('PageMeta') })
              : obj({ data: response }),
          },
        },
      },
      401: { $ref: '#/components/responses/Unauthorized' },
      403: { $ref: '#/components/responses/Forbidden' },
      404: { description: 'Ressource inconnue dans ce livre ou ce relevé.' },
      409: { $ref: '#/components/responses/OperationalConflict' },
      422: { $ref: '#/components/responses/ValidationError' },
    },
  };
}
operation('get', '/outstanding', ref('TreasuryOutstanding'), {
  paged: true,
  description:
    'Décisions de ce relevé, y compris annulées ; origine des reports reconduits, preuve et examen conservés. Les candidats hérités du relevé précédent figurent aussi dans rows/ledger mais exigent une nouvelle décision s’ils restent non rapprochés.',
});
operation('post', '/outstanding', ref('TreasuryOutstanding'), {
  body: ref('TreasuryOutstandingInput'),
  permission: 'write',
  created: true,
  description:
    'Report explicite d’une ligne non rapprochée, du relevé ou du journal validé. Pièce accounting_evidence propre, motif et date attendue strictement après la fin du relevé. Maximum 1000 décisions actives par relevé. Seules les lignes courantes ou héritées de la dernière clôture du précédent relevé sont admissibles. Un report pending bloque toujours la clôture. Verrou de livre, version et empreinte ; aucune écriture ni réécriture de date.',
});
operation('post', '/outstanding/{item}/review', ref('TreasuryOutstanding'), {
  body: obj({ version, note, sha256: sha }),
  permission: 'review',
  description:
    'Examen de l’empreinte et de la preuve par le rôle comptable. En réel, le créateur ne peut approuver sa propre décision, même s’il possède la permission. À chaque relevé, une prolongation requiert nouvelle preuve/motif/date et nouvel examen ; aucune reconduction automatique.',
});
operation('post', '/outstanding/{item}/void', ref('TreasuryOutstanding'), {
  body: obj({ version, note }),
  permission: 'write',
  description:
    'Retrait motivé d’une décision pending/approved sur relevé ouvert, conservant son contenu et son examen éventuel. Requis avant de rapprocher cette ligne dans le même relevé. Ne retire pas le report historique d’un relevé précédent.',
});
operation('get', '/closures', ref('TreasuryClosure'), {
  paged: true,
  description:
    'Snapshots append-only des clôtures successives : totaux, groupes à cette date et reports examinés. Une réouverture/reclôture ajoute une version sans changer les précédentes. Les relevés strictement clôturés avant cet incrément n’ont pas de snapshot inventé.',
});
const desc = (suffix, method, text) => {
  api.paths[base + suffix][method].description =
    text +
    ' Session HttpOnly et MFA. Aucun paiement ni fonds disponible déduit. ' +
    (method === 'get'
      ? ''
      : 'CSRF, Idempotency-Key UUID et confirmation du mot de passe depuis 15 minutes au plus ; permission ' +
        api.paths[base + suffix][method]['x-permission'] +
        '.');
};
desc(
  '/rows',
  'get',
  'Lignes du relevé et lignes héritées de la clôture du précédent relevé ; statement_id identifie le relevé d’origine. Liste opérationnelle avec match_id courant. Le bilan historique as of figure dans closure.snapshot, conservé après un règlement ultérieur.',
);
desc(
  '/ledger',
  'get',
  'Lignes validées du même livre et compte dans l’intervalle, plus les seules lignes en circulation héritées de la clôture précédente. Une ligne d’un exercice antérieur clôturé peut ainsi être rapprochée sans réouverture de ses comptes. Numéro, date originale, empreinte et identifiant stables ; aucun brouillon.',
);
desc(
  '/matches',
  'post',
  'Groupe à nets exactement égaux : lignes courantes ou reports hérités du précédent relevé clôturé, même livre/trésorerie/compte. Un report actif décidé dans ce relevé doit d’abord être retiré. Place active unique par ligne ; pas d’allocation partielle ni frais implicites.',
);
desc(
  '/close',
  'post',
  'Clôture après rapprochement ou report explicitement examiné de chaque ligne encore en attente, côté journal ou relevé. pending/refus de preuve bloque ; les compteurs unmatched restent visibles, unresolved doit être zéro. Snapshot immuable des totaux, groupes et reports ; les règlements ultérieurs ne réécrivent pas ce bilan. Tout nouveau posting sur le compte et l’intervalle exige toujours réouverture. Les périodes sans relevé ne sont pas réputées couvertes.',
);
desc(
  '/reopen',
  'post',
  'Réouverture propriétaire avec motif et calendrier ouvert. D’abord rouvrir les successeurs clôturés, annuler leurs groupes actifs et retirer leurs décisions actives. Les actions financières des successeurs attendent ensuite la reclôture du prédécesseur. Les anciens snapshots de clôture restent immuables.',
);
desc(
  '/void',
  'post',
  'Annulation d’un relevé ouvert sans successeur non annulé, groupe actif ou décision de report active. Les preuves, contenus et clôtures historiques sont conservés.',
);
api.info.version = '0.22.0';
writeFileSync(file, JSON.stringify(api, null, 2) + '\n');
console.log(Object.keys(api.paths).length + ' chemins API documentés.');
