import { createApi } from '@kora/api-client';
import type { Locale } from './copy';

export type CartOffer = {
  id: string;
  name: string;
  image: string | null;
  price: { amount: number };
  shop: { name: string; slug: string };
  category: string;
};
export type CartLine = {
  id: string;
  offer_id: string;
  quantity: number;
  selected: boolean;
  changes: Record<string, unknown>;
  issues: string[];
  current_line_total: string | null;
  current_conditions: { unit_price: number; minimum_quantity: number } | null;
  offer: CartOffer | null;
};
export type Cart = { id: string; version: number; lines: CartLine[]; selected_subtotal: string | null };

const api = (locale: Locale) => createApi('', locale);

export async function loadCart(locale: Locale): Promise<Cart> {
  return (await api(locale).request<{ data: Cart }>('/api/v1/cart')).data;
}

function command(locale: Locale, method: string, path: string, body: unknown) {
  // Each change carries its own key: a repeated tap never adds the item twice.
  return api(locale).request(path, {
    method,
    body: JSON.stringify(body),
    headers: { 'Idempotency-Key': crypto.randomUUID() },
  });
}

function changed(cart: Cart) {
  window.dispatchEvent(new CustomEvent('kora:cart', { detail: cart }));
}

export async function addToCart(locale: Locale, offerId: string, quantity = 1): Promise<Cart> {
  const cart = await loadCart(locale);
  const line = cart.lines.find((l) => l.offer_id === offerId);
  if (line) {
    await command(locale, 'PUT', '/api/v1/cart/lines/' + line.id, {
      version: cart.version,
      offer_id: offerId,
      quantity: line.quantity + quantity,
      selected: true,
    });
  } else {
    await command(locale, 'POST', '/api/v1/cart/lines', {
      version: cart.version,
      offer_id: offerId,
      quantity,
      selected: true,
    });
  }
  const next = await loadCart(locale);
  changed(next);
  window.dispatchEvent(new CustomEvent('kora:cart-added'));
  return next;
}

export async function setQuantity(locale: Locale, cart: Cart, line: CartLine, quantity: number): Promise<Cart> {
  if (quantity < 1) {
    await command(locale, 'DELETE', '/api/v1/cart/lines/' + line.id, { version: cart.version });
  } else {
    await command(locale, 'PUT', '/api/v1/cart/lines/' + line.id, {
      version: cart.version,
      offer_id: line.offer_id,
      quantity,
      selected: line.selected,
    });
  }
  const next = await loadCart(locale);
  changed(next);
  return next;
}

export function count(cart: Cart | null): number {
  return cart ? cart.lines.reduce((sum, l) => sum + l.quantity, 0) : 0;
}
