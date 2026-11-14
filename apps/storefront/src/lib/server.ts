import { createApi } from '@kora/api-client';
import type { Locale } from './copy';

export const api = (locale: Locale) =>
  createApi(import.meta.env.API_INTERNAL_URL || 'http://localhost:8000', locale);

/** A read that never breaks a page: on failure the section shows its empty state and a notice. */
export async function safe<T>(work: Promise<T>, fallback: T): Promise<{ value: T; failed: boolean }> {
  try {
    return { value: await work, failed: false };
  } catch {
    return { value: fallback, failed: true };
  }
}

export function localeOf(value: string | undefined): Locale | null {
  return value === 'fr' || value === 'en' ? value : null;
}
