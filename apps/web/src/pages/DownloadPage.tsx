import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Art } from '../app/BrandPanel';

interface Release {
  version: string;
  file: string;
  sizeMb: number | null;
  date: string | null;
}

/** Lit latest.yml, publié avec l'installateur (même fichier que pour les mises à jour automatiques). */
function parse(yml: string): Release | null {
  const field = (name: string) =>
    new RegExp(`^${name}:\\s*'?([^'\\n]+)'?\\s*$`, 'm').exec(yml)?.[1]?.trim();
  const version = field('version');
  const file = field('path');
  const size = /^\s+size:\s*(\d+)/m.exec(yml)?.[1];
  if (!version || !file || !/^[\w.-]+\.exe$/.test(file)) return null;
  return {
    version,
    file,
    sizeMb: size ? Math.round(Number(size) / 1048576) : null,
    date: field('releaseDate')?.slice(0, 10) ?? null,
  };
}

/** Page de téléchargement de l'application Windows du poste (pharmacies). */
export function DownloadPage() {
  const { t } = useT();
  const [release, setRelease] = useState<Release | null | undefined>(undefined);

  useEffect(() => {
    fetch('/desktop-releases/latest.yml', { cache: 'no-cache' })
      .then((r) => (r.ok ? r.text() : ''))
      .then((text) => setRelease(parse(text)))
      .catch(() => setRelease(null));
  }, []);

  return (
    <div className="download">
      <section className="download__hero">
        <div>
          <p className="eyebrow">{t('dl.eyebrow')}</p>
          <h1>{t('dl.title')}</h1>
          <p className="landing__lead">{t('dl.lead')}</p>
          {release === undefined && <p className="muted">{t('common.loading')}</p>}
          {release === null && <p className="notice">{t('dl.none')}</p>}
          {release && (
            <>
              <a className="sa-btn" href={`/desktop-releases/${release.file}`} download>
                {t('dl.button', { v: release.version })}
              </a>
              <p className="muted">
                {t('dl.meta', {
                  size: release.sizeMb ?? '?',
                  date: release.date ?? '',
                })}
              </p>
            </>
          )}
        </div>
        <div className="landing__visual">
          <Art name="hors-ligne" />
        </div>
      </section>

      <section className="download__steps">
        <article className="panel">
          <h2>{t('dl.needTitle')}</h2>
          <ul>
            <li>{t('dl.need1')}</li>
            <li>{t('dl.need2')}</li>
            <li>{t('dl.need3')}</li>
          </ul>
        </article>
        <article className="panel">
          <h2>{t('dl.installTitle')}</h2>
          <ol>
            <li>{t('dl.install1')}</li>
            <li>{t('dl.install2')}</li>
            <li>{t('dl.install3')}</li>
            <li>{t('dl.install4')}</li>
          </ol>
          <p className="notice">{t('dl.unsigned')}</p>
        </article>
        <article className="panel">
          <h2>{t('dl.noAccountTitle')}</h2>
          <p>{t('dl.noAccount')}</p>
          <Link className="sa-btn sa-btn--secondary" to="/inscription">
            {t('home.proApply')}
          </Link>
        </article>
      </section>
    </div>
  );
}
