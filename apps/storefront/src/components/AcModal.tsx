import { useEffect, type ReactNode } from 'react';

// The account's window: centred card over a dimmed page on a computer, a sheet from the bottom on a phone. Escape,
// the backdrop or the round close button close it; the page underneath does not scroll meanwhile.

export default function AcModal({ title, onClose, children, wide = false, closeLabel = 'Fermer' }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean; closeLabel?: string }) {
  useEffect(() => {
    const key = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    addEventListener('keydown', key);
    document.body.style.overflow = 'hidden';
    return () => {
      removeEventListener('keydown', key);
      document.body.style.overflow = '';
    };
  }, []);
  return (
    <div className="acm" role="presentation" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={'acm-card' + (wide ? ' wide' : '')} role="dialog" aria-modal="true" aria-label={title}>
        <header className="acm-head">
          <h3>{title}</h3>
          <button type="button" className="acm-close" onClick={onClose} aria-label={closeLabel}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
          </button>
        </header>
        <div className="acm-body">{children}</div>
      </div>
    </div>
  );
}

/** Loading screen of an account section: an intro line, then rows (lists) or tiles (boards, favourites). */
export function AcLoading({ kind = 'rows' }: { kind?: 'rows' | 'tiles' | 'card' }) {
  return (
    <div className="ac-loading ac-skel" aria-busy="true" aria-hidden="true">
      <i className="sk-intro" />
      {kind === 'tiles' ? (
        <div className="ac-loading-tiles">{[0, 1, 2].map((n) => <span key={n}><i className="sk-board" /><i className="sk-line" /><i className="sk-small" /></span>)}</div>
      ) : kind === 'card' ? (
        <><i className="sk-card-big" /><i className="sk-row" /></>
      ) : (
        <div className="ac-loading-rows">{[0, 1, 2].map((n) => <span key={n}><i className="sk-thumb" /><span><i className="sk-line" /><i className="sk-small" /></span><i className="sk-pill" /></span>)}</div>
      )}
    </div>
  );
}
