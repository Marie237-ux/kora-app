// Vérifie le HTML serveur et les conversions de saisie, sans simuler un navigateur.
const test = require('node:test'),
  assert = require('node:assert/strict'),
  fs = require('node:fs'),
  ts = require('typescript');
const React = require('react'),
  { renderToStaticMarkup } = require('react-dom/server');
for (const extension of ['.ts', '.tsx'])
  require.extensions[extension] = (module, filename) => {
    const output = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
      compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
        jsx: ts.JsxEmit.ReactJSX,
        esModuleInterop: true,
      },
    }).outputText;
    module._compile(output, filename);
  };
const { CatalogueFilters } = require('../apps/storefront/src/components/CatalogueFilters.tsx');
const { CatalogueForm } = require('../apps/operations/components/CatalogueForm.tsx');
const { percentToBasisPoints } = require('../apps/operations/components/Agreements.tsx');
const { doualaInput, utcInstant } = require('../apps/operations/components/catalogue-copy.ts');
const { formatXaf } = require('../packages/design-tokens/src/index.ts');
const definition = {
  key: 'material_fixture',
  type: 'choice',
  label: { fr: 'Matière de test', en: 'Test material' },
  unit: null,
  required: true,
  filterable: true,
  options: [
    { value: 'cotton_fixture', label: { fr: 'Coton test', en: 'Test cotton' } },
    { value: 'linen_fixture', label: { fr: 'Lin test', en: 'Test linen' } },
  ],
};
test('Category definitions generate both variant inputs and localized public filters with their selected values', () => {
  for (const locale of ['fr', 'en']) {
    const category = {
      id: 'category-fixture',
      slug: 'category-fixture',
      name: { fr: 'Test FR', en: 'Test EN' },
      parent_id: null,
      attributes: [definition],
    };
    const draft = {
      category_id: category.id,
      slug: 'product-fixture',
      name: { fr: 'Test', en: 'Test' },
      description: { fr: 'Test', en: 'Test' },
      variants: [{ sku: 'FIXTURE-1', attributes: { material_fixture: 'linen_fixture' } }],
    };
    const form = renderToStaticMarkup(
      React.createElement(CatalogueForm, {
        entity: 'products',
        draft,
        setDraft: () => {},
        lookups: { suppliers: [], shops: [], categories: [category], variants: [] },
        locale,
        user: { permissions: [] },
      }),
    );
    assert.ok(form.includes(definition.label[locale]));
    assert.match(form, /value="linen_fixture" selected/);
    const publicCategory = {
      ...category,
      name: category.name[locale],
      attributes: [
        {
          ...definition,
          label: definition.label[locale],
          options: definition.options.map((o) => ({ ...o, label: o.label[locale] })),
        },
      ],
    };
    const filters = renderToStaticMarkup(
      React.createElement(CatalogueFilters, {
        locale,
        categories: [publicCategory],
        search:
          '?category=category-fixture&q=KORA&sort=price_desc&filters[material_fixture]=linen_fixture&shop=shop-fixture',
      }),
    );
    assert.match(filters, /name="filters\[material_fixture\]"/);
    assert.match(filters, /value="linen_fixture" selected/);
    assert.match(filters, /value="price_desc" selected/);
    assert.match(filters, /name="q"[^>]*value="KORA"/);
    assert.match(filters, /name="shop"[^>]*value="shop-fixture"/);
    assert.ok(filters.includes(definition.options[1].label[locale]));
  }
});
test('Boolean false and decimal filters retain their values in SSR instead of becoming empty', () => {
  const category = {
    id: 'fixture',
    slug: 'fixture',
    name: 'Test',
    parent_id: null,
    attributes: [
      {
        key: 'certified',
        type: 'boolean',
        label: 'Certifié',
        unit: null,
        required: true,
        filterable: true,
        options: [],
      },
      {
        key: 'length',
        type: 'number',
        label: 'Longueur',
        unit: 'cm',
        required: true,
        filterable: true,
        options: [],
      },
    ],
  };
  const html = renderToStaticMarkup(
    React.createElement(CatalogueFilters, {
      locale: 'fr',
      categories: [category],
      search: '?category=fixture&filters[certified]=false&filters[length]=12.5',
    }),
  );
  assert.match(html, /value="false" selected/);
  assert.match(html, /name="filters\[length\]"[^>]*value="12.5"/);
  assert.match(html, /Longueur \(cm\)/);
});
test('Percentage input converts to exact basis points and Douala dates round trip without browser timezone dependence', () => {
  for (const [input, expected] of [
    ['3', 300],
    ['3,25', 325],
    ['0.01', 1],
    ['99.99', 9999],
    ['100.00', 10000],
    ['100.01', null],
    ['-1', null],
    ['3.001', null],
    ['', null],
    ['1e2', null],
  ])
    assert.equal(percentToBasisPoints(input), expected, input);
  assert.equal(utcInstant('2026-12-25T10:30'), '2026-12-25T09:30:00.000Z');
  assert.equal(doualaInput('2026-12-25T09:30:00Z'), '2026-12-25T10:30');
  assert.equal(formatXaf('999998999999000001', 'fr').replace(/[^0-9]/g, ''), '999998999999000001');
});
