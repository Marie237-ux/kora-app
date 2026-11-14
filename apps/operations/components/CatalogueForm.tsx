'use client';
import type {
  CatalogueDraft,
  CatalogueEntity,
  CatalogueLookups,
  AttributeDefinition,
  VariantDraft,
  Locale,
  User,
} from '@kora/api-client';
import { MediaPicker } from './MediaLibrary';
import { catalogueCopy, doualaInput, utcInstant } from './catalogue-copy';
type Localized = { fr: string; en: string };
export const blankDraft = (entity: CatalogueEntity): CatalogueDraft => {
  const name = { fr: '', en: '' };
  switch (entity) {
    case 'suppliers':
      return {
        name: '',
        private_details: { contact_name: '', email: '', phone: '', address: '', legal_name: '' },
      };
    case 'shops':
      return { name: '', slug: '', supplier_id: '', description: { ...name } };
    case 'categories':
      return { name: { ...name }, slug: '', parent_id: null, attributes: [] };
    case 'products':
      return {
        name: { ...name },
        slug: '',
        category_id: '',
        description: { ...name },
        variants: [{ sku: '', attributes: {} }],
      };
    case 'offers':
      return {
        shop_id: '',
        variant_id: '',
        reference_price: 0,
        minimum_quantity: 1,
        availability: 'unconfirmed',
        warranty: { ...name },
        image_path: null,
        promotion: null,
      };
  }
};
export const recordName = (draft: CatalogueDraft, locale: Locale) =>
  typeof draft.name === 'string' ? draft.name : draft.name?.[locale] || draft.slug || '';
function LocalizedFields({
  label,
  value,
  onChange,
  area = false,
}: {
  label: string;
  value: Localized | undefined;
  onChange: (value: Localized) => void;
  area?: boolean;
}) {
  const current = value ?? { fr: '', en: '' };
  return (
    <fieldset className="editor-grid">
      <legend>{label}</legend>
      {(['fr', 'en'] as const).map((l) => (
        <label className="field" key={l}>
          {l === 'fr' ? 'Français' : 'English'}
          {area ? (
            <textarea
              value={current[l]}
              onChange={(e) => onChange({ ...current, [l]: e.target.value })}
            />
          ) : (
            <input
              value={current[l]}
              onChange={(e) => onChange({ ...current, [l]: e.target.value })}
            />
          )}
        </label>
      ))}
    </fieldset>
  );
}
export function CatalogueForm({
  entity,
  draft,
  setDraft,
  lookups,
  locale,
  user,
}: {
  entity: CatalogueEntity;
  draft: CatalogueDraft;
  setDraft: (draft: CatalogueDraft) => void;
  lookups: CatalogueLookups;
  locale: Locale;
  user: User;
}) {
  const c = catalogueCopy(locale),
    set = <K extends keyof CatalogueDraft>(key: K, value: CatalogueDraft[K]) =>
      setDraft({ ...draft, [key]: value });
  const select = (
    key: 'supplier_id' | 'shop_id' | 'category_id' | 'parent_id' | 'variant_id',
    items: { id: string; name: string }[],
    nullable = false,
  ) => (
    <label className="field">
      {c[key]}
      <select
        value={draft[key] ?? ''}
        required={!nullable}
        onChange={(e) => {
          if (key === 'category_id' && entity === 'products') {
            const keys = new Set(
              lookups.categories
                .find((c) => c.id === e.target.value)
                ?.attributes.map((a) => a.key) ?? [],
            );
            setDraft({
              ...draft,
              category_id: e.target.value,
              variants: draft.variants?.map((v) => ({
                ...v,
                attributes: Object.fromEntries(
                  Object.entries(v.attributes).filter(([key]) => keys.has(key)),
                ),
              })),
            });
          } else set(key, e.target.value || (null as never));
        }}
      >
        <option value="">{nullable ? c.none : '—'}</option>
        {items.map((item) => (
          <option key={item.id} value={item.id}>
            {item.name}
          </option>
        ))}
      </select>
    </label>
  );
  const names = lookups.categories.map((item) => ({ id: item.id, name: item.name[locale] }));
  return (
    <div className="stack">
      {entity !== 'offers' && (
        <>
          {entity === 'suppliers' || entity === 'shops' ? (
            <label className="field">
              {c.name}
              <input
                value={typeof draft.name === 'string' ? draft.name : ''}
                onChange={(e) => set('name', e.target.value)}
                maxLength={150}
                required
              />
            </label>
          ) : (
            <LocalizedFields
              label={c.name}
              value={typeof draft.name === 'object' ? draft.name : undefined}
              onChange={(value) => set('name', value)}
            />
          )}
          {entity !== 'suppliers' && (
            <label className="field">
              {c.slug}
              <input
                value={draft.slug ?? ''}
                onChange={(e) => set('slug', e.target.value)}
                maxLength={120}
                pattern="[a-z0-9]+(-[a-z0-9]+)*"
                required
              />
            </label>
          )}
        </>
      )}
      {entity === 'suppliers' && (
        <>
          <p className="notice">{c.privateHint}</p>
          <div className="editor-grid">
            {(['contact_name', 'legal_name', 'email', 'phone', 'address'] as const).map((key) => (
              <label className="field" key={key}>
                {c[key]}
                <input
                  type={key === 'email' ? 'email' : 'text'}
                  value={draft.private_details?.[key] ?? ''}
                  maxLength={key === 'address' ? 500 : 254}
                  onChange={(e) =>
                    set('private_details', { ...draft.private_details, [key]: e.target.value })
                  }
                />
              </label>
            ))}
          </div>
        </>
      )}
      {entity === 'shops' && (
        <>
          {select('supplier_id', lookups.suppliers)}
          <LocalizedFields
            label={c.description}
            value={draft.description}
            onChange={(value) => set('description', value)}
            area
          />
        </>
      )}
      {entity === 'categories' && (
        <>
          {select('parent_id', names, true)}
          <h3>{c.attributes}</h3>
          <p className="muted">{c.inherited}</p>
          {draft.attributes?.map((attribute, index) => (
            <AttributeEditor
              key={index}
              value={attribute}
              locale={locale}
              onChange={(value) =>
                set(
                  'attributes',
                  draft.attributes?.map((old, i) => (i === index ? value : old)),
                )
              }
              remove={() =>
                set(
                  'attributes',
                  draft.attributes?.filter((_, i) => i !== index),
                )
              }
            />
          ))}
          <button
            type="button"
            onClick={() =>
              set('attributes', [
                ...(draft.attributes ?? []),
                {
                  key: '',
                  type: 'text',
                  label: { fr: '', en: '' },
                  unit: null,
                  required: false,
                  filterable: false,
                  options: [],
                },
              ])
            }
          >
            {c.add} · {c.attributes}
          </button>
        </>
      )}
      {entity === 'products' && (
        <>
          {select('category_id', names)}
          <LocalizedFields
            label={c.description}
            value={draft.description}
            onChange={(value) => set('description', value)}
            area
          />
          <h3>{c.variants}</h3>
          {!draft.category_id && <p>{c.chooseCategory}</p>}
          {draft.variants?.map((variant, index) => (
            <VariantEditor
              key={variant.id ?? index}
              value={variant}
              definitions={
                lookups.categories.find((item) => item.id === draft.category_id)?.attributes ?? []
              }
              locale={locale}
              onChange={(value) =>
                set(
                  'variants',
                  draft.variants?.map((old, i) => (i === index ? value : old)),
                )
              }
              remove={() =>
                set(
                  'variants',
                  draft.variants?.filter((_, i) => i !== index),
                )
              }
            />
          ))}
          <button
            type="button"
            onClick={() =>
              set('variants', [...(draft.variants ?? []), { sku: '', attributes: {} }])
            }
          >
            {c.add} · {c.variants}
          </button>
        </>
      )}
      {entity === 'offers' && (
        <>
          <div className="editor-grid">
            {select('shop_id', lookups.shops)}
            {select(
              'variant_id',
              lookups.variants.map((item) => ({
                id: item.id,
                name: item.product_name[locale] + ' · ' + item.sku,
              })),
            )}
            <label className="field">
              {c.reference_price}
              <input
                type="number"
                min={1}
                max={1000000000000}
                step={1}
                required
                value={draft.reference_price || ''}
                onChange={(e) => set('reference_price', Number(e.target.value))}
              />
            </label>
            <label className="field">
              {c.minimum_quantity}
              <input
                type="number"
                min={1}
                max={1000000}
                required
                value={draft.minimum_quantity ?? 1}
                onChange={(e) => set('minimum_quantity', Number(e.target.value))}
              />
            </label>
            <label className="field">
              {c.availability}
              <select
                value={draft.availability}
                onChange={(e) =>
                  set('availability', e.target.value as CatalogueDraft['availability'])
                }
              >
                {(['available', 'unconfirmed', 'unavailable'] as const).map((value) => (
                  <option key={value} value={value}>
                    {c[value]}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <LocalizedFields
            label={c.warranty}
            value={draft.warranty}
            onChange={(value) => set('warranty', value)}
            area
          />
          <h3>{c.image_path}</h3>
          <MediaPicker
            locale={locale}
            user={user}
            value={draft.image_path}
            onChange={(value) => set('image_path', value)}
          />
          <label className="checkbox-label">
            <input
              type="checkbox"
              checked={!!draft.promotion}
              onChange={(e) =>
                set(
                  'promotion',
                  e.target.checked
                    ? {
                        price: 0,
                        starts_at: new Date().toISOString(),
                        ends_at: new Date(Date.now() + 86400000).toISOString(),
                        kora_funding: 0,
                        supplier_funding: 0,
                        contract_reference: null,
                        negative_margin_reason: null,
                      }
                    : null,
                )
              }
            />
            {c.promotion}
          </label>
          {draft.promotion && (
            <div className="editor-panel stack">
              <p>{c.promotionHint}</p>
              <div className="editor-grid">
                {(['price', 'kora_funding', 'supplier_funding'] as const).map((key) => (
                  <label className="field" key={key}>
                    {c[key]}
                    <input
                      type="number"
                      min={key === 'price' ? 1 : 0}
                      max={1000000000000}
                      step={1}
                      required
                      value={draft.promotion![key]}
                      onChange={(e) =>
                        set('promotion', { ...draft.promotion!, [key]: Number(e.target.value) })
                      }
                    />
                  </label>
                ))}
                {(['starts_at', 'ends_at'] as const).map((key) => (
                  <label className="field" key={key}>
                    {c[key]}
                    <input
                      type="datetime-local"
                      required
                      value={doualaInput(draft.promotion![key])}
                      onChange={(e) =>
                        set('promotion', {
                          ...draft.promotion!,
                          [key]: utcInstant(e.target.value) ?? '',
                        })
                      }
                    />
                  </label>
                ))}
                <label className="field">
                  {c.contract_reference}
                  <input
                    value={draft.promotion.contract_reference ?? ''}
                    maxLength={150}
                    onChange={(e) =>
                      set('promotion', {
                        ...draft.promotion!,
                        contract_reference: e.target.value || null,
                      })
                    }
                  />
                </label>
                {user.permissions.includes('pricing.loss_override') && (
                  <label className="field">
                    {c.negative_margin_reason}
                    <textarea
                      value={draft.promotion.negative_margin_reason ?? ''}
                      maxLength={1000}
                      onChange={(e) =>
                        set('promotion', {
                          ...draft.promotion!,
                          negative_margin_reason: e.target.value || null,
                        })
                      }
                    />
                  </label>
                )}
              </div>
            </div>
          )}
        </>
      )}
      {entity !== 'suppliers' && <p className="muted">{c.translation}</p>}
    </div>
  );
}
function AttributeEditor({
  value,
  onChange,
  remove,
  locale,
}: {
  value: AttributeDefinition;
  onChange: (value: AttributeDefinition) => void;
  remove: () => void;
  locale: Locale;
}) {
  const c = catalogueCopy(locale),
    set = <K extends keyof AttributeDefinition>(key: K, next: AttributeDefinition[K]) =>
      onChange({ ...value, [key]: next });
  return (
    <fieldset className="editor-panel stack">
      <legend>{value.label[locale] || c.attributes}</legend>
      <div className="editor-grid">
        <label className="field">
          {c.key}
          <input
            required
            pattern="[a-z][a-z0-9_]*"
            maxLength={40}
            value={value.key}
            onChange={(e) => set('key', e.target.value)}
          />
        </label>
        <label className="field">
          {c.type}
          <select
            value={value.type}
            onChange={(e) =>
              onChange({
                ...value,
                type: e.target.value as AttributeDefinition['type'],
                options: e.target.value === 'choice' ? value.options : [],
              })
            }
          >
            {(['text', 'number', 'boolean', 'choice'] as const).map((type) => (
              <option key={type} value={type}>
                {c[type]}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          {c.unit}
          <input
            maxLength={24}
            value={value.unit ?? ''}
            onChange={(e) => set('unit', e.target.value || null)}
          />
        </label>
      </div>
      <LocalizedFields
        label={c.label}
        value={value.label}
        onChange={(next) => set('label', next)}
      />
      <div className="row">
        {(['required', 'filterable'] as const).map((key) => (
          <label key={key} className="checkbox-label">
            <input
              type="checkbox"
              checked={value[key]}
              onChange={(e) => set(key, e.target.checked)}
            />
            {c[key]}
          </label>
        ))}
      </div>
      {value.type === 'choice' && (
        <div className="stack">
          <h4>{c.options}</h4>
          {value.options.map((option, index) => (
            <fieldset className="stack" key={index}>
              <label className="field">
                {c.value}
                <input
                  required
                  value={option.value}
                  maxLength={80}
                  pattern="[a-zA-Z0-9_-]+"
                  onChange={(e) =>
                    set(
                      'options',
                      value.options.map((old, i) =>
                        i === index ? { ...old, value: e.target.value } : old,
                      ),
                    )
                  }
                />
              </label>
              <LocalizedFields
                label={c.label}
                value={option.label}
                onChange={(next) =>
                  set(
                    'options',
                    value.options.map((old, i) => (i === index ? { ...old, label: next } : old)),
                  )
                }
              />
              <button
                type="button"
                onClick={() =>
                  set(
                    'options',
                    value.options.filter((_, i) => i !== index),
                  )
                }
              >
                {c.remove}
              </button>
            </fieldset>
          ))}
          <button
            type="button"
            onClick={() =>
              set('options', [...value.options, { value: '', label: { fr: '', en: '' } }])
            }
          >
            {c.add} · {c.options}
          </button>
        </div>
      )}
      <button type="button" onClick={remove}>
        {c.remove}
      </button>
    </fieldset>
  );
}
function VariantEditor({
  value,
  definitions,
  onChange,
  remove,
  locale,
}: {
  value: VariantDraft;
  definitions: AttributeDefinition[];
  onChange: (value: VariantDraft) => void;
  remove: () => void;
  locale: Locale;
}) {
  const c = catalogueCopy(locale);
  function attribute(key: string, next: string | boolean | undefined) {
    const attributes = { ...value.attributes };
    if (next === undefined || next === '') delete attributes[key];
    else attributes[key] = next;
    onChange({ ...value, attributes });
  }
  return (
    <fieldset className="editor-panel stack">
      <legend>{value.sku || c.variants}</legend>
      <label className="field">
        {c.sku}
        <input
          required
          maxLength={100}
          pattern="[a-zA-Z0-9_.-]+"
          value={value.sku}
          onChange={(e) => onChange({ ...value, sku: e.target.value })}
        />
      </label>
      {!definitions.length && <p className="muted">{c.noAttributes}</p>}
      <div className="editor-grid">
        {definitions.map((def) => (
          <label className="field" key={def.key}>
            {def.label[locale]}
            {def.unit ? ' (' + def.unit + ')' : ''}
            {def.required ? ' *' : ''}
            {def.type === 'boolean' ? (
              <select
                value={
                  value.attributes[def.key] === undefined ? '' : String(value.attributes[def.key])
                }
                onChange={(e) =>
                  attribute(def.key, e.target.value === '' ? undefined : e.target.value === 'true')
                }
              >
                <option value="">—</option>
                <option value="true">{c.yes}</option>
                <option value="false">{c.no}</option>
              </select>
            ) : def.type === 'choice' ? (
              <select
                value={String(value.attributes[def.key] ?? '')}
                onChange={(e) => attribute(def.key, e.target.value)}
              >
                <option value="">—</option>
                {def.options.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label[locale]}
                  </option>
                ))}
              </select>
            ) : (
              <input
                value={String(value.attributes[def.key] ?? '')}
                inputMode={def.type === 'number' ? 'decimal' : 'text'}
                maxLength={500}
                onChange={(e) => attribute(def.key, e.target.value)}
              />
            )}
          </label>
        ))}
      </div>
      <button type="button" onClick={remove}>
        {c.remove}
      </button>
    </fieldset>
  );
}
