'use client';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import {
  ApiError,
  createApi,
  type Locale,
  type User,
  type MediaAsset,
  type PageMeta,
} from '@kora/api-client';
import { catalogueCopy } from './catalogue-copy';
export function MediaLibrary({
  locale,
  user,
  onSelect,
}: {
  locale: Locale;
  user: User;
  onSelect?: (url: string) => void;
}) {
  const c = catalogueCopy(locale),
    api = createApi('', locale);
  const [items, setItems] = useState<MediaAsset[]>([]),
    [page, setPage] = useState(1),
    [meta, setMeta] = useState<PageMeta | null>(null),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(''),
    [status, setStatus] = useState(''),
    [rights, setRights] = useState<Record<string, boolean>>({});
  const canWrite = user.permissions.includes('media.write'),
    canPublish = user.permissions.includes('media.publish');
  function fail(e: unknown) {
    setError(
      e instanceof ApiError
        ? e.status === 409
          ? c.conflict
          : [e.message, ...Object.values(e.fields).flat()].filter(Boolean).join(' ') || c.error
        : c.error,
    );
  }
  async function load() {
    setLoading(true);
    try {
      const r = await api.media(page);
      setItems(r.data);
      setMeta(r.meta);
    } catch (e) {
      fail(e);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    void load();
  }, [page, locale]);
  async function upload(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    setBusy(true);
    setError('');
    setStatus('');
    try {
      await api.uploadMedia(new FormData(form));
      form.reset();
      setStatus(c.changed);
      if (page === 1) await load();
      else setPage(1);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function visibility(item: MediaAsset) {
    if (item.is_public && !window.confirm(c.withdraw)) return;
    setBusy(true);
    setError('');
    setStatus('');
    try {
      await api.mediaVisibility(item, !item.is_public, !!rights[item.id]);
      await load();
      setStatus(c.changed);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="stack" aria-label={c.media}>
      <h2>{c.media}</h2>
      {onSelect && <p className="notice">{c.pickerHint}</p>}
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      {status && (
        <p className="success" role="status">
          {status}
        </p>
      )}
      {canWrite && (
        <form className="editor-panel stack" onSubmit={upload}>
          <h3>{c.upload}</h3>
          <p className="muted">{c.uploadHint}</p>
          <label className="field">
            {c.file}
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              name="file"
              required
              disabled={busy}
            />
          </label>
          <div className="editor-grid">
            {(['fr', 'en'] as const).map((l) => (
              <label className="field" key={l}>
                {c.alt} · {l.toUpperCase()}
                <input name={'alt[' + l + ']'} maxLength={160} required disabled={busy} />
              </label>
            ))}
          </div>
          <button className="primary" disabled={busy}>
            {busy ? c.loading : c.upload}
          </button>
        </form>
      )}
      {loading ? (
        <p role="status">{c.loading}</p>
      ) : !items.length ? (
        <p className="empty">{c.empty}</p>
      ) : (
        <div className="media-grid">
          {items.map((item) => (
            <article className="media-card stack" key={item.id}>
              <img src={item.url} alt={item.alt[locale]} width={item.width} height={item.height} />
              <div>
                <strong>{item.alt[locale]}</strong>
                <p className="muted">
                  {item.width} × {item.height} · {Math.ceil(item.bytes / 1024)} Ko
                </p>
                <span className="badge">{item.is_public ? c.public : c.private}</span>
              </div>
              {canPublish && (
                <>
                  {!item.is_public && (
                    <label className="checkbox-label">
                      <input
                        type="checkbox"
                        checked={!!rights[item.id]}
                        onChange={(e) =>
                          setRights((old) => ({ ...old, [item.id]: e.target.checked }))
                        }
                      />
                      {c.rights}
                    </label>
                  )}
                  <button
                    type="button"
                    disabled={busy || (!item.is_public && !rights[item.id])}
                    onClick={() => void visibility(item)}
                  >
                    {item.is_public ? c.makePrivate : c.makePublic}
                  </button>
                </>
              )}
              {onSelect && item.is_public && !item.revoked && (
                <button type="button" className="primary" onClick={() => onSelect(item.url)}>
                  {c.select}
                </button>
              )}
            </article>
          ))}
        </div>
      )}
      {meta && meta.last_page > 1 && (
        <nav className="row" aria-label={c.page}>
          <button type="button" disabled={loading || page === 1} onClick={() => setPage(page - 1)}>
            {c.previous}
          </button>
          <span>
            {page}/{meta.last_page}
          </span>
          <button
            type="button"
            disabled={loading || page === meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            {c.next}
          </button>
        </nav>
      )}
    </section>
  );
}
export function MediaPicker({
  locale,
  user,
  value,
  onChange,
}: {
  locale: Locale;
  user: User;
  value: string | null | undefined;
  onChange: (url: string | null) => void;
}) {
  const c = catalogueCopy(locale);
  const [open, setOpen] = useState(false);
  return (
    <div className="stack">
      <div className="row">
        {value && (
          <img className="media-thumbnail" src={value} alt={c.selected} width={120} height={100} />
        )}
        <button type="button" onClick={() => setOpen(!open)} aria-expanded={open}>
          {c.media}
        </button>
        {value && (
          <button type="button" onClick={() => onChange(null)}>
            {c.clear}
          </button>
        )}
      </div>
      {open &&
        createPortal(
          <MediaDialog
            locale={locale}
            user={user}
            close={() => setOpen(false)}
            select={(url) => {
              onChange(url);
              setOpen(false);
            }}
          />,
          document.body,
        )}
    </div>
  );
}
function MediaDialog({
  locale,
  user,
  close,
  select,
}: {
  locale: Locale;
  user: User;
  close: () => void;
  select: (url: string) => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const c = catalogueCopy(locale);
  useEffect(() => {
    dialog.current?.showModal();
  }, []);
  return (
    <dialog
      ref={dialog}
      className="media-dialog"
      aria-label={c.media}
      onCancel={close}
      onClose={close}
    >
      <button type="button" onClick={close}>
        {c.close}
      </button>
      <MediaLibrary locale={locale} user={user} onSelect={select} />
    </dialog>
  );
}
