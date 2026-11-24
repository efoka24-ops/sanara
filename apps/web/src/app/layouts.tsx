import { NavLink, Outlet } from 'react-router-dom';
import { Logo } from '@sanara/ui';
import { useT } from '@sanara/i18n';
import { useSession } from './session';
import { IdleLock } from './IdleLock';

const PHARMACY_MENU: Array<{ to: string; key: string; permission?: string }> = [
  { to: '/app', key: 'nav.dashboard' },
  { to: '/app/demandes', key: 'nav.requests', permission: 'requests.process' },
  { to: '/app/caisse', key: 'nav.pos', permission: 'sales.create' },
  { to: '/app/ventes', key: 'nav.sales', permission: 'sales.create' },
  { to: '/app/produits', key: 'nav.products', permission: 'products.read' },
  { to: '/app/stock', key: 'nav.stock', permission: 'stock.read' },
  { to: '/app/achats', key: 'nav.purchases', permission: 'purchases.write' },
  { to: '/app/patients', key: 'nav.patients', permission: 'patients.read' },
  { to: '/app/credit', key: 'nav.credit', permission: 'finance.read' },
  { to: '/app/finance', key: 'nav.finance', permission: 'finance.read' },
  { to: '/app/rapports', key: 'nav.reports', permission: 'reports.read' },
  { to: '/app/assistant', key: 'nav.assistant', permission: 'assistant.configure' },
  { to: '/app/equipe', key: 'nav.team', permission: 'team.manage' },
  { to: '/app/parametres', key: 'nav.settings', permission: 'settings.write' },
  { to: '/securite', key: 'nav.security' },
];

const ADMIN_MENU = [
  'Tableau de bord',
  'Demandes d’accès',
  'Pharmacies',
  'Abonnements',
  'Facturation',
  'Signalements',
  'Modèles d’e-mails',
  'Journal d’audit',
  'Pays et zones',
  'Paramètres',
];

export function PublicLayout() {
  const { t, locale, setLocale } = useT();
  return (
    <div className="layout layout--public">
      <header className="topbar">
        <Logo size={30} />
        <nav className="topbar__actions">
          <button
            className="chip"
            onClick={() => setLocale(locale === 'fr' ? 'en' : 'fr')}
            aria-label="Langue"
          >
            {locale.toUpperCase()} ▾
          </button>
          <NavLink to="/connexion">{t('auth.login')}</NavLink>
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
        <Outlet />
      </main>
      <IdleLock />
    </div>
  );
}

/** Console super admin : barre latérale sombre avec pastille ADMIN. */
export function AdminLayout() {
  return (
    <div className="layout layout--admin">
      <aside className="sidebar sidebar--dark">
        <div className="sidebar__brand">
          <Logo size={24} tone="light" />
          <span className="pill-admin">ADMIN</span>
        </div>
        <nav>
          {ADMIN_MENU.map((m) => (
            <span key={m} className="sidebar__item">
              {m}
            </span>
          ))}
          <NavLink to="/securite" className="sidebar__item">
            Sécurité
          </NavLink>
        </nav>
      </aside>
      <main>
        <Outlet />
      </main>
    </div>
  );
}
