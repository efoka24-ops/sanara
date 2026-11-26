import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Logo } from '@sanara/ui';

type Health = { status: string; database?: { ok: boolean; version?: string } };

export function Home() {
  const { t } = useT();
  const [health, setHealth] = useState<Health | 'error' | null>(null);

  useEffect(() => {
    fetch('/api/health')
      .then((r) => r.json())
      .then(setHealth)
      .catch(() => setHealth('error'));
  }, []);

  const ok = health !== null && health !== 'error' && health.status === 'ok';
  const label = t(health === null ? 'home.checking' : ok ? 'home.online' : 'home.offline');

  return (
    <div className="home">
      <section className="home__brand">
        <Logo size={40} tone="light" />
        <h1 className="home__title">{t('home.title')}</h1>
        <p className="home__lead">{t('home.lead')}</p>
      </section>
      <section className="home__panel">
        <h2>{t('home.welcome')}</h2>
        <p className="muted">{t('home.demo')}</p>
        <div className="home__actions">
          <Link className="sa-btn" to="/inscription">
            {t('home.apply')}
          </Link>
          <Link className="sa-btn sa-btn--secondary" to="/pharmacies">
            {t('home.find')}
          </Link>
        </div>
        <div className="home__status" aria-live="polite">
          <span
            className={`home__dot ${ok ? 'home__dot--ok' : health === null ? '' : 'home__dot--bad'}`}
          />
          {label}
        </div>
        <p className="home__note">{t('home.note')}</p>
      </section>
    </div>
  );
}
