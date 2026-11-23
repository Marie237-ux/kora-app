import { useEffect, useRef, useState } from 'react';
import { type Locale } from '../lib/copy';
import { allSeen, type StoryGroup } from './StoryLayer';
import { CloseIcon } from './icons';

// The stories of the shops the visitor follows, as a vertical glass capsule floating top right on every page (like
// the FAB at the bottom): round photos with the KORA ring (grey once seen), the shop name in a tip beside it. Arrows
// at the top and bottom of the capsule scroll the shops; each one fades out at its end. A tap opens the stories
// (StoryLayer). A round button of its own, just above, folds and unfolds the capsule, like the assistant button of
// the FAB; the choice is remembered on this device. The button stays even when there is nothing to show (a visitor,
// or a member who follows nobody with a story): a tap then shows a small card, like the "added to cart" one, that
// sends to the shops or the inspirations to find accounts to follow.

const OPEN = 'kora.stories-open';
// A story: a segmented ring around a play mark.
const StoriesIcon = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round">
    <circle cx="12" cy="12" r="9" strokeDasharray="9.6 4.54" transform="rotate(-80 12 12)" />
    <path d="M10.2 8.9v6.2l5-3.1Z" fill="currentColor" strokeLinejoin="round" strokeWidth={1.2} />
  </svg>
);

type Empty = { title: string; text: string; shops: string; ideas: string };

export default function StoriesRail({ locale, title, up, down, close, empty }: { locale: Locale; title: string; up: string; down: string; close: string; empty: Empty }) {
  const [open, setOpen] = useState(true);
  const [groups, setGroups] = useState<StoryGroup[]>([]);
  const [hint, setHint] = useState(false);
  const list = useRef<HTMLUListElement>(null);
  const root = useRef<HTMLElement>(null);
  const [ends, setEnds] = useState({ top: true, bottom: true });
  const [seen, setSeen] = useState<Set<string>>(new Set());
  useEffect(() => {
    const on = (e: Event) => {
      setGroups((e as CustomEvent<StoryGroup[]>).detail);
      try { setSeen(new Set(JSON.parse(localStorage.getItem('kora.stories-seen') ?? '[]') as string[])); } catch {}
    };
    addEventListener('kora:stories', on);
    try { setOpen(localStorage.getItem(OPEN) !== 'false'); } catch {}
    // The stories may have arrived before this rail: read them from the page.
    const ready = (window as unknown as { koraStories?: StoryGroup[] }).koraStories;
    if (ready) on(new CustomEvent('kora:stories', { detail: ready }));
    return () => removeEventListener('kora:stories', on);
  }, []);
  // The card closes by itself, on Escape or on a tap elsewhere.
  useEffect(() => {
    if (!hint) return;
    const t = setTimeout(() => setHint(false), 7000);
    const key = (e: KeyboardEvent) => e.key === 'Escape' && setHint(false);
    const away = (e: PointerEvent) => { if (!root.current?.contains(e.target as Node)) setHint(false); };
    addEventListener('keydown', key);
    addEventListener('pointerdown', away);
    return () => { clearTimeout(t); removeEventListener('keydown', key); removeEventListener('pointerdown', away); };
  }, [hint]);
  // Which ends are reached, after every scroll and whenever the list changes.
  const measure = () => {
    const el = list.current;
    if (el) setEnds({ top: el.scrollTop <= 1, bottom: el.scrollTop + el.clientHeight >= el.scrollHeight - 1 });
  };
  useEffect(measure, [groups, open]);
  const has = groups.length > 0;
  const shown = open && has;
  const toggle = () => {
    if (!has) return setHint(!hint);
    setOpen(!open);
    try { localStorage.setItem(OPEN, String(!open)); } catch {}
  };
  // One arrow tap moves two shops.
  const move = (dir: number) => list.current?.scrollBy({ top: dir * 112, behavior: 'smooth' });
  return (
    <nav className="sr" data-open={shown} aria-label={title} ref={root}>
      <div className="sr-pod">
        <button type="button" className="sr-toggle" aria-expanded={has ? shown : hint} aria-label={shown ? close : title} onClick={toggle}>
          {shown ? <CloseIcon /> : <StoriesIcon />}
          {!shown && !hint && <span className="sr-tip" aria-hidden="true">{title}</span>}
        </button>
      </div>
      {hint && (
        <div className="sr-hint" role="status">
          <span className="sr-hint-ico" aria-hidden="true"><StoriesIcon /></span>
          <span className="sr-hint-t">
            <strong>{empty.title}</strong>
            <small>{empty.text}</small>
            <span className="sr-hint-go">
              <a href={'/' + locale + '/shops'}>{empty.shops}</a>
              <a href={'/' + locale + '/inspirations'}>{empty.ideas}</a>
            </span>
          </span>
          <button type="button" className="sr-hint-x" onClick={() => setHint(false)} aria-label={close}><CloseIcon /></button>
        </div>
      )}
      {has && (
        <div className="sr-body" inert={!shown}>
          <div className="sr-in sr-pod">
            <button type="button" className="sr-arrow" aria-label={up} disabled={ends.top} onClick={() => move(-1)}>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 15.5 7-7 7 7" /></svg>
            </button>
            <ul className="sr-list" ref={list} onScroll={measure}>
              {groups.map((g) => (
                <li key={g.shop.id}>
                  <button type="button" aria-label={g.shop.name} onClick={() => dispatchEvent(new CustomEvent('kora:story-open', { detail: g.shop.slug }))}>
                    <span className="story-ring sr-ring" data-story={allSeen(g, seen) ? 'seen' : 'new'}>{g.shop.avatar ? <img src={g.shop.avatar} alt="" /> : <b>{g.shop.name.charAt(0)}</b>}</span>
                    <span className="sr-tip" aria-hidden="true">{g.shop.name}</span>
                  </button>
                </li>
              ))}
            </ul>
            <button type="button" className="sr-arrow" aria-label={down} disabled={ends.bottom} onClick={() => move(1)}>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 8.5 7 7 7-7" /></svg>
            </button>
          </div>
        </div>
      )}
    </nav>
  );
}
