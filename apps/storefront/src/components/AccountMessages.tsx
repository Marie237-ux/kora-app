import { useEffect, useRef, useState, type SyntheticEvent } from 'react';
import type { createApi } from '@kora/api-client';
import { type Locale } from '../lib/copy';
import { accountCopy } from '../lib/account-copy';
import AcModal, { AcLoading } from './AcModal';

// Messages and notifications (spec §26): two tabs underlined in orange. Messages = the conversations with customer
// service (one opens in a window with its thread, marked read, a reply box) and a new message; WhatsApp only opens
// the real configured number, after the member agreed. Notifications = what KORA sent in the account, unread first.

type Api = ReturnType<typeof createApi>;
type Conversation = { id: string; number: string; subject: string; status: string; unread?: number; updated_at?: string };
type Msg = { id: string; seq: number; side: string; body: string; created_at: string };
type Note = { id: string; title: string; body: string; link: string | null; read_at: string | null; created_at: string };
const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });
const ref = () => crypto.randomUUID().slice(0, 32);

export default function AccountMessages({ locale, api, onNote }: { locale: Locale; api: Api; onNote: (t: string) => void }) {
  const t = accountCopy(locale);
  const [view, setView] = useState<'messages' | 'notifications'>('messages');
  const [list, setList] = useState<Conversation[] | null>(null);
  const [notes, setNotes] = useState<Note[] | null>(null);
  const [open, setOpen] = useState<Conversation | null>(null);
  const [thread, setThread] = useState<Msg[] | null>(null);
  const [writing, setWriting] = useState(false);
  const [draft, setDraft] = useState({ subject: '', body: '' });
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState('');
  const end = useRef<HTMLLIElement>(null);

  const load = () => api.request<{ data: Conversation[] }>('/api/v1/account/conversations').then((r) => setList(r.data)).catch(() => setList([]));
  useEffect(() => {
    load();
    api.request<{ data: Note[] }>('/api/v1/notifications').then((r) => setNotes(r.data)).catch(() => setNotes([]));
  }, []);
  useEffect(() => end.current?.scrollIntoView({ block: 'end' }), [thread]);

  const when = (iso: string) => new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

  async function show(c: Conversation) {
    setOpen(c);
    setThread(null);
    try {
      const r = await api.request<{ data: Msg[] }>('/api/v1/account/conversations/' + c.id + '/messages');
      setThread(r.data);
      const last = r.data.at(-1)?.seq;
      if (last !== undefined) api.request('/api/v1/account/conversations/' + c.id + '/read', { method: 'POST', body: JSON.stringify({ seq: last }) }).then(() => setList((l) => (l ?? []).map((x) => (x.id === c.id ? { ...x, unread: 0 } : x)))).catch(() => {});
    } catch {
      setThread([]);
    }
  }
  async function create(e: SyntheticEvent) {
    e.preventDefault();
    setBusy('new');
    try {
      const r = await api.request<{ data: Conversation }>('/api/v1/account/conversations', { method: 'POST', headers: key(), body: JSON.stringify({ subject: draft.subject.trim(), context_type: 'general', context_id: null, body: draft.body.trim(), client_ref: ref() }) });
      setWriting(false);
      setDraft({ subject: '', body: '' });
      await load();
      show(r.data);
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function send(c: Conversation) {
    setBusy('send');
    try {
      await api.request('/api/v1/account/conversations/' + c.id + '/messages', { method: 'POST', headers: key(), body: JSON.stringify({ body: reply.trim(), client_ref: ref() }) });
      setReply('');
      const r = await api.request<{ data: Msg[] }>('/api/v1/account/conversations/' + c.id + '/messages');
      setThread(r.data);
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function whatsapp() {
    try {
      const r = await api.request<{ data: { url?: string; link?: string } }>('/api/v1/account/whatsapp-link?consent=1');
      const url = r.data.url ?? r.data.link;
      if (url) window.open(url, '_blank', 'noopener');
    } catch {
      onNote(t.failed);
    }
  }
  async function read(n: Note) {
    if (n.read_at) return;
    setNotes((l) => (l ?? []).map((x) => (x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x)));
    api.request('/api/v1/notifications/' + n.id + '/read', { method: 'POST', body: JSON.stringify({}) }).catch(() => {});
  }
  const unreadNotes = notes?.filter((n) => !n.read_at).length ?? 0;
  if (list === null || notes === null) return <AcLoading />;

  return (
    <div className="ac-msgs">
      <nav className="ac-subtabs" role="tablist">
        <button type="button" role="tab" aria-selected={view === 'messages'} onClick={() => setView('messages')}>{t.messagesTab}</button>
        <button type="button" role="tab" aria-selected={view === 'notifications'} onClick={() => setView('notifications')}>{t.notificationsTab}{unreadNotes > 0 && <span className="ac-dotcount">{unreadNotes}</span>}</button>
      </nav>

      {view === 'messages' ? (
        <>
          <div className="ac-lead-row">
            <p className="ac-intro">{t.noConversationsText}</p>
            <div className="ac-lead-actions">
              <button type="button" className="btn btn-line btn-small" onClick={whatsapp}>{t.whatsapp}</button>
              <button type="button" className="btn btn-accent btn-small" onClick={() => setWriting(true)}>{t.newConversation}</button>
            </div>
          </div>
          {list === null ? <div className="ac-skel ac-recent"><i className="sk-row" /></div> : list.length === 0 ? <p className="ac-empty-text">{t.noConversations}</p> : (
            <ul className="ac-list">
              {list.map((c) => (
                <li key={c.id}>
                  <button type="button" onClick={() => show(c)}>
                    <span className="ac-thumb ac-thumb-kora">K</span>
                    <span className="ac-list-text"><strong>{c.subject}</strong><small>{c.number}{c.updated_at && ' · ' + when(c.updated_at)}</small></span>
                    {!!c.unread && <span className="ac-dotcount">{c.unread}</span>}
                    <svg className="ac-list-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </>
      ) : notes === null ? <div className="ac-skel ac-recent"><i className="sk-row" /></div> : notes.length === 0 ? <p className="ac-empty-text">{t.noNotifications}</p> : (
        <ul className="ac-notes">
          {notes.map((n) => (
            <li key={n.id} className={n.read_at ? undefined : 'unread'}>
              <a href={n.link ?? undefined} onClick={() => read(n)}>
                <strong>{n.title}</strong>
                <p>{n.body}</p>
                <small>{when(n.created_at)}</small>
              </a>
            </li>
          ))}
        </ul>
      )}

      {writing && (
        <AcModal title={t.newConversation} onClose={() => setWriting(false)}>
          <form className="acm-form" onSubmit={create}>
            <label className="ac-field"><span>{t.subject}</span><input required maxLength={150} value={draft.subject} onChange={(e) => setDraft({ ...draft, subject: e.target.value })} placeholder={t.subjectHint} /></label>
            <label className="ac-field"><span>{t.message}</span><textarea required rows={5} maxLength={4000} value={draft.body} onChange={(e) => setDraft({ ...draft, body: e.target.value })} placeholder={t.messageHint} /></label>
            <button type="submit" className="btn btn-accent ac-wide" disabled={busy === 'new'}>{busy === 'new' && <i className="spin" />} {t.send}</button>
          </form>
        </AcModal>
      )}
      {open && (
        <AcModal title={open.subject} onClose={() => setOpen(null)} wide>
          <div className="acm-chat">
            <p className="acm-sub">{t.support} · {open.number}</p>
            <ul className="ac-thread">
              {thread === null ? <li className="ac-skel"><i className="sk-line" /></li> : thread.map((m) => (
                <li key={m.id} className={m.side === 'customer' ? 'me' : 'them'}>
                  <small>{when(m.created_at)}</small>
                  <p>{m.body}</p>
                </li>
              ))}
              <li ref={end} aria-hidden="true" className="ac-thread-end" />
            </ul>
            {open.status !== 'closed' && (
              <form className="ac-composer" onSubmit={(e) => { e.preventDefault(); if (reply.trim()) send(open); }}>
                <textarea rows={1} value={reply} onChange={(e) => setReply(e.target.value)} placeholder={t.messageHint} maxLength={4000} />
                <button type="submit" className="ac-send" disabled={busy === 'send' || !reply.trim()} aria-label={t.send}>{busy === 'send' ? <i className="spin" /> : <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" /></svg>}</button>
              </form>
            )}
          </div>
        </AcModal>
      )}
    </div>
  );
}
