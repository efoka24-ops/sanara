import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Button, Field, Switch } from '@sanara/ui';
import { api } from '../api/client';

const STEPS = ['hours', 'team', 'catalog', 'visibility'] as const;
type Step = (typeof STEPS)[number];
interface Onboarding {
  steps: Record<Step, string>;
  current: Step | null;
  percent: number;
  finished: boolean;
}
interface Day {
  weekday: number;
  is_open: boolean;
  opens: string;
  closes: string;
}
const defaults = (): Day[] =>
  Array.from({ length: 7 }, (_, i) => ({
    weekday: i + 1,
    is_open: i < 6,
    opens: '07:30',
    closes: '21:00',
  }));

export function SetupPage() {
  const { t } = useT();
  const nav = useNavigate();
  const [o, setO] = useState<Onboarding | null>(null);
  const [step, setStep] = useState<Step>('hours');
  const [days, setDays] = useState<Day[]>(defaults());
  const [visible, setVisible] = useState(false);
  const [refDone, setRefDone] = useState(false);
  const [invite, setInvite] = useState({ name: '', email: '', role: 'cashier' });
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<Onboarding>('GET', '/app/onboarding').then((r) => {
      setO(r);
      if (r.current) setStep(r.current);
    });
    api<{ opening_hours: Day[] }>('GET', '/app/settings').then((r) => {
      if (r.opening_hours.length)
        setDays(
          r.opening_hours.map((d) => ({
            ...d,
            opens: d.opens ?? '07:30',
            closes: d.closes ?? '21:00',
          })),
        );
    });
  }, []);

  const idx = STEPS.indexOf(step);
  async function advance(skip = false) {
    setError(null);
    try {
      if (step === 'hours' && !skip) await api('PUT', '/app/opening-hours', { days });
      if (step === 'team' && !skip && invite.email) await api('POST', '/app/team', invite);
      if (step === 'visibility') await api('PUT', '/app/visibility', { visible });
      const r = await api<Onboarding>('POST', `/app/onboarding/${step}`, { skip });
      setO(r);
      if (idx === STEPS.length - 1 || r.finished) nav('/app');
      else setStep(STEPS[idx + 1]!);
    } catch {
      setError(t('errors.server_error'));
    }
  }
  const setDay = (i: number, p: Partial<Day>) =>
    setDays((d) => d.map((x, j) => (j === i ? { ...x, ...p } : x)));

  return (
    <div className="setup">
      <aside className="setup__side">
        <h2>{t('setup.title')}</h2>
        <ol>
          {STEPS.map((s, i) => (
            <li
              key={s}
              className={s === step ? 'is-current' : o?.steps[s] !== 'todo' && o ? 'is-done' : ''}
            >
              <span>{o?.steps[s] !== 'todo' && o && s !== step ? '✓' : i + 1}</span>
              {t(`setup.${s}`)}
            </li>
          ))}
        </ol>
        <p className="muted">{t('setup.progress', { percent: o?.percent ?? 0 })}</p>
      </aside>
      <section className="setup__main">
        {step === 'hours' && (
          <>
            <h1>{t('setup.hoursTitle')}</h1>
            <p className="muted">{t('setup.hoursHint')}</p>
            <div className="panel">
              {days.map((d, i) => (
                <div key={d.weekday} className="hours-row">
                  <b>{t(`day.${d.weekday}`)}</b>
                  <input
                    type="time"
                    value={d.opens}
                    disabled={!d.is_open}
                    onChange={(e) => setDay(i, { opens: e.target.value })}
                    aria-label="open"
                  />
                  <input
                    type="time"
                    value={d.closes}
                    disabled={!d.is_open}
                    onChange={(e) => setDay(i, { closes: e.target.value })}
                    aria-label="close"
                  />
                  <span>
                    {t('setup.open')}{' '}
                    <Switch
                      checked={d.is_open}
                      onChange={(v) => setDay(i, { is_open: v })}
                      label={t(`day.${d.weekday}`)}
                    />
                  </span>
                </div>
              ))}
            </div>
          </>
        )}
        {step === 'team' && (
          <>
            <h1>{t('setup.teamTitle')}</h1>
            <p className="muted">{t('setup.teamHint')}</p>
            <div className="panel">
              <Field
                label={t('team.name')}
                value={invite.name}
                onChange={(e) => setInvite({ ...invite, name: e.target.value })}
              />
              <Field
                label={t('team.email')}
                type="email"
                value={invite.email}
                onChange={(e) => setInvite({ ...invite, email: e.target.value })}
              />
              <label className="sa-field">
                <span className="sa-field__label">{t('team.role')}</span>
                <select
                  className="sa-field__input"
                  value={invite.role}
                  onChange={(e) => setInvite({ ...invite, role: e.target.value })}
                >
                  {['pharmacist', 'storekeeper', 'preparateur', 'cashier', 'accountant'].map(
                    (r) => (
                      <option key={r} value={r}>
                        {t(`role.${r}`)}
                      </option>
                    ),
                  )}
                </select>
              </label>
            </div>
          </>
        )}
        {step === 'catalog' && (
          <>
            <h1>{t('setup.catalogTitle')}</h1>
            <p className="muted">{t('setup.catalogHint')}</p>
            <div className="panel">
              <b>Catalogue Sanara</b>
              <Button
                variant="secondary"
                onClick={async () => {
                  await api('POST', '/app/products/reference');
                  setRefDone(true);
                }}
              >
                {t('cat.reference')}
              </Button>
              {refDone && (
                <div role="status" className="notice">
                  ✓ {t('cat.reference')}
                </div>
              )}
            </div>
          </>
        )}
        {step === 'visibility' && (
          <>
            <h1>{t('setup.visTitle')}</h1>
            <div className="panel row">
              <div>
                <b>{t('setup.visPublic')}</b>
                <div className="muted">{t('setup.visPublicHint')}</div>
              </div>
              <Switch checked={visible} onChange={setVisible} label={t('setup.visPublic')} />
            </div>
          </>
        )}
        {error && (
          <div role="alert" className="form-error">
            {error}
          </div>
        )}
        <div className="actions">
          <button className="link" onClick={() => advance(true)}>
            {t('setup.skip')}
          </button>
          <Button onClick={() => advance(false)}>
            {idx === STEPS.length - 1 ? t('setup.finish') : t('common.continue')}
          </Button>
        </div>
      </section>
    </div>
  );
}
