'use client';
import { useEffect, useState, type FormEvent } from 'react';
import {
  ApiError,
  createApi,
  type Locale,
  type SceneContent,
  type SceneRecord,
  type User,
} from '@kora/api-client';
import { t } from '@kora/ui';
import { MediaPicker } from './MediaLibrary';
const initial: SceneContent = {
  kind: 'campaign',
  target_id: null,
  category: null,
  title: { fr: '', en: '' },
  description: { fr: '', en: '' },
  label: { fr: '', en: '' },
  cta: { fr: '', en: '' },
  alt: { fr: '', en: '' },
  media: '/brand/logo.png',
  mobile_media: null,
  decor: 'bands',
  preset: 'signature',
  hold_ms: 8000,
  autoplay: true,
  starts_at: null,
  ends_at: null,
  priority: 0,
};
const localDate = (value: string | null) =>
  value ? new Date(new Date(value).getTime() + 3600000).toISOString().slice(0, 16) : '';
const instant = (value: string) => (value ? new Date(value + ':00+01:00').toISOString() : null);

export function SceneEditor({
  locale,
  user,
  onDirtyChange,
}: {
  locale: Locale;
  user: User;
  onDirtyChange?: (dirty: boolean) => void;
}) {
  const copy = t(locale),
    api = createApi('', locale);
  const [scenes, setScenes] = useState<SceneRecord[]>([]),
    [selected, setSelected] = useState<SceneRecord | null>(null),
    [draft, setDraft] = useState<SceneContent>(structuredClone(initial)),
    [name, setName] = useState(''),
    [editing, setEditing] = useState(false),
    [loading, setLoading] = useState(true),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(''),
    [fields, setFields] = useState<Record<string, string[]>>({}),
    [status, setStatus] = useState(''),
    [preview, setPreview] = useState(false),
    [previewLocale, setPreviewLocale] = useState<Locale>(locale),
    [previewTheme, setPreviewTheme] = useState<'light' | 'dark'>('light'),
    [size, setSize] = useState<'desktop' | 'tablet' | 'phone'>('desktop'),
    [reduced, setReduced] = useState(false),
    [revision, setRevision] = useState<{ version: number; created_at: string }[]>([]);
  const canWrite = user.permissions.includes('content.write'),
    canPublish = user.permissions.includes('content.publish');
  function failure(e: unknown) {
    setError(
      e instanceof ApiError
        ? e.status === 409
          ? copy.versionConflict
          : e.message || copy.networkError
        : copy.error,
    );
    if (e instanceof ApiError) setFields(e.fields);
  }
  async function refresh() {
    setLoading(true);
    try {
      const r = await api.scenes();
      setScenes(r.data.data);
    } catch (e) {
      failure(e);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    void refresh();
  }, [locale]);
  const dirty =
    editing &&
    (name !== (selected?.name ?? '') ||
      JSON.stringify(draft) !== JSON.stringify(selected?.draft ?? initial));
  useEffect(() => {
    onDirtyChange?.(dirty);
    return () => onDirtyChange?.(false);
  }, [dirty, onDirtyChange]);
  const set = <K extends keyof SceneContent>(key: K, value: SceneContent[K]) =>
    setDraft((old) => ({ ...old, [key]: value }));
  function open(scene: SceneRecord | null, duplicate = false) {
    setError('');
    setStatus('');
    setFields({});
    setPreview(false);
    setRevision([]);
    setSelected(duplicate ? null : scene);
    setName(scene?.name ?? '');
    setDraft(structuredClone(scene?.draft ?? initial));
    setEditing(true);
  }
  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError('');
    setFields({});
    try {
      const path = '/api/v1/operations/content/scenes' + (selected ? '/' + selected.id : '');
      const r = await api.request<{ data: SceneRecord }>(path, {
        method: selected ? 'PUT' : 'POST',
        body: JSON.stringify({ name, draft, ...(selected ? { version: selected.version } : {}) }),
      });
      setSelected(r.data);
      setDraft(r.data.draft);
      setStatus(copy.sceneSaved);
      setPreview(false);
      await refresh();
    } catch (e) {
      failure(e);
    } finally {
      setBusy(false);
    }
  }
  async function change(
    scene: SceneRecord,
    action: 'publish' | 'pause' | 'restore',
    extra: object = {},
  ) {
    if (action === 'publish' && !window.confirm(copy.confirmPublish)) return;
    setBusy(true);
    setError('');
    try {
      const r = await api.request<{ data: SceneRecord }>(
        '/api/v1/operations/content/scenes/' + scene.id + '/' + action,
        { method: 'POST', body: JSON.stringify({ version: scene.version, ...extra }) },
      );
      if (selected?.id === scene.id) {
        setSelected(r.data);
        setDraft(r.data.draft);
        setPreview(false);
      }
      setStatus(action === 'publish' ? copy.scenePublished : copy.saved);
      await refresh();
    } catch (e) {
      failure(e);
    } finally {
      setBusy(false);
    }
  }
  async function move(i: number, direction: number) {
    const next = [...scenes];
    [next[i], next[i + direction]] = [next[i + direction], next[i]];
    setBusy(true);
    try {
      await api.request('/api/v1/operations/content/reorder', {
        method: 'POST',
        body: JSON.stringify({ items: next.map((s) => ({ id: s.id, version: s.version })) }),
      });
      await refresh();
      setSelected(null);
      setEditing(false);
    } catch (e) {
      failure(e);
    } finally {
      setBusy(false);
    }
  }
  async function revisions() {
    if (!selected) return;
    try {
      const r = await api.request<{ data: { version: number; created_at: string }[] }>(
        '/api/v1/operations/content/scenes/' + selected.id + '/revisions',
      );
      setRevision(r.data);
    } catch (e) {
      failure(e);
    }
  }
  return (
    <div className="stack">
      <div className="row spread">
        <div>
          <h1 style={{ fontSize: '2.7rem' }}>{copy.content}</h1>
          <p className="muted">{copy.opsIntro}</p>
        </div>
        {canWrite && (
          <button className="primary" onClick={() => open(null)}>
            {copy.newScene}
          </button>
        )}
      </div>
      {error && (
        <div className="error" role="alert">
          {error}
          {Object.entries(fields).map(([key, values]) => (
            <p key={key}>
              {key}: {values.join(' ')}
            </p>
          ))}
          <button type="button" onClick={() => void refresh()}>
            {copy.reload}
          </button>
        </div>
      )}
      {status && (
        <p role="status" className="success">
          {status}
        </p>
      )}
      {loading ? (
        <p role="status">{copy.loading}</p>
      ) : (
        <div className="scene-list">
          {!scenes.length && <p className="empty">{copy.noScenes}</p>}
          {scenes.map((scene, i) => (
            <article className="scene-item" key={scene.id}>
              <div className="row spread">
                <div>
                  <strong>{scene.name}</strong>{' '}
                  <span className="badge">
                    {scene.paused ? copy.paused : scene.publication ? copy.published : copy.draft}
                  </span>
                </div>
                <div className="row">
                  <button onClick={() => open(scene)}>{copy.editScene}</button>
                  {canWrite && (
                    <>
                      <button onClick={() => open(scene, true)}>{copy.duplicate}</button>
                      <button
                        disabled={busy || i === 0}
                        aria-label={copy.up + ' ' + scene.name}
                        onClick={() => void move(i, -1)}
                      >
                        ↑
                      </button>
                      <button
                        disabled={busy || i === scenes.length - 1}
                        aria-label={copy.down + ' ' + scene.name}
                        onClick={() => void move(i, 1)}
                      >
                        ↓
                      </button>
                    </>
                  )}
                  {canPublish && scene.publication && (
                    <button
                      disabled={busy}
                      onClick={() => void change(scene, 'pause', { paused: !scene.paused })}
                    >
                      {scene.paused ? copy.resume : copy.pause}
                    </button>
                  )}
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
      {editing && (
        <div className="editor-panel stack">
          <form onSubmit={save} className="stack">
            <label className="field">
              {copy.internalName}
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                maxLength={100}
                required
                disabled={!canWrite}
              />
            </label>
            <div className="editor-grid">
              <label className="field">
                {copy.sceneKind}
                <select
                  value={draft.kind}
                  onChange={(e) => set('kind', e.target.value as SceneContent['kind'])}
                >
                  {(['product', 'collection', 'shop', 'campaign'] as const).map((k) => (
                    <option key={k} value={k}>
                      {copy[k]}
                    </option>
                  ))}
                </select>
              </label>
              <label className="field">
                {copy.targetId}
                <input
                  value={draft.target_id ?? ''}
                  onChange={(e) => set('target_id', e.target.value || null)}
                />
              </label>
              <label className="field">
                {copy.category}
                <input
                  value={draft.category ?? ''}
                  onChange={(e) => set('category', e.target.value || null)}
                />
              </label>
            </div>
            <div className="editor-grid">
              {(['fr', 'en'] as const).map((language) => (
                <fieldset className="stack" key={language}>
                  <legend>{language === 'fr' ? 'Français' : 'English'}</legend>
                  {(['label', 'title', 'description', 'cta', 'alt'] as const).map((key) => (
                    <label className="field" key={key}>
                      {copy[key]}
                      {key === 'description' ? (
                        <textarea
                          value={draft[key][language]}
                          maxLength={300}
                          onChange={(e) => set(key, { ...draft[key], [language]: e.target.value })}
                        />
                      ) : (
                        <input
                          value={draft[key][language]}
                          maxLength={
                            key === 'title' ? 100 : key === 'label' ? 40 : key === 'cta' ? 50 : 160
                          }
                          onChange={(e) => set(key, { ...draft[key], [language]: e.target.value })}
                        />
                      )}
                    </label>
                  ))}
                </fieldset>
              ))}
            </div>
            <div className="editor-grid">
              <div className="field">
                <span>{copy.media}</span>
                <MediaPicker
                  locale={locale}
                  user={user}
                  value={draft.media}
                  onChange={(value) => set('media', value)}
                />
              </div>
              <div className="field">
                <span>{copy.mobileMedia}</span>
                <MediaPicker
                  locale={locale}
                  user={user}
                  value={draft.mobile_media}
                  onChange={(value) => set('mobile_media', value)}
                />
              </div>
              <label className="field">
                {copy.decor}
                <select
                  value={draft.decor}
                  onChange={(e) => set('decor', e.target.value as SceneContent['decor'])}
                >
                  {(['bands', 'circle', 'arch', 'none'] as const).map((k) => (
                    <option key={k} value={k}>
                      {copy[k]}
                    </option>
                  ))}
                </select>
              </label>
              <label className="field">
                {copy.preset}
                <select
                  value={draft.preset}
                  onChange={(e) => set('preset', e.target.value as SceneContent['preset'])}
                >
                  {(['signature', 'quiet'] as const).map((k) => (
                    <option key={k} value={k}>
                      {copy[k]}
                    </option>
                  ))}
                </select>
              </label>
              <label className="field">
                {copy.reading}
                <input
                  type="number"
                  min={8}
                  max={30}
                  value={draft.hold_ms / 1000}
                  onChange={(e) => set('hold_ms', Number(e.target.value) * 1000)}
                />
              </label>
              <label className="field">
                {copy.priority}
                <input
                  type="number"
                  min={0}
                  max={100}
                  value={draft.priority}
                  onChange={(e) => set('priority', Number(e.target.value))}
                />
              </label>
              <label className="field">
                {copy.starts}
                <input
                  type="datetime-local"
                  value={localDate(draft.starts_at)}
                  onChange={(e) => set('starts_at', instant(e.target.value))}
                />
              </label>
              <label className="field">
                {copy.ends}
                <input
                  type="datetime-local"
                  value={localDate(draft.ends_at)}
                  onChange={(e) => set('ends_at', instant(e.target.value))}
                />
              </label>
            </div>
            <label className="checkbox-label">
              <input
                type="checkbox"
                checked={draft.autoplay}
                onChange={(e) => set('autoplay', e.target.checked)}
              />
              {copy.autoplay}
            </label>
            <div className="row">
              {canWrite && (
                <button className="primary" disabled={busy}>
                  {busy ? copy.loading : copy.save}
                </button>
              )}
              <button
                type="button"
                onClick={() => {
                  setEditing(false);
                  setPreview(false);
                }}
              >
                {copy.close}
              </button>
              {selected && (
                <>
                  <button
                    type="button"
                    disabled={dirty || busy}
                    onClick={() => setPreview(!preview)}
                  >
                    {copy.preview}
                  </button>
                  {canPublish && (
                    <button
                      type="button"
                      className="accent"
                      disabled={busy || dirty}
                      onClick={() => void change(selected, 'publish')}
                    >
                      {copy.publish}
                    </button>
                  )}
                  <button type="button" onClick={() => void revisions()}>
                    {copy.revisions}
                  </button>
                </>
              )}
            </div>
            <p className="muted">{copy.previewHint}</p>
          </form>
          {revision.map((r) => (
            <div className="row" key={r.version}>
              <span>
                v{r.version} ·{' '}
                {new Intl.DateTimeFormat(locale + '-CM', {
                  dateStyle: 'medium',
                  timeStyle: 'short',
                  timeZone: 'Africa/Douala',
                }).format(new Date(r.created_at))}
              </span>
              {canWrite && selected && (
                <button
                  disabled={busy}
                  onClick={() => void change(selected, 'restore', { revision: r.version })}
                >
                  {copy.restore}
                </button>
              )}
            </div>
          ))}
          {preview && selected && (
            <section className="stack">
              <div className="row">
                <label>
                  {copy.language}{' '}
                  <select
                    value={previewLocale}
                    onChange={(e) => setPreviewLocale(e.target.value as Locale)}
                  >
                    <option value="fr">Français</option>
                    <option value="en">English</option>
                  </select>
                </label>
                <label>
                  {copy.theme}{' '}
                  <select
                    value={previewTheme}
                    onChange={(e) => setPreviewTheme(e.target.value as 'light' | 'dark')}
                  >
                    <option value="light">{copy.light}</option>
                    <option value="dark">{copy.dark}</option>
                  </select>
                </label>
                <label>
                  {copy.preview}{' '}
                  <select value={size} onChange={(e) => setSize(e.target.value as typeof size)}>
                    {(['desktop', 'tablet', 'phone'] as const).map((k) => (
                      <option value={k} key={k}>
                        {copy[k]}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="checkbox-label">
                  <input
                    type="checkbox"
                    checked={reduced}
                    onChange={(e) => setReduced(e.target.checked)}
                  />
                  {copy.reduced}
                </label>
              </div>
              <div style={{ overflowX: 'auto' }}>
                <iframe
                  title={copy.preview}
                  src={
                    '/' +
                    previewLocale +
                    '/preview/' +
                    selected.id +
                    '?theme=' +
                    previewTheme +
                    '&reduced=' +
                    (reduced ? '1' : '0') +
                    '&v=' +
                    selected.version
                  }
                  width={size === 'phone' ? 390 : size === 'tablet' ? 768 : 1100}
                  height={900}
                  style={{ border: '1px solid var(--border)', borderRadius: 12 }}
                />
              </div>
            </section>
          )}
        </div>
      )}
    </div>
  );
}
