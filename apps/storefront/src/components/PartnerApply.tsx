import { useMemo, useState } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { type Locale } from '../lib/copy';
import { pagesCopy } from '../lib/pages-copy';
import { CITIES } from '../lib/cities';
import Combo from './Combo';

// Partner application (spec §28) in three steps under numbered chapters like the home page (01 Your shop ·
// 02 Your products · 03 Your details): shop and activity, product types (the site's aisles), contact and consent.
// Sent to POST /partner-applications without an account; a hidden field stays empty for people (robots fill it).

type Aisle = { slug: string; name: string };
type Step = 1 | 2 | 3;

export default function PartnerApply({ locale, aisles }: { locale: Locale; aisles: Aisle[] }) {
  const p = pagesCopy(locale);
  const api = useMemo(() => createApi('', locale), [locale]);
  const [step, setStep] = useState<Step>(1);
  const [form, setForm] = useState({ business_name: '', activity: '', city: 'Douala', district: '', contact_name: '', phone: '', email: '', message: '', company_site: '' });
  const [types, setTypes] = useState<string[]>([]);
  const [other, setOther] = useState('');
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);

  const allTypes = [...types, ...(other.trim() ? [other.trim()] : [])];
  const ok: Record<Step, boolean> = {
    1: form.business_name.trim() !== '' && form.activity.trim() !== '' && form.city !== '',
    2: allTypes.length > 0,
    3: form.contact_name.trim() !== '' && form.phone.trim() !== '' && consent,
  };
  const toggle = (name: string) => setTypes((t) => (t.includes(name) ? t.filter((x) => x !== name) : t.length < 10 ? [...t, name] : t));
  const field = (name: keyof typeof form) => errors[name] && <small className="pa-err">{errors[name].join(' ')}</small>;

  async function send() {
    setBusy(true);
    setError('');
    setErrors({});
    try {
      await api.request('/api/v1/partner-applications', {
        method: 'POST',
        headers: { 'Idempotency-Key': crypto.randomUUID() },
        body: JSON.stringify({ ...form, email: form.email || null, district: form.district || null, message: form.message || null, product_types: allTypes, consent: true }),
      });
      setSent(true);
    } catch (err) {
      const f = err instanceof ApiError ? err.fields ?? {} : {};
      setErrors(f);
      setError(p.apFailed);
      if (f.business_name || f.activity || f.city || f.district) setStep(1);
      else if (f.product_types) setStep(2);
    } finally {
      setBusy(false);
    }
  }

  if (sent) {
    return (
      <div className="pa-done">
        <span className="ac-nobuy-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM8 12.2l2.8 2.8L16 9.5" /></svg></span>
        <h3>{p.apSent}</h3>
        <p>{p.apSentText}</p>
      </div>
    );
  }

  return (
    <div className="pa">
      <nav className="chapters pa-chapters" aria-label={p.applyTitle}>
        {p.apSteps.map((s, i) => {
          const n = (i + 1) as Step;
          return (
            <button key={s} type="button" className="chapter" aria-current={step === n} disabled={n > step && !ok[(n - 1) as Step]} onClick={() => setStep(n)}>
              <small>{String(n).padStart(2, '0')}</small>
              <strong>{s}</strong>
            </button>
          );
        })}
      </nav>
      <form className="pa-card kcard" onSubmit={(e) => { e.preventDefault(); if (step < 3) setStep((step + 1) as Step); else send(); }}>
        <input type="text" name="company_site" value={form.company_site} onChange={(e) => setForm({ ...form, company_site: e.target.value })} tabIndex={-1} autoComplete="off" className="pa-trap" aria-hidden="true" />
        {step === 1 && (
          <div className="pa-fields">
            <label className="ac-field"><span>{p.apBusiness}</span><input required maxLength={150} value={form.business_name} onChange={(e) => setForm({ ...form, business_name: e.target.value })} autoFocus />{field('business_name')}</label>
            <label className="ac-field"><span>{p.apActivity}</span><textarea required rows={4} maxLength={2000} value={form.activity} onChange={(e) => setForm({ ...form, activity: e.target.value })} placeholder={p.apActivityHint} />{field('activity')}</label>
            <div className="ac-row2">
              <label className="ac-field"><span>{p.apCity}</span><Combo strict required value={form.city} options={CITIES} onChange={(city) => setForm({ ...form, city })} /></label>
              <label className="ac-field"><span>{p.apDistrict}</span><input maxLength={120} value={form.district} onChange={(e) => setForm({ ...form, district: e.target.value })} /></label>
            </div>
          </div>
        )}
        {step === 2 && (
          <div className="pa-fields">
            <p className="pa-hint">{p.apTypesHint}</p>
            <div className="pa-types">
              {aisles.map((a) => (
                <button key={a.slug} type="button" aria-pressed={types.includes(a.name)} onClick={() => toggle(a.name)}>{a.name}</button>
              ))}
            </div>
            <label className="ac-field"><span>{p.apOther}</span><input maxLength={80} value={other} onChange={(e) => setOther(e.target.value)} /></label>
            {errors.product_types && <small className="pa-err">{errors.product_types.join(' ')}</small>}
          </div>
        )}
        {step === 3 && (
          <div className="pa-fields">
            <div className="ac-row2">
              <label className="ac-field"><span>{p.apContact}</span><input required maxLength={150} autoComplete="name" value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} autoFocus />{field('contact_name')}</label>
              <label className="ac-field"><span>{p.apPhone}</span><input required type="tel" autoComplete="tel" maxLength={40} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="+237 6XX XX XX XX" />{field('phone')}</label>
            </div>
            <label className="ac-field"><span>{p.apEmail}</span><input type="email" autoComplete="email" maxLength={254} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />{field('email')}</label>
            <label className="ac-field"><span>{p.apMessage}</span><textarea rows={3} maxLength={4000} value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} /></label>
            <label className="ac-agree"><input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} /> <span>{p.apConsent}</span></label>
          </div>
        )}
        {error && <p className="ac-error" role="alert">{error}</p>}
        <div className="pa-actions">
          {step > 1 && <button type="button" className="btn btn-line btn-small" onClick={() => setStep((step - 1) as Step)}>{p.apBack}</button>}
          <button type="submit" className="btn btn-accent" disabled={busy || !ok[step]}>{busy && <i className="spin" />} {step === 3 ? p.apSend : p.apContinue}</button>
        </div>
      </form>
    </div>
  );
}
