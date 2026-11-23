import { useEffect, useState } from 'react';
import { Logo } from '@sanara/ui';

type Health = { status: string; database?: { ok: boolean; version?: string } };

export function App() {
  const [health, setHealth] = useState<Health | 'error' | null>(null);

  useEffect(() => {
    fetch('/api/health')
      .then((r) => r.json())
      .then(setHealth)
      .catch(() => setHealth('error'));
  }, []);

  const ok = health !== null && health !== 'error' && health.status === 'ok';
  const label =
    health === null ? 'Vérification du service…' : ok ? 'Service en ligne' : 'Service indisponible';

  return (
    <main className="home">
      <section className="home__brand">
        <Logo size={40} tone="light" />
        <h1 className="home__title">Votre pharmacie, même quand le réseau coupe.</h1>
        <p className="home__lead">Stock, caisse, ordonnances et assistant au même endroit.</p>
      </section>
      <section className="home__panel">
        <h2>Bienvenue</h2>
        <p style={{ color: 'var(--sa-muted)', margin: 0 }}>
          Sanara est en construction. Cette version est une démonstration : n’y saisissez aucune
          donnée réelle.
        </p>
        <div className="home__actions">
          <a className="sa-btn" href="#">
            Digitaliser ma pharmacie
          </a>
          <a className="sa-btn sa-btn--ghost" href="#">
            Trouver une pharmacie
          </a>
        </div>
        <div className="home__status" aria-live="polite">
          <span
            className={`home__dot ${ok ? 'home__dot--ok' : health === 'error' || (health && !ok) ? 'home__dot--bad' : ''}`}
          />
          {label}
        </div>
        <p className="home__note">Version de démonstration, données de test uniquement.</p>
      </section>
    </main>
  );
}
