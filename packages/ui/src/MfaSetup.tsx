'use client';
import { useState, type FormEvent } from 'react';
import { ApiError, createApi, type Locale } from '@kora/api-client';
import { t } from './i18n';
export function MfaSetup({ locale }: { locale: Locale }) {
  const copy = t(locale),
    api = createApi('', locale);
  const [secret, setSecret] = useState(''),
    [codes, setCodes] = useState<string[]>([]),
    [error, setError] = useState(''),
    [busy, setBusy] = useState(false);
  async function enable(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError('');
    const data = Object.fromEntries(new FormData(e.currentTarget));
    try {
      await api.request('/api/v1/auth/user/confirm-password', {
        method: 'POST',
        body: JSON.stringify(data),
      });
      await api.request('/api/v1/auth/user/two-factor-authentication', { method: 'POST' });
      const result = await api.request<{ secretKey: string }>(
        '/api/v1/auth/user/two-factor-secret-key',
      );
      setSecret(result.secretKey);
    } catch (e) {
      setError(e instanceof ApiError ? e.message || copy.networkError : copy.error);
    } finally {
      setBusy(false);
    }
  }
  async function confirm(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError('');
    const data = Object.fromEntries(new FormData(e.currentTarget));
    try {
      await api.request('/api/v1/auth/user/confirmed-two-factor-authentication', {
        method: 'POST',
        body: JSON.stringify(data),
      });
      setCodes(await api.request<string[]>('/api/v1/auth/user/two-factor-recovery-codes'));
      setSecret('');
    } catch (e) {
      setError(e instanceof ApiError ? e.message || copy.networkError : copy.error);
    } finally {
      setBusy(false);
    }
  }
  async function reconnect() {
    await api.request('/api/v1/auth/logout', { method: 'POST' });
    window.location.reload();
  }
  return (
    <section className="auth-panel">
      <h1>{copy.mfaTitle}</h1>
      <p>{copy.mfaIntro}</p>
      {error && (
        <p role="alert" className="error">
          {error}
        </p>
      )}
      {codes.length ? (
        <div className="stack">
          <p className="success">{copy.mfaReady}</p>
          <h2>{copy.recoveryCodes}</h2>
          <pre>{codes.join('\n')}</pre>
          <button onClick={() => void reconnect()}>{copy.reconnect}</button>
        </div>
      ) : secret ? (
        <form className="stack" onSubmit={confirm}>
          <p>{copy.mfaSecret}</p>
          <code style={{ overflowWrap: 'anywhere' }}>{secret}</code>
          <label className="field">
            {copy.code}
            <input
              name="code"
              required
              inputMode="numeric"
              pattern="[0-9]{6}"
              autoComplete="one-time-code"
            />
          </label>
          <button className="primary" disabled={busy}>
            {copy.mfaConfirm}
          </button>
        </form>
      ) : (
        <form className="stack" onSubmit={enable}>
          <label className="field">
            {copy.password}
            <input name="password" type="password" autoComplete="current-password" required />
          </label>
          <button className="primary" disabled={busy}>
            {copy.enableMfa}
          </button>
          <button type="button" onClick={() => void reconnect()}>
            {copy.reconnect}
          </button>
        </form>
      )}
    </section>
  );
}
