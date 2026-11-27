import { useEffect, useState } from 'react';
import { useT } from '@sanara/i18n';
import { Switch } from '@sanara/ui';
import { api } from '../api/client';
import { useDesktop } from './desktop';

const BEAT_MS = 15000;

/**
 * T188, T193 : signe de vie toutes les 15 s tant que l'écran est ouvert, et choix d'être disponible ou
 * non pour les demandes des patients. Le patient voit alors si un pharmacien est connecté.
 */
export function Presence() {
  const { t } = useT();
  const { status } = useDesktop();
  const [available, setAvailable] = useState(true);

  useEffect(() => {
    // Hors ligne, le poste ne peut pas répondre aux patients : rien n'est annoncé.
    if (status && !status.online) return;
    const beat = () => void api('POST', '/app/presence', { available }).catch(() => undefined);
    beat();
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') beat();
    }, BEAT_MS);
    return () => window.clearInterval(timer);
  }, [available, status?.online]);

  return (
    <div className="presence">
      <span className={`home__dot ${available ? 'home__dot--ok' : ''}`} />
      <span>{t(available ? 'presence.on' : 'presence.off')}</span>
      <Switch checked={available} onChange={setAvailable} label={t('presence.label')} />
    </div>
  );
}
