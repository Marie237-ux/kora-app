import { useEffect, useState } from 'react';
import { copy, type Locale } from '../lib/copy';
import { addToCart } from '../lib/cart';
import { CartIcon, CheckIcon, PlusIcon } from './icons';

/** Quick "+" on a tile, or the full button with quantity on a product page. Feedback is immediate. */
const RetryIcon = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M20 12a8 8 0 1 1-2.34-5.66" /><path d="M20 4v5h-5" /></svg>
);

export default function AddToCart({
  locale,
  offerId: offerIdProp,
  name,
  variant = 'plus',
  minimum = 1,
  disabled: disabledProp = false,
  follow = false,
}: {
  locale: Locale;
  offerId: string;
  name: string;
  variant?: 'plus' | 'cart' | 'pill' | 'full';
  minimum?: number;
  disabled?: boolean;
  follow?: boolean;
}) {
  const c = copy(locale);
  const [state, setState] = useState<'idle' | 'busy' | 'done' | 'error'>('idle');
  const [quantity, setQuantity] = useState(Math.max(1, minimum));
  // On a product page, choosing another variant (shade, size…) switches the offer this button adds.
  const [current, setCurrent] = useState({ offerId: offerIdProp, disabled: disabledProp });
  useEffect(() => {
    if (!follow) return;
    const on = (e: Event) => {
      const d = (e as CustomEvent<{ offerId: string; disabled: boolean }>).detail;
      setCurrent({ offerId: d.offerId, disabled: d.disabled });
      setState('idle');
    };
    window.addEventListener('kora:variant', on);
    return () => window.removeEventListener('kora:variant', on);
  }, [follow]);
  const offerId = current.offerId;
  const disabled = current.disabled;
  async function add() {
    if (state === 'busy' || disabled) return;
    setState('busy');
    try {
      await addToCart(locale, offerId, quantity, name);
      setState('done');
      window.setTimeout(() => setState('idle'), 1600);
    } catch {
      setState('error');
      window.setTimeout(() => setState('idle'), 2400);
    }
  }
  if (variant === 'pill') {
    return (
      <button type="button" className="cart-pill" data-state={state} onClick={add} disabled={disabled} aria-label={state === 'error' ? c.addFailed : c.addToCart + ' — ' + name} title={state === 'error' ? c.addFailed : undefined}>
        {state === 'busy' ? <i className="spin" aria-hidden="true" /> : state === 'done' ? <CheckIcon /> : state === 'error' ? <RetryIcon /> : <CartIcon />}
        <span>{state === 'busy' ? c.adding : state === 'done' ? c.added : state === 'error' ? c.addRetry : c.addShort}</span>
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
        {state === 'busy' ? <i className="spin" aria-hidden="true" /> : state === 'done' ? <CheckIcon /> : variant === 'cart' ? <CartIcon /> : <PlusIcon />}
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
      <button type="button" className="pdp-add" data-state={state} onClick={add} disabled={disabled || state === 'busy'}>
        {state === 'busy' ? <><i className="spin" aria-hidden="true" /> {c.adding}</> : state === 'done' ? c.added : state === 'error' ? c.addFailed : c.addToCart}
      </button>
    </div>
  );
}
