import { useId, useRef, useState, type KeyboardEvent } from 'react';

// A field you type in, with its own list underneath (same width, rounded, filtered as you type, orange check on the
// chosen entry): replaces the pill select and the browser's datalist arrow in the address forms. `strict` keeps the
// value to one of the options (city); otherwise the options are only suggestions (district).

const plain = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

export default function Combo({ value, onChange, options, placeholder, required, strict = false }: {
  value: string;
  onChange: (value: string) => void;
  options: string[];
  placeholder?: string;
  required?: boolean;
  strict?: boolean;
}) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  // While the field shows a chosen entry, the whole list stays available; typing filters it.
  const [typed, setTyped] = useState(false);
  const last = useRef(value);
  const list = typed ? options.filter((o) => plain(o).includes(plain(value))) : options;

  function choose(o: string) {
    last.current = o;
    onChange(o);
    setTyped(false);
    setOpen(false);
    setActive(-1);
  }
  function leave() {
    setOpen(false);
    setTyped(false);
    if (!strict) return;
    const match = options.find((o) => plain(o) === plain(value));
    onChange(match ?? last.current);
  }
  function keys(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      setOpen(true);
      setActive((a) => (list.length ? (a + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length : -1));
    } else if (e.key === 'Enter' && open && active >= 0 && list[active]) {
      e.preventDefault();
      choose(list[active]);
    } else if (e.key === 'Escape') setOpen(false);
  }

  return (
    <span className="combo" data-open={open && list.length > 0}>
      <input
        role="combobox"
        aria-expanded={open && list.length > 0}
        aria-controls={id}
        aria-autocomplete="list"
        autoComplete="off"
        value={value}
        placeholder={placeholder}
        required={required}
        onChange={(e) => { onChange(e.target.value); setTyped(true); setOpen(true); setActive(-1); }}
        onFocus={(e) => { setOpen(true); e.target.select(); }}
        onClick={() => setOpen(true)}
        onBlur={leave}
        onKeyDown={keys}
      />
      <svg className="combo-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      {open && list.length > 0 && (
        <ul id={id} role="listbox" className="combo-list">
          {list.map((o, i) => (
            <li key={o} role="option" aria-selected={o === value} data-active={i === active} onMouseDown={(e) => { e.preventDefault(); choose(o); }}>
              <span>{o}</span>
              {o === value && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>}
            </li>
          ))}
        </ul>
      )}
    </span>
  );
}
