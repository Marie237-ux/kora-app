import { useEffect, useState } from 'react';
import { type Locale } from '../lib/copy';
import { pagesCopy } from '../lib/pages-copy';
import { readCompare, toggleCompare, type CompareItem } from '../lib/compare';

// "Compare" on a product page (spec §12): adds the product to the comparison list kept in this browser (up to four
// products of one aisle; a product from another aisle starts a new comparison). The floating comparison bar shows
// the list and opens the comparison page.

export default function CompareButton({ locale, id, category, name, image, price }: { locale: Locale; id: string; category: string; name: string; image: string | null; price: number }) {
  const p = pagesCopy(locale);
  const [list, setList] = useState<CompareItem[]>([]);
  useEffect(() => {
    setList(readCompare());
    const on = (e: Event) => setList((e as CustomEvent<{ list: CompareItem[] }>).detail.list);
    addEventListener('kora:compare', on);
    return () => removeEventListener('kora:compare', on);
  }, []);
  const on = list.some((x) => x.id === id);

  return (
    <span className="cmp-btn">
      <button type="button" aria-pressed={on} onClick={() => toggleCompare({ id, category, name, image, price })}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{on ? <path d="m5 12.5 4.5 4.5L19 7.5" /> : <path d="M8 4v16M16 4v16M4 8h4M16 16h4" />}</svg>
        {on ? p.cmpAdded : p.cmpAdd}
      </button>
    </span>
  );
}
