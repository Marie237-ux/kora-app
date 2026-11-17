import { useEffect, useState, type SyntheticEvent } from 'react';
import type { createApi } from '@kora/api-client';
import { type Locale } from '../lib/copy';
import { accountCopy } from '../lib/account-copy';
import AcModal, { AcLoading } from './AcModal';

// Purchases & warranty (spec §25): every delivered item with its shop, the warranty archived with the order and the
// requests already open; "Report a problem" opens a window (what is wrong, quantity, explanation). Below, the
// member's after-sales requests with their status badge; one opens in a window with the exchanges and a reply box.

type Api = ReturnType<typeof createApi>;
type Line = { order_line_id: string; name: string; shop: string | null; warranty: string | null; delivered_quantity: number; open_cases: number };
type Purchase = { order_id: string; number: string; delivered_at: string | null; lines: Line[] };
type Case = { id: string; number: string; status: string; product: string; kind: string; quantity: number; description: string; contact: string | null; messages: { id?: string; side?: string; author?: string; body: string; created_at: string }[] };
const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });
const KINDS = ['missing', 'damaged', 'non_conforming', 'breakdown', 'other'];

export default function AccountPurchases({ locale, api, onNote }: { locale: Locale; api: Api; onNote: (t: string) => void }) {
  const t = accountCopy(locale);
  const [purchases, setPurchases] = useState<Purchase[] | null>(null);
  const [cases, setCases] = useState<Case[] | null>(null);
  const [reporting, setReporting] = useState<Line | null>(null);
  const [form, setForm] = useState({ kind: 'damaged', quantity: '1', description: '' });
  const [openCase, setOpenCase] = useState<Case | null>(null);
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState('');

  const loadCases = () => api.request<{ data: Case[] }>('/api/v1/account/after-sales').then((r) => setCases(r.data)).catch(() => setCases([]));
  useEffect(() => {
    api.request<{ data: Purchase[] }>('/api/v1/account/purchases').then((r) => setPurchases(r.data)).catch(() => setPurchases([]));
    loadCases();
  }, []);
  const date = (iso: string) => new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso));
  const tone = (s: string) => (s === 'resolved' ? 'ok' : s === 'proposed' ? 'wait' : ['rejected', 'closed'].includes(s) ? 'off' : 'run');

  async function report(e: SyntheticEvent) {
    e.preventDefault();
    if (!reporting) return;
    setBusy('report');
    try {
      await api.request('/api/v1/account/after-sales', { method: 'POST', headers: key(), body: JSON.stringify({ order_line_id: reporting.order_line_id, kind: form.kind, quantity: Number(form.quantity) || 1, identifiers: [], description: form.description }) });
      setReporting(null);
      setForm({ kind: 'damaged', quantity: '1', description: '' });
      onNote(t.savSent);
      loadCases();
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function sendReply(c: Case) {
    setBusy('reply');
    try {
      await api.request('/api/v1/account/after-sales/' + c.id + '/messages', { method: 'POST', headers: key(), body: JSON.stringify({ body: reply.trim() }) });
      const fresh = await api.request<{ data: Case }>('/api/v1/account/after-sales/' + c.id);
      setOpenCase(fresh.data);
      setReply('');
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }

  if (purchases === null || cases === null) return <AcLoading />;

  // Nothing delivered and no request yet: one calm invitation instead of empty blocks.
  if (purchases.length === 0 && cases !== null && cases.length === 0) {
    return (
      <div className="ac-bempty ac-nobuy">
        <span className="ac-nobuy-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M12 3.5 5 6v5.5c0 4.2 3 7.6 7 9 4-1.4 7-4.8 7-9V6l-7-2.5ZM9 12l2 2 4-4" /></svg>
        </span>
        <h3>{t.noPurchases}</h3>
        <p>{t.noPurchasesText}</p>
        <a className="btn btn-dark btn-small" href={'/' + locale + '/account#commandes'}>{t.seeOrders}</a>
      </div>
    );
  }

  return (
    <div className="ac-buys">
      <p className="ac-intro">{t.purchasesIntro}</p>
      {purchases.length === 0 ? null : (
        purchases.map((p) => (
          <section key={p.order_id} className="ac-buy">
            <header><strong>{p.number}</strong>{p.delivered_at && <small>{t.deliveredOn(date(p.delivered_at))}</small>}</header>
            <ul>
              {p.lines.map((l) => (
                <li key={l.order_line_id}>
                  <span className="ac-buy-text">
                    <strong>{l.name}</strong>
                    <small>{[l.shop, '× ' + l.delivered_quantity].filter(Boolean).join(' · ')}</small>
                    <small className="ac-warranty">{l.warranty ? t.warranty + ' : ' + l.warranty : t.noWarranty}</small>
                    {l.open_cases > 0 && <span className="ac-badge run">{t.savOpenCases(l.open_cases)}</span>}
                  </span>
                  <button type="button" className="btn btn-line btn-small" onClick={() => setReporting(l)}>{t.report}</button>
                </li>
              ))}
            </ul>
          </section>
        ))
      )}

      {cases && cases.length > 0 && <h3 className="ac-h3">{t.savCases}</h3>}
      {cases === null || cases.length === 0 ? null : (
        <ul className="ac-list">
          {cases.map((c) => (
            <li key={c.id}>
              <button type="button" onClick={() => setOpenCase(c)}>
                <span className="ac-list-text"><strong>{c.product}</strong><small>{c.number} · {t.savKinds[c.kind] ?? c.kind}</small></span>
                <span className={'ac-badge ' + tone(c.status)}>{t.savStatuses[c.status] ?? c.status}</span>
                <svg className="ac-list-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
              </button>
            </li>
          ))}
        </ul>
      )}

      {reporting && (
        <AcModal title={t.report} onClose={() => setReporting(null)}>
          <form className="acm-form" onSubmit={report}>
            <p className="acm-sub">{reporting.name}</p>
            <div className="ac-field">
              <span>{t.savWhat}</span>
              <div className="ac-choice-grid">
                {KINDS.map((k) => (
                  <label key={k} className={form.kind === k ? 'on' : undefined}>
                    <input type="radio" name="kind" checked={form.kind === k} onChange={() => setForm({ ...form, kind: k })} />
                    {t.savKinds[k]}
                  </label>
                ))}
              </div>
            </div>
            <label className="ac-field"><span>{t.savQuantity}</span><input type="number" min={1} max={reporting.delivered_quantity || 1} value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} /></label>
            <label className="ac-field"><span>{t.savDescription}</span><textarea rows={4} required maxLength={4000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder={t.savDescriptionHint} /></label>
            <button type="submit" className="btn btn-accent ac-wide" disabled={busy === 'report'}>{busy === 'report' && <i className="spin" />} {t.savSend}</button>
          </form>
        </AcModal>
      )}
      {openCase && (
        <AcModal title={openCase.number} onClose={() => setOpenCase(null)}>
          <div className="acm-form">
            <p className="acm-sub"><span className={'ac-badge ' + tone(openCase.status)}>{t.savStatuses[openCase.status] ?? openCase.status}</span> {openCase.product}</p>
            <p className="ac-rq-text">{openCase.description}</p>
            <ul className="ac-thread">
              {openCase.messages.map((m, i) => (
                <li key={m.id ?? i} className={m.side === 'customer' || m.author === 'customer' ? 'me' : 'them'}>
                  <small>{date(m.created_at)}</small>
                  <p>{m.body}</p>
                </li>
              ))}
            </ul>
            {!['resolved', 'closed'].includes(openCase.status) && (
              <form className="ac-composer" onSubmit={(e) => { e.preventDefault(); if (reply.trim()) sendReply(openCase); }}>
                <textarea rows={2} value={reply} onChange={(e) => setReply(e.target.value)} placeholder={t.messageHint} maxLength={4000} />
                <button type="submit" className="ac-send" disabled={busy === 'reply' || !reply.trim()} aria-label={t.send}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" /></svg></button>
              </form>
            )}
          </div>
        </AcModal>
      )}
    </div>
  );
}
