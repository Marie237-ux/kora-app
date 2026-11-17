import { useEffect, useMemo, useState, type SyntheticEvent } from 'react';
import { createApi } from '@kora/api-client';
import { copy, money, type Locale } from '../lib/copy';
import { loadCart, type Cart, type CartLine } from '../lib/cart';
import { CITIES } from '../lib/cities';
import Combo from './Combo';
import AcModal from './AcModal';

// Checkout in three steps, with a stepper on top and the totals beside: 1. where the order is received (address
// cards, a new one in a window), 2. the delivery quote from the API, split into what KORA couriers deliver in that
// city (a day, then a time) and what is shipped from another city (one parcel per origin city, fee and delay),
// 3. a recap (address, delivery, items, each with "Edit") and payment. Amounts always come from the API.

type Address = { id: string; label: string; city: string; district: string; landmark: string; contact_name: string; contact_phone: string; served: boolean; version: number };
type Slot = { rule_id: string; date: string; starts_at: string; ends_at: string; remaining: number };
type Group = { mode: 'local' | 'shipping'; from?: string; city: string; line_ids: string[]; fee: string | null; days_min?: number | null; days_max?: number | null; to_door?: boolean | null };
type Quote = {
  id: string; version: number; status: 'quoted' | 'confirmation_required' | 'unserviceable'; reasons: string[]; total: string | null;
  payable_delivery: string | null; groups: Group[]; needs_slot: boolean; slots: Slot[]; slot: { rule_id: string; date: string; status: string } | null;
};
type Zone = { city: string; districts: string[] };

const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });

export default function Checkout({ locale }: { locale: Locale }) {
  const c = copy(locale);
  const api = useMemo(() => createApi('', locale), [locale]);
  const home = '/' + locale;
  const [cart, setCart] = useState<Cart | null>(null);
  const [member, setMember] = useState<boolean | null>(null);
  const [addresses, setAddresses] = useState<Address[]>([]);
  const [zones, setZones] = useState<Zone[]>([]);
  const [addressId, setAddressId] = useState('');
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState({ label: c.coHome, city: 'Douala', district: '', landmark: '', contact_name: '', contact_phone: '' });
  const [quote, setQuote] = useState<Quote | null>(null);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  // Three steps (address → delivery → payment); the delivery day shown in the slot picker.
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [day, setDay] = useState('');

  useEffect(() => {
    loadCart(locale).then(setCart).catch(() => setCart(null));
    api.request<{ data: { name: string } }>('/api/v1/me').then(() => setMember(true)).catch(() => setMember(false));
    api.request<{ data: Zone[] }>('/api/v1/delivery/zones').then((r) => setZones(r.data)).catch(() => {});
    // Signed in from the account panel without leaving the page: the cart (merged) and the address step follow.
    const onSigned = () => {
      setMember(true);
      loadCart(locale).then(setCart).catch(() => {});
    };
    window.addEventListener('kora:signed-in', onSigned);
    return () => window.removeEventListener('kora:signed-in', onSigned);
  }, []);
  useEffect(() => {
    if (!member) return;
    api.request<{ data: Address[] }>('/api/v1/account/addresses').then((r) => {
      setAddresses(r.data);
      if (r.data[0]) setAddressId(r.data[0].id);
      else setAdding(true);
    }).catch(() => setAdding(true));
  }, [member]);

  const selected = cart?.lines.filter((l) => l.selected) ?? [];
  const itemsTotal = selected.reduce((t, l) => t + Number(l.current_line_total ?? 0), 0);
  const lineById = (id: string) => cart?.lines.find((l) => l.id === id);
  const districts = zones.filter((z) => z.city === form.city).flatMap((z) => z.districts);

  // A new quote whenever the address (or the cart) changes.
  useEffect(() => {
    if (!member || !addressId || !cart || selected.length === 0) return;
    setQuote(null);
    setError('');
    setBusy('quote');
    api
      .request<{ data: Quote }>('/api/v1/cart/delivery-quotes', { method: 'POST', headers: key(), body: JSON.stringify({ cart_version: cart.version, address_id: addressId }) })
      .then((r) => setQuote(r.data))
      .catch(() => setError(c.coQuoteFailed))
      .finally(() => setBusy(''));
  }, [addressId, cart?.version, member]);

  async function saveAddress(e: SyntheticEvent) {
    e.preventDefault();
    setBusy('address');
    setError('');
    try {
      const r = await api.request<{ data: Address }>('/api/v1/account/addresses', { method: 'POST', headers: key(), body: JSON.stringify(form) });
      setAddresses((a) => [r.data, ...a]);
      setAddressId(r.data.id);
      setAdding(false);
    } catch {
      setError(c.coAddressFailed);
    } finally {
      setBusy('');
    }
  }

  async function pickSlot(slot: Slot) {
    if (!quote) return;
    setBusy('slot');
    try {
      const r = await api.request<{ data: Quote }>('/api/v1/cart/delivery-quotes/' + quote.id + '/slot', { method: 'POST', headers: key(), body: JSON.stringify({ version: quote.version, rule_id: slot.rule_id, date: slot.date }) });
      setQuote(r.data);
    } catch {
      setError(c.coSlotFailed);
    } finally {
      setBusy('');
    }
  }

  const total = quote?.payable_delivery != null ? itemsTotal + Number(quote.payable_delivery) : null;
  const slotOk = quote ? !quote.needs_slot || quote.slot?.status === 'held' : false;
  const canPay = quote?.status === 'quoted' && total !== null && slotOk;

  async function pay() {
    if (!quote || total === null) return;
    setBusy('pay');
    setError('');
    try {
      const order = await api.request<{ data: { id: string } }>('/api/v1/orders', { method: 'POST', headers: key(), body: JSON.stringify({ quote_id: quote.id, quote_version: quote.version, accepted_total: String(total) }) });
      const attempt = await api.request<{ data: { payment_url: string | null } }>('/api/v1/orders/' + order.data.id + '/payments', { method: 'POST', headers: key() });
      window.location.href = attempt.data.payment_url ?? home + '/account#commandes';
    } catch {
      setError(c.coPayFailed);
      setBusy('');
    }
  }

  const Lines = ({ ids }: { ids: string[] }) => (
    <ul className="co-lines">
      {ids.map((id) => lineById(id)).filter((l): l is CartLine => Boolean(l)).map((l) => (
        <li key={l.id}>
          <span className="co-img">{l.offer?.image && <img src={l.offer.image} alt="" loading="lazy" />}</span>
          <span className="co-name"><strong>{l.offer?.name}</strong><small>{l.offer?.shop.name}{l.offer?.shop.city && ' · ' + l.offer.shop.city} · × {l.quantity}</small></span>
          <b>{l.current_line_total ? money(l.current_line_total, locale) : '—'}</b>
        </li>
      ))}
    </ul>
  );

  useEffect(() => {
    const block = document.querySelector<HTMLElement>('[data-co-suggest]');
    if (block) block.hidden = !(cart && cart.lines.length === 0);
  }, [cart]);

  if (cart && cart.lines.length === 0) {
    return (
      <div className="co-empty">
        <h2>{c.coEmptyTitle}</h2>
        <p>{c.coEmptyText}</p>
        <div className="co-empty-actions">
          <a className="btn btn-dark" href={home + '/catalogue'}>{c.continueShopping}</a>
          <a className="co-empty-link" href={home + '/inspirations'}>{c.coEmptyIdeas} →</a>
        </div>
      </div>
    );
  }

  const steps = c.coSteps;
  const address = addresses.find((a) => a.id === addressId);
  const days = [...new Set((quote?.slots ?? []).map((s) => s.date))];
  const shownDay = day && days.includes(day) ? day : quote?.slot?.date && days.includes(quote.slot.date) ? quote.slot.date : days[0];
  const dateLabel = (d: string, long = false) => new Date(d + 'T12:00:00').toLocaleDateString(locale === 'fr' ? 'fr-FR' : 'en-GB', long ? { weekday: 'long', day: 'numeric', month: 'long' } : { weekday: 'short', day: 'numeric' });
  const part = (start: string) => c.coDayParts[Number(start.slice(0, 2)) < 12 ? 0 : Number(start.slice(0, 2)) < 17 ? 1 : 2];
  const chosen = quote?.slot ? quote.slots.find((s) => s.rule_id === quote.slot!.rule_id && s.date === quote.slot!.date) : undefined;
  const itemCount = selected.reduce((n, l) => n + l.quantity, 0);
  const go = (n: 1 | 2 | 3) => {
    setStep(n);
    document.querySelector('.co-stepper')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  const next = step === 1
    ? { label: c.coToDelivery, ok: !!addressId && member === true, run: () => go(2) }
    : step === 2
      ? { label: c.coToPayment, ok: canPay, run: () => go(3) }
      : { label: busy === 'pay' ? c.coPaying : c.coPayNow, ok: canPay && busy !== 'pay', run: pay };

  return (
    <div className="co co-v2">
      <ol className="co-stepper" aria-label={c.coPay}>
        {steps.map((s, i) => {
          const n = (i + 1) as 1 | 2 | 3;
          const state = n < step ? 'done' : n === step ? 'now' : 'next';
          return (
            <li key={s} data-state={state}>
              <button type="button" disabled={n > step} onClick={() => go(n)}>
                <span>{state === 'done' ? '✓' : n}</span>{s}
              </button>
            </li>
          );
        })}
      </ol>

      <div className="co-main">
        {/* 1. Address */}
        {step === 1 && (
          <section className="co-panel">
            <h2>{c.coWhere}</h2>
            {member === false ? (
              <div className="co-guest">
                <p>{c.coSignIn}</p>
                <div>
                  <a className="btn btn-accent" href={home + '/login?next=' + encodeURIComponent(home + '/commande')}>{c.signIn}</a>
                  <a className="btn btn-line" href={home + '/register?next=' + encodeURIComponent(home + '/commande')}>{c.createAccount}</a>
                </div>
              </div>
            ) : (
              <div className="co-addr-grid" role="radiogroup" aria-label={c.coWhere}>
                {addresses.map((a) => (
                  <label key={a.id} className="co-addr" data-on={addressId === a.id}>
                    <input type="radio" name="address" checked={addressId === a.id} onChange={() => setAddressId(a.id)} />
                    <span className="co-addr-top"><strong>{a.label}</strong><em>{a.city}</em><i aria-hidden="true" /></span>
                    <span>{a.district} — {a.landmark}</span>
                    <small>{a.contact_name} · {a.contact_phone}</small>
                  </label>
                ))}
                {member && (
                  <button type="button" className="co-addr co-addr-new" onClick={() => setAdding(true)}>
                    <span className="co-plus">+</span>
                    <strong>{c.coNewAddress}</strong>
                  </button>
                )}
              </div>
            )}
          </section>
        )}

        {/* 2. Delivery: groups with their items, then the day and the time */}
        {step === 2 && (
          <section className="co-panel">
            <h2>{c.coHow}</h2>
            {busy === 'quote' && <p className="co-wait"><i className="spin" /> {c.coComputing}</p>}
            {quote && quote.status !== 'quoted' && <p className={'co-note ' + quote.status}>{quote.status === 'unserviceable' ? c.coUnserviceable : c.coConfirm}</p>}
            {quote?.groups.map((g, i) => (
              <div key={i} className={'co-group2 ' + g.mode}>
                <header>
                  <span className="co-group-ico" aria-hidden="true">{g.mode === 'local'
                    ? <svg viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7" /><circle cx="7" cy="17.5" r="1.6" /><circle cx="17" cy="17.5" r="1.6" /></svg>
                    : <svg viewBox="0 0 24 24"><path d="M3 8 12 3l9 5v8l-9 5-9-5z" /><path d="m3 8 9 5 9-5M12 13v8" /></svg>}</span>
                  <span>
                    <strong>{g.mode === 'local' ? c.coDelivered.replace('{c}', g.city) : c.coShipped.replace('{from}', g.from ?? '').replace('{to}', g.city)}</strong>
                    <small>{g.mode === 'local' ? c.coDeliveredHint : g.days_min ? c.coShippedHint.replace('{a}', String(g.days_min)).replace('{b}', String(g.days_max)) + (g.to_door ? '' : ' · ' + c.coAgency) : c.coConfirmShort}</small>
                  </span>
                  <b>{g.fee !== null ? money(g.fee, locale) : c.coToConfirm}</b>
                </header>
                <Lines ids={g.line_ids} />
                {g.mode === 'local' && quote.needs_slot && quote.status === 'quoted' && (
                  <div className="co-when">
                    {quote.slots.length === 0 ? <p className="co-hint">{c.coNoSlot}</p> : (
                      <>
                        <p className="co-when-title">{c.coChooseDay}</p>
                        <div className="co-days" role="tablist">
                          {days.map((d) => (
                            <button key={d} type="button" role="tab" aria-selected={d === shownDay} onClick={() => setDay(d)}>
                              <small>{dateLabel(d).split(' ')[0]}</small>
                              <strong>{new Date(d + 'T12:00:00').getDate()}</strong>
                            </button>
                          ))}
                        </div>
                        <p className="co-when-title">{c.coChooseTime}</p>
                        <div className="co-times" role="radiogroup">
                          {quote.slots.filter((s) => s.date === shownDay).map((s) => {
                            const on = quote.slot?.rule_id === s.rule_id && quote.slot?.date === s.date;
                            const full = s.remaining < 1;
                            return (
                              <button key={s.rule_id + s.date} type="button" role="radio" aria-checked={on} disabled={busy === 'slot' || full} onClick={() => pickSlot(s)}>
                                <i aria-hidden="true" />
                                <span><strong>{part(s.starts_at)}</strong><small>{s.starts_at.slice(0, 5)} – {s.ends_at.slice(0, 5)}</small></span>
                                {full ? <em>{c.coSlotFull}</em> : s.remaining <= 3 ? <em>{c.coSlotLeft.replace('{n}', String(s.remaining))}</em> : null}
                              </button>
                            );
                          })}
                        </div>
                      </>
                    )}
                  </div>
                )}
              </div>
            ))}
            {!quote && busy !== 'quote' && <p className="co-hint">{c.coHowHint}</p>}
          </section>
        )}

        {/* 3. Recap: what, where, when — each with its "Edit" */}
        {step === 3 && (
          <section className="co-panel co-recap">
            <h2>{c.coRecap}</h2>
            <div className="co-recap-row">
              <span className="co-recap-label">{c.coDeliverTo}</span>
              <div>{address && <><strong>{address.label} · {address.city}</strong><small>{address.district} — {address.landmark}</small><small>{address.contact_name} · {address.contact_phone}</small></>}</div>
              <button type="button" onClick={() => go(1)}>{c.coEdit}</button>
            </div>
            <div className="co-recap-row">
              <span className="co-recap-label">{c.coDeliveryPlan}</span>
              <div>
                {quote?.groups.map((g, i) => <strong key={i}>{g.mode === 'local' ? c.coDelivered.replace('{c}', g.city) : c.coShipped.replace('{from}', g.from ?? '').replace('{to}', g.city)}</strong>)}
                {chosen && <small>{dateLabel(chosen.date, true)} · {chosen.starts_at.slice(0, 5)} – {chosen.ends_at.slice(0, 5)}</small>}
              </div>
              <button type="button" onClick={() => go(2)}>{c.coEdit}</button>
            </div>
            <div className="co-recap-row co-recap-items">
              <span className="co-recap-label">{c.coYourItems} ({itemCount})</span>
              <Lines ids={selected.map((l) => l.id)} />
            </div>
          </section>
        )}
      </div>

      {/* Summary: totals and the one button that moves on */}
      <aside className="co-side">
        <div className="co-summary">
          <h2>{c.coPay}</h2>
          <dl>
            <div><dt>{c.coItems} ({itemCount})</dt><dd>{money(itemsTotal, locale)}</dd></div>
            {quote?.groups.map((g, i) => (
              <div key={i}><dt>{g.mode === 'local' ? c.coDeliveryLine.replace('{c}', g.city) : c.coShippingLine.replace('{from}', g.from ?? '')}</dt><dd>{g.fee !== null ? money(g.fee, locale) : c.coToConfirm}</dd></div>
            ))}
            {quote?.payable_delivery != null && quote.total != null && Number(quote.total) > Number(quote.payable_delivery) && (
              <div className="co-discount"><dt>{c.coDeliveryOffer}</dt><dd>− {money(Number(quote.total) - Number(quote.payable_delivery), locale)}</dd></div>
            )}
          </dl>
          <div className="co-total"><span>{c.coTotal}</span><strong>{total !== null ? money(total, locale) : '—'}</strong></div>
          {step === 3 && <p className="co-methods">{c.coMethods}</p>}
          <button type="button" className="btn btn-accent co-pay" disabled={!next.ok} onClick={next.run}>
            {busy === 'pay' && <i className="spin" />} {next.label}
          </button>
          {step === 2 && quote?.status === 'quoted' && quote.needs_slot && !slotOk && <small className="co-why">{c.coPickSlot}</small>}
          {error && <p className="co-error" role="alert">{error}</p>}
          <a className="co-back" href={home + '/catalogue'}>← {c.continueShopping}</a>
        </div>
      </aside>

      {adding && (
        <AcModal title={c.coNewAddress} onClose={() => setAdding(false)} closeLabel={c.close}>
          <form className="co-form acm-form" onSubmit={saveAddress}>
            <label><span>{c.coCity}</span>
              <Combo strict required value={form.city} options={CITIES} onChange={(city) => setForm((f) => ({ ...f, city, district: f.city === city ? f.district : '' }))} />
            </label>
            <label><span>{c.district}</span>
              <Combo required value={form.district} options={districts} placeholder={c.coDistrictHint} onChange={(district) => setForm((f) => ({ ...f, district }))} />
            </label>
            <label className="wide"><span>{c.coLandmark}</span>
              <input required value={form.landmark} onChange={(e) => setForm({ ...form, landmark: e.target.value })} placeholder={c.coLandmarkHint} maxLength={300} />
            </label>
            <label><span>{c.coName}</span><input required value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} maxLength={150} /></label>
            <label><span>{c.coPhone}</span><input required type="tel" value={form.contact_phone} onChange={(e) => setForm({ ...form, contact_phone: e.target.value })} placeholder="+237 6XX XX XX XX" /></label>
            <button type="submit" className="btn btn-accent wide ac-wide" disabled={busy === 'address'}>{busy === 'address' && <i className="spin" />} {c.coSaveAddress}</button>
          </form>
        </AcModal>
      )}
    </div>
  );
}
