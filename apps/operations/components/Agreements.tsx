'use client';
import { useEffect, useState, type FormEvent } from 'react';
import {
  ApiError,
  createApi,
  type Locale,
  type User,
  type AgreementInput,
  type PurchaseAgreement,
  type CatalogueLookups,
  type PageMeta,
} from '@kora/api-client';
import { formatXaf } from '@kora/design-tokens';
import { catalogueCopy, displayDate, doualaInput, utcInstant } from './catalogue-copy';
export function percentToBasisPoints(value: string): number | null {
  const match = /^(\d{1,3})(?:[.,](\d{1,2}))?$/.exec(value.trim());
  if (!match) return null;
  const basis = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
  return basis <= 10000 ? basis : null;
}
export function Agreements({ locale, user }: { locale: Locale; user: User }) {
  const c = catalogueCopy(locale),
    api = createApi('', locale),
    canWrite = user.permissions.includes('pricing.write');
  const [lookups, setLookups] = useState<CatalogueLookups>({
      suppliers: [],
      shops: [],
      categories: [],
      variants: [],
    }),
    [items, setItems] = useState<PurchaseAgreement[]>([]),
    [meta, setMeta] = useState<PageMeta | null>(null),
    [page, setPage] = useState(1),
    [filter, setFilter] = useState(''),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(''),
    [status, setStatus] = useState(''),
    [scope, setScope] = useState('general'),
    [rate, setRate] = useState(''),
    [draft, setDraft] = useState<AgreementInput>({
      supplier_id: '',
      category_id: null,
      product_id: null,
      minimum_quantity: 1,
      priority: 0,
      mode: 'discount',
      discount_basis_points: 0,
      fixed_cost: null,
      starts_at: new Date().toISOString(),
      ends_at: null,
      contract_reference: '',
    });
  function fail(e: unknown) {
    setError(
      e instanceof ApiError
        ? [e.message, ...Object.values(e.fields).flat()].filter(Boolean).join(' ') || c.error
        : c.error,
    );
  }
  async function load() {
    setLoading(true);
    try {
      const [r, l] = await Promise.all([api.agreements(page, filter), api.lookups()]);
      setItems(r.data);
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
  }, [locale, page, filter]);
  async function save(e: FormEvent) {
    e.preventDefault();
    setError('');
    setStatus('');
    const basis = percentToBasisPoints(rate);
    if (draft.mode === 'discount' && basis === null) {
      setError(c.rateInvalid);
      return;
    }
    setBusy(true);
    try {
      await api.createAgreement({
        ...draft,
        discount_basis_points: draft.mode === 'fixed' ? 0 : basis!,
        fixed_cost: draft.mode === 'discount' ? null : draft.fixed_cost,
        category_id: scope === 'category' ? draft.category_id : null,
        product_id: scope === 'product' ? draft.product_id : null,
      });
      setStatus(c.agreementSaved);
      setDraft((old) => ({ ...old, contract_reference: '' }));
      if (page === 1) await load();
      else setPage(1);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function withdraw(item: PurchaseAgreement) {
    if (!window.confirm(c.confirmWithdraw)) return;
    setBusy(true);
    setError('');
    try {
      await api.request('/api/v1/operations/pricing/agreements/' + item.id + '/withdraw', {
        method: 'POST',
      });
      await load();
      setStatus(c.changed);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  const products = [
    ...new Map(
      lookups.variants.map((v) => [
        v.product_id,
        { id: v.product_id, name: v.product_name[locale] },
      ]),
    ).values(),
  ];
  return (
    <section className="stack">
      <div>
        <p className="eyebrow">KORA</p>
        <h1 className="admin-title">{c.agreements}</h1>
        <p className="muted">{c.agreementHint}</p>
      </div>
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
        <form className="editor-panel stack" onSubmit={save}>
          <h2>{c.create}</h2>
          <fieldset disabled={busy} className="form-fields editor-grid">
            <label className="field">
              {c.supplier_id}
              <select
                required
                value={draft.supplier_id}
                onChange={(e) => setDraft({ ...draft, supplier_id: e.target.value })}
              >
                <option value="">—</option>
                {lookups.suppliers.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              {c.scope}
              <select value={scope} onChange={(e) => setScope(e.target.value)}>
                {(['general', 'category', 'product'] as const).map((s) => (
                  <option key={s} value={s}>
                    {c[s]}
                  </option>
                ))}
              </select>
            </label>
            {scope === 'category' && (
              <label className="field">
                {c.category_id}
                <select
                  required
                  value={draft.category_id ?? ''}
                  onChange={(e) => setDraft({ ...draft, category_id: e.target.value })}
                >
                  <option value="">—</option>
                  {lookups.categories.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name[locale]}
                    </option>
                  ))}
                </select>
              </label>
            )}
            {scope === 'product' && (
              <label className="field">
                {c.product_id}
                <select
                  required
                  value={draft.product_id ?? ''}
                  onChange={(e) => setDraft({ ...draft, product_id: e.target.value })}
                >
                  <option value="">—</option>
                  {products.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <label className="field">
              {c.mode}
              <select
                value={draft.mode}
                onChange={(e) =>
                  setDraft({ ...draft, mode: e.target.value as AgreementInput['mode'] })
                }
              >
                <option value="discount">{c.discount}</option>
                <option value="fixed">{c.fixed}</option>
              </select>
            </label>
            {draft.mode === 'discount' ? (
              <label className="field">
                {c.rate}
                <input
                  inputMode="decimal"
                  required
                  value={rate}
                  maxLength={6}
                  onChange={(e) => setRate(e.target.value)}
                />
              </label>
            ) : (
              <label className="field">
                {c.fixed_cost}
                <input
                  type="number"
                  min={0}
                  max={1000000000000}
                  step={1}
                  required
                  value={draft.fixed_cost ?? ''}
                  onChange={(e) =>
                    setDraft({
                      ...draft,
                      fixed_cost: e.target.value === '' ? null : Number(e.target.value),
                    })
                  }
                />
              </label>
            )}
            {(['minimum_quantity', 'priority'] as const).map((key) => (
              <label className="field" key={key}>
                {c[key]}
                <input
                  type="number"
                  min={key === 'priority' ? 0 : 1}
                  max={key === 'priority' ? 100 : 1000000}
                  required
                  value={draft[key]}
                  onChange={(e) => setDraft({ ...draft, [key]: Number(e.target.value) })}
                />
              </label>
            ))}
            <label className="field">
              {c.starts_at}
              <input
                type="datetime-local"
                required
                value={doualaInput(draft.starts_at)}
                onChange={(e) =>
                  setDraft({ ...draft, starts_at: utcInstant(e.target.value) ?? '' })
                }
              />
            </label>
            <label className="field">
              {c.ends_at}
              <input
                type="datetime-local"
                value={doualaInput(draft.ends_at)}
                onChange={(e) => setDraft({ ...draft, ends_at: utcInstant(e.target.value) })}
              />
            </label>
            <label className="field">
              {c.contract_reference}
              <input
                required
                maxLength={150}
                value={draft.contract_reference}
                onChange={(e) => setDraft({ ...draft, contract_reference: e.target.value })}
              />
            </label>
          </fieldset>
          <button className="primary" disabled={busy}>
            {busy ? c.loading : c.agreementSave}
          </button>
        </form>
      )}
      <label className="field">
        {c.supplier_id}
        <select
          value={filter}
          onChange={(e) => {
            setFilter(e.target.value);
            setPage(1);
          }}
        >
          <option value="">{c.all}</option>
          {lookups.suppliers.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>
      </label>
      {loading ? (
        <p role="status">{c.loading}</p>
      ) : !items.length ? (
        <p className="empty">{c.empty}</p>
      ) : (
        <div className="scene-list">
          {items.map((item) => (
            <article key={item.id} className="scene-item stack">
              <div className="row spread">
                <strong>{item.contract_reference}</strong>
                <span className="badge">{item.withdrawn_at ? c.withdrawn : c.source}</span>
              </div>
              <p>
                {lookups.suppliers.find((s) => s.id === item.supplier_id)?.name ?? item.supplier_id}{' '}
                ·{' '}
                {item.product_id
                  ? (products.find((p) => p.id === item.product_id)?.name ?? item.product_id)
                  : item.category_id
                    ? (lookups.categories.find((cat) => cat.id === item.category_id)?.name[
                        locale
                      ] ?? item.category_id)
                    : c.general}
              </p>
              <p>
                {item.mode === 'discount'
                  ? (item.discount_basis_points / 100).toLocaleString(locale + '-CM') + ' %'
                  : formatXaf(item.fixed_cost ?? 0, locale)}{' '}
                · {c.minimum_quantity}: {item.minimum_quantity} · {c.priority}: {item.priority}
              </p>
              <p className="muted">
                {c.effective}: {displayDate(item.starts_at, locale)} →{' '}
                {item.ends_at ? displayDate(item.ends_at, locale) : '∞'}
              </p>
              {canWrite && !item.withdrawn_at && (
                <button type="button" disabled={busy} onClick={() => void withdraw(item)}>
                  {c.withdrawAgreement}
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
