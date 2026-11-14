import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
test('Astro production CSP permits its exact inline scripts without allowing arbitrary inline scripts', async () => {
  const response = await fetch(process.env.KORA_STOREFRONT_URL ?? 'http://localhost:4322');
  // La racine redirige vers la langue sélectionnée.
  assert.equal(response.status, 200);
  const html = await response.text();
  const policy =
    response.headers.get('content-security-policy') ??
    html.match(/<meta[^>]*http-equiv="content-security-policy"[^>]*content="([^"]*)"/i)?.[1];
  assert.ok(policy, 'Built Astro CSP header or meta must exist');
  assert.match(policy, /script-src/);
  for (const match of html.matchAll(/<script([^>]*)>([\s\S]*?)<\/script>/g)) {
    if (/\bsrc=/.test(match[1]) || /type="application\/(json|ld\+json)"/.test(match[1])) continue;
    const hash = createHash('sha256').update(match[2]).digest('base64');
    assert.ok(policy.includes('sha256-' + hash), 'CSP must allow each exact inline script body');
  }
  assert.ok(!/script-src[^;]*unsafe-inline/.test(policy));
});
test('Next production scripts carry a fresh nonce and CSP forbids framing the management page', async () => {
  const base = process.env.KORA_OPERATIONS_URL ?? 'http://localhost:3001';
  const response = await fetch(base + '/en');
  const html = await response.text(),
    policy = response.headers.get('content-security-policy') ?? '';
  const nonce = policy.match(/'nonce-([^']+)'/)?.[1];
  assert.ok(nonce);
  assert.match(policy, /frame-ancestors 'none'/);
  assert.ok(!policy.includes('unsafe-eval'));
  for (const match of html.matchAll(/<script([^>]*)>/g)) {
    if (/type="application\/(json|ld\+json)"/.test(match[1])) continue;
    assert.ok(
      match[1].includes('nonce="' + nonce + '"'),
      'Every executable Next script must carry the nonce',
    );
  }
  const second = await fetch(base + '/en');
  assert.notEqual(second.headers.get('content-security-policy'), policy);
});
