import { useEffect, useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Button } from '@sanara/ui';
import { Art } from './app/BrandPanel';
import { PlaceSelects } from './app/PlaceSelects';
import { locate, usePlaces } from './lib/publicSearch';

type Health = { status: string; database?: { ok: boolean; version?: string } };

const STEPS = [
  { key: 'find', soon: false },
  { key: 'send', soon: true },
  { key: 'collect', soon: true },
];

/** Accueil public (maquette P1) : chercher une pharmacie d'abord. */
export function Home() {
  const { t } = useT();
  const navigate = useNavigate();
  const { hash } = useLocation();
  const places = usePlaces();
  const [q, setQ] = useState('');
  const [region, setRegion] = useState('');
  const [department, setDepartment] = useState('');
  const [locating, setLocating] = useState(false);
  const [health, setHealth] = useState<Health | 'error' | null>(null);

  useEffect(() => {
    fetch('/api/health')
      .then((r) => r.json())
      .then(setHealth)
      .catch(() => setHealth('error'));
  }, []);

  useEffect(() => {
    if (hash) document.getElementById(hash.slice(1))?.scrollIntoView();
  }, [hash]);

  const query = () => {
    const params = new URLSearchParams();
    if (q.trim()) params.set('q', q.trim());
    if (region) params.set('region', region);
    if (department) params.set('department', department);
    return params.toString();
  };

  function search(e: FormEvent) {
    e.preventDefault();
    navigate({ pathname: '/pharmacies', search: query() });
  }

  async function around() {
    setLocating(true);
    try {
      const position = await locate();
      navigate({ pathname: '/pharmacies', search: query() }, { state: { position } });
    } catch {
      navigate({ pathname: '/pharmacies', search: query() }, { state: { denied: true } });
    }
  }

  const ok = health !== null && health !== 'error' && health.status === 'ok';
  const label = t(health === null ? 'home.checking' : ok ? 'home.online' : 'home.offline');

  return (
    <div className="landing">
      <section className="landing__hero">
        <div>
          <p className="eyebrow">{t('home.eyebrow')}</p>
          <h1>{t('home.headline')}</h1>
          <p className="landing__lead">{t('home.intro')}</p>
          <form className="search-card" onSubmit={search}>
            <label className="pick pick--wide">
              <span>{t('home.where')}</span>
              <input type="search" value={q} onChange={(e) => setQ(e.target.value)} />
            </label>
            <PlaceSelects
              places={places}
              region={region}
              department={department}
              onChange={(r, d) => {
                setRegion(r);
                setDepartment(d);
              }}
            />
            <div className="search-card__actions">
              <Button type="submit">{t('home.search')}</Button>
              <Button type="button" variant="secondary" onClick={around} disabled={locating}>
                {t('home.position')}
              </Button>
            </div>
          </form>
        </div>
        <div className="landing__visual">
          <Art name="carte" />
        </div>
      </section>

      <section id="comment" className="landing__steps" aria-label={t('home.how')}>
        {STEPS.map((s, i) => (
          <article key={s.key} className="panel">
            <span className="landing__number">{i + 1}</span>
            <h2>
              {t(`home.step.${s.key}`)}
              {s.soon && <em>{t('home.soon')}</em>}
            </h2>
            <p>{t(`home.step.${s.key}.text`)}</p>
          </article>
        ))}
      </section>

      <section id="pharmaciens" className="landing__pro">
        <div>
          <h2>{t('home.pro')}</h2>
          <p>{t('home.proText')}</p>
        </div>
        <Link className="sa-btn sa-btn--amber" to="/inscription">
          {t('home.proApply')}
        </Link>
      </section>

      <footer className="landing__foot">
        <p>{t('home.demo')}</p>
        <p>{t('home.note')}</p>
        <div className="home__status" aria-live="polite">
          <span
            className={`home__dot ${ok ? 'home__dot--ok' : health === null ? '' : 'home__dot--bad'}`}
          />
          {label}
        </div>
      </footer>
    </div>
  );
}
