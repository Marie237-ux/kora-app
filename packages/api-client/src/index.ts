import type { components } from './schema';
export type Locale = components['schemas']['Locale'];
export type Theme = components['schemas']['Theme'];
export type Offer = components['schemas']['Offer'];
export type Shop = components['schemas']['Shop'];
export type HeroScene = components['schemas']['HeroScene'];
export type SceneContent = components['schemas']['SceneContent'];
export type SceneRecord = components['schemas']['SceneRecord'];
export type User = components['schemas']['User'];
export type CatalogueEntity = components['schemas']['CatalogueEntity'];
export type CatalogueDraft = components['schemas']['CatalogueDraft'];
export type CatalogueRecord = components['schemas']['CatalogueRecord'];
export type AttributeDefinition = components['schemas']['AttributeDefinition'];
export type VariantDraft = components['schemas']['VariantDraft'];
export type CatalogueLookups = components['schemas']['CatalogueLookups'];
export type MediaAsset = components['schemas']['MediaAsset'];
export type AgreementInput = components['schemas']['AgreementInput'];
export type PurchaseAgreement = components['schemas']['PurchaseAgreement'];
export type PricePreview = components['schemas']['PricePreview'];
export type PublicCategory = components['schemas']['PublicCategory'];
export type PageMeta = components['schemas']['PageMeta'];

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public fields: Record<string, string[]> = {},
  ) {
    super(message);
  }
}
export function createApi(base = '', locale: Locale = 'fr') {
  async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const method = options.method ?? 'GET';
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    headers.set('X-Kora-Locale', locale);
    if (method !== 'GET' && typeof document !== 'undefined') {
      const csrf = await fetch(base + '/sanctum/csrf-cookie', {
        credentials: 'include',
        cache: 'no-store',
      });
      if (!csrf.ok) throw new ApiError(csrf.status, 'CSRF_FAILED', '');
      const token = document.cookie
        .split('; ')
        .find((x) => x.startsWith('XSRF-TOKEN='))
        ?.slice(11);
      if (token) headers.set('X-XSRF-TOKEN', decodeURIComponent(token));
    }
    if (options.body && !(options.body instanceof FormData))
      headers.set('Content-Type', 'application/json');
    let response: Response;
    try {
      response = await fetch(base + path, {
        signal: AbortSignal.timeout(15000),
        ...options,
        headers,
        credentials: 'include',
        cache: 'no-store',
      });
    } catch {
      throw new ApiError(0, 'NETWORK_ERROR', '');
    }
    if (response.status === 204) return undefined as T;
    const payload = await response.json().catch(() => null);
    if (!response.ok)
      throw new ApiError(
        response.status,
        payload?.error?.code ?? 'REQUEST_FAILED',
        payload?.error?.message ?? payload?.message ?? '',
        payload?.error?.fields ?? payload?.errors ?? {},
      );
    return payload as T;
  }
  return {
    request,
    me: () => request<{ data: User }>('/api/v1/me'),
    preferences: (data: { locale: Locale; theme: Theme }) =>
      request<{ data: User }>('/api/v1/me/preferences', {
        method: 'PATCH',
        body: JSON.stringify(data),
      }),
    hero: () => request<{ data: HeroScene[]; meta: { demo: boolean } }>('/api/v1/content/hero'),
    catalogue: (query = '') =>
      request<{
        data: Offer[];
        meta: { page: number; last_page: number; total: number; demo: boolean };
      }>('/api/v1/catalogue' + query),
    offer: (id: string) => request<{ data: Offer }>('/api/v1/catalogue/' + encodeURIComponent(id)),
    shops: () => request<{ data: Shop[] }>('/api/v1/shops'),
    categories: () => request<{ data: PublicCategory[] }>('/api/v1/categories'),
    shop: (slug: string) => request<{ data: Shop }>('/api/v1/shops/' + encodeURIComponent(slug)),
    lookups: () => request<{ data: CatalogueLookups }>('/api/v1/operations/catalogue/lookups'),
    records: (entity: CatalogueEntity, query = '') =>
      request<{ data: CatalogueRecord[]; meta: PageMeta }>(
        '/api/v1/operations/catalogue/' + entity + query,
      ),
    saveRecord: (entity: CatalogueEntity, draft: CatalogueDraft, record: CatalogueRecord | null) =>
      request<{ data: CatalogueRecord }>(
        '/api/v1/operations/catalogue/' + entity + (record ? '/' + record.id : ''),
        {
          method: record ? 'PUT' : 'POST',
          body: JSON.stringify({ draft, ...(record ? { version: record.version } : {}) }),
        },
      ),
    changeRecord: (record: CatalogueRecord, action: 'publish' | 'archive') =>
      request<{ data: CatalogueRecord }>(
        '/api/v1/operations/catalogue/' + record.entity + '/' + record.id + '/' + action,
        { method: 'POST', body: JSON.stringify({ version: record.version }) },
      ),
    media: (page = 1) =>
      request<{ data: MediaAsset[]; meta: PageMeta }>('/api/v1/operations/media?page=' + page),
    uploadMedia: (body: FormData) =>
      request<{ data: MediaAsset }>('/api/v1/operations/media', { method: 'POST', body }),
    mediaVisibility: (media: MediaAsset, is_public: boolean, rights_confirmed: boolean) =>
      request<{ data: MediaAsset }>('/api/v1/operations/media/' + media.id + '/visibility', {
        method: 'POST',
        body: JSON.stringify({ version: media.version, is_public, rights_confirmed }),
      }),
    agreements: (page = 1, supplier = '') =>
      request<{ data: PurchaseAgreement[]; meta: PageMeta }>(
        '/api/v1/operations/pricing/agreements?page=' +
          page +
          '&supplier_id=' +
          encodeURIComponent(supplier),
      ),
    createAgreement: (draft: AgreementInput) =>
      request<{ data: PurchaseAgreement }>('/api/v1/operations/pricing/agreements', {
        method: 'POST',
        body: JSON.stringify(draft),
      }),
    pricePreview: (id: string, quantity: number) =>
      request<{ data: PricePreview }>(
        '/api/v1/operations/pricing/offers/' + id + '/preview?quantity=' + quantity,
      ),
    scenes: () =>
      request<{
        data: { data: SceneRecord[]; current_page: number; last_page: number; total: number };
      }>('/api/v1/operations/content/scenes'),
  };
}
