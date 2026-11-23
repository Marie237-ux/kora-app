import { useEffect, useMemo, useRef, useState } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { type Locale } from '../lib/copy';
import AcModal from './AcModal';

// The "…" button of a shop page: report the shop to the KORA team (a reason and a few words), or block it (its
// stories and products stop being shown to this member; unblocking is one tap). Signing in is asked first.

const REASONS = ['counterfeit', 'misleading', 'behaviour', 'prohibited', 'other'] as const;
const T = {
  fr: {
    more: 'Plus d’options', report: 'Signaler la boutique', block: 'Bloquer la boutique', unblock: 'Débloquer la boutique',
    reportTitle: 'Signaler cette boutique', reportText: 'L’équipe KORA examine chaque signalement. La boutique ne sait pas qui l’a signalée.',
    reasons: { counterfeit: 'Produits contrefaits', misleading: 'Prix ou descriptions trompeurs', behaviour: 'Comportement inapproprié', prohibited: 'Produits interdits', other: 'Autre raison' },
    details: 'Précisions (facultatif)', send: 'Envoyer le signalement', sent: 'Merci, votre signalement a été transmis à l’équipe KORA.', already: 'Vous avez déjà signalé cette boutique ; l’équipe l’examine.',
    blockTitle: 'Bloquer cette boutique ?', blockText: 'Vous ne verrez plus ses stories ni ses produits. Vous pourrez la débloquer à tout moment.',
    blocked: 'Boutique bloquée', blockedText: 'Ses produits et stories ne vous sont plus montrés.', cancel: 'Annuler', failed: 'Ça n’a pas marché. Réessayez.',
  },
  en: {
    more: 'More options', report: 'Report the shop', block: 'Block the shop', unblock: 'Unblock the shop',
    reportTitle: 'Report this shop', reportText: 'The KORA team reviews every report. The shop does not know who reported it.',
    reasons: { counterfeit: 'Counterfeit products', misleading: 'Misleading prices or descriptions', behaviour: 'Inappropriate behaviour', prohibited: 'Prohibited products', other: 'Another reason' },
    details: 'Details (optional)', send: 'Send the report', sent: 'Thank you, your report was passed on to the KORA team.', already: 'You already reported this shop; the team is reviewing it.',
    blockTitle: 'Block this shop?', blockText: 'You will no longer see its stories or products. You can unblock it at any time.',
    blocked: 'Shop blocked', blockedText: 'Its products and stories are no longer shown to you.', cancel: 'Cancel', failed: 'That did not work. Try again.',
  },
};

export default function ShopActions({ locale, slug }: { locale: Locale; slug: string }) {
  const t = T[locale];
  const api = useMemo(() => createApi('', locale), [locale]);
  const [menu, setMenu] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const [modal, setModal] = useState<'report' | 'block' | null>(null);
  const [reason, setReason] = useState<(typeof REASONS)[number]>('misleading');
  const [details, setDetails] = useState('');
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    api.request<{ data: { shops: { slug: string }[] } }>('/api/v1/shops-blocked').then((r) => setBlocked(r.data.shops.some((s) => s.slug === slug))).catch(() => {});
    const out = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setMenu(false); };
    document.addEventListener('mousedown', out);
    return () => document.removeEventListener('mousedown', out);
  }, []);

  // Signing in first when needed, then the chosen action.
  async function guard(next: () => void) {
    setMenu(false);
    try {
      await api.request('/api/v1/me');
      next();
    } catch {
      window.dispatchEvent(new CustomEvent('kora:auth', { detail: { mode: 'login', next: null } }));
      addEventListener('kora:signed-in', () => next(), { once: true });
    }
  }
  async function report() {
    setBusy(true);
    try {
      const r = await api.request<{ data: { already: boolean } }>('/api/v1/shops/' + slug + '/report', { method: 'POST', body: JSON.stringify({ reason, details: details.trim() || null }) });
      setNote(r.data.already ? t.already : t.sent);
    } catch (err) {
      setNote(err instanceof ApiError && err.message ? err.message : t.failed);
    } finally {
      setBusy(false);
    }
  }
  async function toggleBlock() {
    setBusy(true);
    try {
      const r = await api.request<{ data: { blocked: boolean } }>('/api/v1/shops/' + slug + '/block', { method: 'POST', body: JSON.stringify({}) });
      setBlocked(r.data.blocked);
      setModal(null);
      document.documentElement.dataset.shopBlocked = r.data.blocked ? 'true' : '';
    } catch {
      setNote(t.failed);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="sa" ref={box}>
      <button type="button" className="icon-round sa-more" aria-label={t.more} aria-expanded={menu} onClick={() => setMenu((m) => !m)}>
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.7" /><circle cx="12" cy="12" r="1.7" /><circle cx="19" cy="12" r="1.7" /></svg>
      </button>
      {menu && (
        <div className="sa-menu" role="menu">
          <button type="button" role="menuitem" onClick={() => guard(() => { setNote(''); setModal('report'); })}>{t.report}</button>
          <button type="button" role="menuitem" className="danger" onClick={() => guard(() => (blocked ? toggleBlock() : setModal('block')))}>{blocked ? t.unblock : t.block}</button>
        </div>
      )}
      {blocked && <p className="sa-blocked"><strong>{t.blocked}</strong> {t.blockedText}</p>}
      {modal === 'report' && (
        <AcModal title={t.reportTitle} onClose={() => setModal(null)} closeLabel={t.cancel}>
          {note ? <div className="acm-confirm"><p>{note}</p></div> : (
            <form className="acm-form" onSubmit={(e) => { e.preventDefault(); report(); }}>
              <p className="acm-sub">{t.reportText}</p>
              <div className="ac-kinds2 sa-reasons" role="radiogroup">
                {REASONS.map((r) => (
                  <label key={r} data-on={reason === r}>
                    <input type="radio" name="reason" checked={reason === r} onChange={() => setReason(r)} />
                    <strong>{t.reasons[r]}</strong>
                  </label>
                ))}
              </div>
              <label className="ac-field"><span>{t.details}</span><textarea rows={3} maxLength={2000} value={details} onChange={(e) => setDetails(e.target.value)} /></label>
              <button type="submit" className="btn btn-accent ac-wide" disabled={busy}>{busy && <i className="spin" />} {t.send}</button>
            </form>
          )}
        </AcModal>
      )}
      {modal === 'block' && (
        <AcModal title={t.blockTitle} onClose={() => setModal(null)} closeLabel={t.cancel}>
          <div className="acm-confirm">
            <p>{t.blockText}</p>
            <div className="acm-actions">
              <button type="button" className="btn btn-line btn-small" onClick={() => setModal(null)}>{t.cancel}</button>
              <button type="button" className="btn btn-small acm-danger" onClick={toggleBlock} disabled={busy}>{busy && <i className="spin" />} {t.block}</button>
            </div>
          </div>
        </AcModal>
      )}
    </div>
  );
}
