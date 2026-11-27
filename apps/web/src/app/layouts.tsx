import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { Logo } from '@sanara/ui';
import { useT } from '@sanara/i18n';
import { useSession } from './session';
import { IdleLock } from './IdleLock';
import { BackButton } from './BackButton';

// Une entrée n'est ajoutée qu'avec sa page (route dans Router.tsx) : jamais de lien vers un écran vide.
const PHARMACY_MENU: Array<{ to: string; key: string; permission?: string }> = [
  { to: '/app', key: 'nav.dashboard' },
  { to: '/app/caisse', key: 'nav.pos', permission: 'sales.create' },
  { to: '/app/ventes', key: 'nav.sales', permission: 'sales.create' },
  { to: '/app/produits', key: 'nav.products', permission: 'products.read' },
  { to: '/app/stock', key: 'nav.stock', permission: 'stock.read' },
  { to: '/app/clients', key: 'nav.customers', permission: 'customers.read' },
  { to: '/app/synchronisation', key: 'nav.sync', permission: 'stock.read' },
  { to: '/app/equipe', key: 'nav.team', permission: 'team.manage' },
  { to: '/securite', key: 'nav.security' },
];

const ADMIN_MENU: Array<{ to: string; key: string; end?: boolean }> = [
  { to: '/admin', key: 'adm.nav.dashboard', end: true },
  { to: '/admin/demandes', key: 'adm.nav.applications' },
  { to: '/admin/pharmacies', key: 'adm.nav.pharmacies' },
  { to: '/admin/abonnements', key: 'adm.nav.plans' },
  { to: '/admin/facturation', key: 'adm.nav.billing' },
  { to: '/admin/signalements', key: 'adm.nav.reports' },
  { to: '/admin/modeles', key: 'adm.nav.templates' },
  { to: '/admin/audit', key: 'adm.nav.audit' },
  { to: '/admin/pays', key: 'adm.nav.countries' },
  { to: '/admin/parametres', key: 'adm.nav.settings' },
];

export function PublicLayout() {
  const { t, locale, setLocale } = useT();
  const { pathname } = useLocation();
  // La connexion et la demande d'accès ont déjà leurs propres boutons.
  const offerApply = !/^\/(connexion|inscription|activer)/.test(pathname);
  return (
    <div className="layout layout--public">
      <header className="topbar">
        <BackButton />
        <Link to="/" className="topbar__home" aria-label={t('home.back')}>
          <Logo size={30} />
        </Link>
        <nav className="topbar__links">
          <NavLink to="/pharmacies">{t('home.find')}</NavLink>
          <Link to="/#comment">{t('home.how')}</Link>
          <Link to="/#pharmaciens">{t('home.forPharmacists')}</Link>
        </nav>
        <nav className="topbar__actions">
          <button
            className="chip"
            onClick={() => setLocale(locale === 'fr' ? 'en' : 'fr')}
            aria-label="Langue"
          >
            {locale.toUpperCase()} ▾
          </button>
          <NavLink to="/connexion">{t('auth.login')}</NavLink>
          {offerApply && (
            <Link to="/inscription" className="sa-btn topbar__apply">
              {t('home.apply')}
            </Link>
          )}
        </nav>
      </header>
      <main>
        <Outlet />
      </main>
    </div>
  );
}

/** Barre latérale blanche, entrée active en vert clair ; entrées filtrées par permission. */
export function PharmacyLayout() {
  const { t } = useT();
  const { can } = useSession();
  return (
    <div className="layout layout--pharmacy">
      <aside className="sidebar sidebar--light">
        <Logo size={26} />
        <nav>
          {PHARMACY_MENU.filter((m) => !m.permission || can(m.permission)).map((m) => (
            <NavLink
              key={m.to}
              to={m.to}
              end={m.to === '/app'}
              className={({ isActive }) => `sidebar__item${isActive ? ' is-active' : ''}`}
            >
              {t(m.key)}
            </NavLink>
          ))}
        </nav>
      </aside>
      <main>
        <BackButton />
        <Outlet />
      </main>
      <IdleLock />
    </div>
  );
}

/** Console super admin : barre latérale sombre avec pastille ADMIN. */
export function AdminLayout() {
  const { t, locale, setLocale } = useT();
  const { signOut } = useSession();
  return (
    <div className="layout layout--admin">
      <aside className="sidebar sidebar--dark">
        <div className="sidebar__brand">
          <Logo size={24} tone="light" />
          <span className="pill-admin">ADMIN</span>
        </div>
        <nav>
          {ADMIN_MENU.map((m) => (
            <NavLink
              key={m.to}
              to={m.to}
              end={m.end}
              className={({ isActive }) => `sidebar__item${isActive ? ' is-active' : ''}`}
            >
              {t(m.key)}
            </NavLink>
          ))}
          <NavLink
            to="/securite"
            className={({ isActive }) => `sidebar__item${isActive ? ' is-active' : ''}`}
          >
            {t('adm.nav.security')}
          </NavLink>
        </nav>
        <div className="sidebar__foot">
          <button
            className="chip"
            onClick={() => setLocale(locale === 'fr' ? 'en' : 'fr')}
            aria-label="Langue"
          >
            {locale.toUpperCase()} ▾
          </button>
          <button className="sidebar__item sidebar__signout" onClick={signOut}>
            {t('adm.nav.signout')}
          </button>
        </div>
      </aside>
      <main>
        <BackButton />
        <Outlet />
      </main>
    </div>
  );
}
