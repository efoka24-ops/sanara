import { useCallback, useEffect, useRef, useState } from 'react';
import { useT } from '@sanara/i18n';
import { Button, Field } from '@sanara/ui';
import { api, ApiError } from '../api/client';

/** Verrouille l'écran après inactivité ; se déverrouille avec le PIN (T078). Actif seulement si un PIN est défini. */
export function IdleLock({ minutes = 5 }: { minutes?: number }) {
  const { t } = useT();
  const [locked, setLocked] = useState(false);
  const [pin, setPin] = useState('');
  const [msg, setMsg] = useState<string | null>(null);
  const timer = useRef<number | undefined>(undefined);
  const arm = useCallback(() => {
    window.clearTimeout(timer.current);
    if (sessionStorage.getItem('sa_pin'))
      timer.current = window.setTimeout(() => setLocked(true), minutes * 60_000);
  }, [minutes]);
  useEffect(() => {
    const ev = ['pointerdown', 'keydown', 'wheel'];
    const h = () => {
      if (!locked) arm();
    };
    ev.forEach((e) => window.addEventListener(e, h));
    arm();
    return () => {
      ev.forEach((e) => window.removeEventListener(e, h));
      window.clearTimeout(timer.current);
    };
  }, [arm, locked]);
  if (!locked) return null;
  async function unlock() {
    try {
      const r = await api<{ valid: boolean }>('POST', '/auth/pin/verify', { pin });
      if (r.valid) {
        setLocked(false);
        setPin('');
        setMsg(null);
      } else setMsg(t('lock.wrong'));
    } catch (e) {
      setMsg(e instanceof ApiError && e.status === 423 ? t('lock.locked') : t('lock.wrong'));
    }
  }
  return (
    <div
      className="sa-modal__backdrop"
      role="dialog"
      aria-modal="true"
      aria-label={t('lock.title')}
    >
      <div className="sa-modal">
        <h2 className="sa-modal__title">{t('lock.title')}</h2>
        <Field
          label="PIN"
          type="password"
          inputMode="numeric"
          maxLength={4}
          value={pin}
          onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
          autoFocus
        />
        {msg && (
          <div role="alert" className="form-error">
            {msg}
          </div>
        )}
        <Button block onClick={unlock} disabled={pin.length !== 4}>
          {t('lock.unlock')}
        </Button>
      </div>
    </div>
  );
}
