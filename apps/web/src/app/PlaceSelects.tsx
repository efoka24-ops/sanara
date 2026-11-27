import { useT } from '@sanara/i18n';
import { distinct, type Place } from '../lib/publicSearch';

/** T147 : région puis département, limités aux lieux où une pharmacie est publiée. */
export function PlaceSelects({
  places,
  region,
  department,
  onChange,
}: {
  places: Place[];
  region: string;
  department: string;
  onChange: (region: string, department: string) => void;
}) {
  const { t } = useT();
  const regions = distinct(places.map((p) => p.region));
  const departments = distinct(
    places.filter((p) => !region || p.region === region).map((p) => p.department),
  );
  // Une valeur venue de l'adresse reste affichée tant que la liste n'est pas arrivée.
  const withValue = (list: string[], value: string) =>
    value && !list.includes(value) ? [value, ...list] : list;

  return (
    <>
      <label className="pick">
        <span>{t('pub.region')}</span>
        <select value={region} onChange={(e) => onChange(e.target.value, '')}>
          <option value="">{t('pub.allRegions')}</option>
          {withValue(regions, region).map((r) => (
            <option key={r}>{r}</option>
          ))}
        </select>
      </label>
      <label className="pick">
        <span>{t('pub.department')}</span>
        <select value={department} onChange={(e) => onChange(region, e.target.value)}>
          <option value="">{t('pub.allDepartments')}</option>
          {withValue(departments, department).map((d) => (
            <option key={d}>{d}</option>
          ))}
        </select>
      </label>
    </>
  );
}
