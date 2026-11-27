import { useT } from '@sanara/i18n';
import { Switch } from '@sanara/ui';

export interface Day {
  weekday: number;
  is_open: boolean;
  opens: string;
  closes: string;
}

export const defaultDays = (): Day[] =>
  Array.from({ length: 7 }, (_, i) => ({
    weekday: i + 1,
    is_open: i < 6,
    opens: '07:30',
    closes: '21:00',
  }));

/** Horaires enregistrés, ou proposition par défaut tant qu'il n'y en a pas. */
export const savedDays = (rows: Day[]): Day[] =>
  rows.length
    ? rows.map((d) => ({ ...d, opens: d.opens ?? '07:30', closes: d.closes ?? '21:00' }))
    : defaultDays();

export function HoursEditor({ days, onChange }: { days: Day[]; onChange: (days: Day[]) => void }) {
  const { t } = useT();
  const setDay = (i: number, p: Partial<Day>) =>
    onChange(days.map((x, j) => (j === i ? { ...x, ...p } : x)));

  return (
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
  );
}
