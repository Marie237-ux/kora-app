import { useEffect, useState, type SyntheticEvent } from 'react';
import type { createApi } from '@kora/api-client';
import { money, type Locale } from '../lib/copy';
import { accountCopy } from '../lib/account-copy';
import { AcLoading } from './AcModal';

// "My product requests" (spec §12.1): what the member asked the KORA team to find, with its status, photos and the
// exchanges with the team; a new request is written here (photos from the gallery or camera, name, description,
// optional details, a recap to tick) and sent as draft → photos → submit. Nothing is paid at this stage.

type Api = ReturnType<typeof createApi>;
type Details = { name: string | null; description: string | null; brand: string | null; reference: string | null; variant: string | null; quantity: number | null; budget: string | null; reference_url: string | null };
type Request = {
  id: string; reference: string; version: number; state: string; details: Details; submitted_at: string | null; created_at: string;
  photos: { id: string; url: string }[]; offer: { id: string; name: string; price: { amount: number } } | null;
};
type Message = { id: string; author_kind: string; body: string; created_at: string };
const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });
const OPEN = ['submitted', 'reviewing', 'needs_details', 'sourcing', 'identified', 'preparing'];
const blank = { name: '', description: '', brand: '', variant: '', quantity: '1', budget: '', reference_url: '' };

export default function AccountRequests({ locale, api, onNote }: { locale: Locale; api: Api; onNote: (t: string) => void }) {
  const t = accountCopy(locale);
  const home = '/' + locale;
  const [list, setList] = useState<Request[] | null>(null);
  const [openId, setOpenId] = useState('');
  const [writing, setWriting] = useState(false);
  const [form, setForm] = useState(blank);
  const [files, setFiles] = useState<File[]>([]);
  const [reviewed, setReviewed] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [messages, setMessages] = useState<Message[] | null>(null);
  const [reply, setReply] = useState('');

  // The screen's "‹" (phone bar) first closes what is open here, then leaves the section.
  useEffect(() => {
    const onBack = (e: Event) => {
      if (writing) { setWriting(false); e.preventDefault(); } else if (openId) { setOpenId(''); e.preventDefault(); }
    };
    addEventListener('kora:ac-back', onBack);
    return () => removeEventListener('kora:ac-back', onBack);
  }, [writing, openId]);
  const load = () => api.request<{ data: Request[] }>('/api/v1/product-requests').then((r) => setList(r.data)).catch(() => setList([]));
  useEffect(() => { load(); }, []);
  const current = list?.find((r) => r.id === openId);
  useEffect(() => {
    if (!openId) return;
    setMessages(null);
    api.request<{ data: Message[] }>('/api/v1/product-requests/' + openId + '/messages').then((r) => setMessages(r.data)).catch(() => setMessages([]));
  }, [openId]);

  const date = (iso: string) => new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', { day: 'numeric', month: 'long' }).format(new Date(iso));
  const short = (label: string) => label.replace(/ \((facultatif|optional)\)$/, '');
  const title = (r: Request) => r.details.name || r.details.description?.slice(0, 60) || t.rqReference(r.reference);
  const tone = (s: string) => (s === 'published' ? 'ok' : s === 'needs_details' ? 'wait' : ['not_found', 'closed', 'withdrawn'].includes(s) ? 'off' : 'run');

  async function send(e: SyntheticEvent) {
    e.preventDefault();
    setError('');
    const ok = form.description.trim().length >= 10 || (files.length > 0 && (form.name.trim() || form.description.trim()));
    if (!ok) return setError(t.rqNeedDetails);
    setBusy('send');
    try {
      const details = {
        name: form.name.trim() || null, description: form.description.trim() || null, brand: form.brand.trim() || null, reference: null,
        variant: form.variant.trim() || null, quantity: Number(form.quantity) || 1, budget: form.budget.trim() || null, reference_url: form.reference_url.trim() || null,
      };
      const draft = (await api.request<{ data: Request }>('/api/v1/product-request-drafts', { method: 'POST', headers: key(), body: JSON.stringify({ locale, details }) })).data;
      let version = draft.version;
      for (const f of files) {
        const body = new FormData();
        body.append('file', f);
        const r = await api.request<{ data: { request_version: number } }>('/api/v1/product-requests/' + draft.id + '/photos', { method: 'POST', headers: key(), body });
        version = r.data.request_version ?? version;
      }
      await api.request('/api/v1/product-requests/' + draft.id + '/submit', { method: 'POST', headers: key(), body: JSON.stringify({ version, reviewed: true }) });
      setWriting(false);
      setForm(blank);
      setFiles([]);
      setReviewed(false);
      onNote(t.rqSent);
      load();
    } catch {
      setError(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function sendReply(r: Request) {
    if (!reply.trim()) return;
    setBusy('reply');
    try {
      await api.request('/api/v1/product-requests/' + r.id + '/messages', { method: 'POST', headers: key(), body: JSON.stringify({ version: r.version, body: reply.trim() }) });
      setReply('');
      const [m, fresh] = await Promise.all([api.request<{ data: Message[] }>('/api/v1/product-requests/' + r.id + '/messages'), api.request<{ data: Request }>('/api/v1/product-requests/' + r.id)]);
      setMessages(m.data);
      setList((l) => (l ?? []).map((x) => (x.id === r.id ? fresh.data : x)));
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function withdraw(r: Request) {
    setBusy('withdraw');
    try {
      const fresh = await api.request<{ data: Request }>('/api/v1/product-requests/' + r.id + '/withdraw', { method: 'POST', headers: key(), body: JSON.stringify({ version: r.version }) });
      setList((l) => (l ?? []).map((x) => (x.id === r.id ? { ...x, ...fresh.data } : x)));
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }

  if (list === null) return <AcLoading />;

  if (writing) {
    return (
      <form className="ac-rq-form" onSubmit={send}>
        <button type="button" className="ac-sub-back" onClick={() => setWriting(false)}>‹ {t.tabs.demandes}</button>
        <div className="ac-field">
          <span>{t.rqPhotos}</span>
          <div className="ac-photos">
            {files.map((f, i) => (
              <span key={i} className="ac-photo">
                <img src={URL.createObjectURL(f)} alt="" />
                <button type="button" onClick={() => setFiles(files.filter((_, j) => j !== i))} aria-label={t.removePin}>×</button>
              </span>
            ))}
            {files.length < 5 && (
              <label className="ac-photo ac-photo-add">
                <input type="file" accept="image/*" onChange={(e) => { const f = e.target.files?.[0]; if (f) setFiles([...files, f]); e.target.value = ''; }} />
                <span>+</span><small>{t.rqAddPhoto}</small>
              </label>
            )}
          </div>
          <small className="ac-hint">{t.rqPhotosHint}</small>
        </div>
        <label className="ac-field"><span>{t.rqName}</span><input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder={t.rqNameHint} maxLength={200} /></label>
        <label className="ac-field"><span>{t.rqDescription}</span><textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder={t.rqDescriptionHint} maxLength={5000} rows={4} /></label>
        <div className="ac-row2">
          <label className="ac-field"><span>{t.rqBrand}</span><input value={form.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} maxLength={150} /></label>
          <label className="ac-field"><span>{t.rqVariant}</span><input value={form.variant} onChange={(e) => setForm({ ...form, variant: e.target.value })} maxLength={500} /></label>
          <label className="ac-field"><span>{t.rqQuantity}</span><input type="number" min={1} value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} /></label>
          <label className="ac-field"><span>{t.rqBudget}</span><input inputMode="numeric" value={form.budget} onChange={(e) => setForm({ ...form, budget: e.target.value.replace(/[^\d]/g, '') })} /></label>
        </div>
        <label className="ac-field"><span>{t.rqLink}</span><input type="url" value={form.reference_url} onChange={(e) => setForm({ ...form, reference_url: e.target.value })} placeholder="https://" maxLength={2000} /></label>
        <label className="ac-agree"><input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} required /> <span>{t.rqReviewed}</span></label>
        {error && <p className="ac-error" role="alert">{error}</p>}
        <button type="submit" className="btn btn-accent ac-wide" disabled={busy === 'send' || !reviewed}>{busy === 'send' && <i className="spin" />} {t.rqSend}</button>
      </form>
    );
  }

  if (current) {
    const open = OPEN.includes(current.state);
    return (
      <div className="ac-rq">
        <button type="button" className="ac-sub-back" onClick={() => setOpenId('')}>‹ {t.tabs.demandes}</button>
        <header className="ac-rq-head">
          <span className={'ac-status ' + tone(current.state)}>{t.rqStates[current.state] ?? current.state}</span>
          <h3>{title(current)}</h3>
          <small>{t.rqReference(current.reference)}{current.submitted_at && ' · ' + date(current.submitted_at)}</small>
        </header>
        {current.photos.length > 0 && <div className="ac-photos">{current.photos.map((p) => <a key={p.id} className="ac-photo" href={p.url} target="_blank" rel="noopener"><img src={p.url} alt="" loading="lazy" /></a>)}</div>}
        {current.details.description && <p className="ac-rq-text">{current.details.description}</p>}
        <dl className="ac-rq-facts">
          {current.details.brand && <div><dt>{short(t.rqBrand)}</dt><dd>{current.details.brand}</dd></div>}
          {current.details.variant && <div><dt>{short(t.rqVariant)}</dt><dd>{current.details.variant}</dd></div>}
          {current.details.quantity && <div><dt>{t.rqQuantity}</dt><dd>{current.details.quantity}</dd></div>}
          {current.details.budget && <div><dt>{short(t.rqBudget)}</dt><dd>{money(current.details.budget, locale)}</dd></div>}
        </dl>
        {current.offer && (
          <a className="ac-rq-offer" href={home + '/products/' + current.offer.id}>
            <span><strong>{current.offer.name}</strong><small>{money(current.offer.price.amount, locale)}</small></span>
            <b>{t.rqSeeProduct} →</b>
          </a>
        )}
        <h4 className="ac-h4">{t.rqReply}</h4>
        <ul className="ac-thread">
          {messages === null ? <li className="ac-skel"><i className="sk-line" /></li> : messages.length === 0 ? <li className="ac-thread-none">{t.rqNoMessages}</li> : messages.map((m) => (
            <li key={m.id} className={m.author_kind === 'customer' ? 'me' : 'them'}>
              <small>{m.author_kind === 'customer' ? t.rqYou : t.rqTeam} · {date(m.created_at)}</small>
              <p>{m.body}</p>
            </li>
          ))}
        </ul>
        {open && (
          <form className="ac-composer" onSubmit={(e) => { e.preventDefault(); sendReply(current); }}>
            <textarea value={reply} onChange={(e) => setReply(e.target.value)} placeholder={t.rqReplyHint} rows={1} maxLength={5000} />
            <button type="submit" className="ac-send" disabled={busy === 'reply' || !reply.trim()} aria-label={t.rqSendMsg}>{busy === 'reply' ? <i className="spin" /> : <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" /></svg>}</button>
          </form>
        )}
        {open && <button type="button" className="ac-danger" onClick={() => withdraw(current)} disabled={busy === 'withdraw'}>{t.rqWithdraw}</button>}
      </div>
    );
  }

  return (
    <div className="ac-rqs">
      <div className="ac-lead-row">
        <p className="ac-intro">{t.requestsIntro}</p>
        <button type="button" className="btn btn-accent btn-small" onClick={() => setWriting(true)}>{t.newRequest}</button>
      </div>
      {list.length === 0 ? (
        <p className="ac-empty-text">{t.noRequests}</p>
      ) : (
        <ul className="ac-list">
          {list.map((r) => (
            <li key={r.id}>
              <button type="button" onClick={() => setOpenId(r.id)}>
                <span className="ac-thumb">{r.photos[0] ? <img src={r.photos[0].url} alt="" loading="lazy" /> : '?'}</span>
                <span className="ac-list-text">
                  <strong>{title(r)}</strong>
                  <small>{t.rqReference(r.reference)} · {date(r.submitted_at ?? r.created_at)}</small>
                </span>
                <span className={'ac-status ' + tone(r.state)}>{t.rqStates[r.state] ?? r.state}</span>
                <svg className="ac-list-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
