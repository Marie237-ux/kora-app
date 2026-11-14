'use client';
import { useEffect, useState, type FormEvent } from 'react';
import {
  ApiError,
  createApi,
  type CatalogueEntity,
  type CatalogueDraft,
  type CatalogueRecord,
  type CatalogueLookups,
  type PricePreview,
  type PageMeta,
  type Locale,
  type User,
} from '@kora/api-client';
import { formatXaf } from '@kora/design-tokens';
import { CatalogueForm, blankDraft, recordName } from './CatalogueForm';
import { catalogueCopy, displayDate } from './catalogue-copy';
const noLookups: CatalogueLookups = { suppliers: [], shops: [], categories: [], variants: [] };
type Revision = { version: number; action: string; snapshot: unknown; created_at: string };
export function CatalogueEditor({
  locale,
  user,
  entity,
  onDirtyChange,
}: {
  locale: Locale;
  user: User;
  entity: CatalogueEntity;
  onDirtyChange: (dirty: boolean) => void;
}) {
  const c = catalogueCopy(locale),
    api = createApi('', locale),
    supplier = entity === 'suppliers',
    canWrite = user.permissions.includes(supplier ? 'purchases.write' : 'catalogue.write'),
    canPublish = user.permissions.includes(supplier ? 'purchases.write' : 'catalogue.publish');
  const [records, setRecords] = useState<CatalogueRecord[]>([]),
    [lookups, setLookups] = useState(noLookups),
    [meta, setMeta] = useState<PageMeta | null>(null),
    [page, setPage] = useState(1),
    [search, setSearch] = useState(''),
    [query, setQuery] = useState(''),
    [filter, setFilter] = useState(''),
    [selected, setSelected] = useState<CatalogueRecord | null>(null),
    [draft, setDraft] = useState<CatalogueDraft>(blankDraft(entity)),
    [editing, setEditing] = useState(false),
    [loading, setLoading] = useState(true),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(''),
    [status, setStatus] = useState(''),
    [fields, setFields] = useState<Record<string, string[]>>({}),
    [quote, setQuote] = useState<PricePreview | null>(null),
    [quantity, setQuantity] = useState(1),
    [history, setHistory] = useState<Revision[]>([]),
    [historyPage, setHistoryPage] = useState<PageMeta | null>(null);
  const dirty =
    editing && JSON.stringify(draft) !== JSON.stringify(selected?.draft ?? blankDraft(entity));
  useEffect(() => {
    onDirtyChange(dirty);
    return () => onDirtyChange(false);
  }, [dirty, onDirtyChange]);
  function fail(e: unknown) {
    setError(
      e instanceof ApiError ? (e.status === 409 ? c.conflict : e.message || c.error) : c.error,
    );
    setFields(e instanceof ApiError ? e.fields : {});
  }
  async function load() {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page: String(page), q: query, status: filter });
      const [r, l] = await Promise.all([
        api.records(entity, '?' + params),
        user.permissions.includes('catalogue.read')
          ? api.lookups()
          : Promise.resolve({ data: noLookups }),
      ]);
      setRecords(r.data);
      setMeta(r.meta);
      setLookups(l.data);
    } catch (e) {
      fail(e);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    void load();
  }, [entity, locale, page, query, filter]);
  useEffect(() => {
    if (!dirty) return;
    const guard = (event: BeforeUnloadEvent) => {
      event.preventDefault();
    };
    window.addEventListener('beforeunload', guard);
    return () => window.removeEventListener('beforeunload', guard);
  }, [dirty]);
  function open(record: CatalogueRecord | null) {
    if (dirty && !window.confirm(c.discard)) return;
    setSelected(record);
    setDraft(structuredClone(record?.draft ?? blankDraft(entity)));
    setEditing(true);
    setQuote(null);
    setHistory([]);
    setHistoryPage(null);
    setError('');
    setFields({});
    setStatus('');
  }
  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError('');
    setStatus('');
    setFields({});
    try {
      const r = await api.saveRecord(entity, draft, selected);
      setSelected(r.data);
      setDraft(r.data.draft);
      setQuote(null);
      setStatus(c.saved);
      await load();
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function change(action: 'publish' | 'archive') {
    if (!selected || !window.confirm(action === 'publish' ? c.confirmPublish : c.confirmArchive))
      return;
    setBusy(true);
    setError('');
    setFields({});
    try {
      const r = await api.changeRecord(selected, action);
      setSelected(r.data);
      setDraft(r.data.draft);
      setQuote(null);
      setStatus(c.changed);
      await load();
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function price() {
    if (!selected) return;
    setBusy(true);
    setError('');
    try {
      setQuote((await api.pricePreview(selected.id, quantity)).data);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function revisions(page = 1) {
    if (!selected) return;
    setBusy(true);
    try {
      const r = await api.request<{ data: Revision[]; meta: PageMeta }>(
        '/api/v1/operations/catalogue/' + entity + '/' + selected.id + '/revisions?page=' + page,
      );
      setHistory(r.data);
      setHistoryPage(r.meta);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  const title = (record: CatalogueRecord) =>
    recordName(record.draft, locale) ||
    (lookups.variants.find((v) => v.id === record.draft.variant_id)?.sku ?? record.id);
  return (
    <section className="stack">
      <div className="row spread">
        <div>
          <p className="eyebrow">{c.catalogue}</p>
          <h1 className="admin-title">{c[entity]}</h1>
          <p className="muted">{supplier ? c.privateHint : c.intro}</p>
        </div>
        {canWrite && (
          <button type="button" className="primary" disabled={busy} onClick={() => open(null)}>
            {c.create}
          </button>
        )}
      </div>
      {error && (
        <div className="error" role="alert">
          <p>{error}</p>
          {Object.entries(fields).map(([field, messages]) => (
            <p key={field}>
              {field}: {messages.join(' ')}
            </p>
          ))}
          <button type="button" disabled={busy} onClick={() => void load()}>
            {c.reload}
          </button>
        </div>
      )}
      {status && (
        <p className="success" role="status">
          {status}
        </p>
      )}
      <form
        className="filters"
        onSubmit={(e) => {
          e.preventDefault();
          setQuery(search);
          setPage(1);
        }}
      >
        <label className="field">
          {c.search}
          <input value={search} maxLength={120} onChange={(e) => setSearch(e.target.value)} />
        </label>
        <label className="field">
          {c.all}
          <select
            value={filter}
            onChange={(e) => {
              setFilter(e.target.value);
              setPage(1);
            }}
          >
            <option value="">{c.all}</option>
            {(supplier
              ? (['active', 'archived'] as const)
              : (['draft', 'published', 'archived'] as const)
            ).map((value) => (
              <option key={value} value={value}>
                {c[value]}
              </option>
            ))}
          </select>
        </label>
        <button disabled={busy}>{c.search}</button>
      </form>
      {loading ? (
        <p role="status">{c.loading}</p>
      ) : !records.length ? (
        <p className="empty">{c.empty}</p>
      ) : (
        <div className="scene-list">
          {records.map((record) => (
            <article className="scene-item row spread" key={record.id}>
              <div>
                <strong>{title(record)}</strong>
                <p className="record-meta">
                  <span className="badge">{c[record.status]}</span> · {c.version} {record.version}
                  {record.has_unpublished_changes && <span className="badge">{c.pending}</span>}
                </p>
              </div>
              <button type="button" disabled={busy} onClick={() => open(record)}>
                {c.edit}
              </button>
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
      {editing && (
        <div className="editor-panel stack">
          <h2>{selected ? title(selected) : c.create + ' · ' + c[entity]}</h2>
          <form className="stack" onSubmit={save}>
            <fieldset disabled={busy || !canWrite} className="form-fields">
              <CatalogueForm
                entity={entity}
                draft={draft}
                setDraft={setDraft}
                lookups={lookups}
                locale={locale}
                user={user}
              />
            </fieldset>
            <div className="row">
              {canWrite && (
                <button className="primary" disabled={busy}>
                  {busy
                    ? c.loading
                    : supplier
                      ? locale === 'fr'
                        ? 'Enregistrer'
                        : 'Save'
                      : c.save}
                </button>
              )}
              <button
                type="button"
                disabled={busy}
                onClick={() => {
                  if (!dirty || window.confirm(c.discard)) setEditing(false);
                }}
              >
                {c.close}
              </button>
              {selected && (
                <>
                  {canPublish && (
                    <>
                      <button
                        type="button"
                        className="accent"
                        disabled={busy || dirty}
                        onClick={() => void change('publish')}
                      >
                        {c.publish}
                      </button>
                      <button
                        type="button"
                        disabled={busy || dirty}
                        onClick={() => void change('archive')}
                      >
                        {c.archive}
                      </button>
                    </>
                  )}
                  <button type="button" disabled={busy} onClick={() => void revisions()}>
                    {c.history}
                  </button>
                </>
              )}
            </div>
            {dirty && <p className="muted">{c.unsaved}</p>}
          </form>
          {entity === 'offers' && selected && user.permissions.includes('pricing.read') && (
            <section className="stack">
              <div className="row">
                <label className="field">
                  {c.quantity}
                  <input
                    type="number"
                    min={1}
                    max={1000000}
                    value={quantity}
                    onChange={(e) => {
                      setQuantity(Number(e.target.value));
                      setQuote(null);
                    }}
                  />
                </label>
                <button
                  type="button"
                  disabled={busy || dirty || !Number.isInteger(quantity) || quantity < 1}
                  onClick={() => void price()}
                >
                  {c.preview}
                </button>
              </div>
              <p className="muted">{c.quoteHint}</p>
              {quote && (
                <div className="notice">
                  <p>
                    {c.resolved}: {quote.agreement.contract_reference}
                  </p>
                  <dl className="price-summary">
                    <dt>{c.purchase}</dt>
                    <dd>{formatXaf(quote.calculation.purchase_unit, locale)}</dd>
                    <dt>{c.selling}</dt>
                    <dd>{formatXaf(quote.calculation.selling_unit, locale)}</dd>
                    <dt>{c.margin}</dt>
                    <dd>{formatXaf(quote.calculation.gross_difference, locale)}</dd>
                  </dl>
                  {quote.scheduled_promotion && (
                    <div>
                      <h3>
                        {c.promotion} · {displayDate(quote.scheduled_promotion.as_of, locale)}
                      </h3>
                      <p>
                        {c.resolved}: {quote.scheduled_promotion.agreement.contract_reference}
                      </p>
                      <dl className="price-summary">
                        <dt>{c.purchase}</dt>
                        <dd>
                          {formatXaf(quote.scheduled_promotion.calculation.purchase_unit, locale)}
                        </dd>
                        <dt>{c.selling}</dt>
                        <dd>
                          {formatXaf(quote.scheduled_promotion.calculation.selling_unit, locale)}
                        </dd>
                        <dt>{c.margin}</dt>
                        <dd>
                          {formatXaf(
                            quote.scheduled_promotion.calculation.gross_difference,
                            locale,
                          )}
                        </dd>
                      </dl>
                    </div>
                  )}
                  {(quote.negative_margin || quote.scheduled_promotion?.negative_margin) && (
                    <p className="error" role="alert">
                      {c.negative}
                    </p>
                  )}
                </div>
              )}
            </section>
          )}
          {!!history.length && (
            <section className="stack">
              <h3>{c.history}</h3>
              {history.map((r) => (
                <details key={r.version}>
                  <summary>
                    {c.version} {r.version} · {r.action} · {displayDate(r.created_at, locale)}
                  </summary>
                  <pre className="revision-data">{JSON.stringify(r.snapshot, null, 2)}</pre>
                </details>
              ))}
              {historyPage && historyPage.last_page > 1 && (
                <div className="row">
                  <button
                    type="button"
                    disabled={busy || historyPage.page === 1}
                    onClick={() => void revisions(historyPage.page - 1)}
                  >
                    {c.previous}
                  </button>
                  <span>
                    {historyPage.page}/{historyPage.last_page}
                  </span>
                  <button
                    type="button"
                    disabled={busy || historyPage.page === historyPage.last_page}
                    onClick={() => void revisions(historyPage.page + 1)}
                  >
                    {c.next}
                  </button>
                </div>
              )}
            </section>
          )}
        </div>
      )}
    </section>
  );
}
