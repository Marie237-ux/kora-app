import { useState } from 'react';
import type { Locale, PublicCategory } from '@kora/api-client';
import { t } from '@kora/ui';
export function CatalogueFilters({
  locale,
  categories,
  search,
}: {
  locale: Locale;
  categories: PublicCategory[];
  search: string;
}) {
  const c = t(locale),
    initial = new URLSearchParams(search);
  const [category, setCategory] = useState(initial.get('category') ?? ''),
    [values, setValues] = useState<Record<string, string>>(() =>
      Object.fromEntries(
        [...initial]
          .filter(([key]) => /^filters\[[a-z][a-z0-9_]*\]$/.test(key))
          .map(([key, value]) => [key.slice(8, -1), value]),
      ),
    );
  const selected = categories.find((item) => item.slug === category),
    label =
      locale === 'fr'
        ? { yes: 'Oui', no: 'Non', apply: 'Appliquer les filtres' }
        : { yes: 'Yes', no: 'No', apply: 'Apply filters' };
  return (
    <form className="filters" method="get">
      <label className="field">
        {c.search}
        <input
          name="q"
          type="search"
          defaultValue={initial.get('q') ?? ''}
          placeholder={c.searchPlaceholder}
          maxLength={150}
        />
      </label>
      <label className="field">
        {c.category}
        <select
          name="category"
          value={category}
          onChange={(e) => {
            setCategory(e.target.value);
            setValues({});
          }}
        >
          <option value="">{c.all}</option>
          {categories.map((item) => (
            <option key={item.id} value={item.slug}>
              {item.name}
            </option>
          ))}
        </select>
      </label>
      <label className="field">
        {c.sort}
        <select name="sort" defaultValue={initial.get('sort') ?? 'name'}>
          {[
            ['name', c.sortName],
            ['price_asc', c.priceAsc],
            ['price_desc', c.priceDesc],
          ].map(([value, title]) => (
            <option key={value} value={value}>
              {title}
            </option>
          ))}
        </select>
      </label>
      {initial.get('shop') && <input type="hidden" name="shop" value={initial.get('shop')!} />}
      {selected?.attributes
        .filter((def) => def.filterable)
        .map((def) => (
          <label className="field" key={def.key}>
            {def.label}
            {def.unit ? ' (' + def.unit + ')' : ''}
            {def.type === 'boolean' || def.type === 'choice' ? (
              <select
                name={'filters[' + def.key + ']'}
                value={values[def.key] ?? ''}
                onChange={(e) => setValues({ ...values, [def.key]: e.target.value })}
              >
                <option value="">{c.all}</option>
                {def.type === 'boolean' ? (
                  <>
                    <option value="true">{label.yes}</option>
                    <option value="false">{label.no}</option>
                  </>
                ) : (
                  def.options.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))
                )}
              </select>
            ) : (
              <input
                name={'filters[' + def.key + ']'}
                value={values[def.key] ?? ''}
                inputMode={def.type === 'number' ? 'decimal' : 'text'}
                maxLength={500}
                onChange={(e) => setValues({ ...values, [def.key]: e.target.value })}
              />
            )}
          </label>
        ))}
      <button className="primary">{label.apply}</button>
    </form>
  );
}
