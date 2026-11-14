import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { createApi } from '@kora/api-client';
import { copy, money, tone, type Locale } from '../lib/copy';
import { count, loadCart, setQuantity, type Cart } from '../lib/cart';
import SearchPanel from './SearchPanel';
import { ArrowRight, BagIcon, CloseIcon, GridIcon, HomeIcon, MenuIcon, MoonIcon, SearchIcon, SunIcon, UserIcon } from './icons';

type Theme = 'light' | 'dark';
type Link = { href: string; label: string; key: string };
export type Aisle = { slug: string; name: string };

/**
 * Site header: logo, main navigation, search sheet, account, cart drawer, language and theme.
 * On phones: logo + menu at the top, and a floating tab bar at the bottom (home, catalogue, search, cart, account).
 */
export default function Header({ locale, links, aisles = [], active = '', query = '' }: { locale: Locale; links: Link[]; aisles?: Aisle[]; active?: string; query?: string }) {
  const c = copy(locale);
  const [cart, setCart] = useState<Cart | null>(null);
  const [drawer, setDrawer] = useState(false);
  const [menu, setMenu] = useState(false);
  const [menuTop, setMenuTop] = useState(0);
  const [search, setSearch] = useState(false);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState('');
  const [theme, setTheme] = useState<Theme>('light');
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    loadCart(locale).then(setCart).catch(() => {});
    const onCart = (e: Event) => setCart((e as CustomEvent<Cart>).detail);
    const onAdded = () => {
      setToast(c.added);
      window.setTimeout(() => setToast(''), 2200);
    };
    const onOpenSearch = () => setSearch(true);
    const onOpenCart = () => setDrawer(true);
    window.addEventListener('kora:cart', onCart);
    window.addEventListener('kora:cart-added', onAdded);
    window.addEventListener('kora:open-search', onOpenSearch);
    window.addEventListener('kora:open-cart', onOpenCart);
    setTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      window.removeEventListener('kora:cart', onCart);
      window.removeEventListener('kora:cart-added', onAdded);
      window.removeEventListener('kora:open-search', onOpenSearch);
      window.removeEventListener('kora:open-cart', onOpenCart);
      window.removeEventListener('scroll', onScroll);
    };
  }, []);
  useEffect(() => {
    const close = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setDrawer(false);
        setMenu(false);
        setSearch(false);
      }
    };
    window.addEventListener('keydown', close);
    document.body.style.overflow = drawer || search || menu ? 'hidden' : '';
    return () => window.removeEventListener('keydown', close);
  }, [drawer, search, menu]);

  function applyTheme(value: Theme) {
    setTheme(value);
    document.documentElement.dataset.theme = value;
    try {
      localStorage.setItem('kora.theme', value);
    } catch {}
    void createApi('', locale).preferences({ locale, theme: value }).catch(() => {});
  }
  function language(value: Locale) {
    if (value === locale) return;
    document.cookie = 'kora_locale=' + value + '; Path=/; SameSite=Lax; Max-Age=31536000';
    const url = new URL(window.location.href);
    url.pathname = url.pathname.replace(/^\/(fr|en)(?=\/|$)/, '/' + value);
    window.location.assign(url.pathname + url.search);
  }
  async function change(lineId: string, quantity: number) {
    if (!cart || busy) return;
    const line = cart.lines.find((l) => l.id === lineId);
    if (!line) return;
    setBusy(true);
    try {
      setCart(await setQuantity(locale, cart, line, quantity));
    } catch {
      setCart(await loadCart(locale).catch(() => cart));
    } finally {
      setBusy(false);
    }
  }
  function openSearch() {
    setMenu(false);
    setDrawer(false);
    setSearch(true);
  }
  function openCart() {
    setMenu(false);
    setSearch(false);
    setDrawer(true);
  }
  const n = count(cart);
  const home = '/' + locale;
  const tabs: { key: string; label: string; icon: ReactNode; href?: string; onClick?: () => void; badge?: number }[] = [
    { key: 'home', label: c.home, icon: <HomeIcon />, href: home },
    { key: 'catalogue', label: c.catalogue, icon: <GridIcon />, href: home + '/catalogue' },
    { key: 'search', label: c.search, icon: <SearchIcon />, onClick: openSearch },
    { key: 'cart', label: c.cart, icon: <BagIcon />, onClick: openCart, badge: n },
    { key: 'account', label: c.account, icon: <UserIcon />, href: home + '/account' },
  ];
  const current = active;

  return (
    <>
      <header className="site-header" data-scrolled={scrolled} data-search={search} data-menu={menu}>
        <div className="wrap header-row">
          <a className="brand" href={home} aria-label={'KORA — ' + c.home}>
            <img src="/brand/logo-ink.png" alt="KORA" width="152" height="70" />
          </a>
          <nav className="main-nav" aria-label={c.menu}>
            {links.map((l) => (
              <a key={l.key} href={l.href} aria-current={active === l.key ? 'page' : undefined}>
                {l.label}
              </a>
            ))}
          </nav>
          <div className="header-tools">
            <button type="button" className="tool desk" aria-expanded={search} aria-controls="site-search" onClick={openSearch}>
              <SearchIcon />
              <span className="label">{c.search}</span>
            </button>
            <a className="tool desk" href={home + '/account'} aria-label={c.account}>
              <UserIcon />
            </a>
            <button type="button" className="tool desk" onClick={openCart} aria-haspopup="dialog">
              <BagIcon />
              <span className="label">
                {c.cart} ({n})
              </span>
              <span className="sr-only">{n + ' ' + c.cart}</span>
            </button>
            <button type="button" className="tool lang desk" onClick={() => language(locale === 'fr' ? 'en' : 'fr')} aria-label={c.language}>
              <span aria-current={locale === 'fr'}>FR</span>
              <i>/</i>
              <span aria-current={locale === 'en'}>EN</span>
            </button>
            <button type="button" className="tool theme desk" onClick={() => applyTheme(theme === 'dark' ? 'light' : 'dark')} aria-label={theme === 'dark' ? c.themeLight : c.themeDark} aria-pressed={theme === 'dark'}>
              {theme === 'dark' ? <SunIcon /> : <MoonIcon />}
            </button>
            <button
              type="button"
              className="menu-toggle"
              aria-expanded={menu}
              aria-controls="mobile-nav"
              onClick={(e) => {
                setSearch(false);
                setMenuTop(e.currentTarget.closest('header')!.getBoundingClientRect().bottom);
                setMenu(!menu);
              }}
              aria-label={menu ? c.close : c.menu}
            >
              {menu ? <CloseIcon /> : <MenuIcon />}
            </button>
          </div>
          <nav className="mobile-nav" id="mobile-nav" data-open={menu} aria-label={c.menu} hidden={!menu} style={{ top: menuTop }}>
            <p className="mnav-kicker" style={{ '--i': 0 } as CSSProperties}>{c.menuKicker}</p>
            <ul className="mnav-main">
              {links.map((l, i) => (
                <li key={l.key} style={{ '--i': i + 1 } as CSSProperties}>
                  <a href={l.href} aria-current={active === l.key ? 'page' : undefined}>
                    {l.label}
                    {l.key === 'catalogue' && aisles.length > 0 && <sup>{aisles.length}</sup>}
                  </a>
                  {l.key === 'catalogue' && aisles.length > 0 && (
                    <ul className="mnav-aisles">
                      {aisles.map((a) => (
                        <li key={a.slug}>
                          <a href={home + '/catalogue?category=' + a.slug}>{a.name}</a>
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
            <ul className="mnav-main mnav-settings" style={{ '--i': links.length + 1 } as CSSProperties}>
              <li>
                <div className="mnav-row">
                  <span>{c.languageLabel}</span>
                  <span className="mnav-seg" role="group" aria-label={c.language}>
                    <button type="button" aria-pressed={locale === 'fr'} onClick={() => language('fr')}>FR</button>
                    <button type="button" aria-pressed={locale === 'en'} onClick={() => language('en')}>EN</button>
                  </span>
                </div>
              </li>
              <li>
                <button type="button" className="mnav-row" role="switch" aria-checked={theme === 'dark'} onClick={() => applyTheme(theme === 'dark' ? 'light' : 'dark')}>
                  <span>{c.darkTheme}</span>
                  <b className="mnav-switch" aria-hidden="true"><em /></b>
                </button>
              </li>
            </ul>
            <div className="mnav-actions" style={{ '--i': links.length + 2 } as CSSProperties}>
              <a className="btn btn-accent mnav-cta" href={home + '/catalogue'}>{c.startShopping} <ArrowRight /></a>
            </div>
          </nav>
        </div>
      </header>
      <SearchPanel locale={locale} open={search} onClose={() => setSearch(false)} query={query} aisles={aisles} />
      <nav className="tabbar" aria-label={c.quickNav}>
        {tabs.map((t) =>
          t.href ? (
            <a key={t.key} href={t.href} aria-current={current === t.key ? 'page' : undefined}>
              {t.icon}
              <span>{t.label}</span>
            </a>
          ) : (
            <button key={t.key} type="button" onClick={t.onClick} aria-pressed={(t.key === 'search' && search) || (t.key === 'cart' && drawer)}>
              {t.icon}
              <span>{t.label}</span>
              {t.badge ? <b aria-label={t.badge + ' ' + c.cart}>{t.badge}</b> : null}
            </button>
          ),
        )}
      </nav>
      <div className="drawer-backdrop" data-open={drawer} hidden={!drawer} onClick={() => setDrawer(false)} />
      <aside className="drawer" data-open={drawer} role="dialog" aria-modal="true" aria-label={c.cartTitle} aria-hidden={!drawer}>
        <header>
          <h2>{c.cartTitle}</h2>
          <button type="button" className="icon-round" onClick={() => setDrawer(false)} aria-label={c.close}>
            <CloseIcon />
          </button>
        </header>
        <div className="drawer-lines" aria-busy={busy}>
          {!cart || cart.lines.length === 0 ? (
            <div className="empty" style={{ marginTop: 20 }}>
              <h3>{c.emptyCart}</h3>
              <p className="muted">{c.emptyCartText}</p>
              <a className="btn btn-dark btn-small" href={home + '/catalogue'}>{c.catalogue}</a>
            </div>
          ) : (
            cart.lines.map((l) => (
              <div className="line" key={l.id}>
                <div className={'line-img ' + tone(l.offer?.category ?? l.offer_id)}>
                  {l.offer?.image && <img src={l.offer.image} alt="" loading="lazy" />}
                </div>
                <div>
                  <h3><a href={home + '/products/' + l.offer_id}>{l.offer?.name ?? '—'}</a></h3>
                  <small>{l.offer?.shop.name}</small>
                  {Object.keys(l.changes ?? {}).length > 0 && <small style={{ display: 'block', color: 'var(--accent)' }}>{c.priceChanged}</small>}
                  <div className="line-qty">
                    <button type="button" aria-label={c.decrease} onClick={() => change(l.id, l.quantity - 1)} disabled={busy}>−</button>
                    <output aria-label={c.quantity}>{l.quantity}</output>
                    <button type="button" aria-label={c.increase} onClick={() => change(l.id, l.quantity + 1)} disabled={busy}>+</button>
                  </div>
                </div>
                <div className="line-total">
                  {l.current_line_total ? money(l.current_line_total, locale) : c.unavailable}
                  <button type="button" onClick={() => change(l.id, 0)} disabled={busy}>{c.remove}</button>
                </div>
              </div>
            ))
          )}
        </div>
        {cart && cart.lines.length > 0 && (
          <footer>
            <div className="subtotal">
              <span>{c.subtotal}</span>
              <span>{cart.selected_subtotal ? money(cart.selected_subtotal, locale) : '—'}</span>
            </div>
            <small className="muted">{c.deliveryLater}</small>
            <a className="btn btn-accent" href={home + '/cart'}>{c.checkout}</a>
            <button type="button" className="btn btn-line" onClick={() => setDrawer(false)}>{c.continueShopping}</button>
          </footer>
        )}
      </aside>
      <div className="toast" role="status" aria-live="polite" data-show={toast !== ''}>{toast}</div>
    </>
  );
}
