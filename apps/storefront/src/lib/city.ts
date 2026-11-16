import type { AstroCookies } from 'astro';
import { CITIES } from './cities';

/** Cookie remembering where the visitor receives orders (set by the city picker, read by every page). */
export const CITY_COOKIE = 'kora_city';

/**
 * The visitor's city: the "ma" parameter when the page has one (an empty value means "anywhere in Cameroon"),
 * otherwise the remembered choice. Only known Cameroonian cities are accepted.
 */
export function visitorCity(url: URL, cookies: AstroCookies): string {
  const fromUrl = url.searchParams.has('ma') ? url.searchParams.get('ma') ?? '' : cookies.get(CITY_COOKIE)?.value ?? '';
  const city = decodeURIComponent(fromUrl);
  return CITIES.includes(city) ? city : '';
}
