'use client';
import { useEffect, useState } from 'react';
import {
  ApiError,
  createApi,
  type Locale,
  type User,
  type CatalogueEntity,
} from '@kora/api-client';
import { AuthPanel, MfaSetup, Preferences, t } from '@kora/ui';
import { SceneEditor } from './SceneEditor';
import { CatalogueEditor } from './CatalogueEditor';
import { MediaLibrary } from './MediaLibrary';
import { Agreements } from './Agreements';
import { HomeEditor } from './HomeEditor';
import { catalogueCopy } from './catalogue-copy';
const sections = [
  'suppliers',
  'shops',
  'categories',
  'products',
  'offers',
  'media',
  'agreements',
  'content',
  'home',
] as const;
type Section = (typeof sections)[number];
const permissions: Record<Section, string> = {
  suppliers: 'purchases.read',
  shops: 'catalogue.read',
  categories: 'catalogue.read',
  products: 'catalogue.read',
  offers: 'catalogue.read',
  media: 'media.read',
  agreements: 'pricing.read',
  content: 'content.read',
  home: 'content.read',
};
export function Operations({ locale }: { locale: Locale }) {
  const copy = t(locale),
    c = catalogueCopy(locale),
    api = createApi('', locale);
  const [user, setUser] = useState<User | null>(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(''),
    [view, setView] = useState<Section>('products'),
    [dirty, setDirty] = useState(false);
  useEffect(() => {
    document.documentElement.lang = locale + '-CM';
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
  useEffect(() => {
    const section = window.location.hash.slice(1);
    if (sections.includes(section as Section)) setView(section as Section);
  }, []);
  const allowed = sections.filter((section) => user?.permissions.includes(permissions[section]));
  const section = allowed.includes(view) ? view : allowed[0];
  function navigate(next: Section) {
    if (dirty && !window.confirm(c.discard)) return;
    setDirty(false);
    setView(next);
    window.history.replaceState(null, '', '#' + next);
  }
  async function logout() {
    if (dirty && !window.confirm(c.discard)) return;
    try {
      await api.request('/api/v1/auth/logout', { method: 'POST' });
      setUser(null);
      setDirty(false);
    } catch {
      setError(copy.networkError);
    }
  }
  return (
    <div className="operations-shell">
      <a className="skip-link" href="#operations-main">
        {locale === 'fr' ? 'Aller au contenu' : 'Skip to content'}
      </a>
      <aside className="operations-nav">
        <a className="brand" href={'/' + locale}>
          <img src="/brand/logo.png" alt="KORA" width="156" height="78" />
        </a>
        <p className="eyebrow">{copy.ops}</p>
        {user?.mfa_enabled && user.mfa_verified && (
          <nav className="operations-links" aria-label={copy.ops}>
            {allowed.map((item) => (
              <a
                key={item}
                href={'#' + item}
                aria-current={item === section ? 'page' : undefined}
                onClick={(e) => {
                  e.preventDefault();
                  navigate(item);
                }}
              >
                {c[item]}
              </a>
            ))}
          </nav>
        )}
        <Preferences locale={locale} />
        <a
          className="button-link storefront-link"
          href={(process.env.NEXT_PUBLIC_STOREFRONT_URL ?? 'http://localhost:4321') + '/' + locale}
        >
          {copy.catalogue}
        </a>
        {user && (
          <button onClick={() => void logout()} style={{ marginTop: 24 }}>
            {copy.logout}
          </button>
        )}
      </aside>
      <main id="operations-main" className="operations-main">
        <p className="notice">{copy.development}</p>
        {error && (
          <p className="error" role="alert">
            {error}
          </p>
        )}
        {loading ? (
          <p role="status">{copy.loading}</p>
        ) : !user ? (
          <AuthPanel locale={locale} onAuth={setUser} />
        ) : !allowed.length ? (
          <p className="error" role="alert">
            {copy.permissionDenied}
          </p>
        ) : !user.mfa_enabled || !user.mfa_verified ? (
          <MfaSetup locale={locale} />
        ) : section === 'content' ? (
          <SceneEditor locale={locale} user={user} onDirtyChange={setDirty} />
        ) : section === 'home' ? (
          <HomeEditor locale={locale} user={user} onDirtyChange={setDirty} />
        ) : section === 'media' ? (
          <MediaLibrary locale={locale} user={user} />
        ) : section === 'agreements' ? (
          <Agreements locale={locale} user={user} />
        ) : section ? (
          <CatalogueEditor
            key={section}
            locale={locale}
            user={user}
            entity={section as CatalogueEntity}
            onDirtyChange={setDirty}
          />
        ) : null}
      </main>
    </div>
  );
}
