import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { copy, money, type Locale } from '../lib/copy';
import { ArrowLeft, ArrowRight, PauseIcon, PlayIcon } from './icons';

export type Scene = {
  id: string;
  kind: string;
  title: string;
  description: string;
  label: string;
  cta: string;
  alt: string;
  media: string | null;
  mobile_media: string | null;
  hold_ms: number;
  autoplay: boolean;
  href: string;
  offer: null | {
    id: string;
    name: string;
    price: { amount: number };
    availability: 'available' | 'unconfirmed' | 'unavailable';
    shop: { name: string; slug: string };
  };
  shop: null | { name: string; slug: string };
};

/** The last words of a title carry the accent colour, as on KORA's boards ("Tout le Cameroun, livré chez vous."). */
function Title({ text }: { text: string }) {
  const words = text.trim().split(/\s+/);
  const cut = Math.max(1, words.length - (words.length > 3 ? 2 : 1));
  return (
    <>
      {words.slice(0, cut).join(' ')} <em>{words.slice(cut).join(' ')}</em>
    </>
  );
}

/**
 * Home stage, after the reference: copy on the left, the product large on the right over soft grey bands,
 * price, shop and stock bottom right, chapters (01 Rentrée · 02 Maison · …) bottom left. Chapters switch
 * scenes — no row of mini product cards. Autoplay pauses on hover, focus, interaction, off-screen and
 * hidden tab, and is off with reduced motion. Price and availability come from the offer.
 */
export default function HeroStage({ scenes, locale }: { scenes: Scene[]; locale: Locale }) {
  const c = copy(locale);
  const root = useRef<HTMLElement>(null);
  const [index, setIndex] = useState(0);
  const [playing, setPlaying] = useState(true);
  const [reduced, setReduced] = useState(false);
  const [hover, setHover] = useState(false);
  const [focus, setFocus] = useState(false);
  const [visible, setVisible] = useState(true);
  const [tab, setTab] = useState(true);
  const [live, setLive] = useState('');
  const touch = useRef<{ x: number; y: number } | null>(null);
  const multiple = scenes.length > 1;
  const scene = scenes[index] ?? null;

  useEffect(() => {
    const m = matchMedia('(prefers-reduced-motion: reduce)');
    const apply = () => {
      setReduced(m.matches);
      if (m.matches) setPlaying(false);
    };
    apply();
    m.addEventListener('change', apply);
    const vis = () => setTab(!document.hidden);
    document.addEventListener('visibilitychange', vis);
    const io = new IntersectionObserver(([e]) => setVisible(e.isIntersecting), { threshold: 0.25 });
    if (root.current) io.observe(root.current);
    return () => {
      m.removeEventListener('change', apply);
      document.removeEventListener('visibilitychange', vis);
      io.disconnect();
    };
  }, []);
  const running = multiple && playing && !reduced && !hover && !focus && visible && tab && Boolean(scene?.autoplay);
  const hold = (scene?.hold_ms ?? 8000) + 1000;
  useEffect(() => {
    if (!running) return;
    const t = window.setTimeout(() => setIndex((i) => (i + 1) % scenes.length), hold);
    return () => window.clearTimeout(t);
  }, [running, index, hold, scenes.length]);
  useEffect(() => {
    const next = scenes[(index + 1) % scenes.length];
    if (next?.media) new Image().src = next.media;
  }, [index, scenes]);

  function go(i: number) {
    const n = (i + scenes.length) % scenes.length;
    setPlaying(false);
    setIndex(n);
    setLive(c.slide + ' ' + (n + 1) + ' / ' + scenes.length + ' — ' + scenes[n].label);
  }

  if (!scene) {
    return (
      <section className="hero hero-fallback" aria-label={c.carousel}>
        <div className="wrap">
        <div className="hero-stage scene-in">
          <div className="hero-head">
            <p className="eyebrow">{c.heroLabel}</p>
            <h1 className="hero-title"><Title text={c.heroTitle} /></h1>
            <p className="hero-text">{c.heroText}</p>
          </div>
          <div className="hero-actions">
            <a className="btn btn-accent" href={'/' + locale + '/catalogue'}>{c.discover} <ArrowRight /></a>
            <a className="btn btn-line" href={'/' + locale + '/shops'}>{c.shops}</a>
          </div>
          <div className="hero-visual">
            <div className="bands" aria-hidden="true"><span /><span /><span /><span /><span /></div>
            <img className="hero-mascot" src="/brand/logo-ink.png" alt="" width="380" height="166" />
          </div>
        </div>
        </div>
      </section>
    );
  }
  const offer = scene.offer;
  const chapters = multiple && (
    <div className="chapters" role="group" aria-label={c.carousel}>
      {scenes.map((s, i) => (
        <button
          type="button"
          key={s.id}
          className="chapter"
          aria-current={i === index}
          data-static={!running}
          onClick={() => go(i)}
          style={{ '--hold': hold + 'ms' } as CSSProperties}
        >
          <small>{String(i + 1).padStart(2, '0')}</small>
          <strong>{s.label}</strong>
          <span className="bar"><i key={s.id + index + String(running)} /></span>
        </button>
      ))}
    </div>
  );
  const nav = multiple && (
    <div className="hero-nav">
      <button type="button" className="icon-round" onClick={() => go(index - 1)} aria-label={c.previous}><ArrowLeft /></button>
      <span className="count" aria-hidden="true">{String(index + 1).padStart(2, '0')} / {String(scenes.length).padStart(2, '0')}</span>
      <button type="button" className="icon-round" onClick={() => go(index + 1)} aria-label={c.next}><ArrowRight /></button>
      {!reduced && (
        <button type="button" className="icon-round" onClick={() => setPlaying(!playing)} aria-label={playing ? c.pause : c.play} aria-pressed={!playing}>
          {playing ? <PauseIcon /> : <PlayIcon />}
        </button>
      )}
    </div>
  );
  return (
    <section
      ref={root}
      className="hero"
      aria-roledescription="carousel"
      aria-label={c.carousel}
      data-paused={!running}
      onPointerEnter={(e) => e.pointerType === 'mouse' && setHover(true)}
      onPointerLeave={(e) => e.pointerType === 'mouse' && setHover(false)}
      onFocusCapture={() => setFocus(true)}
      onBlurCapture={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setFocus(false)}
      onPointerDown={(e) => {
        if (e.pointerType === 'touch') touch.current = { x: e.clientX, y: e.clientY };
      }}
      onPointerUp={(e) => {
        const s = touch.current;
        touch.current = null;
        // Horizontal swipe only; a vertical scroll is left alone and a swipe never triggers a purchase.
        if (s && multiple && Math.abs(e.clientX - s.x) > 50 && Math.abs(e.clientX - s.x) > 1.5 * Math.abs(e.clientY - s.y)) {
          go(index + (e.clientX < s.x ? 1 : -1));
        }
      }}
    >
      <div className="wrap">
        <article
          key={scene.id}
          className="hero-stage scene-in"
          aria-roledescription="slide"
          aria-label={c.slide + ' ' + (index + 1) + ' / ' + scenes.length}
        >
          <div className="hero-head">
            <p className="eyebrow">{scene.label}</p>
            <h1 className="hero-title"><Title text={scene.title} /></h1>
            <p className="hero-text">{scene.description}</p>
          </div>
          <div className="hero-actions">
            <a className="btn btn-accent" href={scene.href}>{scene.cta} <ArrowRight /></a>
            <a className="btn btn-line" href={'/' + locale + '/catalogue'}>{c.seeSelection}</a>
          </div>
          <div className="hero-visual">
            <div className="bands" aria-hidden="true"><span /><span /><span /><span /><span /></div>
            <span className="hero-ground" aria-hidden="true" />
            {scene.media && (
              <picture>
                {scene.mobile_media && <source media="(max-width: 760px)" srcSet={scene.mobile_media} />}
                <img className="hero-product" src={scene.media} alt={scene.alt} width="720" height="620" fetchPriority={index === 0 ? 'high' : 'auto'} decoding="async" />
              </picture>
            )}
          </div>
          <div className="price-card">
            {offer && (
              <>
                <span className="price">{money(offer.price.amount, locale)}</span>
                <span className="sold-by">
                  {c.soldBy} <a href={'/' + locale + '/shops/' + offer.shop.slug}>{offer.shop.name}</a>
                </span>
                <span className={'badge' + (offer.availability === 'available' ? '' : offer.availability === 'unavailable' ? ' off' : ' warn')}>{c[offer.availability]}</span>
              </>
            )}
            {scene.shop && !offer && (
              <span className="sold-by"><a href={'/' + locale + '/shops/' + scene.shop.slug}>{scene.shop.name}</a></span>
            )}
            {nav}
          </div>
          <div>
            {chapters}
            {multiple && <div className="chapters-nav">{nav}</div>}
          </div>
        </article>
      </div>
      <p className="sr-only" role="status" aria-live="polite">{live}</p>
    </section>
  );
}
