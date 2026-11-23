import { useEffect, useMemo, useState } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { copy, money, type Locale } from '../lib/copy';
import { pagesCopy } from '../lib/pages-copy';

// One order, also the page the payment provider sends the customer back to (spec §7.8, §17, §20): with a
// transaction id in the address the payment is checked with the provider first (the id is only a hint), then the
// order shows its real state — paid, waiting, failed, under review — its items, the delivery address and slot, the
// courier's public identity and the one-time handover code once out for delivery, and its documents.

type Line = { id: string; name: string; image: string | null; quantity: number; line_total: string; shop: { name: string; slug: string } };
type Mission = { reference: string; status: string; courier: { public_name: string; badge: string } | null; handover_code: string | null; handover_code_expires_at: string | null; delivered_at: string | null } | null;
type Order = {
  id: string; number: string; status: string; total: string; items_total: string; delivery_total: string; created_at?: string; paid_at: string | null; payable: boolean;
  lines: Line[]; payment: { attempt_id: string; status: string; payment_url: string | null } | null;
  delivery: { address?: { label: string; district: string; landmark: string; city?: string }; slot: { date: string; starts_at: string; ends_at: string } | null; mission?: Mission } | null;
};
type Doc = { id: string; kind: string; number: string | null };
const DOCS: Record<string, [string, string]> = { invoice: ['Facture', 'Invoice'], receipt: ['Reçu', 'Receipt'], delivery_note: ['Bon de livraison', 'Delivery note'], credit_note: ['Avoir', 'Credit note'], order_confirmation: ['Confirmation', 'Confirmation'] };

export default function OrderPage({ locale, id }: { locale: Locale; id: string }) {
  const c = copy(locale);
  const p = pagesCopy(locale);
  const api = useMemo(() => createApi('', locale), [locale]);
  const home = '/' + locale;
  const [order, setOrder] = useState<Order | null>(null);
  const [state, setState] = useState<'loading' | 'checking' | 'ready' | 'missing' | 'guest'>('loading');
  const [docs, setDocs] = useState<Doc[]>([]);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        let o = (await api.request<{ data: Order }>('/api/v1/orders/' + id)).data;
        const tx = new URLSearchParams(location.search).get('transaction_id');
        if (tx && o.payment?.attempt_id && o.status === 'awaiting_payment') {
          setState('checking');
          try {
            o = (await api.request<{ data: Order }>('/api/v1/orders/' + id + '/payments/' + o.payment.attempt_id + '/check', { method: 'POST', body: JSON.stringify({ transaction_id: tx }) })).data;
          } catch {}
          history.replaceState(null, '', location.pathname);
        }
        setOrder(o);
        setState('ready');
        api.request<{ data: Doc[] }>('/api/v1/orders/' + id + '/documents').then((r) => setDocs(r.data)).catch(() => {});
      } catch (err) {
        setState(err instanceof ApiError && err.status === 401 ? 'guest' : 'missing');
        if (err instanceof ApiError && err.status === 401) window.dispatchEvent(new CustomEvent('kora:auth', { detail: { mode: 'login', next: location.pathname + location.search } }));
      }
    })();
  }, []);

  async function pay() {
    if (!order) return;
    setBusy(true);
    try {
      const url = order.payment?.payment_url ?? (await api.request<{ data: { payment_url: string | null } }>('/api/v1/orders/' + order.id + '/payments', { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() } })).data.payment_url;
      if (url) return location.assign(url);
    } catch {}
    setBusy(false);
  }

  if (state === 'loading' || state === 'checking') {
    return <div className="oc-wait kcard"><i className="spin" /><p>{state === 'checking' ? p.ocChecking : c.loading}</p></div>;
  }
  if (state === 'guest') return <div className="oc-wait kcard"><p>{p.ocSignIn}</p></div>;
  if (!order) {
    return (
      <div className="oc-wait kcard">
        <h2>{p.ocNotFound}</h2>
        <p>{p.ocNotFoundText}</p>
        <a className="btn btn-dark btn-small" href={home + '/account#commandes'}>{p.ocSee}</a>
      </div>
    );
  }

  const failed = order.payment?.status === 'failed' || order.payment?.status === 'cancelled';
  const head = order.status === 'paid' || order.status === 'closed'
    ? { tone: 'ok', title: p.ocPaid, text: p.ocPaidText }
    : order.status === 'payment_review'
      ? { tone: 'wait', title: p.ocReview, text: p.ocReviewText }
      : order.status === 'awaiting_payment'
        ? failed ? { tone: 'off', title: p.ocFailed, text: p.ocFailedText } : { tone: 'wait', title: p.ocWaiting, text: p.ocWaitingText }
        : { tone: 'off', title: c.acStatus[order.status] ?? p.ocOther, text: '' };
  const mission = order.delivery?.mission ?? null;
  const slot = order.delivery?.slot;
  const day = (d: string) => new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(d + 'T12:00:00'));
  const steps = locale === 'fr' ? ['Commande', 'Paiement', 'Préparation', 'Livraison'] : ['Order', 'Payment', 'Preparation', 'Delivery'];
  const reached = order.status === 'closed' || mission?.status === 'delivered' ? 4 : mission?.status === 'out_for_delivery' ? 3.5 : order.status === 'paid' ? 2 : 1;

  return (
    <div className="oc">
      <section className={'oc-head kcard ' + head.tone}>
        <span className="oc-ico" aria-hidden="true">
          {head.tone === 'ok' ? <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM8 12.2l2.8 2.8L16 9.5" /></svg>
            : <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 7.5V12l3 2" /></svg>}
        </span>
        <div>
          <small>{c.acOrderNo.replace('{n}', order.number)}</small>
          <h2>{head.title}</h2>
          {head.text && <p>{head.text}</p>}
        </div>
        {order.payable && <button type="button" className="btn btn-accent" onClick={pay} disabled={busy}>{busy && <i className="spin" />} {p.ocRetry}</button>}
      </section>

      <ol className="rq-track oc-track">
        {steps.map((s, i) => <li key={s} data-on={i + 1 <= reached}><span>{i + 1 <= reached ? '✓' : ''}</span>{s}</li>)}
      </ol>

      <div className="oc-grid">
        <section className="kcard oc-card">
          <h3>{p.ocItems}</h3>
          <ul className="oc-lines">
            {order.lines.map((l) => (
              <li key={l.id}>
                <span className="oc-img">{l.image && <img src={l.image} alt="" />}</span>
                <span className="oc-name"><strong>{l.name}</strong><small>{l.shop.name} · × {l.quantity}</small></span>
                <b>{money(l.line_total, locale)}</b>
              </li>
            ))}
          </ul>
          <dl className="oc-sum">
            <div><dt>{c.coItems}</dt><dd>{money(order.items_total, locale)}</dd></div>
            <div><dt>{p.ocDelivery}</dt><dd>{money(order.delivery_total, locale)}</dd></div>
            <div className="oc-total"><dt>{p.ocTotal}</dt><dd>{money(order.total, locale)}</dd></div>
          </dl>
          {docs.length > 0 && (
            <div className="ac-docs">
              <span>{locale === 'fr' ? 'Documents' : 'Documents'}</span>
              {docs.map((d) => <a key={d.id} href={'/api/v1/orders/' + order.id + '/documents/' + d.id + '/pdf'} target="_blank" rel="noopener">{(DOCS[d.kind]?.[locale === 'fr' ? 0 : 1] ?? d.kind) + (d.number ? ' ' + d.number : '')} ↓</a>)}
            </div>
          )}
        </section>

        <aside className="oc-side">
          {order.delivery?.address && (
            <section className="kcard oc-card">
              <h3>{p.ocDelivery}</h3>
              <p className="oc-addr"><strong>{order.delivery.address.label}</strong><br />{order.delivery.address.district} — {order.delivery.address.landmark}</p>
              {slot && <p className="oc-slot">{day(slot.date)} · {slot.starts_at.slice(0, 5)} – {slot.ends_at.slice(0, 5)}</p>}
            </section>
          )}
          {mission && (
            <section className="kcard oc-card oc-mission">
              <h3>{locale === 'fr' ? 'Votre livreur' : 'Your courier'}</h3>
              {mission.courier ? <p><strong>{mission.courier.public_name}</strong><br /><small>{p.vlBadge} : {mission.courier.badge} · {p.vlMission} {mission.reference}</small></p> : <p className="oc-slot">{p.vlMission} {mission.reference}</p>}
              {mission.handover_code && (
                <div className="oc-code">
                  <small>{locale === 'fr' ? 'Code de remise à donner au livreur' : 'Handover code to give the courier'}</small>
                  <b>{mission.handover_code}</b>
                </div>
              )}
            </section>
          )}
          <a className="btn btn-line btn-small oc-more" href={home + '/account#commandes'}>{p.ocSee}</a>
          <a className="oc-continue" href={home + '/catalogue'}>{p.ocContinue} →</a>
        </aside>
      </div>
    </div>
  );
}
