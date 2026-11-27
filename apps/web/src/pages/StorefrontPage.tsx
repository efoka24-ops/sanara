import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Button, Field, Switch, useToast } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { HoursEditor, savedDays, type Day } from '../app/HoursEditor';
import { useErrorText } from '../lib/common';
import { dutyLabel } from '../lib/publicSearch';

const TEXT = ['region', 'department', 'city', 'district', 'address', 'phone', 'email'] as const;
type TextKey = (typeof TEXT)[number];
type Profile = Record<TextKey, string> & {
  latitude: number | null;
  longitude: number | null;
  services: string[];
  insurances: string[];
};
interface Storefront {
  profile: Profile;
  duties: { id: string; starts: string; ends: string }[];
  available_services: string[];
  visible: boolean;
}
type Form = Record<TextKey | 'latitude' | 'longitude' | 'insurances', string> & {
  services: string[];
};

const toForm = (p: Profile): Form => ({
  ...p,
  latitude: p.latitude === null ? '' : String(p.latitude),
  longitude: p.longitude === null ? '' : String(p.longitude),
  insurances: p.insurances.join('\n'),
});

/** US19 : ce que la pharmacie montre au public, ses horaires et ses gardes. */
export function StorefrontPage() {
  const { t, locale } = useT();
  const toast = useToast();
  const errText = useErrorText();
  const [data, setData] = useState<Storefront | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [days, setDays] = useState<Day[] | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [duty, setDuty] = useState({ date: '', from: '20:00', to: '08:00' });
  const [error, setError] = useState<string | null>(null);
  const [urgency, setUrgency] = useState({ minutes: '3', outside: false });

  const reload = () => api<Storefront>('GET', '/app/storefront').then(setData);

  useEffect(() => {
    // Une réponse devenue inutile ne doit pas effacer ce qui est déjà saisi.
    let live = true;
    Promise.all([
      api<Storefront>('GET', '/app/storefront'),
      api<{ opening_hours: Day[]; settings: Record<string, unknown> }>('GET', '/app/settings'),
    ])
      .then(([store, settings]) => {
        if (!live) return;
        setData(store);
        setForm(toForm(store.profile));
        setDays(savedDays(settings.opening_hours));
        setUrgency({
          minutes: String(settings.settings.urgent_escalation_minutes ?? 3),
          outside: settings.settings.urgent_outside_hours === true,
        });
      })
      .catch((e) => live && setError(errText(e)));
    return () => {
      live = false;
    };
  }, []);

  /** Exécute une action ; les refus champ par champ s'affichent près des champs. */
  async function run(action: () => Promise<unknown>, done = t('common.saved')) {
    setFields({});
    try {
      await action();
      toast(done);
    } catch (e) {
      const invalid = e instanceof ApiError ? (e.details.fields as Record<string, string>) : null;
      setFields(invalid ?? {});
      toast(invalid ? t('store.invalid') : errText(e), 'error');
    }
  }

  const problem = (key: string) => (fields[key] ? t(`store.error.${fields[key]}`) : undefined);

  if (error)
    return (
      <div role="alert" className="form-error">
        {error}
      </div>
    );
  if (!data || !form || !days) return <p className="muted">{t('common.loading')}</p>;
  const set = (values: Partial<Form>) => setForm({ ...form, ...values });

  function here() {
    navigator.geolocation?.getCurrentPosition(
      ({ coords }) =>
        setForm(
          (f) =>
            f && {
              ...f,
              latitude: coords.latitude.toFixed(6),
              longitude: coords.longitude.toFixed(6),
            },
        ),
      () => toast(t('store.noPosition'), 'error'),
      { enableHighAccuracy: true, timeout: 15000 },
    );
  }

  function save(e: FormEvent) {
    e.preventDefault();
    const f = form!;
    void run(async () => {
      await api('PUT', '/app/storefront', {
        ...f,
        latitude: f.latitude.trim().replace(',', '.') || null,
        longitude: f.longitude.trim().replace(',', '.') || null,
        insurances: f.insurances.split('\n'),
      });
      await reload();
    });
  }

  return (
    <div className="stack storefront">
      <h1>{t('store.title')}</h1>

      <section className="panel row">
        <div>
          <b>{t('setup.visPublic')}</b>
          <div className="muted">{t('setup.visPublicHint')}</div>
          <Link to="/pharmacies">{t('store.see')}</Link>
        </div>
        <Switch
          checked={data.visible}
          label={t('setup.visPublic')}
          onChange={(visible) =>
            void run(async () => {
              await api('PUT', '/app/visibility', { visible });
              await reload();
            })
          }
        />
      </section>

      <form className="panel stack" onSubmit={save} noValidate>
        <h2>{t('store.place')}</h2>
        <div className="cols2">
          {TEXT.map((k) => (
            <Field
              key={k}
              label={t(`store.${k}`)}
              value={form[k]}
              error={problem(k)}
              onChange={(e) => set({ [k]: e.target.value })}
            />
          ))}
        </div>
        <p className="muted">{t('store.pointHint')}</p>
        <div className="cols2">
          <Field
            label={t('store.latitude')}
            inputMode="decimal"
            value={form.latitude}
            error={problem('latitude')}
            onChange={(e) => set({ latitude: e.target.value })}
          />
          <Field
            label={t('store.longitude')}
            inputMode="decimal"
            value={form.longitude}
            error={problem('longitude')}
            onChange={(e) => set({ longitude: e.target.value })}
          />
        </div>
        <div>
          <Button type="button" variant="secondary" onClick={here}>
            {t('store.here')}
          </Button>
        </div>
        <fieldset className="storefront__services">
          <legend>{t('sheet.services')}</legend>
          {data.available_services.map((s) => (
            <label key={s}>
              <input
                type="checkbox"
                checked={form.services.includes(s)}
                onChange={(e) =>
                  set({
                    services: e.target.checked
                      ? [...form.services, s]
                      : form.services.filter((x) => x !== s),
                  })
                }
              />{' '}
              {t(`service.${s}`)}
            </label>
          ))}
        </fieldset>
        <label className="sa-field">
          <span className="sa-field__label">{t('store.insurances')}</span>
          <textarea
            className="sa-field__input"
            rows={4}
            value={form.insurances}
            onChange={(e) => set({ insurances: e.target.value })}
          />
        </label>
        {problem('insurances') && <div className="form-error">{problem('insurances')}</div>}
        <div>
          <Button type="submit">{t('store.save')}</Button>
        </div>
      </form>

      <section className="stack">
        <h2>{t('sheet.hours')}</h2>
        <HoursEditor days={days} onChange={setDays} />
        <div>
          <Button onClick={() => void run(() => api('PUT', '/app/opening-hours', { days }))}>
            {t('store.saveHours')}
          </Button>
        </div>
      </section>

      <section className="panel stack">
        <h2>{t('store.urgency')}</h2>
        <p className="muted">{t('store.urgencyHint')}</p>
        <div className="storefront__duty">
          <Field
            label={t('store.urgencyMinutes')}
            type="number"
            min={1}
            max={30}
            value={urgency.minutes}
            error={problem('urgent_escalation_minutes')}
            onChange={(e) => setUrgency({ ...urgency, minutes: e.target.value })}
          />
          <label className="check">
            <Switch
              checked={urgency.outside}
              onChange={(outside) => setUrgency({ ...urgency, outside })}
              label={t('store.urgencyOutside')}
            />
            <span>{t('store.urgencyOutside')}</span>
          </label>
          <Button
            onClick={() =>
              void run(() =>
                api('PUT', '/app/settings', {
                  settings: {
                    urgent_escalation_minutes: Number(urgency.minutes),
                    urgent_outside_hours: urgency.outside,
                  },
                }),
              )
            }
          >
            {t('common.save')}
          </Button>
        </div>
      </section>

      <section className="panel stack">
        <h2>{t('store.duties')}</h2>
        <p className="muted">{t('store.dutiesHint')}</p>
        <form
          className="storefront__duty"
          noValidate
          onSubmit={(e) => {
            e.preventDefault();
            void run(async () => {
              await api('POST', '/app/storefront/duties', duty);
              setDuty({ ...duty, date: '' });
              await reload();
            });
          }}
        >
          {(['date', 'from', 'to'] as const).map((k) => (
            <Field
              key={k}
              label={t(`store.duty.${k}`)}
              type={k === 'date' ? 'date' : 'time'}
              value={duty[k]}
              error={problem(k)}
              onChange={(e) => setDuty({ ...duty, [k]: e.target.value })}
            />
          ))}
          <Button type="submit">{t('store.addDuty')}</Button>
        </form>
        {data.duties.length === 0 && <p className="muted">{t('sheet.noDuty')}</p>}
        <ul className="storefront__list">
          {data.duties.map((d) => (
            <li key={d.id} className="row">
              <span>{dutyLabel(t, locale, { ...d, now: false })}</span>
              <button
                type="button"
                className="link-strong"
                onClick={() =>
                  void run(async () => {
                    await api('DELETE', `/app/storefront/duties/${d.id}`);
                    await reload();
                  }, t('store.dutyRemoved'))
                }
              >
                {t('store.remove')}
              </button>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
