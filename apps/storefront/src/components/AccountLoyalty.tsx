import { useEffect, useState } from 'react';
import type { createApi } from '@kora/api-client';
import { money, type Locale } from '../lib/copy';
import { accountCopy } from '../lib/account-copy';
import { AcLoading } from './AcModal';

// Loyalty card and referral (spec §23–24), only from what the API really says: while the programme is off, an honest
// "coming soon" (never invented points); once on, the KORA card (number, tier, available points) and the history of
// movements. Referral: the member's code with copy / WhatsApp share once a programme is open, entering a sponsor's
// code, the people referred and the confirmed rewards.

type Api = ReturnType<typeof createApi>;
type Card = { id: string; card_number: string; version: number; status: string; balance: string; available_points: string; tier?: { label: string } | null };
type Loyalty = { program: { enabled: boolean } | null; card: Card | null };
type Move = { id: string; points: number | string; kind?: string; status?: string; explanation?: string | Record<string, string> | null; created_at: string };
type Referral = { code: string; program: { rate_bp: number; terms: string | null } | null; sponsored_by_someone: boolean; referees: { name?: string; status?: string }[]; balance: string };
const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });

export default function AccountLoyalty({ locale, api, onNote }: { locale: Locale; api: Api; onNote: (t: string) => void }) {
  const t = accountCopy(locale);
  const [loyalty, setLoyalty] = useState<Loyalty | null>(null);
  const [moves, setMoves] = useState<Move[] | null>(null);
  const [ref, setRef] = useState<Referral | null>(null);
  const [busy, setBusy] = useState('');
  const [copied, setCopied] = useState(false);
  const [code, setCode] = useState('');

  useEffect(() => {
    api.request<{ data: Loyalty }>('/api/v1/account/loyalty').then((r) => {
      setLoyalty(r.data);
      if (r.data.card) api.request<{ data: Move[] }>('/api/v1/account/loyalty/movements').then((m) => setMoves(m.data)).catch(() => setMoves([]));
    }).catch(() => setLoyalty({ program: null, card: null }));
    api.request<{ data: Referral }>('/api/v1/account/referral').then((r) => setRef(r.data)).catch(() => setRef({ code: '', program: null, sponsored_by_someone: true, referees: [], balance: '0' }));
  }, []);

  async function activate() {
    setBusy('card');
    try {
      const r = await api.request<{ data: Card }>('/api/v1/account/loyalty/card', { method: 'POST', headers: key(), body: JSON.stringify({}) });
      setLoyalty((l) => ({ program: l?.program ?? null, card: r.data }));
      setMoves([]);
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function join() {
    setBusy('join');
    try {
      await api.request('/api/v1/account/referral/join', { method: 'POST', headers: key(), body: JSON.stringify({ code: code.trim() }) });
      setRef((r) => (r ? { ...r, sponsored_by_someone: true } : r));
      onNote(t.referralJoined);
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  const date = (iso: string) => new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(iso));
  const text = (x: Move['explanation']) => (typeof x === 'string' ? x : x?.[locale] ?? x?.fr ?? '');
  const inviteUrl = typeof location !== 'undefined' ? location.origin + '/' + locale + '?parrain=' + (ref?.code ?? '') : '';

  if (!loyalty || !ref || (loyalty.card && moves === null)) return <AcLoading kind="card" />;
  const on = !!loyalty.program?.enabled;
  const card = loyalty.card;

  return (
    <div className="ac-perks">
      <section className="ac-kcard" data-on={on && !!card}>
        <div className="ac-kcard-top">
          <img src="/brand/logo-ink.png" alt="KORA" />
          {card?.tier && <span>{card.tier.label}</span>}
        </div>
        {on && card ? (
          <>
            <p className="ac-kcard-points"><b>{Number(card.available_points).toLocaleString(locale === 'fr' ? 'fr-FR' : 'en-GB')}</b> {t.loyaltyPoints}</p>
            <p className="ac-kcard-num">•••• •••• {card.card_number.slice(-4)}</p>
          </>
        ) : (
          <>
            <p className="ac-kcard-soon">{on ? t.loyaltyTitle : t.loyaltySoon}</p>
            <p className="ac-kcard-text">{on ? '' : t.loyaltySoonText}</p>
            {on && !card && <button type="button" className="btn btn-accent btn-small" onClick={activate} disabled={busy === 'card'}>{busy === 'card' && <i className="spin" />} {t.loyaltyActivate}</button>}
          </>
        )}
      </section>

      {on && card && (
        <>
          <h3 className="ac-h3">{t.loyaltyHistory}</h3>
          {moves === null ? <div className="ac-skel"><i className="sk-row" /></div> : moves.length === 0 ? <p className="ac-empty-text">{t.loyaltyNoMoves}</p> : (
            <ul className="ac-moves">
              {moves.map((m) => (
                <li key={m.id}>
                  <span><strong>{text(m.explanation) || m.kind}</strong><small>{date(m.created_at)}</small></span>
                  <b className={Number(m.points) < 0 ? 'neg' : 'pos'}>{Number(m.points) > 0 ? '+' : ''}{m.points}</b>
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      <h3 className="ac-h3">{t.referralTitle}</h3>
      {!ref ? <div className="ac-skel"><i className="sk-row" /></div> : !ref.program ? (
        <div className="ac-soft">
          <strong>{t.referralClosed}</strong>
          <p>{t.referralClosedText}</p>
        </div>
      ) : (
        <div className="ac-ref">
          <div className="ac-ref-code">
            <small>{t.referralCode}</small>
            <b>{ref.code}</b>
          </div>
          <div className="ac-ref-actions">
            <button type="button" className="btn btn-line btn-small" onClick={() => { navigator.clipboard?.writeText(inviteUrl); setCopied(true); setTimeout(() => setCopied(false), 1800); }}>{copied ? t.copied : t.copy}</button>
            <a className="btn btn-dark btn-small" href={'https://wa.me/?text=' + encodeURIComponent(t.referralInvite(ref.code, inviteUrl))} target="_blank" rel="noopener">{t.shareWhatsapp}</a>
          </div>
          {ref.program.terms && <p className="ac-hint">{ref.program.terms}</p>}
          <dl className="ac-ref-facts">
            <div><dt>{t.referees}</dt><dd>{ref.referees.length || t.noReferees}</dd></div>
            <div><dt>{t.balance}</dt><dd>{money(ref.balance, locale)}</dd></div>
          </dl>
        </div>
      )}
      {ref && ref.program && !ref.sponsored_by_someone && (
        <form className="ac-inline ac-join" onSubmit={(e) => { e.preventDefault(); join(); }}>
          <label><span>{t.referralJoin}</span><input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={16} required /></label>
          <button type="submit" className="btn btn-dark btn-small" disabled={busy === 'join'}>{t.referralJoinBtn}</button>
        </form>
      )}
    </div>
  );
}
