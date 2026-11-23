import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';

export type Role =
  'super_admin' | 'owner' | 'pharmacist' | 'storekeeper' | 'cashier' | 'accountant';
export interface Session {
  role: Role;
  permissions: string[];
}

interface Ctx {
  session: Session | null;
  signIn: (s: Session) => void;
  signOut: () => void;
  can: (p: string) => boolean;
}
const SessionContext = createContext<Ctx>({
  session: null,
  signIn: () => undefined,
  signOut: () => undefined,
  can: () => false,
});

/** Session en mémoire : le jeton d'accès n'est jamais écrit dans localStorage. Le refresh passe par l'API (US5). */
export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session | null>(null);
  const value = useMemo<Ctx>(
    () => ({
      session,
      signIn: setSession,
      signOut: () => setSession(null),
      can: (p) => session?.permissions.includes(p) ?? false,
    }),
    [session],
  );
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}
export const useSession = () => useContext(SessionContext);
