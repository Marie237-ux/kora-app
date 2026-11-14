'use client';
import { useEffect, useRef, useState, type CSSProperties } from 'react';
import type { HeroScene, Locale } from '@kora/api-client';
import { formatXaf, motion } from '@kora/design-tokens';
import { t } from './i18n';

export function HeroCarousel({
  scenes,
  locale,
  preview = false,
  reducedMotion = false,
}: {
  scenes: HeroScene[];
  locale: Locale;
  preview?: boolean;
  reducedMotion?: boolean;
}) {
  const copy = t(locale);
  const root = useRef<HTMLElement>(null);
  const image = useRef<HTMLImageElement>(null);
  const gesture = useRef<{ x: number; y: number } | null>(null);
  const suppressClick = useRef(0);
  const [index, setIndex] = useState(0);
  const [playing, setPlaying] = useState(!preview);
  const [reduced, setReduced] = useState(true);
  const [visible, setVisible] = useState(true);
  const [tabActive, setTabActive] = useState(true);
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const [ready, setReady] = useState(false);
  const [announcement, setAnnouncement] = useState('');
  const activeIndex = index < scenes.length ? index : 0;
  const scene = scenes[activeIndex];
  const multiple = scenes.length > 1;

  useEffect(() => {
    const preference = matchMedia('(prefers-reduced-motion: reduce)');
    const update = () => {
      setReduced(preference.matches || reducedMotion);
      if (preference.matches || reducedMotion) setPlaying(false);
    };
    update();
    preference.addEventListener('change', update);
    return () => preference.removeEventListener('change', update);
  }, [reducedMotion]);
  useEffect(() => {
    const update = () => setTabActive(!document.hidden);
    update();
    document.addEventListener('visibilitychange', update);
    const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting), {
      threshold: 0.2,
    });
    if (root.current) observer.observe(root.current);
    return () => {
      document.removeEventListener('visibilitychange', update);
      observer.disconnect();
    };
  }, []);
  useEffect(() => {
    setReady(!scene?.media || Boolean(image.current?.complete));
  }, [scene?.id, scene?.media]);
  const canPlay =
    multiple &&
    playing &&
    !reduced &&
    !hovered &&
    !focused &&
    visible &&
    tabActive &&
    ready &&
    Boolean(scene?.autoplay);
  useEffect(() => {
    if (!canPlay) return;
    const timer = window.setTimeout(
      () => setIndex((i) => (i + 1) % scenes.length),
      (scene?.hold_ms ?? motion.reading) + motion.scene,
    );
    return () => clearTimeout(timer);
  }, [activeIndex, canPlay, scenes.length, scene?.hold_ms]);
  useEffect(() => {
    if (preview || scenes.length < 2) return;
    const next = scenes[(activeIndex + 1) % scenes.length];
    if (next.media) {
      const preload = new Image();
      preload.src = next.media;
    }
  }, [activeIndex, scenes, preview]);
  const select = (i: number) => {
    const next = (i + scenes.length) % scenes.length;
    setPlaying(false);
    setIndex(next);
    setAnnouncement(
      copy.slide + ' ' + (next + 1) + ' / ' + scenes.length + ' — ' + scenes[next].label,
    );
  };

  return (
    <section
      ref={root}
      className="hero"
      aria-roledescription="carousel"
      aria-label={copy.carousel}
      data-reduced={reduced}
      onPointerEnter={(e) => {
        if (e.pointerType === 'mouse') setHovered(true);
      }}
      onPointerLeave={(e) => {
        if (e.pointerType === 'mouse') setHovered(false);
      }}
      onFocusCapture={() => setFocused(true)}
      onBlurCapture={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setFocused(false);
      }}
      onPointerDownCapture={(e) => {
        if (!e.target || !(e.target as HTMLElement).closest('[data-play]')) setPlaying(false);
        if (e.pointerType === 'touch') gesture.current = { x: e.clientX, y: e.clientY };
      }}
      onPointerUp={(e) => {
        const start = gesture.current;
        gesture.current = null;
        if (
          start &&
          multiple &&
          Math.abs(e.clientX - start.x) > 45 &&
          Math.abs(e.clientX - start.x) > Math.abs(e.clientY - start.y) * 1.4
        ) {
          suppressClick.current = Date.now() + 400;
          select(activeIndex + (e.clientX < start.x ? 1 : -1));
        }
      }}
      onPointerCancel={() => {
        gesture.current = null;
      }}
      onClickCapture={(e) => {
        if (Date.now() < suppressClick.current) {
          e.preventDefault();
          e.stopPropagation();
        }
      }}
    >
      {scene ? (
        <article
          key={scene.id}
          className={scene.preset === 'signature' ? 'hero-grid scene-enter' : 'hero-grid'}
          aria-roledescription="slide"
          aria-label={copy.slide + ' ' + (activeIndex + 1) + ' / ' + scenes.length}
        >
          <div className="hero-copy">
            <p className="eyebrow">{scene.label}</p>
            <h1>{scene.title}</h1>
            <p className="muted">{scene.description}</p>
          </div>
          <div className="hero-media" data-decor={scene.decor}>
            {scene.media ? (
              <picture>
                {scene.mobile_media && (
                  <source media="(max-width:700px)" srcSet={scene.mobile_media} />
                )}
                <img
                  ref={image}
                  src={scene.media}
                  alt={scene.alt}
                  width="640"
                  height="520"
                  fetchPriority={activeIndex === 0 ? 'high' : 'auto'}
                  onLoad={() => setReady(true)}
                  onError={() => setReady(true)}
                />
              </picture>
            ) : (
              <span className="muted">{copy.noImage}</span>
            )}
          </div>
          <div className="hero-details">
            {scene.offer && (
              <div>
                <p>
                  {scene.offer.name}
                  <br />
                  <a href={'/' + locale + '/shops/' + scene.offer.shop.slug}>
                    {scene.offer.shop.name}
                  </a>
                </p>
                <p className="hero-price">{formatXaf(scene.offer.price.amount, locale)}</p>
                <p className="muted">{copy[scene.offer.availability]}</p>
              </div>
            )}
            {scene.shop && <p>{scene.shop.name}</p>}
            <a className="button primary" href={scene.href}>
              {scene.cta}
              <span aria-hidden="true">↗</span>
            </a>
          </div>
        </article>
      ) : (
        <div className="hero-grid">
          <div className="hero-copy">
            <p className="eyebrow">{copy.fallbackLabel}</p>
            <h1>{copy.fallbackTitle}</h1>
            <p className="muted">{copy.fallbackDescription}</p>
          </div>
          <div className="hero-media" data-decor="circle">
            <img src="/brand/logo.png" alt="KORA" width="640" height="320" fetchPriority="high" />
          </div>
          <div className="hero-details">
            <a className="button primary" href={'/' + locale + '/catalogue'}>
              {copy.discover}
              <span aria-hidden="true">↗</span>
            </a>
          </div>
        </div>
      )}
      {multiple && (
        <>
          <div className="hero-controls">
            <div className="hero-tabs" aria-label={copy.carousel}>
              {scenes.map((item, i) => (
                <button
                  type="button"
                  key={item.id}
                  aria-current={i === activeIndex}
                  onClick={() => select(i)}
                  aria-label={copy.slide + ' ' + (i + 1) + ' — ' + item.label}
                >
                  <span>{String(i + 1).padStart(2, '0')}</span>
                  {item.label}
                </button>
              ))}
            </div>
            <div className="hero-arrows">
              <button
                type="button"
                onClick={() => select(activeIndex - 1)}
                aria-label={copy.previous}
              >
                ←
              </button>
              <span aria-hidden="true">
                {activeIndex + 1}/{scenes.length}
              </span>
              <button type="button" onClick={() => select(activeIndex + 1)} aria-label={copy.next}>
                →
              </button>
              <button
                type="button"
                data-play="true"
                onClick={() => setPlaying(!playing)}
                aria-label={playing ? copy.pause : copy.play}
              >
                {playing ? 'Ⅱ' : '▷'}
              </button>
            </div>
          </div>
          <div
            key={scene?.id + String(canPlay)}
            className="progress"
            data-paused={!canPlay}
            aria-hidden="true"
            style={{ '--hold': (scene?.hold_ms ?? 8000) + motion.scene + 'ms' } as CSSProperties}
          >
            <span />
          </div>
        </>
      )}
      <div className="sr-only" role="status" aria-live="polite">
        {announcement}
      </div>
    </section>
  );
}
