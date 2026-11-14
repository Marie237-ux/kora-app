'use client';
import { useEffect, useState } from 'react';
import { HeroCarousel, t } from '@kora/ui';
import { createApi, type HeroScene, type Locale } from '@kora/api-client';
export function ScenePreview({ locale, id }: { locale: Locale; id: string }) {
  const copy = t(locale);
  const [scene, setScene] = useState<HeroScene | null>(null),
    [state, setState] = useState<'loading' | 'error' | 'ready'>('loading'),
    [reduced, setReduced] = useState(false);
  useEffect(() => {
    const q = new URLSearchParams(location.search);
    const theme = q.get('theme');
    if (theme === 'dark' || theme === 'light') {
      document.documentElement.dataset.theme = theme;
    }
    document.documentElement.lang = locale + '-CM';
    setReduced(q.get('reduced') === '1');
    let active = true;
    createApi('', locale)
      .request<{ data: HeroScene | null }>(
        '/api/v1/operations/content/scenes/' + encodeURIComponent(id) + '/preview',
      )
      .then((r) => {
        if (active) {
          setScene(r.data);
          setState('ready');
        }
      })
      .catch(() => {
        if (active) setState('error');
      });
    return () => {
      active = false;
    };
  }, [id, locale]);
  return (
    <main className="wrap">
      {state === 'loading' ? (
        <p role="status">{copy.loading}</p>
      ) : state === 'error' || !scene ? (
        <p className="error" role="alert">
          {copy.noPreview}
        </p>
      ) : (
        <HeroCarousel scenes={[scene]} locale={locale} preview reducedMotion={reduced} />
      )}
    </main>
  );
}
