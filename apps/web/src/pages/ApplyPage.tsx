import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Button, Field, Stepper } from '@sanara/ui';
import { api, ApiError, readAsBase64 } from '../api/client';
import { BrandPanel } from '../app/BrandPanel';

const DOCS = ['license', 'diploma', 'order_card', 'identity'] as const;
const MAX = 2 * 1024 * 1024;
type Form = Record<string, string>;
type Doc = { name: string; base64: string };

export function ApplyPage() {
  const { t, locale } = useT();
  const nav = useNavigate();
  const [step, setStep] = useState(0);
  const [f, setF] = useState<Form>({ pos_count: '1' });
  const [duty, setDuty] = useState(false);
  const [docs, setDocs] = useState<Record<string, Doc>>({});
  const [agree, setAgree] = useState({ truth: false, terms: false, data: false });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const set = (k: string) => (e: { target: { value: string } }) =>
    setF((x) => ({ ...x, [k]: e.target.value }));
  const err = (k: string) => (errors[k] ? t('common.required') : undefined);
  const input = (k: string, label: string, extra: object = {}) => (
    <Field
      label={label + (['pharmacy_email', 'address'].includes(k) ? '' : ' *')}
      value={f[k] ?? ''}
      onChange={set(k)}
      error={err(k)}
      {...extra}
    />
  );

  const required: string[][] = [
    ['pharmacy_name', 'license_number', 'license_date', 'pharmacy_phone'],
    ['region', 'department', 'city', 'district'],
    [],
    ['owner_last_name', 'owner_first_name', 'owner_order_number', 'owner_phone', 'owner_email'],
  ];

  function next() {
    const missing = Object.fromEntries(
      required[step]!.filter((k) => !f[k]?.trim()).map((k) => [k, 'required']),
    );
    if (step === 2) for (const d of DOCS) if (!docs[d]) missing[`doc_${d}`] = 'required';
    setErrors(missing);
    if (Object.keys(missing).length === 0) setStep(step + 1);
  }

  async function pick(type: string, file?: File) {
    if (!file) return;
    if (file.size > MAX) {
      setBanner(t('apply.fileTooBig'));
      return;
    }
    setBanner(null);
    setDocs((d) => ({ ...d, [type]: { name: file.name, base64: '' } }));
    const base64 = await readAsBase64(file);
    setDocs((d) => ({ ...d, [type]: { name: file.name, base64 } }));
  }

  function locate() {
    navigator.geolocation?.getCurrentPosition((p) =>
      setF((x) => ({
        ...x,
        latitude: p.coords.latitude.toFixed(6),
        longitude: p.coords.longitude.toFixed(6),
      })),
    );
  }

  async function submit() {
    const missing = Object.fromEntries(
      required[3]!.filter((k) => !f[k]?.trim()).map((k) => [k, 'required']),
    );
    setErrors(missing);
    if (Object.keys(missing).length || !agree.truth || !agree.terms || !agree.data) return;
    setBusy(true);
    setBanner(null);
    try {
      const r = await api<{ number: string; tracking_code: string }>(
        'POST',
        '/public/applications',
        {
          ...f,
          country_code: 'CM',
          pos_count: Number(f.pos_count) || 1,
          takes_duty: duty,
          locale,
          latitude: f.latitude ? Number(f.latitude) : null,
          longitude: f.longitude ? Number(f.longitude) : null,
          accept_truth: agree.truth,
          accept_terms: agree.terms,
          accept_data: agree.data,
          documents: docs,
        },
      );
      if (!r?.number || !r?.tracking_code) throw new ApiError(500, 'server_error');
      nav('/inscription/envoyee', { state: r });
    } catch (e) {
      if (e instanceof ApiError) {
        const fields = (e.details.fields ?? {}) as Record<string, string>;
        setErrors(fields);
        setBanner(
          e.code === 'conflict'
            ? t('apply.duplicate')
            : e.code === 'too_many_requests'
              ? t('apply.tooMany')
              : t('apply.invalid'),
        );
      } else setBanner(t('errors.server_error'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="brand-split brand-split--aside">
      <div className="card-form card-form--wide">
        <Stepper
          steps={[t('apply.s1'), t('apply.s2'), t('apply.s3'), t('apply.s4')]}
          current={step}
        />
        {step === 0 && (
          <>
            <h1>{t('apply.officineTitle')}</h1>
            {input('pharmacy_name', t('apply.name'))}
            <div className="grid2">
              {input('license_number', t('apply.license'))}
              {input('license_date', t('apply.licenseDate'), { type: 'date' })}
            </div>
            <div className="grid2">
              {input('pharmacy_phone', t('apply.phone'), { type: 'tel' })}
              {input('pharmacy_email', t('apply.email'), { type: 'email' })}
            </div>
            <Field
              label={t('apply.pos')}
              type="number"
              min={1}
              value={f.pos_count ?? '1'}
              onChange={set('pos_count')}
            />
            <label className="check">
              <input type="checkbox" checked={duty} onChange={(e) => setDuty(e.target.checked)} />{' '}
              {t('apply.duty')}
            </label>
          </>
        )}
        {step === 1 && (
          <>
            <h1>{t('apply.locTitle')}</h1>
            <div className="grid2">
              {input('region', t('apply.region'))}
              {input('department', t('apply.department'))}
            </div>
            <div className="grid2">
              {input('city', t('apply.city'))}
              {input('district', t('apply.district'))}
            </div>
            {input('address', t('apply.address'))}
            <div className="grid2">
              <Field label={t('apply.lat')} value={f.latitude ?? ''} onChange={set('latitude')} />
              <Field label={t('apply.lng')} value={f.longitude ?? ''} onChange={set('longitude')} />
            </div>
            <Button type="button" variant="secondary" onClick={locate}>
              {t('apply.useMyPosition')}
            </Button>
          </>
        )}
        {step === 2 && (
          <>
            <h1>{t('apply.docsTitle')}</h1>
            {DOCS.map((d) => (
              <label
                key={d}
                className={`dropzone${docs[d] ? ' dropzone--ok' : ''}${errors[`doc_${d}`] ? ' dropzone--error' : ''}`}
              >
                <strong>{t(`apply.doc.${d}`)} *</strong>
                <span className="muted">
                  {docs[d] ? `✓ ${t('apply.added')} · ${docs[d]!.name}` : t('apply.docHint')}
                </span>
                <input
                  type="file"
                  accept="application/pdf,image/jpeg,image/png"
                  onChange={(e) => pick(d, e.target.files?.[0])}
                  hidden
                />
              </label>
            ))}
          </>
        )}
        {step === 3 && (
          <>
            <h1>{t('apply.ownerTitle')}</h1>
            <div className="grid2">
              {input('owner_last_name', t('apply.lastName'))}
              {input('owner_first_name', t('apply.firstName'))}
            </div>
            <div className="grid2">
              {input('owner_order_number', t('apply.orderNumber'))}
              {input('owner_phone', t('apply.ownerPhone'), { type: 'tel' })}
            </div>
            {input('owner_email', t('apply.ownerEmail'), { type: 'email' })}
            {(['truth', 'terms', 'data'] as const).map((k) => (
              <label key={k} className="check">
                <input
                  type="checkbox"
                  checked={agree[k]}
                  onChange={(e) => setAgree((a) => ({ ...a, [k]: e.target.checked }))}
                />{' '}
                {t(`apply.${k}`)}
              </label>
            ))}
          </>
        )}
        {banner && (
          <div role="alert" className="form-error">
            {banner}
          </div>
        )}
        <div className="actions">
          <Button
            type="button"
            variant="secondary"
            onClick={() => (step > 0 ? setStep(step - 1) : nav(-1))}
          >
            {t('common.back')}
          </Button>
          {step < 3 ? (
            <Button type="button" onClick={next}>
              {t('common.continue')}
            </Button>
          ) : (
            <Button type="button" onClick={submit} disabled={busy || docs.license?.base64 === ''}>
              {t('apply.submit')}
            </Button>
          )}
        </div>
      </div>
      <BrandPanel
        image="verification"
        title={t('apply.aside.title')}
        lead={t(`apply.aside.${step}`)}
      />
    </div>
  );
}
