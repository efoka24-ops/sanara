import { Navigate, Outlet } from 'react-router-dom';
import { useSession } from './session';

/** Garde d'accès : le serveur reste l'autorité (RBAC API), ce garde évite seulement d'afficher des écrans inutiles. */
export function RequireArea({ area }: { area: 'pharmacy' | 'admin' }) {
  const { session } = useSession();
  if (!session) return <Navigate to="/connexion" replace />;
  if (session.mfaSetupRequired) return <Navigate to="/securite" replace />;
  const isAdmin = session.role === 'super_admin';
  if (area === 'admin' && !isAdmin) return <Navigate to="/app" replace />;
  if (area === 'pharmacy' && isAdmin) return <Navigate to="/admin" replace />;
  return <Outlet />;
}

export function RequireSession() {
  const { session } = useSession();
  return session ? <Outlet /> : <Navigate to="/connexion" replace />;
}
