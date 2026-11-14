import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
const root = path.resolve(import.meta.dirname, '..');
test('All 110 master scenarios remain in the launch matrix exactly once', async () => {
  const prompt = await readFile(path.join(root, 'KORA_PROMPT_MAITRE_CODEX.md'), 'utf8');
  const matrix = await readFile(path.join(root, 'docs/EXIGENCES.md'), 'utf8');
  const original = [...prompt.matchAll(/^\| (V\d+) \|/gm)].map((x) => x[1]);
  const tracked = [...matrix.matchAll(/^\| (V\d+) \|/gm)].map((x) => x[1]);
  assert.equal(original.length, 110);
  assert.deepEqual(tracked, original);
  assert.equal(new Set(tracked).size, 110);
});
test('Reference copies retain the exact originals, including the logo used by both apps', async () => {
  // The original Reference/ folder is not always present; the README records the SHA-256 of each original.
  const recorded = [
    ...(await readFile(path.join(root, 'references/README.md'), 'utf8')).matchAll(
      /^\| `([^`]+\.png)` \| `([a-f0-9]{64})` \|/gm,
    ),
  ];
  assert.equal(
    recorded.length,
    (await readdir(path.join(root, 'references'))).filter((x) => x.endsWith('.png')).length,
  );
  for (const [, name, sha] of recorded)
    assert.equal(
      createHash('sha256')
        .update(await readFile(path.join(root, 'references', name)))
        .digest('hex'),
      sha,
      name,
    );
  for (const app of ['storefront', 'operations'])
    assert.deepEqual(
      await readFile(path.join(root, 'references/Logo.png')),
      await readFile(path.join(root, 'apps', app, 'public/brand/logo.png')),
    );
});
test('OpenAPI references resolve and all private operations declare session security', async () => {
  const contract = JSON.parse(await readFile(path.join(root, 'docs/openapi.json'), 'utf8'));
  function walk(value) {
    if (!value || typeof value !== 'object') return;
    if (value.$ref) {
      const parts = value.$ref.replace(/^#\//, '').split('/');
      assert.ok(
        parts.reduce((o, k) => o?.[k], contract),
        'Missing ' + value.$ref,
      );
    }
    Object.values(value).forEach(walk);
  }
  walk(contract);
  for (const methods of Object.values(contract.paths))
    for (const operation of Object.values(methods))
      for (const requirement of operation.security ?? [])
        for (const name of Object.keys(requirement))
          assert.ok(contract.components.securitySchemes[name], 'Unknown security scheme ' + name);
  for (const [url, methods] of Object.entries(contract.paths))
    if (url.includes('/operations/') || url === '/api/v1/me')
      for (const operation of Object.values(methods)) assert.ok(operation.security?.length);
  assert.ok(contract.components.schemas.Offer.properties.shop);
  assert.equal(contract.components.schemas.Offer.properties.reference_price, undefined);
});

test('Every stock and procurement route has a matching secured contract and retry requirements', async () => {
  const contract = JSON.parse(await readFile(path.join(root, 'docs/openapi.json'), 'utf8'));
  const result = spawnSync('php', ['artisan', 'route:list', '--path=api/v1/operations', '--json'], {
    cwd: path.join(root, 'services/api'),
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(result.status, 0, result.stderr);
  const controllers = [
    'InventoryController',
    'PurchasingController',
    'PayablesController',
    'PrivateDocumentController',
    'SupplyContractController',
    'SupplierAvailabilityController',
    'PayableMonitoringController',
    'InternalNotificationsController',
    'OutboxController',
    'ManageProductRequestsController',
    'ManageSearchController',
    'ManageDeliveryController',
    'ManageOrdersController',
    'ManageDocumentsController',
    'ManagePromotionsController',
    'OrderSupplyController',
    'OrderPurchasesController',
    'ProfessionalController',
    'LoyaltyController',
    'LoyaltyCheckoutController',
    'AccountingController',
    'AccountingLetteringController',
    'ExpensePlansController',
    'TreasuryReconciliationController',
    'TreasuryCoverageController',
    'TreasuryForecastController',
    'WithdrawalsController',
    'BalanceObservationsController',
    'FinancingsController',
    'OrderPreparationController',
  ];
  const routes = JSON.parse(result.stdout).filter((r) =>
    controllers.some((c) => r.action.includes('\\' + c + '@')),
  );
  assert.ok(routes.length >= 60);
  for (const route of routes) {
    const pattern = new RegExp('^/' + route.uri.replace(/\{[^}]+\}/g, '[^/]+') + '$');
    for (const method of route.method
      .toLowerCase()
      .split('|')
      .filter((m) => m !== 'head')) {
      const matches = Object.entries(contract.paths).filter(
        ([p, ops]) => pattern.test(p) && ops[method],
      );
      assert.ok(matches.length, 'Missing contract: ' + method + ' ' + route.uri);
      for (const [url, ops] of matches) {
        const op = ops[method];
        assert.ok(
          op.security?.some((s) => s.sessionCookie),
          url,
        );
        assert.ok(
          route.middleware.some(
            (m) =>
              m === 'permission:' + op['x-permission'] ||
              m.endsWith('\\RequirePermission:' + op['x-permission']),
          ),
          url + ' permission mismatch',
        );
        // Re-checks (rescan, payment verification) are read-and-reconcile operations that are safe to replay.
        if (
          method !== 'get' &&
          !url.endsWith('/rescan') &&
          !url.endsWith('/check') &&
          url !== '/api/v1/operations/documents'
        )
          assert.ok(
            op.parameters.some((p) => p.$ref?.endsWith('/IdempotencyKey')),
            url + ' missing idempotency',
          );
        if (route.middleware.some((m) => m.endsWith('RequireRecentConfirmation')))
          assert.equal(op['x-recent-password-seconds'], 900, url);
      }
    }
  }
});

test('Every customer request and cart route declares its ownership and retry boundary', async () => {
  const contract = JSON.parse(await readFile(path.join(root, 'docs/openapi.json'), 'utf8'));
  const result = spawnSync('php', ['artisan', 'route:list', '--path=api/v1', '--json'], {
    cwd: path.join(root, 'services/api'),
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(result.status, 0, result.stderr);
  const controllers = [
    'ProductRequestController',
    'AccountNotificationsController',
    'CartController',
    'BoardsController',
    'BoardMembersController',
    'DeliveryController',
    'OrdersController',
    'CustomerDocumentsController',
    'OrderSupplyController',
    'ProfessionalController',
    'LoyaltyController',
    'LoyaltyCheckoutController',
  ];
  const routes = JSON.parse(result.stdout).filter(
    (r) =>
      !r.uri.startsWith('api/v1/operations/') &&
      controllers.some((c) => r.action.includes('\\' + c + '@')),
  );
  assert.ok(routes.length >= 55);
  // Compare placeholder-normalised paths so that boards/{id} does not also match the literal boards/public.
  const shape = (u) => u.replace(/\{[^}]+\}/g, '{}');
  for (const route of routes) {
    for (const method of route.method
      .toLowerCase()
      .split('|')
      .filter((m) => m !== 'head')) {
      const matches = Object.entries(contract.paths).filter(
        ([p, ops]) => shape(p) === shape('/' + route.uri) && ops[method],
      );
      assert.ok(matches.length, 'Missing customer contract ' + method + ' ' + route.uri);
      for (const [url, ops] of matches) {
        const op = ops[method];
        assert.ok(op['x-resource-owner'], url);
        if (
          route.action.endsWith('@publicShare') ||
          route.action.endsWith('BoardsController@shared') ||
          route.action.endsWith('LoyaltyController@consult')
        )
          assert.equal(op['x-resource-owner'], 'revocable-capability');
        else if (
          /(BoardsController@public(Index|Show)|DeliveryController@zones)$/.test(route.action)
        )
          assert.equal(op['x-resource-owner'], 'public');
        else
          assert.ok(
            op.security?.some((s) => s.sessionCookie),
            url,
          );
        if (
          method !== 'get' &&
          !route.action.endsWith('@photo') &&
          !route.action.endsWith('OrdersController@check')
        )
          assert.ok(
            op.parameters.some((p) => p.$ref?.endsWith('/IdempotencyKey')),
            url,
          );
      }
    }
  }
});
