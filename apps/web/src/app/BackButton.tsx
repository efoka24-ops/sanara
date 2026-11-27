import { useLocation, useNavigate } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { useDesktop } from './desktop';

/** Écrans d'accueil : rien au-dessus d'eux. La demande d'accès a son propre retour, étape par étape. */
const NONE = ['/', '/app', '/admin', '/inscription'];

/** Écran parent, quand l'écran a été ouvert directement. */
function parentOf(pathname: string): string {
  if (!/^\/(app|admin)\//.test(pathname)) return '/';
  return pathname.slice(0, pathname.lastIndexOf('/'));
}

/** Retour à l'écran précédent, présent sur chaque écran : le poste n'a pas les flèches d'un navigateur. */
export function BackButton() {
  const { pathname, key } = useLocation();
  const navigate = useNavigate();
  const { desktop } = useDesktop();
  const { t } = useT();
  const first = key === 'default';
  // Sur le poste, la connexion est le premier écran.
  if (NONE.includes(pathname) || (desktop && first && pathname === '/connexion')) return null;
  return (
    <button
      type="button"
      className="back"
      onClick={() => (first ? navigate(parentOf(pathname)) : navigate(-1))}
    >
      ← {t('common.back')}
    </button>
  );
}
