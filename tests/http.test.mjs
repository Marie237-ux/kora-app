import test from 'node:test';
import assert from 'node:assert/strict';

const get = (url, options = {}) => fetch(url, { ...options, signal: AbortSignal.timeout(30000) });
const storefront = process.env.KORA_STOREFRONT_URL ?? 'http://localhost:4321';
const operations = process.env.KORA_OPERATIONS_URL ?? 'http://localhost:3000';
for (const locale of ['fr', 'en']) {
  test('Storefront SSR ' + locale + ' shows the real logo and honest empty catalogue', async () => {
    const response = await get(storefront + '/' + locale);
    assert.equal(response.status, 200);
    const html = await response.text();
    assert.ok(new RegExp('lang="' + locale + '-CM"').test(html), 'Storefront SSR language');
    assert.match(html, /brand\/logo\.png/);
    assert.match(html, /noindex,nofollow/);
    assert.doesNotMatch(html, /href="#"/);
    assert.match(
      html,
      locale === 'fr' ? /Le catalogue se prépare/ : /The catalogue is taking shape/,
    );
  });
  test('Catalogue SSR ' + locale + ' preserves search filters in the form', async () => {
    const response = await get(storefront + '/' + locale + '/catalogue?q=KORA&sort=price_desc');
    assert.equal(response.status, 200);
    const html = await response.text();
    assert.match(html, /value="KORA"/);
    assert.match(html, /value="price_desc" selected/);
  });
  test('Operations ' + locale + ' returns a private page', async () => {
    const response = await get(operations + '/' + locale);
    assert.equal(response.status, 200);
    assert.match(response.headers.get('cache-control') ?? '', /private|no-store/);
    const html = await response.text();
    assert.ok(new RegExp('lang="' + locale + '-CM"').test(html), 'Operations SSR language');
  });
}
test('API rejects unauthenticated operations and preferences', async () => {
  for (const base of ['http://localhost:8000', operations, storefront]) {
    const response = await get(base + '/api/v1/me', { headers: { Accept: 'application/json' } });
    assert.equal(response.status, 401);
    assert.equal((await response.json()).error.code, 'UNAUTHENTICATED');
  }
});
test('CSRF protects real HTTP authentication requests', async () => {
  const response = await get('http://localhost:8000/api/v1/auth/login', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Origin: 'http://localhost:4321',
    },
    body: JSON.stringify({ email: 'nobody@example.test', password: 'InvalidFixture1234' }),
  });
  assert.equal(response.status, 419);
});
test('Both frontend proxies forward CSRF cookies and headers to Laravel', async () => {
  for (const base of [storefront, operations]) {
    const csrf = await get(base + '/sanctum/csrf-cookie');
    assert.equal(csrf.status, 204);
    const cookies = csrf.headers.getSetCookie().map((x) => x.split(';')[0]);
    const token = cookies.find((x) => x.startsWith('XSRF-TOKEN='))?.slice(11);
    assert.ok(token, 'CSRF cookie');
    const response = await get(base + '/api/v1/auth/login', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Origin: 'http://localhost:4321',
        Cookie: cookies.join('; '),
        'X-XSRF-TOKEN': decodeURIComponent(token),
      },
      body: JSON.stringify({ email: 'nobody@example.test', password: 'InvalidFixture1234' }),
    });
    assert.equal(response.status, 422, 'Valid CSRF must reach credential validation');
  }
});
test('Private preview only permits same-origin framing and cannot expose a draft to guests', async () => {
  const response = await get(operations + '/fr/preview/00000000-0000-4000-8000-000000000000');
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('x-frame-options'), 'SAMEORIGIN');
  const api = await get(
    operations + '/api/v1/operations/content/scenes/00000000-0000-4000-8000-000000000000/preview',
    { headers: { Accept: 'application/json' } },
  );
  assert.equal(api.status, 401);
});
test('API unknown product gives a safe localized error', async () => {
  const response = await get(
    'http://localhost:8000/api/v1/catalogue/00000000-0000-4000-8000-000000000000',
    { headers: { Accept: 'application/json', 'X-Kora-Locale': 'en' } },
  );
  assert.equal(response.status, 404);
  const data = await response.json();
  assert.equal(data.error.code, 'NOT_FOUND');
  assert.equal(data.error.message, 'This resource could not be found.');
  assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
});
test('Catalogue, pricing and media administration remain private through both frontend proxies', async () => {
  for (const base of [storefront, operations])
    for (const path of ['catalogue/products', 'pricing/agreements', 'media']) {
      const response = await get(base + '/api/v1/operations/' + path, {
        headers: { Accept: 'application/json' },
      });
      assert.equal(response.status, 401);
      assert.equal((await response.json()).error.code, 'UNAUTHENTICATED');
    }
});

test('Stock, documents and supplier debts stay private at the API and through both frontend proxies', async () => {
  for (const base of ['http://localhost:8000', storefront, operations])
    for (const path of [
      'inventory/balances',
      'contracts',
      'purchases',
      'payables/schedule',
      'supplier-payments',
      'supplier-credits',
      'documents/00000000-0000-4000-8000-000000000000/download',
    ]) {
      const response = await get(base + '/api/v1/operations/' + path, {
        headers: { Accept: 'application/json' },
      });
      assert.equal(response.status, 401, base + ' ' + path);
      assert.equal((await response.json()).error.code, 'UNAUTHENTICATED');
    }
});

test('Internal reminders, supplier forecasts and worker diagnostics stay private through all three services', async () => {
  for (const base of ['http://localhost:8000', storefront, operations])
    for (const path of [
      'settings/payable-reminders',
      'payables/alerts',
      'payables/forecast',
      'notifications',
      'system/outbox',
    ]) {
      const response = await get(base + '/api/v1/operations/' + path, {
        headers: { Accept: 'application/json' },
      });
      assert.equal(response.status, 401, base + ' ' + path);
      assert.equal((await response.json()).error.code, 'UNAUTHENTICATED');
    }
});
