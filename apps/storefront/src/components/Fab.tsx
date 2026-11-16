import { useEffect, useId, useRef, useState, type SyntheticEvent } from 'react';
import { createApi } from '@kora/api-client';
import { copy, money, type Locale } from '../lib/copy';
import { CloseIcon } from './icons';

// Floating action capsule, bottom-right on every page: back to top (once the visitor has scrolled down), WhatsApp
// (only when a number is set in the back office) and the KORA choice assistant (spec §12). The assistant asks the
// aisle, a budget and a few of the aisle's own characteristics, then shows published offers from the API with the
// reasons the API gives; it never invents a product, a price or an opinion.

type Aisle = { slug: string; name: string };
type Label = { fr: string; en: string };
type Question = { key: string; type: 'budget' | 'choice' | 'boolean' | 'number'; label: Label; unit: string | null; options?: { value: string; label: Label }[] | null };
type Result = {
  offer: { id: string; name: string; image: string | null; price: { amount: number }; shop: { name: string } };
  met: number;
  criteria: number;
  explanation: string;
};
type Chip = { label: string; run: () => void };
type Msg = { from: 'bot' | 'me'; text: string; chips?: Chip[]; results?: Result[]; links?: { href: string; label: string }[] };

const MAX_QUESTIONS = 3;
const BUDGETS = [10000, 25000, 50000, 100000, 250000];

const WhatsAppIcon = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.7.63.71.22 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.18-1.41-.08-.13-.28-.2-.57-.35m-5.42 7.4h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89a11.82 11.82 0 0 0-3.48-8.41Z" /></svg>
);
// The KORA cat from the logo, drawn as an icon: pointed ears, a wink, a round eye, a small nose and two paws.
// Details are cut out with a mask so they show the button colour behind.
function CatIcon() {
  const id = 'kcat' + useId().replace(/:/g, '');
  return (
    <svg viewBox="0 0 32 32" aria-hidden="true" className="cat-ico">
      <mask id={id}>
        <rect width="32" height="32" fill="#fff" />
        <path d="M8.9 12.2 9.6 7.4l2.9 3.1Z M23.4 12.2l-.4-4.9-3.2 2.6Z" fill="#000" />
        <path d="M9.6 17.6q1.9-2 3.8 0" fill="none" stroke="#000" strokeWidth="1.5" strokeLinecap="round" />
        <ellipse cx="20.8" cy="17.3" rx="1.55" ry="1.9" fill="#000" />
        <path d="M15 19.6h2.6l-1.3 1.8Z" fill="#000" />
        <ellipse cx="9" cy="26.6" rx="4.4" ry="3.4" fill="none" stroke="#000" strokeWidth="1.5" />
        <ellipse cx="23" cy="26.6" rx="4.4" ry="3.4" fill="none" stroke="#000" strokeWidth="1.5" />
        <path d="M7.6 28.6v-1.6M10.4 28.6v-1.6M21.6 28.6v-1.6M24.4 28.6v-1.6" stroke="#000" strokeWidth="1.2" strokeLinecap="round" />
      </mask>
      <g mask={'url(#' + id + ')'} fill="currentColor" stroke="currentColor" strokeLinejoin="round" strokeWidth="1.6">
        <path d="M6.4 16 7.7 4.6l6.1 5.6Z M18.2 10.2l7.4-5.2.7 11Z" />
        <ellipse cx="16" cy="19.6" rx="11" ry="9.4" stroke="none" />
        <ellipse cx="9" cy="26.6" rx="3.7" ry="2.7" stroke="none" />
        <ellipse cx="23" cy="26.6" rx="3.7" ry="2.7" stroke="none" />
      </g>
    </svg>
  );
}
const UpIcon = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
);

export default function Fab({ locale, aisles = [], whatsapp = null }: { locale: Locale; aisles?: Aisle[]; whatsapp?: string | null }) {
  const c = copy(locale);
  const [up, setUp] = useState(false);
  const [open, setOpen] = useState(false);
  const [msgs, setMsgs] = useState<Msg[]>([]);
  const [busy, setBusy] = useState(false);
  const body = useRef<HTMLDivElement>(null);
  const [draft, setDraft] = useState('');
  // What a typed answer can do right now: pick one of the chips on screen, or give the budget being asked.
  const chipsRef = useRef<Chip[]>([]);
  const budgetRef = useRef<((amount: number) => void) | null>(null);
  const t = (l: Label) => l[locale] || l.fr;

  useEffect(() => {
    const onScroll = () => setUp(window.scrollY > 700);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('keydown', onKey);
    };
  }, []);
  useEffect(() => {
    body.current?.scrollTo({ top: body.current.scrollHeight, behavior: 'smooth' });
  }, [msgs, busy]);

  const say = (m: Msg) => {
    chipsRef.current = m.chips ?? [];
    setMsgs((list) => [...list.map((x) => ({ ...x, chips: undefined })), m]);
  };
  const me = (text: string) => {
    chipsRef.current = [];
    budgetRef.current = null;
    setMsgs((list) => [...list.map((x) => ({ ...x, chips: undefined })), { from: 'me', text }]);
  };
  const norm = (s: string) => s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[’']/g, ' ').trim();

  // Free text: a budget when one is asked, a chip on screen, an aisle by name, otherwise a catalogue search.
  function send(e: SyntheticEvent) {
    e.preventDefault();
    const text = draft.trim();
    if (!text || busy) return;
    setDraft('');
    const n = norm(text);
    const digits = text.replace(/[^\d]/g, '');
    if (budgetRef.current && digits) return budgetRef.current(Number(digits) * (/\d\s*k\b/i.test(text) ? 1000 : 1));
    const chip = chipsRef.current.find((ch) => norm(ch.label) === n) ?? (n.length >= 3 ? chipsRef.current.find((ch) => norm(ch.label).includes(n)) : undefined);
    if (chip) return chip.run();
    const words = n.split(/\s+/);
    const aisle = aisles.find((a) => {
      const stem = norm(a.name).replace(/s$/, '');
      return words.some((w) => w.replace(/s$/, '') === stem);
    });
    if (aisle && words.length <= 4) {
      me(text);
      return pickAisle(aisle, false);
    }
    search(text);
  }

  async function search(text: string) {
    me(text);
    setBusy(true);
    const url = '/' + locale + '/catalogue?q=' + encodeURIComponent(text);
    try {
      const r = await createApi('', locale).request<{ data: Result['offer'][] }>('/api/v1/catalogue?per_page=12&q=' + encodeURIComponent(text));
      const results = r.data.slice(0, 4).map((offer) => ({ offer, met: 0, criteria: 0, explanation: '' }));
      say({
        from: 'bot',
        text: (results.length ? c.botSearchFound : c.botSearchNone).replace('{x}', text),
        results,
        links: results.length ? [{ href: url, label: c.botSeeResults }] : [{ href: '/' + locale + '/request-product', label: c.botRequest }],
        chips: [{ label: c.botRestart, run: start }],
      });
    } catch {
      say({ from: 'bot', text: c.botError, links: [{ href: url, label: c.botSeeResults }], chips: [{ label: c.botRestart, run: start }] });
    } finally {
      setBusy(false);
    }
  }

  function start() {
    setMsgs([]);
    say({ from: 'bot', text: c.botHello, chips: aisles.map((a) => ({ label: a.name, run: () => pickAisle(a) })) });
  }
  function toggle() {
    if (!open && msgs.length === 0) start();
    setOpen(!open);
  }

  async function pickAisle(a: Aisle, echo = true) {
    if (echo) me(a.name);
    setBusy(true);
    try {
      const r = await createApi('', locale).request<{ data: { questions: Question[] } }>('/api/v1/assistant/choice/' + a.slug);
      const qs = r.data.questions.filter((q) => q.type === 'budget' || (q.type === 'choice' && q.options?.length) || q.type === 'boolean');
      const budget = qs.find((q) => q.type === 'budget');
      const rest = qs.filter((q) => q.type !== 'budget').slice(0, MAX_QUESTIONS);
      ask(a, budget ? [budget, ...rest] : rest, 0, { budget: null, answers: {} });
    } catch {
      fail(a);
    } finally {
      setBusy(false);
    }
  }

  type State = { budget: number | null; answers: Record<string, string | boolean> };
  function ask(a: Aisle, qs: Question[], i: number, st: State) {
    if (i >= qs.length) return recommend(a, st);
    const q = qs[i];
    const next = (label: string, patch: (s: State) => State) => () => {
      me(label);
      ask(a, qs, i + 1, patch(st));
    };
    const skip: Chip = { label: c.botAny, run: next(c.botAny, (s) => s) };
    const setBudget = (b: number) => next(c.botUpTo + ' ' + money(b, locale), (s) => ({ ...s, budget: b }))();
    let chips: Chip[];
    if (q.type === 'budget') {
      chips = BUDGETS.map((b) => ({ label: c.botUpTo + ' ' + money(b, locale), run: next(c.botUpTo + ' ' + money(b, locale), (s) => ({ ...s, budget: b })) }));
    } else if (q.type === 'boolean') {
      chips = [
        { label: c.yes, run: next(c.yes, (s) => ({ ...s, answers: { ...s.answers, [q.key]: true } })) },
        { label: c.no, run: next(c.no, (s) => ({ ...s, answers: { ...s.answers, [q.key]: false } })) },
      ];
    } else {
      chips = (q.options ?? []).map((o) => ({ label: t(o.label), run: next(t(o.label), (s) => ({ ...s, answers: { ...s.answers, [q.key]: o.value } })) }));
    }
    const text = q.type === 'budget' ? c.botBudget : q.type === 'boolean' ? t(q.label) + ' ?' : c.botWhich.replace('{x}', t(q.label).toLowerCase());
    say({ from: 'bot', text, chips: [...chips, skip] });
    if (q.type === 'budget') budgetRef.current = setBudget;
  }

  async function recommend(a: Aisle, st: State) {
    setBusy(true);
    try {
      const r = await createApi('', locale).request<{ data: { results: Result[] } }>('/api/v1/assistant/choice', {
        method: 'POST',
        body: JSON.stringify({ category: a.slug, budget: st.budget, answers: st.answers }),
        headers: { 'Content-Type': 'application/json' },
      });
      const results = r.data.results.slice(0, 4);
      say({
        from: 'bot',
        text: results.length ? c.botFound : c.botNone,
        results,
        links: [{ href: '/' + locale + '/catalogue?category=' + a.slug, label: c.botSeeAisle.replace('{x}', a.name) }],
        chips: [{ label: c.botRestart, run: start }],
      });
    } catch {
      fail(a);
    } finally {
      setBusy(false);
    }
  }
  function fail(a: Aisle) {
    say({ from: 'bot', text: c.botError, links: [{ href: '/' + locale + '/catalogue?category=' + a.slug, label: c.botSeeAisle.replace('{x}', a.name) }], chips: [{ label: c.botRestart, run: start }] });
  }

  return (
    <div className="fab" data-open={open}>
      {open && (
        <section className="bot" role="dialog" aria-label={c.botName}>
          <header className="bot-head">
            <span className="bot-av" aria-hidden="true"><CatIcon /></span>
            <span className="bot-title"><strong>{c.botName}</strong><small>{c.botHint}</small></span>
            <button type="button" className="bot-x" onClick={() => setOpen(false)} aria-label={c.close}><CloseIcon /></button>
          </header>
          <div className="bot-body" ref={body} aria-live="polite">
            {msgs.map((m, i) => (
              <div key={i} className={'bot-msg from-' + m.from}>
                <p>{m.text}</p>
                {m.results && m.results.length > 0 && (
                  <ul className="bot-results">
                    {m.results.map((r) => (
                      <li key={r.offer.id}>
                        <a href={'/' + locale + '/products/' + r.offer.id}>
                          <span className="bot-img">{r.offer.image && <img src={r.offer.image} alt="" loading="lazy" />}</span>
                          <span className="bot-info">
                            <strong>{r.offer.name}</strong>
                            <small>{r.offer.shop.name}</small>
                            <b>{money(r.offer.price.amount, locale)}</b>
                            {r.criteria > 0 && <em>{c.botMatch.replace('{n}', String(r.met)).replace('{t}', String(r.criteria))}</em>}
                          </span>
                        </a>
                      </li>
                    ))}
                  </ul>
                )}
                {m.links?.map((l) => <a key={l.href} className="bot-link" href={l.href}>{l.label} →</a>)}
                {m.chips && (
                  <div className="bot-chips">
                    {m.chips.map((ch) => <button key={ch.label} type="button" onClick={ch.run}>{ch.label}</button>)}
                  </div>
                )}
              </div>
            ))}
            {busy && <div className="bot-msg from-bot"><p className="bot-typing" aria-label={c.loading}><i /><i /><i /></p></div>}
          </div>
          <form className="bot-form" onSubmit={send}>
            <input type="text" value={draft} onChange={(e) => setDraft(e.target.value)} placeholder={c.botPlaceholder} aria-label={c.botPlaceholder} maxLength={150} enterKeyHint="send" />
            <button type="submit" disabled={!draft.trim() || busy} aria-label={c.botSend}><UpIcon /></button>
          </form>
        </section>
      )}
      <div className="fab-stack">
        <button type="button" className="fab-btn fab-up" data-show={up} onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })} aria-label={c.backToTop} tabIndex={up ? 0 : -1}>
          <UpIcon />
        </button>
        {whatsapp && (
          <a className="fab-btn fab-wa" href={whatsapp} target="_blank" rel="noopener" aria-label="WhatsApp">
            <WhatsAppIcon />
            <span className="fab-tip">{c.whatsappUs}</span>
          </a>
        )}
        <button type="button" className="fab-btn fab-bot" onClick={toggle} aria-expanded={open} aria-label={c.botName}>
          {open ? <CloseIcon /> : <CatIcon />}
          {!open && <span className="fab-tip">{c.botName}</span>}
        </button>
      </div>
    </div>
  );
}
