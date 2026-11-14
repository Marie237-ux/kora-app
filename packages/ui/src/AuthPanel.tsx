'use client';
import { useState, type FormEvent } from 'react';
import { ApiError, createApi, type Locale, type User } from '@kora/api-client';
import { t } from './i18n';
export function AuthPanel({
  locale,
  mode = 'login',
  onAuth,
  returnTo,
}: {
  locale: Locale;
  mode?: 'login' | 'register' | 'forgot' | 'reset';
  onAuth?: (user: User) => void;
  returnTo?: string;
}) {
  const copy = t(locale),
    api = createApi('', locale);
  const [stage, setStage] = useState<'login' | 'challenge'>('login'),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(''),
    [fields, setFields] = useState<Record<string, string[]>>({}),
    [status, setStatus] = useState('');
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError('');
    setFields({});
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    if (mode === 'reset')
      data.token = new URLSearchParams(window.location.search).get('token') ?? '';
    try {
      if (stage === 'challenge')
        await api.request('/api/v1/auth/two-factor-challenge', {
          method: 'POST',
          body: JSON.stringify(data),
        });
      else {
        const endpoint = {
          login: 'login',
          register: 'register',
          forgot: 'forgot-password',
          reset: 'reset-password',
        }[mode];
        const result = await api.request<{ two_factor?: boolean }>('/api/v1/auth/' + endpoint, {
          method: 'POST',
          body: JSON.stringify({
            ...data,
            locale,
            theme: document.documentElement.dataset.theme ?? 'system',
          }),
        });
        if (result?.two_factor) {
          setStage('challenge');
          return;
        }
        if (mode === 'forgot') {
          setStatus(copy.resetSent);
          return;
        }
        if (mode === 'reset') {
          window.location.assign('/' + locale + '/login');
          return;
        }
      }
      const { data: user } = await api.me();
      if (onAuth) onAuth(user);
      else window.location.assign(returnTo ?? '/' + locale + '/account');
    } catch (e) {
      setError(e instanceof ApiError ? e.message || copy.networkError : copy.error);
      if (e instanceof ApiError) setFields(e.fields);
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="auth-panel">
      <p className="eyebrow">KORA</p>
      <h1>
        {stage === 'challenge'
          ? copy.challengeTitle
          : mode === 'register'
            ? copy.registerTitle
            : mode === 'forgot'
              ? copy.resetTitle
              : mode === 'reset'
                ? copy.newPassword
                : copy.loginTitle}
      </h1>
      <form className="stack" onSubmit={submit}>
        {stage === 'challenge' ? (
          <>
            <label className="field">
              {copy.code}
              <input
                name="code"
                inputMode="numeric"
                autoComplete="one-time-code"
                pattern="[0-9]{6}"
                maxLength={6}
              />
            </label>
            <label className="field">
              {copy.recovery}
              <input name="recovery_code" autoComplete="off" />
            </label>
          </>
        ) : (
          <>
            {mode === 'register' && (
              <label className="field">
                {copy.name}
                <input name="name" autoComplete="name" required maxLength={100} />
              </label>
            )}
            <label className="field">
              {copy.email}
              <input name="email" type="email" autoComplete="username" required maxLength={254} />
            </label>
            {mode !== 'forgot' && (
              <label className="field">
                {copy.password}
                <input
                  name="password"
                  type="password"
                  autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
                  required
                  minLength={mode === 'login' ? 1 : 12}
                />
                {mode !== 'login' && <span className="muted">{copy.passwordHint}</span>}
              </label>
            )}
            {(mode === 'register' || mode === 'reset') && (
              <label className="field">
                {copy.confirmPassword}
                <input
                  name="password_confirmation"
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={12}
                />
              </label>
            )}
          </>
        )}
        {error && (
          <div className="error" role="alert">
            {error}
            {Object.entries(fields).map(([key, values]) => (
              <p key={key}>{values.join(' ')}</p>
            ))}
          </div>
        )}
        {status && (
          <p role="status" className="success">
            {status}
          </p>
        )}
        <button className="primary" disabled={busy}>
          {busy
            ? copy.loading
            : stage === 'challenge'
              ? copy.verify
              : mode === 'register'
                ? copy.register
                : mode === 'forgot'
                  ? copy.sendReset
                  : mode === 'reset'
                    ? copy.resetPassword
                    : copy.signIn}
        </button>
      </form>
      {stage !== 'challenge' && (
        <div className="stack" style={{ marginTop: 24 }}>
          <a href={'/' + locale + '/' + (mode === 'register' ? 'login' : 'register')}>
            {mode === 'register' ? copy.haveAccount : copy.noAccount}
          </a>
          <a href={'/' + locale + '/forgot-password'}>{copy.forgot}</a>
        </div>
      )}
    </section>
  );
}
