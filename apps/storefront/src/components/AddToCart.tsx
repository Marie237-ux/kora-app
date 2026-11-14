import { useState } from 'react';
import { copy, type Locale } from '../lib/copy';
import { addToCart } from '../lib/cart';
import { CartIcon, CheckIcon, PlusIcon } from './icons';

/** Quick "+" on a tile, or the full button with quantity on a product page. Feedback is immediate. */
export default function AddToCart({
  locale,
  offerId,
  name,
  variant = 'plus',
  minimum = 1,
  disabled = false,
}: {
  locale: Locale;
  offerId: string;
  name: string;
  variant?: 'plus' | 'cart' | 'pill' | 'full';
  minimum?: number;
  disabled?: boolean;
}) {
  const c = copy(locale);
  const [state, setState] = useState<'idle' | 'busy' | 'done' | 'error'>('idle');
  const [quantity, setQuantity] = useState(Math.max(1, minimum));
  async function add() {
    if (state === 'busy' || disabled) return;
    setState('busy');
    try {
      await addToCart(locale, offerId, quantity);
      setState('done');
      window.setTimeout(() => setState('idle'), 1600);
    } catch {
      setState('error');
      window.setTimeout(() => setState('idle'), 2400);
    }
  }
  if (variant === 'pill') {
    return (
      <button type="button" className="cart-pill" data-state={state} onClick={add} disabled={disabled} aria-label={c.addToCart + ' — ' + name}>
        {state === 'done' ? <CheckIcon /> : <CartIcon />}
        <span>{state === 'done' ? c.added : state === 'error' ? c.addFailed : c.addShort}</span>
      </button>
    );
  }
  if (variant === 'plus' || variant === 'cart') {
    return (
      <button
        type="button"
        className={variant === 'cart' ? 'cart-btn' : 'plus'}
        data-state={state}
        onClick={add}
        disabled={disabled}
        aria-label={c.addToCart + ' — ' + name}
        title={state === 'error' ? c.addFailed : c.addToCart}
      >
        {state === 'done' ? <CheckIcon /> : variant === 'cart' ? <CartIcon /> : <PlusIcon />}
      </button>
    );
  }
  return (
    <div className="pdp-row">
      <div className="qty" role="group" aria-label={c.quantity}>
        <button type="button" aria-label={c.decrease} onClick={() => setQuantity(Math.max(minimum, quantity - 1))}>−</button>
        <output>{quantity}</output>
        <button type="button" aria-label={c.increase} onClick={() => setQuantity(quantity + 1)}>+</button>
      </div>
      <button type="button" className="btn btn-accent" style={{ flex: 1 }} onClick={add} disabled={disabled || state === 'busy'}>
        {state === 'done' ? c.added : state === 'error' ? c.addFailed : c.addToCart}
      </button>
    </div>
  );
}
