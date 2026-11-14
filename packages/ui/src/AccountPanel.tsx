'use client';
import { useEffect, useState, type FormEvent } from 'react';
import { ApiError, createApi, type Locale, type Theme, type User } from '@kora/api-client';
import { t } from './i18n';
export function AccountPanel({ locale }: { locale: Locale }) {
  const copy = t(locale),
    api = createApi('', locale);
  const [user, setUser] = useState<User | null>(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(''),
    [status, setStatus] = useState('');
  useEffect(() => {
    let active = true;
    api
      .me()
      .then((r) => {
        if (active) setUser(r.data);
      })
      .catch((e) => {
        if (active && (!(e instanceof ApiError) || e.status !== 401)) setError(copy.networkError);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [locale]);
  async function preferences(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = new FormData(event.currentTarget);
    const theme = form.get('theme') as Theme;
    const language = form.get('locale') as Locale;
    try {
      const result = await api.preferences({ locale: language, theme });
      setUser(result.data);
      localStorage.setItem('kora.theme', theme);
      localStorage.setItem('kora.locale', language);
      document.documentElement.dataset.theme = theme;
      setStatus(copy.preferencesSaved);
      if (language !== locale) window.location.assign('/' + language + '/account');
    } catch (e) {
      setError(e instanceof ApiError ? e.message || copy.networkError : copy.error);
    }
  }
  async function logout() {
    try {
      await api.request('/api/v1/auth/logout', { method: 'POST' });
      setUser(null);
    } catch (e) {
      setError(copy.networkError);
    }
  }
  if (loading) return <p role="status">{copy.loading}</p>;
  if (!user)
    return (
      <section className="auth-panel">
        <h1>{copy.accountTitle}</h1>
        {error && (
          <p className="error" role="alert">
            {error}
          </p>
        )}
        <a className="button primary" href={'/' + locale + '/login'}>
          {copy.signIn}
        </a>
      </section>
    );
  return (
    <section className="auth-panel">
      <p className="eyebrow">{copy.accountTitle}</p>
      <h1>{user.name}</h1>
      <p>{user.email}</p>
      <form className="stack" onSubmit={preferences}>
        <h2>{copy.preferences}</h2>
        <label className="field">
          {copy.language}
          <select name="locale" defaultValue={user.locale}>
            <option value="fr">Français</option>
            <option value="en">English</option>
          </select>
        </label>
        <label className="field">
          {copy.theme}
          <select name="theme" defaultValue={user.theme}>
            <option value="system">{copy.system}</option>
            <option value="light">{copy.light}</option>
            <option value="dark">{copy.dark}</option>
          </select>
        </label>
        {error && (
          <p role="alert" className="error">
            {error}
          </p>
        )}
        {status && (
          <p role="status" className="success">
            {status}
          </p>
        )}
        <button className="primary">{copy.save}</button>
      </form>
      <button onClick={() => void logout()} style={{ marginTop: 24 }}>
        {copy.logout}
      </button>
    </section>
  );
}
