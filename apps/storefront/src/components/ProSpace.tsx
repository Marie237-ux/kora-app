import { useEffect, useMemo, useState, type SyntheticEvent } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { money, type Locale } from '../lib/copy';
import { pagesCopy } from '../lib/pages-copy';
import { loadCart } from '../lib/cart';
import AcModal from './AcModal';

// Business space (spec §14): a business profile (billing identity + a delivery address), quotes asked from the
// current cart, then for an issued quote: accept (conditions shown, stock not reserved), pick the delivery day and
// time, order and pay online — or decline. All amounts come from the quote the KORA team issued.

type Address = { id: string; address: { label: string; district: string; landmark: string } };
type Profile = { id: string; version: number; billing_identity: { legal_name: string; billing_address: string; email?: string | null; phone?: string | null }; addresses: Address[] };
type Revision = { id: string; valid_until: string; snapshot: { totals: { total: string }; items_total: string; delivery: { total: string }; lines: { name?: string | Record<string, string>; quantity: number; line_total?: string }[]; conditions?: string | null } };
type Quote = { id: string; number: string; version: number; status: string; revisions: Revision[]; order_id: string | null; request_notes: string | null };
type Slot = { rule_id: string; date: string; starts_at: string; ends_at: string; remaining: number };
const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });

export default function ProSpace({ locale }: { locale: Locale }) {
  const p = pagesCopy(locale);
  const api = useMemo(() => createApi('', locale), [locale]);
  const home = '/' + locale;
  const [member, setMember] = useState<boolean | null>(null);
  const [profile, setProfile] = useState<Profile | null | undefined>(undefined);
  const [quotes, setQuotes] = useState<Quote[]>([]);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [form, setForm] = useState({ legal_name: '', billing_address: '', niu: '', email: '', phone: '', label: 'Siège', district: '', landmark: '', contact_name: '', contact_phone: '' });
  const [asking, setAsking] = useState(false);
  const [notes, setNotes] = useState('');
  const [addressId, setAddressId] = useState('');
  const [ordering, setOrdering] = useState<{ quote: Quote; slots: Slot[]; total: string } | null>(null);
  const [slot, setSlot] = useState<Slot | null>(null);

  async function load() {
    try {
      const list = (await api.request<{ data: Profile[] }>('/api/v1/professional/profiles')).data;
      const first = list[0] ?? null;
      setProfile(first);
      if (first) {
        setAddressId(first.addresses[0]?.id ?? '');
        setQuotes((await api.request<{ data: Quote[] }>('/api/v1/professional/profiles/' + first.id + '/quotes')).data);
      }
    } catch {
      setProfile(null);
    }
  }
  useEffect(() => {
    api.request('/api/v1/me').then(() => { setMember(true); load(); }).catch(() => setMember(false));
    const onSigned = () => { setMember(true); load(); };
    addEventListener('kora:signed-in', onSigned);
    return () => removeEventListener('kora:signed-in', onSigned);
  }, []);

  const fail = (err: unknown) => setError(err instanceof ApiError && err.message ? err.message : p.failed);

  async function create(e: SyntheticEvent) {
    e.preventDefault();
    setBusy('create');
    setError('');
    try {
      const prof = (await api.request<{ data: Profile }>('/api/v1/professional/profiles', { method: 'POST', headers: key(), body: JSON.stringify({ legal_name: form.legal_name, billing_address: form.billing_address, niu: form.niu || null, rccm: null, email: form.email || null, phone: form.phone || null }) })).data;
      await api.request('/api/v1/professional/profiles/' + prof.id + '/addresses', { method: 'POST', headers: key(), body: JSON.stringify({ label: form.label, district: form.district, landmark: form.landmark, contact_name: form.contact_name, contact_phone: form.contact_phone }) });
      await load();
    } catch (err) {
      fail(err);
    } finally {
      setBusy('');
    }
  }
  async function ask(e: SyntheticEvent) {
    e.preventDefault();
    if (!profile) return;
    setBusy('ask');
    setError('');
    try {
      const cart = await loadCart(locale);
      await api.request('/api/v1/professional/profiles/' + profile.id + '/quotes', { method: 'POST', headers: key(), body: JSON.stringify({ cart_version: cart.version, address_id: addressId, notes: notes || null }) });
      setAsking(false);
      setNotes('');
      await load();
    } catch (err) {
      fail(err);
    } finally {
      setBusy('');
    }
  }
  async function decline(q: Quote) {
    if (!profile) return;
    setBusy('decline:' + q.id);
    try {
      await api.request('/api/v1/professional/profiles/' + profile.id + '/quotes/' + q.id + '/decline', { method: 'POST', headers: key(), body: JSON.stringify({ version: q.version, reason: null }) });
      await load();
    } catch (err) {
      fail(err);
    } finally {
      setBusy('');
    }
  }
  // Accept: the conditions of the current revision; then the delivery day and time, the order, the payment.
  async function accept(q: Quote) {
    if (!profile) return;
    const rev = q.revisions[q.revisions.length - 1];
    setBusy('accept:' + q.id);
    setError('');
    try {
      let quote = q;
      if (q.status === 'issued') {
        quote = (await api.request<{ data: Quote }>('/api/v1/professional/profiles/' + profile.id + '/quotes/' + q.id + '/accept', { method: 'POST', headers: key(), body: JSON.stringify({ version: q.version, revision_id: rev.id, accepted_total: rev.snapshot.totals.total, supplier_unreserved_acknowledged: true }) })).data;
      }
      const preview = (await api.request<{ data: { delivery: { slots: Slot[] }; accepted_total: string } }>('/api/v1/professional/profiles/' + profile.id + '/quotes/' + q.id + '/checkout-preview', { method: 'POST', headers: key(), body: JSON.stringify({}) })).data;
      setOrdering({ quote, slots: preview.delivery.slots ?? [], total: preview.accepted_total });
      setSlot(null);
    } catch (err) {
      fail(err);
    } finally {
      setBusy('');
    }
  }
  async function order() {
    if (!profile || !ordering || !slot) return;
    setBusy('order');
    try {
      const o = (await api.request<{ data: { id: string } }>('/api/v1/professional/profiles/' + profile.id + '/quotes/' + ordering.quote.id + '/convert', { method: 'POST', headers: key(), body: JSON.stringify({ version: ordering.quote.version, accepted_total: ordering.total, rule_id: slot.rule_id, date: slot.date }) })).data;
      const attempt = await api.request<{ data: { payment_url: string | null } }>('/api/v1/orders/' + o.id + '/payments', { method: 'POST', headers: key() });
      location.assign(attempt.data.payment_url ?? home + '/orders/' + o.id);
    } catch (err) {
      fail(err);
      setBusy('');
    }
  }

  const date = (iso: string) => new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso));
  const tone = (s: string) => (s === 'accepted' || s === 'converted' ? 'ok' : s === 'issued' ? 'wait' : s === 'requested' ? 'run' : 'off');

  if (member === false) {
    return (
      <div className="pro-cta kcard">
        <p>{p.proSignIn}</p>
        <button type="button" className="btn btn-accent" onClick={() => window.dispatchEvent(new CustomEvent('kora:auth', { detail: { mode: 'login', next: null } }))}>{p.proStart}</button>
      </div>
    );
  }
  if (member === null || profile === undefined) return <div className="oc-wait kcard"><i className="spin" /></div>;

  if (!profile) {
    return (
      <form className="pro-form kcard" onSubmit={create}>
        <h3>{p.proStart}</h3>
        <div className="ac-row2">
          <label className="ac-field"><span>{p.proName}</span><input required maxLength={200} value={form.legal_name} onChange={(e) => setForm({ ...form, legal_name: e.target.value })} /></label>
          <label className="ac-field"><span>{p.proTax}</span><input maxLength={100} value={form.niu} onChange={(e) => setForm({ ...form, niu: e.target.value })} /></label>
        </div>
        <label className="ac-field"><span>{locale === 'fr' ? 'Adresse de facturation' : 'Billing address'}</span><input required maxLength={1000} value={form.billing_address} onChange={(e) => setForm({ ...form, billing_address: e.target.value })} /></label>
        <div className="ac-row2">
          <label className="ac-field"><span>{p.proEmail}</span><input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
          <label className="ac-field"><span>{p.proPhone}</span><input type="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></label>
        </div>
        <p className="pro-sub">{locale === 'fr' ? 'Adresse de livraison' : 'Delivery address'}</p>
        <div className="ac-row2">
          <label className="ac-field"><span>{locale === 'fr' ? 'Quartier' : 'District'}</span><input required maxLength={120} value={form.district} onChange={(e) => setForm({ ...form, district: e.target.value })} /></label>
          <label className="ac-field"><span>{locale === 'fr' ? 'Repère' : 'Landmark'}</span><input required maxLength={300} value={form.landmark} onChange={(e) => setForm({ ...form, landmark: e.target.value })} /></label>
          <label className="ac-field"><span>{locale === 'fr' ? 'Personne qui reçoit' : 'Receiving person'}</span><input required maxLength={150} value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} /></label>
          <label className="ac-field"><span>{p.proPhone}</span><input required type="tel" value={form.contact_phone} onChange={(e) => setForm({ ...form, contact_phone: e.target.value })} placeholder="+237 6XX XX XX XX" /></label>
        </div>
        {error && <p className="ac-error">{error}</p>}
        <button type="submit" className="btn btn-accent" disabled={busy === 'create'}>{busy === 'create' && <i className="spin" />} {p.proCreate}</button>
      </form>
    );
  }

  return (
    <div className="pro">
      <section className="kcard pro-profile">
        <div>
          <small>{p.proProfiles}</small>
          <h3>{profile.billing_identity.legal_name}</h3>
          <p>{profile.billing_identity.billing_address}</p>
        </div>
        <button type="button" className="btn btn-accent" onClick={() => setAsking(true)}>{p.proRequestQuote}</button>
      </section>
      <h3 className="ac-h3">{p.proQuotes}</h3>
      {error && <p className="ac-error">{error}</p>}
      {quotes.length === 0 ? <p className="ac-empty-text">{p.proNoQuotes}</p> : (
        <ul className="pro-quotes">
          {quotes.map((q) => {
            const rev = q.revisions[q.revisions.length - 1];
            return (
              <li key={q.id} className="kcard">
                <div className="pro-q-head">
                  <strong>{q.number}</strong>
                  <span className={'ac-badge ' + tone(q.status)}>{p.proStatuses[q.status] ?? q.status}</span>
                </div>
                {rev ? (
                  <>
                    <p className="pro-q-total">{money(rev.snapshot.totals.total, locale)}</p>
                    <small>{p.proValid} {date(rev.valid_until)}</small>
                    {rev.snapshot.conditions && <p className="pro-q-cond">{rev.snapshot.conditions}</p>}
                  </>
                ) : <small>{q.request_notes || '—'}</small>}
                {(q.status === 'issued' || q.status === 'accepted') && rev && (
                  <div className="pro-q-actions">
                    <button type="button" className="btn btn-accent btn-small" onClick={() => accept(q)} disabled={busy === 'accept:' + q.id}>{busy === 'accept:' + q.id && <i className="spin" />} {q.status === 'issued' ? p.proAccept : (locale === 'fr' ? 'Commander' : 'Order')}</button>
                    {q.status === 'issued' && <button type="button" className="btn btn-line btn-small" onClick={() => decline(q)} disabled={busy === 'decline:' + q.id}>{p.proDecline}</button>}
                  </div>
                )}
                {q.order_id && <a className="pg-tile-cta" href={home + '/orders/' + q.order_id}>{p.ocSee} →</a>}
              </li>
            );
          })}
        </ul>
      )}

      {asking && (
        <AcModal title={p.proRequestQuote} onClose={() => setAsking(false)}>
          <form className="acm-form" onSubmit={ask}>
            <div className="ac-field">
              <span>{locale === 'fr' ? 'Livrer à' : 'Deliver to'}</span>
              <div className="ac-kinds2">
                {profile.addresses.map((a) => (
                  <label key={a.id} data-on={addressId === a.id}>
                    <input type="radio" name="address" checked={addressId === a.id} onChange={() => setAddressId(a.id)} />
                    <strong>{a.address.label}</strong>
                    <small>{a.address.district} — {a.address.landmark}</small>
                  </label>
                ))}
              </div>
            </div>
            <label className="ac-field"><span>{p.proQuoteNote}</span><textarea rows={3} maxLength={3000} value={notes} onChange={(e) => setNotes(e.target.value)} /></label>
            {error && <p className="ac-error">{error}</p>}
            <button type="submit" className="btn btn-accent ac-wide" disabled={busy === 'ask' || !addressId}>{busy === 'ask' && <i className="spin" />} {p.proRequestQuote}</button>
          </form>
        </AcModal>
      )}
      {ordering && (
        <AcModal title={ordering.quote.number} onClose={() => setOrdering(null)}>
          <div className="acm-form">
            <p className="acm-sub">{locale === 'fr' ? 'Choisissez le jour et l’heure de livraison.' : 'Choose the delivery day and time.'}</p>
            <div className="co-times">
              {ordering.slots.slice(0, 12).map((s) => (
                <button key={s.rule_id + s.date} type="button" role="radio" aria-checked={slot === s} disabled={s.remaining < 1} onClick={() => setSlot(s)}>
                  <i aria-hidden="true" />
                  <span><strong>{new Date(s.date + 'T12:00:00').toLocaleDateString(locale === 'fr' ? 'fr-FR' : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}</strong><small>{s.starts_at.slice(0, 5)} – {s.ends_at.slice(0, 5)}</small></span>
                </button>
              ))}
            </div>
            <div className="oc-sum"><div className="oc-total"><dt>{p.ocTotal}</dt><dd>{money(ordering.total, locale)}</dd></div></div>
            <button type="button" className="btn btn-accent ac-wide" disabled={!slot || busy === 'order'} onClick={order}>{busy === 'order' && <i className="spin" />} {locale === 'fr' ? 'Commander et payer' : 'Order and pay'}</button>
          </div>
        </AcModal>
      )}
    </div>
  );
}
