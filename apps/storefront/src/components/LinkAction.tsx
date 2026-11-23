import { useMemo, useState } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { type Locale } from '../lib/copy';
import { pagesCopy } from '../lib/pages-copy';
import { loadCart } from '../lib/cart';

// Buttons of the shared-link pages: copy a shared cart into one's own cart (prices and delivery worked out again
// for the visitor), or accept an invitation to a board (signing in first when needed).

export default function LinkAction({ locale, kind, token }: { locale: Locale; kind: 'cart' | 'invitation'; token: string }) {
  const p = pagesCopy(locale);
  const api = useMemo(() => createApi('', locale), [locale]);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState('');
  const [error, setError] = useState('');

  async function run() {
    setBusy(true);
    setError('');
    try {
      if (kind === 'cart') {
        const cart = await loadCart(locale);
        await api.request('/api/v1/cart-shares/' + token + '/copy', { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() }, body: JSON.stringify({ version: cart.version }) });
        const fresh = await loadCart(locale);
        window.dispatchEvent(new CustomEvent('kora:cart', { detail: fresh }));
        window.dispatchEvent(new CustomEvent('kora:open-cart'));
        setDone(p.scCopied);
      } else {
        const r = await api.request<{ data: { id: string } }>('/api/v1/board-invitations/' + token + '/accept', { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() }, body: JSON.stringify({ accepted: true }) });
        setDone(p.invDone);
        setTimeout(() => location.assign('/' + locale + '/account#inspirations'), 900);
        void r;
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        window.dispatchEvent(new CustomEvent('kora:auth', { detail: { mode: 'login', next: null } }));
        addEventListener('kora:signed-in', () => run(), { once: true });
      } else setError(err instanceof ApiError && err.message ? err.message : p.failed);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="la">
      {done ? <p className="la-done">✓ {done}</p> : (
        <button type="button" className="btn btn-accent" onClick={run} disabled={busy}>{busy && <i className="spin" />} {kind === 'cart' ? p.scCopy : p.invAccept}</button>
      )}
      {error && <p className="ac-error" role="alert">{error}</p>}
    </div>
  );
}
