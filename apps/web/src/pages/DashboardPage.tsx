import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { api } from '../api/client';

export function DashboardPage() {
  const { t } = useT();
  const [o, setO] = useState<{ percent: number; finished: boolean } | null>(null);
  useEffect(() => {
    api<{ percent: number; finished: boolean }>('GET', '/app/onboarding')
      .then(setO)
      .catch(() => setO(null));
  }, []);
  return (
    <div>
      <h1>{t('nav.dashboard')}</h1>
      {o && !o.finished && (
        <div className="panel row">
          <div>
            <b>{t('setup.progress', { percent: o.percent })}</b>
          </div>
          <Link className="sa-btn" to="/app/configuration">
            {t('setup.resume')}
          </Link>
        </div>
      )}
    </div>
  );
}
