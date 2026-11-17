import { useEffect, useMemo, useState, type ReactNode, type SyntheticEvent } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { copy, money, type Locale } from '../lib/copy';
import { accountCopy } from '../lib/account-copy';
import { CITIES } from '../lib/cities';
import Combo from './Combo';
import AcModal, { AcLoading } from './AcModal';
import AccountBoards from './AccountBoards';
import AccountRequests from './AccountRequests';
import AccountLoyalty from './AccountLoyalty';
import AccountPurchases from './AccountPurchases';
import AccountMessages from './AccountMessages';
import AccountFavorites from './AccountFavorites';

// Customer account, the whole space of the master prompt (§7.9–13, §11, §12.1, §23–26). Computer (Nike / Apple
// account): under the hero, a menu card on the left in groups (purchases, wishes, perks, help, account) and the
// chosen section on the right, switched in place (the address keeps it: #commandes, #inspirations…). Phone (Amazon
// app "You" tab): an app screen — avatar, greeting and settings button, section tiles, payment reminder and the
// latest orders; a tile opens its screen, which slides in with a "‹ title" bar. Orders: rows with grouped photos,
// a status badge and pages; one order opens in a window (computer) or its own screen (phone) with its items,
// delivery slot, documents and "Pay now". Addresses are cards, added or changed in a window, deleted after a
// confirmation. Settings: profile, security (new password), notification switches, then signing out.

type User = { name: string; email: string; locale: Locale; theme: 'light' | 'dark' | 'system'; mfa_enabled?: boolean; avatar?: string | null };
type OrderLine = { id: string; name: string; image: string | null; quantity: number; line_total: string; shop: { name: string } };
type Order = {
  id: string; number: string; status: string; total: string; created_at: string; payable: boolean; preparation_status: string;
  lines: OrderLine[]; payment: { payment_url: string | null } | null;
  delivery: { slot: { date: string; starts_at: string; ends_at: string } | null };
};
type Doc = { id: string; kind: string; number: string | null };
type Address = { id: string; label: string; city: string; district: string; landmark: string; contact_name: string; contact_phone: string; served: boolean; version: number };
type Zone = { city: string; districts: string[] };
type Prefs = Record<'reminder' | 'marketing' | 'transactional', Record<'in_app' | 'email' | 'web_push' | 'whatsapp', boolean>>;
type Tab = 'commandes' | 'achats' | 'favoris' | 'boutiques' | 'inspirations' | 'demandes' | 'fidelite' | 'messages' | 'adresses' | 'parametres';
const TABS: Tab[] = ['commandes', 'achats', 'favoris', 'boutiques', 'inspirations', 'demandes', 'fidelite', 'messages', 'adresses', 'parametres'];
const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });
const DOCS: Record<string, [string, string]> = {
  invoice: ['Facture', 'Invoice'], receipt: ['Reçu', 'Receipt'], delivery_note: ['Bon de livraison', 'Delivery note'], credit_note: ['Avoir', 'Credit note'],
  order_confirmation: ['Confirmation de commande', 'Order confirmation'], quote: ['Devis', 'Quote'], refund: ['Preuve de remboursement', 'Refund proof'],
};

export default function Account({ locale }: { locale: Locale }) {
  const c = copy(locale);
  const t = accountCopy(locale);
  const api = useMemo(() => createApi('', locale), [locale]);
  const home = '/' + locale;
  const [user, setUser] = useState<User | null>(null);
  const [tab, setTab] = useState<Tab>('commandes');
  // Phone: the account screen (false) or a section's screen (true). On a computer both are always shown.
  const [inside, setInside] = useState(false);
  const [phone, setPhone] = useState(false);
  const [orders, setOrders] = useState<Order[] | null>(null);
  const [page, setPage] = useState({ page: 1, last: 1, total: 0 });
  const [openOrder, setOpenOrder] = useState('');
  const [docs, setDocs] = useState<Doc[] | null>(null);
  const [addresses, setAddresses] = useState<Address[] | null>(null);
  const [zones, setZones] = useState<Zone[]>([]);
  const [failed, setFailed] = useState('');
  const [editing, setEditing] = useState<Address | 'new' | null>(null);
  const [removing, setRemoving] = useState<Address | null>(null);
  const emptyForm = { label: c.coHome, city: 'Douala', district: '', landmark: '', contact_name: '', contact_phone: '' };
  const [form, setForm] = useState(emptyForm);
  const [busy, setBusy] = useState('');
  const [toast, setToast] = useState('');
  const [pwOpen, setPwOpen] = useState(false);
  const [pwErrors, setPwErrors] = useState<Record<string, string[]>>({});
  const [prefs, setPrefs] = useState<Prefs | null>(null);
  const [outOpen, setOutOpen] = useState(false);
  const [editOrder, setEditOrder] = useState<Order | null>(null);
  // Two-step verification window: password → QR code + code → recovery codes; or password → turned off.
  const [mfa, setMfa] = useState<{ step: 'password' | 'qr' | 'codes'; off?: boolean } | null>(null);
  const [qr, setQr] = useState('');
  const [codes, setCodes] = useState<string[]>([]);
  const [mfaError, setMfaError] = useState('');
  const [viewed, setViewed] = useState<boolean | null>(null);

  const note = (text: string) => {
    setToast(text);
    window.setTimeout(() => setToast((x) => (x === text ? '' : x)), 3600);
  };

  useEffect(() => {
    api
      .request<{ data: User }>('/api/v1/me')
      .then((r) => setUser(r.data))
      .catch(() => location.replace(home + '/login?next=' + encodeURIComponent(home + '/account' + location.hash)));
    const fromHash = () => {
      const raw = location.hash.slice(1);
      const tb = (raw === 'preferences' ? 'parametres' : raw) as Tab;
      if (TABS.includes(tb)) setTab(tb);
      setInside(TABS.includes(tb));
    };
    fromHash();
    const small = matchMedia('(max-width: 760px)');
    const onSize = () => setPhone(small.matches);
    onSize();
    small.addEventListener('change', onSize);
    addEventListener('hashchange', fromHash);
    addEventListener('popstate', fromHash);
    return () => {
      removeEventListener('hashchange', fromHash);
      removeEventListener('popstate', fromHash);
      small.removeEventListener('change', onSize);
    };
  }, []);

  function loadOrders(p: number) {
    setOrders(null);
    api.request<{ data: Order[]; meta?: { page: number; last_page: number; total: number } }>('/api/v1/orders?page=' + p)
      .then((r) => {
        setOrders(r.data);
        setPage({ page: r.meta?.page ?? p, last: r.meta?.last_page ?? 1, total: r.meta?.total ?? r.data.length });
      })
      .catch(() => { setOrders([]); setFailed('orders'); });
  }
  useEffect(() => {
    if (!user) return;
    loadOrders(1);
    api.request<{ data: Address[] }>('/api/v1/account/addresses').then((r) => setAddresses(r.data)).catch(() => { setAddresses([]); setFailed('addresses'); });
    api.request<{ data: Zone[] }>('/api/v1/delivery/zones').then((r) => setZones(r.data)).catch(() => {});
    api.request<{ data: Prefs }>('/api/v1/account/notification-preferences').then((r) => setPrefs(r.data)).catch(() => {});
    api.request<{ meta: { enabled: boolean } }>('/api/v1/account/viewed').then((r) => setViewed(r.meta.enabled)).catch(() => {});
  }, [user]);

  const opened = orders?.find((o) => o.id === openOrder);
  useEffect(() => {
    if (!openOrder) return;
    setDocs(null);
    api.request<{ data: Doc[] }>('/api/v1/orders/' + openOrder + '/documents').then((r) => setDocs(r.data)).catch(() => setDocs([]));
  }, [openOrder]);

  function open(tb: Tab, order = '') {
    setTab(tb);
    setInside(true);
    setOpenOrder(order);
    if (location.hash !== '#' + tb) history.pushState(null, '', '#' + tb);
    if (phone) scrollTo({ top: 0 });
  }
  function toMenu() {
    // A section with something open (a board, a request…) closes it first and keeps the screen.
    if (!dispatchEvent(new CustomEvent('kora:ac-back', { cancelable: true }))) return;
    setInside(false);
    setOpenOrder('');
    history.replaceState(null, '', location.pathname);
    scrollTo({ top: 0 });
  }

  const date = (iso: string, short = false) => new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', short ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso));
  const day = (d: string) => new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(d + 'T12:00:00'));
  const hm = (s: string) => s.slice(0, 5).replace(':', locale === 'fr' ? ' h ' : ':').replace(' h 00', ' h');

  function editAddress(a: Address | 'new') {
    setForm(a === 'new' ? { ...emptyForm, contact_name: user?.name ?? '' } : { label: a.label, city: a.city, district: a.district, landmark: a.landmark, contact_name: a.contact_name, contact_phone: a.contact_phone });
    setEditing(a);
  }
  async function saveAddress(e: SyntheticEvent) {
    e.preventDefault();
    setBusy('address');
    try {
      if (editing === 'new') {
        const r = await api.request<{ data: Address }>('/api/v1/account/addresses', { method: 'POST', headers: key(), body: JSON.stringify(form) });
        setAddresses((a) => [r.data, ...(a ?? [])]);
      } else if (editing) {
        const r = await api.request<{ data: Address }>('/api/v1/account/addresses/' + editing.id, { method: 'PUT', headers: key(), body: JSON.stringify({ ...form, version: editing.version }) });
        setAddresses((a) => (a ?? []).map((x) => (x.id === r.data.id ? r.data : x)));
      }
      setEditing(null);
    } catch {
      note(c.coAddressFailed);
    } finally {
      setBusy('');
    }
  }
  async function remove(a: Address) {
    setBusy('remove');
    try {
      await api.request('/api/v1/account/addresses/' + a.id + '/archive', { method: 'POST', headers: key(), body: JSON.stringify({ version: a.version }) });
      setAddresses((list) => (list ?? []).filter((x) => x.id !== a.id));
      setRemoving(null);
    } catch {
      note(c.acFailed);
    } finally {
      setBusy('');
    }
  }
  // New password: the current one, then the new one twice (12 characters at least).
  async function changePassword(e: SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy('password');
    setPwErrors({});
    const data = Object.fromEntries(new FormData(e.currentTarget).entries());
    try {
      await api.request('/api/v1/auth/user/password', { method: 'PUT', body: JSON.stringify(data) });
      setPwOpen(false);
      note(c.acPasswordSaved);
    } catch (err) {
      const fields = err instanceof ApiError ? err.fields ?? {} : {};
      setPwErrors(fields);
      if (!Object.keys(fields).length) note(c.acFailed);
    } finally {
      setBusy('');
    }
  }
  async function setPref(group: 'reminder' | 'marketing', channel: keyof Prefs['reminder'], value: boolean) {
    if (!prefs) return;
    const next = { ...prefs, [group]: { ...prefs[group], [channel]: value } };
    setPrefs(next);
    try {
      const r = await api.request<{ data: Prefs }>('/api/v1/account/notification-preferences', { method: 'PUT', body: JSON.stringify({ reminder: next.reminder, marketing: next.marketing }) });
      setPrefs(r.data);
    } catch {
      setPrefs(prefs);
      note(c.acFailed);
    }
  }
  // Pay an order still waiting: the open payment page if there is one, otherwise a new attempt from the API.
  async function pay(o: Order) {
    setBusy('pay:' + o.id);
    try {
      const url = o.payment?.payment_url ?? (await api.request<{ data: { payment_url: string | null } }>('/api/v1/orders/' + o.id + '/payments', { method: 'POST', headers: key() })).data.payment_url;
      if (url) return location.assign(url);
      note(c.coPayFailed);
    } catch {
      note(c.coPayFailed);
    }
    setBusy('');
  }
  // Change an unpaid order: it is cancelled, its items go back to the cart, then the checkout opens.
  async function reopen(o: Order) {
    setBusy('reopen');
    try {
      await api.request('/api/v1/orders/' + o.id + '/reopen', { method: 'POST', headers: key(), body: JSON.stringify({}) });
      location.assign(home + '/commande');
    } catch (err) {
      note(err instanceof ApiError && err.message ? err.message : c.acFailed);
      setBusy('');
    }
  }
  async function mfaPassword(e: SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!mfa) return;
    setBusy('mfa');
    setMfaError('');
    try {
      await api.request('/api/v1/auth/user/confirm-password', { method: 'POST', body: JSON.stringify({ password: new FormData(e.currentTarget).get('password') }) });
      if (mfa.off) {
        await api.request('/api/v1/auth/user/two-factor-authentication', { method: 'DELETE' });
        setUser((u) => (u ? { ...u, mfa_enabled: false } : u));
        setMfa(null);
        note(t.twofaDisabled);
      } else {
        await api.request('/api/v1/auth/user/two-factor-authentication', { method: 'POST', body: JSON.stringify({}) });
        setQr((await api.request<{ svg: string }>('/api/v1/auth/user/two-factor-qr-code')).svg);
        setMfa({ step: 'qr' });
      }
    } catch (err) {
      setMfaError(err instanceof ApiError && err.message ? err.message : c.acFailed);
    } finally {
      setBusy('');
    }
  }
  async function mfaConfirm(e: SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy('mfa');
    setMfaError('');
    try {
      await api.request('/api/v1/auth/user/confirmed-two-factor-authentication', { method: 'POST', body: JSON.stringify({ code: new FormData(e.currentTarget).get('code') }) });
      setCodes(await api.request<string[]>('/api/v1/auth/user/two-factor-recovery-codes'));
      setUser((u) => (u ? { ...u, mfa_enabled: true } : u));
      setMfa({ step: 'codes' });
    } catch (err) {
      setMfaError(err instanceof ApiError && err.message ? err.message : c.acFailed);
    } finally {
      setBusy('');
    }
  }
  async function setHistory(on: boolean) {
    setViewed(on);
    try {
      await api.request('/api/v1/account/viewed/settings', { method: 'PUT', body: JSON.stringify({ enabled: on }) });
    } catch {
      setViewed(!on);
      note(c.acFailed);
    }
  }
  async function clearHistory() {
    try {
      await api.request('/api/v1/account/viewed', { method: 'DELETE' });
      note(t.historyCleared);
    } catch {
      note(c.acFailed);
    }
  }
  // Profile photo: chosen from the phone's gallery or camera, or a file on a computer; shown at once.
  async function uploadAvatar(e: SyntheticEvent<HTMLInputElement>) {
    const file = e.currentTarget.files?.[0];
    e.currentTarget.value = '';
    if (!file) return;
    setBusy('avatar');
    try {
      const body = new FormData();
      body.append('file', file);
      setUser((await api.request<{ data: User }>('/api/v1/me/avatar', { method: 'POST', body })).data);
    } catch (err) {
      note(err instanceof ApiError && err.message ? err.message : c.acFailed);
    } finally {
      setBusy('');
    }
  }
  async function logout() {
    setBusy('logout');
    try {
      await api.request('/api/v1/auth/logout', { method: 'POST' });
    } finally {
      location.assign(home);
    }
  }

  const districts = zones.filter((z) => z.city === form.city).flatMap((z) => z.districts);
  const labels: Record<Tab, string> = {
    commandes: c.acOrders, achats: t.tabs.achats, favoris: t.favTab, boutiques: t.shopsTab, inspirations: t.tabs.inspirations, demandes: t.tabs.demandes, fidelite: t.tabs.fidelite,
    messages: t.tabs.messages, adresses: c.acAddresses, parametres: c.acSettings,
  };
  const icons: Record<Tab, ReactNode> = { commandes: bag, achats: shield, favoris: heart, boutiques: store, inspirations: boards, demandes: spark, fidelite: card, messages: chat, adresses: pin, parametres: gear };
  const groups: [string, Tab[]][] = [[t.groups.shop, ['commandes', 'achats']], [t.groups.wish, ['favoris', 'boutiques', 'inspirations', 'demandes']], [t.groups.perks, ['fidelite']], [t.groups.help, ['messages']], [t.groups.me, ['adresses', 'parametres']]];
  const counts: Partial<Record<Tab, number>> = { commandes: page.total || undefined, adresses: addresses?.length || undefined };
  const toPay = orders?.filter((o) => o.payable) ?? [];
  const statusOf = (o: Order) =>
    o.status === 'paid' ? (o.preparation_status === 'ready' ? c.acPrepReady : o.preparation_status === 'in_progress' ? c.acPrepRunning : c.acStatus.paid) : c.acStatus[o.status] ?? o.status;
  const toneOf = (o: Order) => (o.status === 'paid' || o.status === 'closed' ? 'ok' : o.status === 'awaiting_payment' ? 'wait' : 'off');
  const first = user?.name.split(' ')[0] ?? '';
  // The member's round, like a shop's profile photo (KORA ring); touching it changes the photo.
  const avatar = (size = '') => (
    <label className={'ac-av story-ring is-new ' + size} title={t.changePhoto} aria-busy={busy === 'avatar'}>
      {user?.avatar ? <img src={user.avatar} alt="" /> : <span>{first.charAt(0).toUpperCase()}</span>}
      <input type="file" accept="image/*" onChange={uploadAvatar} aria-label={t.changePhoto} />
      <i className="ac-av-cam" aria-hidden="true">{busy === 'avatar' ? <b className="spin" /> : camera}</i>
    </label>
  );
  const count = (o: Order) => o.lines.reduce((n, l) => n + l.quantity, 0);
  // The order's photos grouped like the faces under a Facebook post: three overlapping rounds, then "+n".
  const stack = (o: Order) => (
    <span className="ac-stack">
      {o.lines.slice(0, 3).map((l) => <span key={l.id} className="ac-dot">{l.image && <img src={l.image} alt="" loading="lazy" />}</span>)}
      {o.lines.length > 3 && <span className="ac-dot ac-plus">+{o.lines.length - 3}</span>}
    </span>
  );
  const slotText = (o: Order) =>
    o.delivery.slot && o.status === 'paid' ? c.acSlot.replace('{d}', day(o.delivery.slot.date)).replace('{t}', hm(o.delivery.slot.starts_at) + ' – ' + hm(o.delivery.slot.ends_at)) : '';

  // One order: summary, items, documents, total and "Pay now" (window on a computer, own screen on a phone).
  const detail = (o: Order) => (
    <div className="ac-screen">
      <div className="ac-sum">
        {stack(o)}
        <span className={'ac-badge ' + toneOf(o)}>{statusOf(o)}</span>
        <small>{c.acPlaced.replace('{d}', date(o.created_at))} · {c.acItemsN.replace('{n}', String(count(o)))}</small>
        {slotText(o) && <p className="ac-slot">{slotText(o)}</p>}
      </div>
      <ul className="ac-lines">
        {o.lines.map((l) => (
          <li key={l.id}>
            <span className="ac-img">{l.image && <img src={l.image} alt="" />}</span>
            <span className="ac-name"><strong>{l.name}</strong><small>{l.shop.name} · × {l.quantity}</small></span>
            <b>{money(l.line_total, locale)}</b>
          </li>
        ))}
      </ul>
      {docs && docs.length > 0 && (
        <div className="ac-docs">
          <span>{t.documents}</span>
          {docs.map((d) => (
            <a key={d.id} href={'/api/v1/orders/' + o.id + '/documents/' + d.id + '/pdf'} target="_blank" rel="noopener">
              {(DOCS[d.kind]?.[locale === 'fr' ? 0 : 1] ?? d.kind) + (d.number ? ' ' + d.number : '')} ↓
            </a>
          ))}
        </div>
      )}
      <div className="ac-sum-total"><span>{c.coTotal}</span><b>{money(o.total, locale)}</b></div>
      {o.status === 'awaiting_payment' && (
        <button type="button" className="ac-edit-order" onClick={() => setEditOrder(o)}>{pencil}<span>{t.editOrder}</span></button>
      )}
      {o.payable && (
        <button type="button" className="btn btn-accent ac-pay-big" onClick={() => pay(o)} disabled={busy === 'pay:' + o.id}>
          {busy === 'pay:' + o.id && <i className="spin" />} {c.acPayNow}
        </button>
      )}
    </div>
  );
  const phoneOrder = phone && tab === 'commandes' ? opened : undefined;

  return (
    <div className="ac" data-inside={inside}>
      <header className="ac-hello">
        {avatar()}
        {user ? <div><h2>{c.acHello.replace('{n}', first)}</h2><p>{user.email}</p></div> : <div className="ac-skel" aria-hidden="true"><i className="sk-title" /><i className="sk-line" /></div>}
        <button type="button" className="ac-gear" onClick={() => open('parametres')} aria-label={c.acSettings} title={c.acSettings}>{gear}</button>
      </header>

      <div className="ac-grid">
        <nav className="ac-menu" aria-label={c.acTitle}>
          <div className="ac-me">
            {avatar('small')}
            {user ? <span><strong>{user.name}</strong><small>{user.email}</small></span> : <span className="ac-skel"><i className="sk-line" /><i className="sk-small" /></span>}
          </div>
          {groups.map(([g, tabs]) => (
            <div key={g} className="ac-group-menu">
              <p>{g}</p>
              {tabs.map((tb) => (
                <button key={tb} type="button" className={'ac-tile ac-tile-' + tb} onClick={() => open(tb)} aria-current={tab === tb ? 'page' : undefined}>
                  <span className="ac-tile-ico">{icons[tb]}</span>
                  <span className="ac-tile-label">{labels[tb]}</span>
                  {counts[tb] && <small>{counts[tb]}</small>}
                </button>
              ))}
            </div>
          ))}
        </nav>

        {/* Phone home (shopping-app "Me" page): orders by state as shortcuts, then grouped lists of the sections. */}
        <div className="ac-phome">
          {toPay.length > 0 && (
            <button type="button" className="ac-alert" onClick={() => open('commandes', toPay[0].id)}>
              <span>{c.acToPay.replace('{n}', String(toPay.length))}</span>
              <b>{c.acPay}</b>
            </button>
          )}
          <section className="ac-pcard">
            <header>
              <h3>{c.acOrders}</h3>
              <button type="button" onClick={() => open('commandes')}>{t.allOrders}{chevron}</button>
            </header>
            <div className="ac-shortcuts">
              {([
                ['pay', wallet, orders?.filter((o) => o.status === 'awaiting_payment').length ?? 0, 'commandes'],
                ['running', truck, orders?.filter((o) => o.status === 'paid').length ?? 0, 'commandes'],
                ['done', done, orders?.filter((o) => o.status === 'closed').length ?? 0, 'commandes'],
                ['sav', shield, 0, 'achats'],
              ] as const).map(([k, ico, n, target]) => (
                <button key={k} type="button" onClick={() => open(target)}>
                  <span className="ac-sc-ico">{ico}{n > 0 && <b>{n}</b>}</span>
                  <span>{t.shortcuts[k]}</span>
                </button>
              ))}
            </div>
          </section>
          {([['favoris', 'boutiques', 'inspirations', 'demandes'], ['fidelite', 'messages', 'achats', 'adresses']] as Tab[][]).map((list, i) => (
            <ul key={i} className="ac-plist">
              {list.map((tb) => (
                <li key={tb}>
                  <button type="button" onClick={() => open(tb)}>
                    <span className="ac-pico">{icons[tb]}</span>
                    <span className="ac-plabel">{labels[tb]}</span>
                    {counts[tb] && <small>{counts[tb]}</small>}
                    {chevron}
                  </button>
                </li>
              ))}
            </ul>
          ))}
        </div>

        {/* Former phone home (kept hidden): what waits for payment, then the latest orders. */}
        <div className="ac-home">
          {toPay.length > 0 && (
            <button type="button" className="ac-alert" onClick={() => open('commandes', toPay[0].id)}>
              <span>{c.acToPay.replace('{n}', String(toPay.length))}</span>
              <b>{c.acPay}</b>
            </button>
          )}
          <div className="ac-home-head">
            <h2>{c.acRecent}</h2>
            {orders && orders.length > 0 && <button type="button" onClick={() => open('commandes')}>{c.acSeeAll}</button>}
          </div>
          {orders === null ? (
            <div className="ac-recent ac-skel" aria-hidden="true">{[0, 1].map((i) => <i key={i} className="sk-row" />)}</div>
          ) : orders.length === 0 ? (
            <div className="ac-home-empty">
              <p><strong>{c.acNoOrders}</strong> {c.acNoOrdersText}</p>
              <a className="btn btn-dark btn-small" href={home + '/catalogue'}>{c.continueShopping}</a>
            </div>
          ) : (
            <ul className="ac-recent">
              {orders.slice(0, 3).map((o) => (
                <li key={o.id}>
                  <button type="button" onClick={() => open('commandes', o.id)}>
                    {stack(o)}
                    <span className="ac-info">
                      <span className={'ac-badge ' + toneOf(o)}>{statusOf(o)}</span>
                      <small>{date(o.created_at, true)} · {c.acItemsN.replace('{n}', String(count(o)))}</small>
                    </span>
                    <b>{money(o.total, locale)}</b>
                    {chevron}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <section className="ac-panel" aria-label={labels[tab]} key={tab}>
          <div className="ac-bar">
            <button type="button" className="ac-back" onClick={phoneOrder ? () => setOpenOrder('') : toMenu} aria-label={phoneOrder ? labels.commandes : c.acTitle}>{back}</button>
            <h2>{phoneOrder ? c.acOrderNo.replace('{n}', phoneOrder.number) : labels[tab]}</h2>
            <span aria-hidden="true" />
          </div>

          {tab === 'commandes' && (
            orders === null ? <AcLoading /> : failed === 'orders' ? (
              <p className="ac-empty-text">{c.acLoadFailed}</p>
            ) : orders.length === 0 ? (
              <div className="ac-empty">
                <p><strong>{c.acNoOrders}</strong> {c.acNoOrdersText}</p>
                <a className="btn btn-dark btn-small" href={home + '/catalogue'}>{c.continueShopping}</a>
              </div>
            ) : phoneOrder ? detail(phoneOrder) : (
              <>
                <ul className="ac-orders">
                  {orders.map((o) => (
                    <li key={o.id} className={'ac-order' + (o.payable ? ' payable' : '')}>
                      <button type="button" className="ac-row" onClick={() => { setOpenOrder(o.id); if (phone) scrollTo({ top: 0 }); }} title={c.acDetails}>
                        {stack(o)}
                        <span className="ac-info">
                          <strong>{c.acOrderNo.replace('{n}', o.number)}</strong>
                          <small>{c.acPlaced.replace('{d}', date(o.created_at))} · {c.acItemsN.replace('{n}', String(count(o)))}</small>
                        </span>
                        <span className={'ac-badge ac-status-cell ' + toneOf(o)}>{statusOf(o)}</span>
                        <b className="ac-total">{money(o.total, locale)}</b>
                        <span className="ac-chev">{chevron}</span>
                      </button>
                      {o.payable && (
                        <button type="button" className="btn btn-accent btn-small ac-pay" onClick={() => pay(o)} disabled={busy === 'pay:' + o.id}>
                          {busy === 'pay:' + o.id && <i className="spin" />} {c.acPay}
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
                {page.last > 1 && (
                  <nav className="ac-pager" aria-label="Pagination">
                    <button type="button" disabled={page.page <= 1} onClick={() => loadOrders(page.page - 1)}>{back}<span>{locale === 'fr' ? 'Précédent' : 'Previous'}</span></button>
                    <span>{page.page} / {page.last}</span>
                    <button type="button" disabled={page.page >= page.last} onClick={() => loadOrders(page.page + 1)}><span>{locale === 'fr' ? 'Suivant' : 'Next'}</span>{chevron}</button>
                  </nav>
                )}
              </>
            )
          )}

          {tab === 'achats' && user && <AccountPurchases locale={locale} api={api} onNote={note} />}
          {tab === 'favoris' && user && <AccountFavorites locale={locale} api={api} onNote={note} mode="products" />}
          {tab === 'boutiques' && user && <AccountFavorites locale={locale} api={api} onNote={note} mode="shops" />}
          {tab === 'inspirations' && user && <AccountBoards locale={locale} api={api} onNote={note} />}
          {tab === 'demandes' && user && <AccountRequests locale={locale} api={api} onNote={note} />}
          {tab === 'fidelite' && user && <AccountLoyalty locale={locale} api={api} onNote={note} />}
          {tab === 'messages' && user && <AccountMessages locale={locale} api={api} onNote={note} />}

          {tab === 'adresses' && (
            addresses === null ? <AcLoading kind="tiles" /> : (
              <div className="ac-addr-grid">
                {failed === 'addresses' && <p className="ac-empty-text">{c.acLoadFailed}</p>}
                {addresses.map((a) => (
                  <article key={a.id} className="ac-addr">
                    <header>
                      <span className="ac-addr-ico">{pin}</span>
                      <strong>{a.label}</strong>
                      <span className="ac-badge soft">{a.city}</span>
                    </header>
                    <p>{a.district}<br />{a.landmark}</p>
                    <p className="ac-addr-who">{a.contact_name}<br />{a.contact_phone}</p>
                    {!a.served && <small className="ac-addr-warn">{c.acNotServed}</small>}
                    <footer>
                      <button type="button" onClick={() => editAddress(a)}>{pencil}<span>{locale === 'fr' ? 'Modifier' : 'Edit'}</span></button>
                      <button type="button" className="danger" onClick={() => setRemoving(a)}>{trash}<span>{c.acRemove}</span></button>
                    </footer>
                  </article>
                ))}
                <button type="button" className="ac-addr ac-addr-new" onClick={() => editAddress('new')}>
                  <span>+</span>
                  <strong>{c.acAddAddress}</strong>
                </button>
              </div>
            )
          )}

          {tab === 'parametres' && (!user || !prefs || viewed === null) && <AcLoading />}
          {tab === 'parametres' && user && prefs && viewed !== null && (
            <div className="ac-set">
              <section className="ac-set-card ac-set-profile">
                {avatar('big')}
                <div>
                  <strong>{user?.name}</strong>
                  <small>{user?.email}</small>
                </div>
              </section>
              <section className="ac-set-card">
                <h3>{c.acSecurity}</h3>
                <button type="button" className="ac-set-row" onClick={() => { setPwOpen(true); setPwErrors({}); }} disabled={!user}>
                  <span className="ac-set-ico">{lock}</span>
                  <span><strong>{c.acChangePassword}</strong><small>{c.auPasswordHint}</small></span>
                  {chevron}
                </button>
                <button type="button" className="ac-set-row" onClick={() => { setMfaError(''); setMfa({ step: 'password', off: !!user?.mfa_enabled }); }} disabled={!user}>
                  <span className="ac-set-ico">{shield}</span>
                  <span><strong>{t.twofa}</strong><small>{t.twofaIntro}</small></span>
                  <span className={'ac-badge ' + (user?.mfa_enabled ? 'ok' : 'off')}>{user?.mfa_enabled ? t.twofaOn : t.twofaOff}</span>
                </button>
              </section>
              <section className="ac-set-card">
                <h3>{t.notifTitle}</h3>
                {prefs ? (
                  <div className="ac-notif2">
                    {(['reminder', 'marketing'] as const).map((g) => (
                      <div key={g} className="ac-notif-group">
                        <p>{g === 'reminder' ? t.notifReminder : t.notifMarketing}</p>
                        <ul>
                          {(['email', 'whatsapp', 'web_push', 'in_app'] as const).map((ch) => (
                            <li key={ch}>
                              <span className="ac-set-ico small">{channelIcons[ch]}</span>
                              <span>{t.channels[ch]}</span>
                              <button type="button" className="ac-switch" role="switch" aria-checked={prefs[g][ch]} aria-label={(g === 'reminder' ? t.notifReminder : t.notifMarketing) + ' · ' + t.channels[ch]} onClick={() => setPref(g, ch, !prefs[g][ch])}><i /></button>
                            </li>
                          ))}
                        </ul>
                      </div>
                    ))}
                    <p className="ac-hint">{t.notifTransactional}</p>
                  </div>
                ) : <div className="ac-skel"><i className="sk-row" /></div>}
              </section>
              <section className="ac-set-card">
                <h3>{t.privacy}</h3>
                <div className="ac-set-row static">
                  <span className="ac-set-ico">{eye}</span>
                  <span><strong>{t.viewedHistory}</strong><small>{t.viewedHistoryText}</small></span>
                  {viewed !== null && <button type="button" className="ac-switch" role="switch" aria-checked={viewed} aria-label={t.viewedHistory} onClick={() => setHistory(!viewed)}><i /></button>}
                </div>
                <button type="button" className="ac-text-btn ac-clear" onClick={clearHistory}>{t.clearHistory}</button>
              </section>
              <section className="ac-set-card ac-set-out">
                <button type="button" className="ac-out-btn" onClick={() => setOutOpen(true)} disabled={!user}>{exit}<span>{c.acLogout}</span></button>
              </section>
            </div>
          )}
        </section>
      </div>

      {!phone && opened && tab === 'commandes' && (
        <AcModal title={c.acOrderNo.replace('{n}', opened.number)} onClose={() => setOpenOrder('')} closeLabel={c.close}>{detail(opened)}</AcModal>
      )}
      {editing && (
        <AcModal title={editing === 'new' ? c.acAddAddress : (locale === 'fr' ? 'Modifier l’adresse' : 'Edit address')} onClose={() => setEditing(null)} closeLabel={c.close}>
          <form className="co-form acm-form ac-addr-form" onSubmit={saveAddress}>
            <label><span>{c.coCity}</span><Combo strict required value={form.city} options={CITIES} onChange={(city) => setForm((f) => ({ ...f, city, district: f.city === city ? f.district : '' }))} /></label>
            <label><span>{c.district}</span><Combo required value={form.district} options={districts} placeholder={c.coDistrictHint} onChange={(district) => setForm((f) => ({ ...f, district }))} /></label>
            <label className="wide"><span>{c.coLandmark}</span><input required value={form.landmark} onChange={(e) => setForm({ ...form, landmark: e.target.value })} placeholder={c.coLandmarkHint} maxLength={300} /></label>
            <label><span>{c.coName}</span><input required value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} maxLength={150} /></label>
            <label><span>{c.coPhone}</span><input required type="tel" value={form.contact_phone} onChange={(e) => setForm({ ...form, contact_phone: e.target.value })} placeholder="+237 6XX XX XX XX" /></label>
            <button type="submit" className="btn btn-accent wide ac-wide" disabled={busy === 'address'}>{busy === 'address' && <i className="spin" />} {t.save}</button>
          </form>
        </AcModal>
      )}
      {removing && (
        <AcModal title={c.acRemove + ' ?'} onClose={() => setRemoving(null)} closeLabel={c.close}>
          <div className="acm-confirm">
            <p><strong>{removing.label} · {removing.city}</strong><br />{removing.district} — {removing.landmark}</p>
            <div className="acm-actions">
              <button type="button" className="btn btn-line btn-small" onClick={() => setRemoving(null)}>{c.cancel}</button>
              <button type="button" className="btn btn-small acm-danger" onClick={() => remove(removing)} disabled={busy === 'remove'}>{busy === 'remove' && <i className="spin" />} {c.acRemove}</button>
            </div>
          </div>
        </AcModal>
      )}
      {pwOpen && (
        <AcModal title={c.acChangePassword} onClose={() => setPwOpen(false)} closeLabel={c.close}>
          <form className="ac-pw acm-form" onSubmit={changePassword}>
            {([['current_password', c.acCurrentPassword, 'current-password'], ['password', c.acNewPassword, 'new-password'], ['password_confirmation', c.auConfirm, 'new-password']] as const).map(([name, label, auto]) => (
              <label key={name} className={pwErrors[name] ? 'bad' : undefined}>
                <span>{label}</span>
                <input name={name} type="password" autoComplete={auto} required minLength={name === 'current_password' ? 1 : 12} />
                {pwErrors[name] && <small>{pwErrors[name].join(' ')}</small>}
              </label>
            ))}
            <p className="ac-pw-hint">{c.auPasswordHint}</p>
            <button type="submit" className="btn btn-accent ac-wide" disabled={busy === 'password'}>{busy === 'password' && <i className="spin" />} {c.acSave}</button>
          </form>
        </AcModal>
      )}
      {outOpen && (
        <AcModal title={c.acLogout + ' ?'} onClose={() => setOutOpen(false)} closeLabel={c.close}>
          <div className="acm-confirm">
            <p>{user?.email}</p>
            <div className="acm-actions">
              <button type="button" className="btn btn-line btn-small" onClick={() => setOutOpen(false)}>{c.cancel}</button>
              <button type="button" className="btn btn-small acm-danger" onClick={logout} disabled={busy === 'logout'}>{busy === 'logout' && <i className="spin" />} {c.acLogout}</button>
            </div>
          </div>
        </AcModal>
      )}
      {editOrder && (
        <AcModal title={t.editOrder} onClose={() => setEditOrder(null)} closeLabel={c.close}>
          <div className="acm-confirm">
            <p>{t.editOrderText}</p>
            <div className="acm-actions">
              <button type="button" className="btn btn-line btn-small" onClick={() => setEditOrder(null)}>{c.cancel}</button>
              <button type="button" className="btn btn-accent btn-small" onClick={() => reopen(editOrder)} disabled={busy === 'reopen'}>{busy === 'reopen' && <i className="spin" />} {t.editOrderConfirm}</button>
            </div>
          </div>
        </AcModal>
      )}
      {mfa && (
        <AcModal title={t.twofa} onClose={() => setMfa(null)} closeLabel={c.close}>
          {mfa.step === 'password' && (
            <form className="ac-pw acm-form" onSubmit={mfaPassword}>
              <p className="acm-sub">{mfa.off ? t.twofaDisable : t.twofaIntro}</p>
              <label><span>{t.twofaPassword}</span><input name="password" type="password" autoComplete="current-password" required autoFocus /></label>
              {mfaError && <p className="ac-error">{mfaError}</p>}
              <button type="submit" className={'btn ac-wide ' + (mfa.off ? 'acm-danger' : 'btn-accent')} disabled={busy === 'mfa'}>{busy === 'mfa' && <i className="spin" />} {mfa.off ? t.twofaDisable : t.twofaContinue}</button>
            </form>
          )}
          {mfa.step === 'qr' && (
            <form className="ac-pw acm-form" onSubmit={mfaConfirm}>
              <p className="acm-sub">{t.twofaScan}</p>
              <div className="ac-qr" dangerouslySetInnerHTML={{ __html: qr }} />
              <label><span>{t.twofaCode}</span><input name="code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} autoComplete="one-time-code" required autoFocus /></label>
              {mfaError && <p className="ac-error">{mfaError}</p>}
              <button type="submit" className="btn btn-accent ac-wide" disabled={busy === 'mfa'}>{busy === 'mfa' && <i className="spin" />} {t.twofaConfirm}</button>
            </form>
          )}
          {mfa.step === 'codes' && (
            <div className="acm-form">
              <p className="acm-sub">{t.twofaCodes}</p>
              <ul className="ac-codes">{codes.map((k) => <li key={k}>{k}</li>)}</ul>
              <button type="button" className="btn btn-dark ac-wide" onClick={() => setMfa(null)}>{t.twofaDone}</button>
            </div>
          )}
        </AcModal>
      )}
      {toast && <p className="ac-toast" role="status">{toast}</p>}
    </div>
  );
}

const icon = (d: string) => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={d} /></svg>
);
const chevron = icon('m9 6 6 6-6 6');
const back = icon('m15 6-6 6 6 6');
const bag = icon('M5.5 8h13l-1 12h-11l-1-12ZM9 8V6.5a3 3 0 0 1 6 0V8');
const shield = icon('M12 3.5 5 6v5.5c0 4.2 3 7.6 7 9 4-1.4 7-4.8 7-9V6l-7-2.5ZM9 12l2 2 4-4');
const heart = icon('M12 20.2s-7.4-4.5-9.1-9.2C1.7 7.6 4 4.4 7.3 4.4c2 0 3.6 1.1 4.7 2.8 1.1-1.7 2.7-2.8 4.7-2.8 3.3 0 5.6 3.2 4.4 6.6-1.7 4.7-9.1 9.2-9.1 9.2Z');
const spark = icon('M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14ZM20.5 20.5 16 16M11 8v6M8 11h6');
const card = icon('M3.5 6.5h17v11h-17zM3.5 10h17M7 14.5h4');
const chat = icon('M4 5.5h16v10H9l-5 4v-14ZM8 10h8M8 13h5');
const pin = icon('M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11ZM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z');
const pencil = icon('M4 20h4L19 9l-4-4L4 16v4ZM13.5 6.5l4 4');
const trash = icon('M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13');
const lock = icon('M6.5 11h11v9h-11zM8.5 11V8a3.5 3.5 0 0 1 7 0v3');
// Lucide "settings" icon (ISC licence).
const gear = icon('M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z');
const camera = icon('M4 8h3l1.5-2h7L17 8h3v11H4zM12 16.5a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z');
const store = icon('M4 9h16l-1.2-4.5H5.2L4 9ZM5 9v10.5h14V9M9.5 19.5V14h5v5.5');
const boards = icon('M3.5 3.5h7.5v10H3.5zM13 3.5h7.5v6H13zM3.5 15.5h7.5v5H3.5zM13 11.5h7.5v9H13z');
const wallet = icon('M4 7.5h15.5v11H4zM4 7.5l11.5-3v3M15.5 13h2');
const truck = icon('M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19.5a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2ZM17 19.5a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2Z');
const done = icon('M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM8 12.2l2.8 2.8L16 9.5');
const eye = icon('M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12ZM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z');
const channelIcons: Record<string, ReactNode> = {
  email: icon('M3.5 6h17v12h-17zM3.5 6.5l8.5 6.5 8.5-6.5'),
  whatsapp: icon('M4.5 19.5l1.2-3.6A8 8 0 1 1 8.4 18.6l-3.9.9Z'),
  web_push: icon('M6 17V11a6 6 0 1 1 12 0v6l1.5 1.5h-15L6 17ZM10 20.5a2 2 0 0 0 4 0'),
  in_app: icon('M3.5 5h17v11.5h-17zM9 20h6M12 16.5V20'),
};
const exit = icon('M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4');
