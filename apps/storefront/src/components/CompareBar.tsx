import { useEffect, useState } from 'react';
import { type Locale } from '../lib/copy';
import { pagesCopy } from '../lib/pages-copy';
import { COMPARE_MAX, comparePath, readCompare, writeCompare, type CompareItem } from '../lib/compare';

// Floating comparison bar, bottom centre on every page once a product is chosen (like the Darty / Boulanger
// comparators): the chosen products as thumbnails (× removes one), the free slots up to four, "Clear all" and
// "Compare (n)" — which waits for a second product. When a product from another aisle restarts the list, the bar
// says so for a few seconds. Hidden on the comparison page itself.

export default function CompareBar({ locale }: { locale: Locale }) {
  const p = pagesCopy(locale);
  const [list, setList] = useState<CompareItem[]>([]);
  const [reset, setReset] = useState(false);
  useEffect(() => {
    setList(readCompare());
    let timer = 0;
    const on = (e: Event) => {
      const d = (e as CustomEvent<{ list: CompareItem[]; reset: boolean }>).detail;
      setList(d.list);
      if (d.reset) {
        setReset(true);
        clearTimeout(timer);
        timer = window.setTimeout(() => setReset(false), 4500);
      }
    };
    // Another tab changed the list.
    const store = (e: StorageEvent) => e.key === 'kora.compare' && setList(readCompare());
    addEventListener('kora:compare', on);
    addEventListener('storage', store);
    return () => {
      removeEventListener('kora:compare', on);
      removeEventListener('storage', store);
      clearTimeout(timer);
    };
  }, []);
  if (!list.length) return null;
  const free = Array.from({ length: COMPARE_MAX - list.length });
  const ready = list.length >= 2;

  return (
    <aside className="cbar" aria-label={p.cmpBar}>
      {reset && <p className="cbar-note" role="status">{p.cmpReset}</p>}
      <div className="cbar-in">
        <div className="cbar-head">
          <strong>{p.cmpBar}</strong>
          <small>{ready ? p.cmpCount(list.length) + ' / ' + COMPARE_MAX : p.cmpBarHint}</small>
        </div>
        <ul className="cbar-items">
          {list.map((x) => (
            <li key={x.id}>
              <a href={'/' + locale + '/products/' + x.id} title={x.name}>
                {x.image ? <img src={x.image} alt={x.name ?? ''} /> : <span>{(x.name ?? '?').charAt(0)}</span>}
              </a>
              <button type="button" className="cbar-x" onClick={() => writeCompare(list.filter((y) => y.id !== x.id))} aria-label={p.cmpRemove + (x.name ? ' — ' + x.name : '')}>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" /></svg>
              </button>
            </li>
          ))}
          {free.map((_, i) => <li key={'free' + i} className="cbar-free" aria-label={p.cmpEmptySlot}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v10M7 12h10" /></svg></li>)}
        </ul>
        <div className="cbar-go">
          <button type="button" className="cbar-clear" onClick={() => writeCompare([])}>{p.cmpClear}</button>
          {ready ? (
            <a className="cbar-btn" href={comparePath(locale, list)}>{p.cmpNow} ({list.length})<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg></a>
          ) : (
            <span className="cbar-btn" aria-disabled="true">{p.cmpNow}</span>
          )}
        </div>
      </div>
    </aside>
  );
}
