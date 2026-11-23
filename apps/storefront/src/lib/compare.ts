// The comparison list (spec §12), kept in this browser: up to four products of one aisle. The product page button,
// the floating comparison bar and the comparison page all read and write it here; every change is announced with a
// "kora:compare" event ({ list, reset }), reset = a product from another aisle started a new comparison.

export const COMPARE_MAX = 4;
const KEY = 'kora.compare';

export type CompareItem = { id: string; category: string; name?: string; image?: string | null; price?: number };

export function readCompare(): CompareItem[] {
  try {
    const list = JSON.parse(localStorage.getItem(KEY) ?? '[]');
    return Array.isArray(list) ? (list as CompareItem[]).slice(-COMPARE_MAX) : [];
  } catch {
    return [];
  }
}

export function writeCompare(list: CompareItem[], reset = false) {
  try {
    localStorage.setItem(KEY, JSON.stringify(list));
  } catch {}
  dispatchEvent(new CustomEvent('kora:compare', { detail: { list, reset } }));
}

/** Add a product, or remove it when it is already there. A product from another aisle starts a new list. */
export function toggleCompare(item: CompareItem): CompareItem[] {
  let list = readCompare();
  let reset = false;
  if (list.some((x) => x.id === item.id)) list = list.filter((x) => x.id !== item.id);
  else {
    if (list.some((x) => x.category !== item.category)) {
      reset = true;
      list = [];
    }
    list = [...list, item].slice(-COMPARE_MAX);
  }
  writeCompare(list, reset);
  return list;
}

export const comparePath = (locale: string, list: { id: string }[]) => '/' + locale + '/compare?offers=' + list.map((x) => x.id).join(',');
