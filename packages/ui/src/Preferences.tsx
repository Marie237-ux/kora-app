'use client';
import { useEffect, useState } from 'react';
import { createApi, type Locale, type Theme } from '@kora/api-client';
import { t } from './i18n';

export function Preferences({ locale }: { locale: Locale }) {
  const copy = t(locale);
  const [theme, setTheme] = useState<Theme>('system');
  useEffect(() => {
    const value = document.documentElement.dataset.theme;
    if (['light', 'dark', 'system'].includes(value ?? '')) setTheme(value as Theme);
  }, []);
  function apply(value: Theme) {
    setTheme(value);
    document.documentElement.dataset.theme = value;
    document.documentElement.style.colorScheme = value === 'system' ? 'light dark' : value;
    try {
      localStorage.setItem('kora.theme', value);
    } catch {}
    void createApi('', locale)
      .preferences({ locale, theme: value })
      .catch(() => {});
  }
  async function language(value: Locale) {
    try {
      localStorage.setItem('kora.locale', value);
    } catch {}
    document.cookie =
      'kora_locale=' +
      value +
      '; Path=/; SameSite=Lax; Max-Age=31536000' +
      (location.protocol === 'https:' ? '; Secure' : '');
    try {
      await createApi('', locale).preferences({ locale: value, theme });
    } catch {}
    const url = new URL(window.location.href);
    url.pathname = url.pathname.replace(/^\/(fr|en)(?=\/|$)/, '/' + value);
    window.location.assign(url.pathname + url.search + url.hash);
  }
  return (
    <div className="preferences">
      <label className="sr-only" htmlFor="kora-locale">
        {copy.language}
      </label>
      <select
        id="kora-locale"
        value={locale}
        onChange={(e) => void language(e.target.value as Locale)}
      >
        <option value="fr">Français</option>
        <option value="en">English</option>
      </select>
      <label className="sr-only" htmlFor="kora-theme">
        {copy.theme}
      </label>
      <select id="kora-theme" value={theme} onChange={(e) => apply(e.target.value as Theme)}>
        <option value="system">{copy.system}</option>
        <option value="light">{copy.light}</option>
        <option value="dark">{copy.dark}</option>
      </select>
    </div>
  );
}
