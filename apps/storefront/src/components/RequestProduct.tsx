import { useEffect, useMemo, useState } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { money, type Locale } from '../lib/copy';
import { accountCopy } from '../lib/account-copy';

// "Request a product" (spec §12.1) in four steps under the home page's numbered chapters (01 Photos · 02 …), one step at a time on the
// left (photos → the product → details → check), and on the right "Your request", which fills in as the visitor
// types (photo, name, details) with the one button that moves on. A visitor can prepare everything: the draft and
// its photos are kept for their session; at "Send" the sign-in panel opens and, once signed in, the request goes
// out by itself (also after the Google round trip). Once sent: the request's journey (sent → under review → found).

type Draft = { id: string; version: number; reference: string };
type Step = 1 | 2 | 3 | 4;
const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });
const blank = { name: '', description: '', brand: '', variant: '', quantity: 1, budget: '', reference_url: '' };
const SAVED = 'kora.request-draft';
const short = (label: string) => label.replace(/ \((facultatif|optional)\)$/, '');

export default function RequestProduct({ locale }: { locale: Locale }) {
  const t = accountCopy(locale);
  const api = useMemo(() => createApi('', locale), [locale]);
  const home = '/' + locale;
  const [member, setMember] = useState<boolean | null>(null);
  const [step, setStep] = useState<Step>(1);
  const [form, setForm] = useState(blank);
  const [files, setFiles] = useState<File[]>([]);
  const [reviewed, setReviewed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [waiting, setWaiting] = useState<Draft | null>(null);
  const [sent, setSent] = useState<{ reference: string; photo: string | null; name: string } | null>(null);
  const previews = useMemo(() => files.map((f) => URL.createObjectURL(f)), [files]);

  useEffect(() => {
    api.request('/api/v1/me').then(() => setMember(true)).catch(() => setMember(false));
    try {
      const saved = JSON.parse(sessionStorage.getItem(SAVED) ?? 'null') as Draft | null;
      if (saved) setWaiting(saved);
    } catch {}
    const onSigned = () => setMember(true);
    addEventListener('kora:signed-in', onSigned);
    return () => removeEventListener('kora:signed-in', onSigned);
  }, []);
  useEffect(() => {
    if (member && waiting && !busy && !sent) submit(waiting);
  }, [member, waiting]);

  const enough = form.description.trim().length >= 10 || (files.length > 0 && (form.name.trim() !== '' || form.description.trim() !== ''));
  const go = (n: Step) => {
    setError('');
    setStep(n);
    document.querySelector('.rq-chapters')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  const next = () => {
    if (step === 2 && !enough) return setError(t.rqNeedDetails);
    if (step < 4) return go((step + 1) as Step);
    send();
  };

  async function submit(d: Draft) {
    setBusy(true);
    setError('');
    try {
      await api.request('/api/v1/product-requests/' + d.id + '/submit', { method: 'POST', headers: key(), body: JSON.stringify({ version: d.version, reviewed: true }) });
      try { sessionStorage.removeItem(SAVED); } catch {}
      setWaiting(null);
      setSent({ reference: d.reference, photo: previews[0] ?? null, name: form.name || form.description.slice(0, 60) });
      scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
      setError(err instanceof ApiError && err.status === 404 ? t.rqExpired : t.failed);
      try { sessionStorage.removeItem(SAVED); } catch {}
      setWaiting(null);
    } finally {
      setBusy(false);
    }
  }
  async function send() {
    if (!enough) return go(2);
    setBusy(true);
    setError('');
    try {
      const details = {
        name: form.name.trim() || null, description: form.description.trim() || null, brand: form.brand.trim() || null, reference: null,
        variant: form.variant.trim() || null, quantity: form.quantity, budget: form.budget.trim() || null, reference_url: form.reference_url.trim() || null,
      };
      const draft = (await api.request<{ data: Draft }>('/api/v1/product-request-drafts', { method: 'POST', headers: key(), body: JSON.stringify({ locale, details }) })).data;
      let version = draft.version;
      for (const f of files) {
        const body = new FormData();
        body.append('file', f);
        const r = await api.request<{ data: { request_version: number } }>('/api/v1/product-requests/' + draft.id + '/photos', { method: 'POST', headers: key(), body });
        version = r.data.request_version ?? version;
      }
      const ready = { id: draft.id, version, reference: draft.reference };
      if (member) {
        setBusy(false);
        return submit(ready);
      }
      try { sessionStorage.setItem(SAVED, JSON.stringify(ready)); } catch {}
      setWaiting(ready);
      window.dispatchEvent(new CustomEvent('kora:auth', { detail: { mode: 'login', next: null } }));
    } catch (err) {
      setError(err instanceof ApiError && err.message ? err.message : t.failed);
    } finally {
      setBusy(false);
    }
  }

  // Sent: the request's journey, like an order tracker, with its photo.
  if (sent) {
    return (
      <div className="rq-sent">
        <div className="rq-sent-card">
          <span className="rq-sent-img">{sent.photo ? <img src={sent.photo} alt="" /> : <i>{camera}</i>}</span>
          <div>
            <span className="ac-badge run">{t.rqStates.submitted}</span>
            <h2>{sent.name || t.rqDoneTitle}</h2>
            <p>{t.rqReference(sent.reference)}</p>
          </div>
        </div>
        <ol className="rq-track">
          {t.rqTrack.map((label, i) => <li key={label} data-on={i === 0}><span>{i === 0 ? '✓' : ''}</span>{label}</li>)}
        </ol>
        <p className="rq-sent-text">{t.rqDoneText.replace('{r}', sent.reference)}</p>
        <div className="rq-sent-actions">
          <a className="btn btn-accent btn-small" href={home + '/account#demandes'}>{t.rqFollow}</a>
          <a className="btn btn-line btn-small" href={home + '/catalogue'}>{t.seeCatalogue}</a>
        </div>
      </div>
    );
  }

  return (
    <div className="co rq-v2">
      <nav className="chapters rq-chapters" aria-label={t.rqPageTitle}>
        {t.rqSteps.map((s, i) => {
          const n = (i + 1) as Step;
          return (
            <button key={s} type="button" className="chapter" aria-current={step === n} data-done={n < step} disabled={n > step} onClick={() => go(n)}>
              <small>{String(n).padStart(2, '0')}</small>
              <strong>{s}</strong>
            </button>
          );
        })}
      </nav>

      <div className="co-main">
        {step === 1 && (
          <section className="co-panel">
            <h2>{t.rqStepPhotos}</h2>
            <p className="rq-lead">{t.rqStepPhotosText}</p>
            {files.length === 0 ? (
              <label className="rq-drop">
                <input type="file" accept="image/*" multiple onChange={(e) => { setFiles([...(e.target.files ?? [])].slice(0, 5)); e.target.value = ''; }} />
                <span className="rq-drop-ico">{camera}</span>
                <strong>{t.rqDropTitle}</strong>
                <small>{t.rqPhotosHint}</small>
              </label>
            ) : (
              <div className="rq-photos">
                {previews.map((src, i) => (
                  <span key={src} className={'rq-photo' + (i === 0 ? ' main' : '')}>
                    <img src={src} alt="" />
                    <button type="button" onClick={() => setFiles(files.filter((_, j) => j !== i))} aria-label={t.removePin}>×</button>
                  </span>
                ))}
                {files.length < 5 && (
                  <label className="rq-photo rq-photo-add">
                    <input type="file" accept="image/*" onChange={(e) => { const f = e.target.files?.[0]; if (f) setFiles([...files, f]); e.target.value = ''; }} />
                    <span>+</span>
                  </label>
                )}
              </div>
            )}
            {files.length === 0 && <button type="button" className="rq-skip" onClick={() => go(2)}>{t.rqNoPhoto} →</button>}
          </section>
        )}

        {step === 2 && (
          <section className="co-panel rq-fields">
            <h2>{t.rqStepProduct}</h2>
            <p className="rq-lead">{t.rqProductLead}</p>
            <label className="ac-field"><span>{t.rqName}</span><input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder={t.rqNameHint} maxLength={200} autoFocus /></label>
            <label className="ac-field"><span>{t.rqDescription}</span><textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder={t.rqDescriptionHint} maxLength={5000} rows={5} /></label>
          </section>
        )}

        {step === 3 && (
          <section className="co-panel rq-fields">
            <h2>{t.rqStepMore}</h2>
            <p className="rq-lead">{t.rqMoreLead}</p>
            <div className="ac-row2">
              <label className="ac-field"><span>{short(t.rqBrand)}</span><input value={form.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} maxLength={150} /></label>
              <label className="ac-field"><span>{short(t.rqVariant)}</span><input value={form.variant} onChange={(e) => setForm({ ...form, variant: e.target.value })} maxLength={500} /></label>
            </div>
            <div className="ac-row2">
              <div className="ac-field">
                <span>{t.rqQuantity}</span>
                <div className="rq-qty">
                  <button type="button" onClick={() => setForm({ ...form, quantity: Math.max(1, form.quantity - 1) })} aria-label="−">−</button>
                  <b>{form.quantity}</b>
                  <button type="button" onClick={() => setForm({ ...form, quantity: form.quantity + 1 })} aria-label="+">+</button>
                </div>
              </div>
              <label className="ac-field"><span>{short(t.rqBudget)}</span><input inputMode="numeric" value={form.budget} onChange={(e) => setForm({ ...form, budget: e.target.value.replace(/[^\d]/g, '') })} placeholder="FCFA" /></label>
            </div>
            <label className="ac-field"><span>{short(t.rqLink)}</span><input type="url" value={form.reference_url} onChange={(e) => setForm({ ...form, reference_url: e.target.value })} placeholder="https://" maxLength={2000} /></label>
          </section>
        )}

        {step === 4 && (
          <section className="co-panel co-recap">
            <h2>{t.rqStepCheck}</h2>
            <div className="co-recap-row">
              <span className="co-recap-label">{t.rqStepPhotos}</span>
              <div className="rq-recap-photos">{previews.length ? previews.map((src) => <img key={src} src={src} alt="" />) : <small>{t.rqNoPhotoShort}</small>}</div>
              <button type="button" onClick={() => go(1)}>{t.coEditShort}</button>
            </div>
            <div className="co-recap-row">
              <span className="co-recap-label">{t.rqStepProduct}</span>
              <div>{form.name && <strong>{form.name}</strong>}{form.description && <small>{form.description}</small>}</div>
              <button type="button" onClick={() => go(2)}>{t.coEditShort}</button>
            </div>
            <div className="co-recap-row">
              <span className="co-recap-label">{short(t.rqStepMore)}</span>
              <div>
                <small>{t.rqQuantity} : {form.quantity}</small>
                {form.brand && <small>{short(t.rqBrand)} : {form.brand}</small>}
                {form.variant && <small>{short(t.rqVariant)} : {form.variant}</small>}
                {form.budget && <small>{short(t.rqBudget)} : {money(form.budget, locale)}</small>}
              </div>
              <button type="button" onClick={() => go(3)}>{t.coEditShort}</button>
            </div>
            <label className="ac-agree rq-agree"><input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} /> <span>{t.rqReviewed}</span></label>
          </section>
        )}
      </div>

      {/* "Your request": fills in as the visitor goes, with the one button that moves on */}
      <aside className="co-side">
        <div className="co-summary rq-summary">
          <h2>{t.rqYours}</h2>
          <div className="rq-sum-img">{previews[0] ? <img src={previews[0]} alt="" /> : <span>{camera}<small>{t.rqNoPhotoShort}</small></span>}{previews.length > 1 && <em>+{previews.length - 1}</em>}</div>
          <strong className="rq-sum-name">{form.name || (form.description ? form.description.slice(0, 70) : t.rqUnnamed)}</strong>
          <dl>
            <div><dt>{t.rqQuantity}</dt><dd>{form.quantity}</dd></div>
            <div><dt>{short(t.rqBudget).replace(/ en FCFA| in FCFA/, '')}</dt><dd>{form.budget ? money(form.budget, locale) : '—'}</dd></div>
          </dl>
          <p className="co-methods">{t.rqFree}</p>
          <button type="button" className="btn btn-accent co-pay" onClick={next} disabled={busy || (step === 4 && !reviewed)}>
            {busy && <i className="spin" />} {step === 4 ? t.rqSend : t.rqContinue}
          </button>
          {waiting && !member && <small className="co-why">{t.rqSignInToSend}</small>}
          {member === false && !waiting && step === 4 && <small className="co-why">{t.rqGuestNote}</small>}
          {error && <p className="co-error" role="alert">{error}</p>}
        </div>
      </aside>
    </div>
  );
}

const camera = (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3v11H4zM12 16.5a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" /></svg>
);
