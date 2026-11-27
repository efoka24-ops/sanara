import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { api } from '../api/client';

export interface DesktopState {
  online: boolean;
  ready: boolean;
  syncing: boolean;
  pending: number;
  rejected: { id: string; code: string }[];
  conflicts: number;
  lastSyncAt: string | null;
  clockSkewSeconds: number | null;
  station: string | null;
  pharmacy: string | null;
  user: { email: string; role: string } | null;
  version: string;
  printers?: number;
  progress: { sent: number; total: number } | null;
}

declare global {
  interface Window {
    sanaraDesktop?: { desktop: boolean };
  }
}

interface Desktop {
  /** Vrai dans l'application de bureau seulement. */
  desktop: boolean;
  status: DesktopState | null;
  refresh: () => Promise<void>;
  sync: () => Promise<void>;
}

const Context = createContext<Desktop>({
  desktop: false,
  status: null,
  refresh: async () => undefined,
  sync: async () => undefined,
});

export const useDesktop = () => useContext(Context);

/** T141 : état du poste, relu régulièrement auprès de la passerelle locale. */
export function DesktopProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<DesktopState | null>(null);
  const desktop = window.sanaraDesktop?.desktop === true;

  const load = useCallback(async (path: string) => {
    try {
      setStatus(await api<DesktopState>('GET', path));
    } catch {
      setStatus(null);
    }
  }, []);
  const refresh = useCallback(() => load('/_desktop/status'), [load]);
  const sync = useCallback(() => load('/_desktop/sync'), [load]);

  useEffect(() => {
    if (!desktop) return;
    void refresh();
    const timer = window.setInterval(() => void refresh(), 3000);
    return () => window.clearInterval(timer);
  }, [desktop, refresh]);

  return <Context.Provider value={{ desktop, status, refresh, sync }}>{children}</Context.Provider>;
}
