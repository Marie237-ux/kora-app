import { useEffect, useMemo, useState, type ReactNode, type SyntheticEvent } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { copy, type Locale } from '../lib/copy';
import { loadCart } from '../lib/cart';
import { ArrowLeft, CloseIcon } from './icons';

// Sign-in, registration, forgotten and new password, inside the account panel (AuthSheet) that slides over the
// page, after H&M's sign-in panel: the photo of the CMS page "compte" edge to edge on top (no frame, no text over it),
// a serif title like the home sections, soft filled fields, an orange KORA button, then "New to KORA?" and its button. Screens switch in place; the new password shows how solid it is. After signing in, the guest cart
// joins the account's cart; then the visitor goes to `next` (only a page of this site) or simply stays on the page,
// which is told through the "kora:signed-in" event.

export type AuthMode = 'login' | 'register' | 'forgot' | 'reset';
export const authPaths: Record<AuthMode, string> = { login: 'login', register: 'register', forgot: 'forgot-password', reset: 'reset-password' };

/** Only a page of this site in this language: never another host ("//…", "/\…") or a scheme. */
export function safeNext(raw: string | null, locale: Locale): string | null {
  if (!raw) return null;
  return new RegExp('^/' + locale + '(/[^/\\\\][^\\\\]*)?$').test(raw) && !raw.startsWith('//') ? raw : null;
}

const svg = (d: ReactNode) => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{d}</svg>
);
const icons = {
  eye: svg(<><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></>),
  eyeOff: svg(<><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /><path d="M4 4l16 16" /></>),
};

/** 0 to 4: length (12+), mixed case, a digit, a symbol. */
function strength(p: string) {
  if (p.length < 12) return p ? 0 : -1;
  return 1 + [/[a-z]/.test(p) && /[A-Z]/.test(p), /\d/.test(p), /[^A-Za-z0-9]/.test(p)].filter(Boolean).length;
}

export default function Auth({ locale, mode: initial, next, standalone, onClose, photo, google = false }: { locale: Locale; mode: AuthMode; next: string | null; standalone: boolean; onClose: () => void; photo: string | null; google?: boolean }) {
  const c = copy(locale);
  const api = useMemo(() => createApi('', locale), [locale]);
  const home = '/' + locale;
  const [mode, setMode] = useState<AuthMode>(initial);
  const [challenge, setChallenge] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [sent, setSent] = useState(false);
  const [show, setShow] = useState(false);
  const [password, setPassword] = useState('');
  // Back from Google: a member with two-step verification answers the code here; a refused round trip is told.
  useEffect(() => {
    const query = new URLSearchParams(location.search);
    if (query.get('challenge') === '1') setChallenge(true);
    const g = query.get('google');
    if (g && g !== '1') setError(g === 'email' ? c.auGoogleEmail : g === 'cancelled' ? c.auGoogleCancelled : c.auGoogleFailed);
  }, []);

  const leads: Record<AuthMode, string> = { login: c.auLoginText, register: c.auRegisterText, forgot: c.auForgotText, reset: c.auResetText };
  const lead = challenge ? c.auChallengeText : leads[mode];

  function href(m: AuthMode) {
    return home + '/' + authPaths[m] + (next ? '?next=' + encodeURIComponent(next) : '');
  }
  function switchTo(m: AuthMode) {
    setMode(m);
    setError('');
    setFields({});
    setSent(false);
    setChallenge(false);
    setPassword('');
    // On the sign-in page itself the address follows the screen; over another page it stays as it is.
    if (standalone) history.replaceState(null, '', href(m));
  }
  const go = (m: AuthMode) => (e: SyntheticEvent) => {
    e.preventDefault();
    switchTo(m);
  };

  // The guest cart joins the account's cart (same product: quantities added up), then go on. Returns true when the
  // page is being left (the button keeps turning).
  async function done() {
    try {
      const cart = await api.request<{ data: { version: number } }>('/api/v1/cart');
      await api.request('/api/v1/cart/merge', {
        method: 'POST',
        headers: { 'Idempotency-Key': crypto.randomUUID() },
        body: JSON.stringify({ version: cart.data.version }),
      });
    } catch {
      // Nothing to merge or already merged: the account keeps its own cart.
    }
    const target = next ?? (standalone ? home + '/account' : null);
    if (target && target !== location.pathname) {
      location.assign(target);
      return true;
    }
    loadCart(locale).then((cart) => window.dispatchEvent(new CustomEvent('kora:cart', { detail: cart }))).catch(() => {});
    window.dispatchEvent(new CustomEvent('kora:signed-in'));
    onClose();
    return false;
  }

  async function submit(e: SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    let leaving = false;
    setBusy(true);
    setError('');
    setFields({});
    const form = new FormData(e.currentTarget);
    const data: Record<string, unknown> = Object.fromEntries(form.entries());
    try {
      if (challenge) {
        await api.request('/api/v1/auth/two-factor-challenge', { method: 'POST', body: JSON.stringify(data) });
        leaving = await done();
        return;
      }
      if (mode === 'login') data.remember = form.has('remember');
      if (mode === 'reset') data.token = new URLSearchParams(location.search).get('token') ?? '';
      const result = await api.request<{ two_factor?: boolean }>('/api/v1/auth/' + authPaths[mode], {
        method: 'POST',
        body: JSON.stringify({ ...data, locale, theme: document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light' }),
      });
      if (result?.two_factor) return setChallenge(true);
      if (mode === 'forgot') return setSent(true);
      if (mode === 'reset') return switchTo('login');
      leaving = await done();
    } catch (err) {
      if (err instanceof ApiError) {
        setFields(err.fields ?? {});
        setError(Object.keys(err.fields ?? {}).length ? '' : err.status >= 500 ? c.auNetwork : err.message || c.auFailed);
      } else setError(c.auNetwork);
    } finally {
      if (!leaving) setBusy(false);
    }
  }

  // One field: label above, then the input; the password has its eye.
  const field = (o: { name: string; label: string; hint: string; type?: string; auto?: string; max?: number; min?: number; secret?: boolean; extra?: Record<string, unknown> }) => (
    <div className={'au-field' + (fields[o.name] ? ' bad' : '')}>
      <label htmlFor={'au-' + o.name}>{o.label}</label>
      <span className="au-input">
        <input
          id={'au-' + o.name}
          name={o.name}
          type={o.secret ? (show ? 'text' : 'password') : o.type ?? 'text'}
          autoComplete={o.auto}
          maxLength={o.max}
          minLength={o.min}
          required
          placeholder={o.hint}
          aria-invalid={!!fields[o.name]}
          onChange={o.name === 'password' ? (e) => setPassword(e.target.value) : undefined}
          {...o.extra}
        />
        {o.secret && (
          <button type="button" className="au-eye" onClick={() => setShow((v) => !v)} aria-label={show ? c.auHide : c.auShow} title={show ? c.auHide : c.auShow}>
            {show ? icons.eyeOff : icons.eye}
          </button>
        )}
      </span>
      {fields[o.name] && <small className="au-err">{fields[o.name].join(' ')}</small>}
    </div>
  );

  const score = strength(password);
  const submitLabel = challenge
    ? c.auVerify
    : { login: c.signIn, register: c.auSubmitRegister, forgot: c.auSubmitForgot, reset: c.auSubmitReset }[mode];
  const main = (mode === 'login' || mode === 'register') && !challenge;
  const back = challenge ? () => setChallenge(false) : () => switchTo('login');

  const titles: Record<AuthMode, string> = { login: c.signIn, register: c.createAccount, forgot: c.auForgotTitle, reset: c.auResetTitle };

  return (
    <>
      <div className={'au-top' + (photo ? ' has-photo' : '')}>
        {photo && <img src={photo} alt="" decoding="async" />}
        <button type="button" className="au-close" onClick={onClose} aria-label={c.close}><CloseIcon /></button>
      </div>

      <div className="au-body" key={mode + String(challenge)}>
        {!main && mode !== 'reset' && (
          <button type="button" className="au-back" onClick={back}><ArrowLeft /><span>{c.auBackLogin}</span></button>
        )}
        <h2 className="au-title">{challenge ? c.auChallengeTitle : titles[mode]}</h2>
        <p className="au-lead">{lead}</p>
        {next?.endsWith('/commande') && main && <p className="au-note">{c.auCheckoutNote}</p>}
        {sent ? (
          <p className="au-sent" role="status">{c.auResetSent}</p>
        ) : (
          <form className="au-form" onSubmit={submit}>
            {challenge ? (
              <>
                {field({ name: 'code', label: c.auCode, hint: '123456', auto: 'one-time-code', max: 6, extra: { inputMode: 'numeric', pattern: '[0-9]{6}', required: false } })}
                {field({ name: 'recovery_code', label: c.auRecovery, hint: '', auto: 'off', extra: { required: false } })}
              </>
            ) : (
              <>
                {mode === 'register' && field({ name: 'name', label: c.auName, hint: c.auNameHint, auto: 'name', max: 100 })}
                {field({ name: 'email', label: c.auEmail, hint: c.auEmailHint, type: 'email', auto: 'username', max: 254 })}
                {mode !== 'forgot' && field({ name: 'password', label: c.auPassword, hint: c.auPasswordInput, auto: mode === 'login' ? 'current-password' : 'new-password', min: mode === 'login' ? 1 : 12, secret: true })}
                {(mode === 'register' || mode === 'reset') && (
                  <div className="au-strength" data-score={score} aria-live="polite">
                    <span><i /><i /><i /><i /></span>
                    <small>{score < 0 ? c.auPasswordHint : c.auStrength[score]}</small>
                  </div>
                )}
                {(mode === 'register' || mode === 'reset') && field({ name: 'password_confirmation', label: c.auConfirm, hint: c.auPasswordInput, auto: 'new-password', min: 12, secret: true })}
                {mode === 'login' && (
                  <div className="au-row">
                    <label className="au-check"><input type="checkbox" name="remember" /> <span>{c.auRemember}</span></label>
                    <a href={href('forgot')} onClick={go('forgot')}>{c.auForgotLink}</a>
                  </div>
                )}
              </>
            )}
            {error && <p className="au-error" role="alert">{error}</p>}
            <button className="au-submit" disabled={busy}>
              {busy && <i className="spin" />}
              <span>{submitLabel}</span>
            </button>
          </form>
        )}
        {main && google && (
          <>
            <div className="au-or"><span>{c.auOr}</span></div>
            <a className="au-google" href={'/api/v1/auth/google/redirect?locale=' + locale + (next ? '&next=' + encodeURIComponent(next) : '')}>
              <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z" /><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" /><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2A11.9 11.9 0 0 1 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" /><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3a12 12 0 0 1-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z" /></svg>
              <span>{c.auGoogle}</span>
            </a>
          </>
        )}
        {main && mode === 'login' && (
          <p className="au-switch">{c.auNew} <a href={href('register')} onClick={go('register')}>{c.createAccount}</a></p>
        )}
        {main && mode === 'register' && (
          <p className="au-switch">{c.auHaveAccount} <a href={href('login')} onClick={go('login')}>{c.signIn}</a></p>
        )}
      </div>
    </>
  );
}
