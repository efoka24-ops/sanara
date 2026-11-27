import { useEffect, useState } from 'react';
import { useT } from '@sanara/i18n';
import { api } from '../api/client';

interface Subscription {
  state: 'none' | 'trial' | 'active' | 'grace' | 'expired';
  ends_at: string | null;
  days_left: number | null;
}

/** T255 : prévient avant la fin de l'essai ou de la période, puis pendant la grâce et la lecture seule. */
export function SubscriptionBanner() {
  const { t } = useT();
  const [sub, setSub] = useState<Subscription | null>(null);
  useEffect(() => {
    api<Subscription>('GET', '/app/subscription')
      .then(setSub)
      .catch(() => undefined);
  }, []);
  if (!sub || sub.state === 'none') return null;
  const date = sub.ends_at?.slice(0, 10) ?? '';
  if (sub.state === 'expired')
    return (
      <p role="alert" className="form-error subscription">
        {t('sub.expired', { date })}
      </p>
    );
  if (sub.state === 'grace')
    return <p className="notice subscription">{t('sub.grace', { date })}</p>;
  if (sub.days_left !== null && sub.days_left <= 7)
    return (
      <p className="notice subscription">
        {t(sub.state === 'trial' ? 'sub.trialEnds' : 'sub.periodEnds', { date, n: sub.days_left })}
      </p>
    );
  return null;
}
