import { useEffect, useMemo, useRef, useState, type FormEvent, type PointerEvent } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { money, type Locale } from '../lib/copy';

// Shop stories, on every page (shops as accounts): the stories of the moment of the shops the visitor follows come
// from the API (never a blocked shop's). Every shop photo carrying data-story-shop gets the KORA ring when the shop has stories
// (grey once all were seen, kept in this browser) and opens them on a tap. The same layer hides the products and
// shops a member blocked ([data-shop] cards).
// The viewer follows the Instagram / Facebook stories: on a computer, the story card in the middle with small round
// arrows on its edges and the neighbouring shops as dimmed previews on both sides (a click opens them); on a phone,
// the story full screen; the page stays visible behind, blurred. The linked product sits above the caption, on the
// left (photo, name, price). Progress bars, tap right / left (or the arrows, ← →) to move, hold, the pause button or
// the space bar to pause, 5 s per story, then the next shop: each picture fades in and zooms slowly, a new shop slides
// in from the side it comes from.
// A member reacts with one of six emojis (tap again to remove it) or replies: the reply
// opens a conversation in their messages. Both ask a visitor to sign in first.

type Story = {
  id: string;
  image: string;
  caption: string | null;
  offer: { id: string; name: string; image?: string | null; price: { amount: number } } | null;
  published_at: string;
  my_reaction?: string | null;
};
export type StoryGroup = {
  shop: { id: string; slug: string; name: string; avatar: string | null };
  followed: boolean;
  latest_at?: string;
  stories: Story[];
};
const SEEN = 'kora.stories-seen';
const seenSet = (): Set<string> => {
  try {
    return new Set(JSON.parse(localStorage.getItem(SEEN) ?? '[]') as string[]);
  } catch {
    return new Set();
  }
};
export const allSeen = (g: StoryGroup, seen: Set<string>) => g.stories.every((s) => seen.has(s.id));
const DURATION = 5000;
// Same keys as the API (ShopSocialController::REACTIONS), in display order.
const REACTIONS: [string, string][] = [['love', '❤️'], ['adore', '😍'], ['fire', '🔥'], ['clap', '👏'], ['wow', '😮'], ['haha', '😂']];

const words = {
  fr: { title: 'Stories', close: 'Fermer', prev: 'Story précédente', next: 'Story suivante', pause: 'Mettre en pause', play: 'Reprendre', see: 'Voir le produit', reply: 'Répondre à', send: 'Envoyer', sent: 'Réponse envoyée. Suivez-la dans vos messages.', failed: 'Envoi impossible pour le moment.', react: 'Réagir', ago: (h: number) => 'il y a ' + h + ' h', subject: (s: string) => 'Réponse à la story de ' + s },
  en: { title: 'Stories', close: 'Close', prev: 'Previous story', next: 'Next story', pause: 'Pause', play: 'Play', see: 'See the product', reply: 'Reply to', send: 'Send', sent: 'Reply sent. Follow it in your messages.', failed: 'Could not send right now.', react: 'React', ago: (h: number) => h + ' h ago', subject: (s: string) => 'Reply to the story of ' + s },
};

const Chevron = ({ dir }: { dir: 'left' | 'right' }) => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round"><path d={dir === 'left' ? 'm14.5 5-7 7 7 7' : 'm9.5 5 7 7-7 7'} /></svg>
);
const Bag = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinejoin="round"><path d="M5 8h14l-1 12H6L5 8Z" /><path d="M9 8V6.5a3 3 0 0 1 6 0V8" /></svg>
);
const Close = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
);

export default function StoryLayer({ locale }: { locale: Locale }) {
  const api = useMemo(() => createApi('', locale), [locale]);
  const w = words[locale] ?? words.fr;
  const [groups, setGroups] = useState<StoryGroup[]>([]);
  const [open, setOpen] = useState<{ g: number; s: number } | null>(null);
  const [progress, setProgress] = useState(0);
  const [held, setHeld] = useState(false);
  const [stopped, setStopped] = useState(false);
  const [typing, setTyping] = useState(false);
  const [draft, setDraft] = useState('');
  const [note, setNote] = useState<string | null>(null);
  const [burst, setBurst] = useState<{ emoji: string; key: number } | null>(null);
  const groupsRef = useRef<StoryGroup[]>([]);
  groupsRef.current = groups;
  const elapsed = useRef(0);
  const current = useRef('');
  // Direction of the last move, for the slide between shops.
  const [dir, setDir] = useState<'next' | 'prev' | 'open'>('open');
  // The story only plays once its picture is there (a slow network never eats the 5 seconds).
  const [loaded, setLoaded] = useState<string | null>(null);
  const img = useRef<HTMLImageElement>(null);
  const storyId = open ? groups[open.g]?.stories[open.s]?.id : undefined;
  const paused = held || stopped || typing || loaded !== storyId;

  // Rings on every shop photo of the page, and a tap that opens that shop's stories.
  function paint(list: StoryGroup[]) {
    const seen = seenSet();
    document.querySelectorAll<HTMLElement>('[data-story-shop]').forEach((el) => {
      const g = list.find((x) => x.shop.slug === el.dataset.storyShop);
      el.dataset.story = g ? (allSeen(g, seen) ? 'seen' : 'new') : '';
    });
    (window as unknown as { koraStories?: StoryGroup[] }).koraStories = list;
    window.dispatchEvent(new CustomEvent('kora:stories', { detail: list }));
  }

  useEffect(() => {
    // Only the followed shops' stories come back; reloaded when the visitor follows or unfollows a shop.
    const load = () => api.request<{ data: StoryGroup[] }>('/api/v1/stories').then((r) => { setGroups(r.data); paint(r.data); }).catch(() => {});
    load();
    addEventListener('kora:follow', load);
    // Blocked shops: their cards disappear for this member.
    api.request<{ data: { shops: { slug: string }[] } }>('/api/v1/shops-blocked').then((r) => {
      const slugs = new Set(r.data.shops.map((s) => s.slug));
      if (!slugs.size) return;
      const hide = () => document.querySelectorAll<HTMLElement>('[data-shop]').forEach((el) => { if (slugs.has(el.dataset.shop ?? '')) el.closest<HTMLElement>('[role="listitem"]')?.setAttribute('hidden', '') ?? el.setAttribute('hidden', ''); });
      hide();
      new MutationObserver(hide).observe(document.body, { childList: true, subtree: true });
    }).catch(() => {});
    const onClick = (e: MouseEvent) => {
      const el = (e.target as Element | null)?.closest?.('[data-story-shop]') as HTMLElement | null;
      if (!el || !el.dataset.story) return;
      const i = groupsRef.current.findIndex((x) => x.shop.slug === el.dataset.storyShop);
      if (i < 0) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      start(i);
    };
    const onOpen = (e: Event) => {
      const slug = (e as CustomEvent<string>).detail;
      const i = groupsRef.current.findIndex((x) => x.shop.slug === slug);
      if (i >= 0) start(i);
    };
    document.addEventListener('click', onClick, true);
    addEventListener('kora:story-open', onOpen);
    return () => {
      document.removeEventListener('click', onClick, true);
      removeEventListener('kora:story-open', onOpen);
      removeEventListener('kora:follow', load);
    };
  }, []);

  function start(g: number, from: 'next' | 'prev' | 'open' = 'open') {
    const seen = seenSet();
    current.current = '';
    setDir(from);
    const s = groupsRef.current[g].stories.findIndex((x) => !seen.has(x.id));
    setStopped(false);
    setOpen({ g, s: s < 0 ? 0 : s });
  }
  function close() {
    current.current = '';
    setOpen(null);
    paint(groupsRef.current);
  }
  function move(step: 1 | -1) {
    setDir(step > 0 ? 'next' : 'prev');
    setOpen((o) => {
      if (!o) return o;
      const list = groupsRef.current;
      const s = o.s + step;
      if (s >= 0 && s < list[o.g].stories.length) return { g: o.g, s };
      const g = o.g + step;
      if (g < 0) return { g: 0, s: 0 };
      if (g >= list.length) { setTimeout(close); return o; }
      return { g, s: step > 0 ? 0 : list[g].stories.length - 1 };
    });
  }
  // A visitor must sign in to react or reply: the viewer closes and the sign-in panel opens.
  function signIn() {
    close();
    dispatchEvent(new CustomEvent('kora:auth', { detail: { mode: 'login', next: null } }));
  }
  function setReaction(id: string, reaction: string | null) {
    setGroups((list) => list.map((g) => ({ ...g, stories: g.stories.map((s) => (s.id === id ? { ...s, my_reaction: reaction } : s)) })));
  }
  async function react(story: Story, key: string, emoji: string) {
    const before = story.my_reaction ?? null;
    const next = before === key ? null : key;
    setReaction(story.id, next);
    if (next) setBurst({ emoji, key: Date.now() });
    try {
      await api.request('/api/v1/stories/' + story.id + '/react', { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() }, body: JSON.stringify({ reaction: next }) });
    } catch (err) {
      setReaction(story.id, before);
      if (err instanceof ApiError && err.status === 401) signIn();
    }
  }
  async function reply(e: FormEvent, group: StoryGroup, story: Story) {
    e.preventDefault();
    const body = draft.trim();
    if (!body) return;
    const when = new Date(story.published_at).toLocaleString(locale === 'fr' ? 'fr-FR' : 'en-GB', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
    try {
      await api.request('/api/v1/account/conversations', {
        method: 'POST',
        headers: { 'Idempotency-Key': crypto.randomUUID() },
        body: JSON.stringify({ subject: w.subject(group.shop.name).slice(0, 150), context_type: 'general', context_id: null, body: (body + '\n\n— Story ' + when + (story.caption ? ' : « ' + story.caption + ' »' : '')).slice(0, 4000), client_ref: crypto.randomUUID() }),
      });
      setDraft('');
      setNote(w.sent);
      (document.activeElement as HTMLElement | null)?.blur();
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) return signIn();
      setNote(w.failed);
    }
  }

  // Mark seen, reset the timer, keyboard and body scroll while open.
  useEffect(() => {
    if (!open) return;
    const story = groups[open.g]?.stories[open.s];
    if (story) {
      const seen = seenSet();
      seen.add(story.id);
      try { localStorage.setItem(SEEN, JSON.stringify([...seen].slice(-300))); } catch {}
    }
    setNote(null);
    document.body.style.overflow = 'hidden';
    const key = (e: KeyboardEvent) => {
      if ((e.target as HTMLElement | null)?.tagName === 'INPUT') return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowRight') move(1);
      if (e.key === 'ArrowLeft') move(-1);
      if (e.key === ' ') { e.preventDefault(); setStopped((v) => !v); }
    };
    addEventListener('keydown', key);
    return () => { removeEventListener('keydown', key); document.body.style.overflow = ''; };
  }, [open?.g, open?.s]);
  // The timer keeps the time already watched of the current story in a ref: a pause resumes where it stopped, a new
  // story (or reopening the viewer) starts from zero.
  useEffect(() => {
    if (!open) return;
    const at = open.g + ':' + open.s;
    if (current.current !== at) {
      current.current = at;
      elapsed.current = 0;
      setProgress(0);
    }
    if (paused) return;
    let frame = 0;
    const base = performance.now() - elapsed.current;
    const tick = (now: number) => {
      elapsed.current = now - base;
      const p = Math.min(1, elapsed.current / DURATION);
      setProgress(p);
      if (p >= 1) move(1);
      else frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [open?.g, open?.s, paused]);
  useEffect(() => {
    if (storyId && img.current?.complete) setLoaded(storyId);
  }, [storyId]);
  useEffect(() => {
    if (!note) return;
    const t = setTimeout(() => setNote(null), 3200);
    return () => clearTimeout(t);
  }, [note]);

  if (!open || !groups[open.g]) return null;
  const group = groups[open.g];
  const story = group.stories[open.s];
  const seen = seenSet();
  const ago = (iso: string) => w.ago(Math.max(1, Math.round((Date.now() - new Date(iso).getTime()) / 3600000)));
  const first = open.g === 0 && open.s === 0;
  const avatar = (g: StoryGroup) => (g.shop.avatar ? <img src={g.shop.avatar} alt="" /> : <b>{g.shop.name.charAt(0)}</b>);
  // Holding the picture pauses; the bottom bar and the buttons never do.
  const hold = (on: boolean) => (e: PointerEvent) => {
    if ((e.target as Element).closest('.sv-foot, .sv-head button')) return;
    setHeld(on);
  };
  // Neighbouring shops shown as previews: two on each side on a wide screen, one otherwise (CSS).
  const peek = (i: number, far: boolean) => {
    const g = groups[i];
    if (!g) return <span key={'empty' + i} className="sv-peek sv-peek-empty" data-far={far} aria-hidden="true" />;
    const next = g.stories.find((s) => !seen.has(s.id)) ?? g.stories[0];
    return (
      <button key={g.shop.id} type="button" className="sv-peek" data-far={far} onClick={() => start(i, i > open.g ? 'next' : 'prev')} aria-label={g.shop.name} style={g.shop.avatar ? { backgroundImage: 'url(' + g.shop.avatar + ')' } : undefined}>
        <img src={next.image} alt="" />
        <span className="sv-peek-in">
          <span className="story-ring sv-peek-av" data-story={allSeen(g, seen) ? 'seen' : 'new'}>{avatar(g)}</span>
          <strong>{g.shop.name}</strong>
          <small>{ago(next.published_at)}</small>
        </span>
      </button>
    );
  };

  return (
    <div className="sv" role="dialog" aria-modal="true" aria-label={w.title + ' · ' + group.shop.name}>
      <button type="button" className="sv-x" onClick={close} aria-label={w.close}><Close /></button>
      <div className="sv-stage" onMouseDown={(e) => e.target === e.currentTarget && close()}>
        {peek(open.g - 2, true)}
        {peek(open.g - 1, false)}
        <div className="sv-main">
          <button type="button" className="sv-nav sv-nav-prev" onClick={() => move(-1)} aria-label={w.prev} disabled={first}><Chevron dir="left" /></button>
          <div key={group.shop.id} className="sv-frame" data-dir={dir} data-paused={paused} onPointerDown={hold(true)} onPointerUp={hold(false)} onPointerLeave={() => setHeld(false)}>
            <img key={story.id} ref={img} className="sv-img" src={story.image} alt="" data-ready={loaded === story.id} onLoad={() => setLoaded(story.id)} onError={() => setLoaded(story.id)} />
            {loaded !== story.id && <span className="sv-wait" aria-hidden="true" />}
            <div className="sv-top">
              <div className="sv-bars">
                {group.stories.map((s, i) => <span key={s.id}><i style={{ transform: 'scaleX(' + (i < open.s ? 1 : i === open.s ? progress : 0) + ')' }} /></span>)}
              </div>
              <div className="sv-head">
                <a className="sv-shop" href={'/' + locale + '/shops/' + group.shop.slug}>
                  <span className="sv-av">{avatar(group)}</span>
                  <strong>{group.shop.name}</strong>
                  <small>{ago(story.published_at)}</small>
                </a>
                <span className="sv-tools">
                  <button type="button" onClick={() => setStopped((v) => !v)} aria-label={stopped ? w.play : w.pause}>
                    {stopped ? <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M7.5 4.8v14.4L19 12Z" /></svg> : <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><rect x="6" y="4.5" width="4" height="15" rx="1.3" /><rect x="14" y="4.5" width="4" height="15" rx="1.3" /></svg>}
                  </button>
                  <button type="button" className="sv-close" onClick={close} aria-label={w.close}><Close /></button>
                </span>
              </div>
            </div>
            <button type="button" className="sv-prev" onClick={() => move(-1)} aria-label={w.prev} tabIndex={-1} />
            <button type="button" className="sv-next" onClick={() => move(1)} aria-label={w.next} tabIndex={-1} />
            {burst && <span key={burst.key} className="sv-burst" aria-hidden="true" onAnimationEnd={() => setBurst(null)}>{burst.emoji}</span>}
            <div className="sv-foot">
              {story.offer && (
                <a className="sv-product" href={'/' + locale + '/products/' + story.offer.id}>
                  <span className="sv-product-img">{story.offer.image ? <img src={story.offer.image} alt="" /> : <Bag />}</span>
                  <span className="sv-product-t">
                    <strong>{story.offer.name}</strong>
                    <span><b>{money(story.offer.price.amount, locale)}</b><em>{w.see}<Chevron dir="right" /></em></span>
                  </span>
                </a>
              )}
              {story.caption && <p className="sv-cap">{story.caption}</p>}
              <div className="sv-react" role="group" aria-label={w.react}>
                {REACTIONS.map(([key, emoji]) => (
                  <button key={key} type="button" data-on={story.my_reaction === key} aria-pressed={story.my_reaction === key} aria-label={emoji} onClick={() => react(story, key, emoji)}>{emoji}</button>
                ))}
              </div>
              <form className="sv-reply" onSubmit={(e) => reply(e, group, story)}>
                <input type="text" value={draft} onChange={(e) => setDraft(e.target.value)} onFocus={() => setTyping(true)} onBlur={() => setTyping(false)} placeholder={w.reply + ' ' + group.shop.name + '…'} aria-label={w.reply + ' ' + group.shop.name} maxLength={500} enterKeyHint="send" />
                <button type="submit" disabled={!draft.trim()} aria-label={w.send}>
                  <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={1.9} strokeLinejoin="round"><path d="M21 3 10.2 13.8M21 3l-6.6 18-4.2-7.2L3 9.6Z" /></svg>
                </button>
              </form>
              {note && <p className="sv-note" role="status">{note}</p>}
            </div>
          </div>
          <button type="button" className="sv-nav sv-nav-next" onClick={() => move(1)} aria-label={w.next}><Chevron dir="right" /></button>
        </div>
        {peek(open.g + 1, false)}
        {peek(open.g + 2, true)}
      </div>
    </div>
  );
}