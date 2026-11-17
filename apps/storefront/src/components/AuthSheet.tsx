import { useEffect, useRef, useState } from 'react';
import { createApi } from '@kora/api-client';
import { copy, type Locale } from '../lib/copy';
import Auth, { authPaths, safeNext, type AuthMode } from './Auth';

// Account panel: sign-in and registration slide over the page (from the right on a computer, from the bottom on a
// phone) instead of leaving it. Any link to the sign-in pages opens it (the "next" of the link is kept), so does
// the "kora:auth" event; a link to the account opens it when the visitor is not signed in. The sign-in pages
// themselves (direct links, e-mails) show the same panel open over an empty page: closing it goes back.

type Open = { mode: AuthMode; next: string | null };

export default function AuthSheet({ locale, initial, photo = null, google = false }: { locale: Locale; initial?: AuthMode; photo?: string | null; google?: boolean }) {
  const c = copy(locale);
  const home = '/' + locale;
  const standalone = !!initial;
  // What the panel shows (kept while it slides out) and whether it is out.
  const [view, setView] = useState<Open | null>(initial ? { mode: initial, next: null } : null);
  const [open, setOpen] = useState(standalone);
  const [round, setRound] = useState(0);
  const panel = useRef<HTMLElement>(null);

  function show(mode: AuthMode, next: string | null) {
    setView({ mode, next });
    setOpen(true);
    setRound((r) => r + 1);
    window.setTimeout(() => panel.current?.querySelector<HTMLInputElement>('input')?.focus(), 380);
  }
  function close() {
    if (!standalone) return setOpen(false);
    const back = document.referrer && new URL(document.referrer).origin === location.origin && !/\/(login|register|forgot-password|reset-password)$/.test(new URL(document.referrer).pathname);
    if (back) history.back();
    else location.assign(home);
  }

  // Back from Google, signed in: the guest cart joins the account's cart, then the address is cleaned.
  useEffect(() => {
    const query = new URLSearchParams(location.search);
    if (query.get('google') !== '1') return;
    query.delete('google');
    history.replaceState(null, '', location.pathname + (query.toString() ? '?' + query : '') + location.hash);
    const api = createApi('', locale);
    api.request<{ data: { version: number } }>('/api/v1/cart')
      .then((cart) => api.request('/api/v1/cart/merge', { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() }, body: JSON.stringify({ version: cart.data.version }) }))
      .catch(() => {})
      .finally(() => api.request<{ data: unknown }>('/api/v1/cart').then((r) => window.dispatchEvent(new CustomEvent('kora:cart', { detail: r.data }))).catch(() => {}));
  }, []);

  useEffect(() => {
    if (initial) setView({ mode: initial, next: safeNext(new URLSearchParams(location.search).get('next'), locale) });
    const onEvent = (e: Event) => {
      const d = (e as CustomEvent<Partial<Open>>).detail ?? {};
      show(d.mode ?? 'login', d.next ?? null);
    };
    const paths = Object.fromEntries(Object.entries(authPaths).map(([m, p]) => [home + '/' + p, m as AuthMode]));
    let known: boolean | null = null;
    const onClick = async (e: MouseEvent) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = (e.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
      if (!a || a.target === '_blank' || a.closest('.au-sheet')) return;
      const url = new URL(a.href, location.href);
      if (url.origin !== location.origin) return;
      const mode = paths[url.pathname];
      if (mode && mode !== 'reset') {
        e.preventDefault();
        return show(mode, safeNext(url.searchParams.get('next'), locale));
      }
      // The account: straight there when signed in, otherwise the panel, then the account.
      if (url.pathname === home + '/account' && location.pathname !== url.pathname) {
        if (known === true) return;
        e.preventDefault();
        if (known === null) known = await createApi('', locale).request('/api/v1/me').then(() => true, () => false);
        if (known) location.assign(url.pathname + url.hash);
        else show('login', url.pathname + url.hash);
      }
    };
    const onSigned = () => (known = true);
    window.addEventListener('kora:auth', onEvent);
    window.addEventListener('kora:signed-in', onSigned);
    document.addEventListener('click', onClick);
    return () => {
      window.removeEventListener('kora:auth', onEvent);
      window.removeEventListener('kora:signed-in', onSigned);
      document.removeEventListener('click', onClick);
    };
  }, []);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close();
    window.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open]);

  return (
    <>
      <div className="drawer-backdrop au-backdrop" data-open={open} hidden={!open} onClick={close} />
      <aside ref={panel} className="drawer au-sheet" data-open={open} role="dialog" aria-modal="true" aria-label={c.account} aria-hidden={!open}>
        {view && <Auth key={round} locale={locale} mode={view.mode} next={view.next} standalone={standalone} onClose={close} photo={photo} google={google} />}
      </aside>
    </>
  );
}
