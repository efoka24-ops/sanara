import { useEffect, useState } from 'react';
import { useT } from '@sanara/i18n';
import { isTeamPermissionChecked } from '@sanara/shared';
import { Badge, Button, Field, Modal, Switch, Table, useToast } from '@sanara/ui';
import { api, ApiError } from '../api/client';

interface Member {
  id: string;
  email: string;
  phone: string | null;
  role_code: string;
  status: string;
  permissions?: string[];
  permission_overrides?: Array<{ permission_code: string; granted: boolean }>;
}
const ROLES = ['pharmacist', 'storekeeper', 'preparateur', 'cashier', 'accountant'];
const TEAM_TOGGLES = ['sales.cancel', 'sales.discount', 'narcotics.dispense', 'finance.read'];
const TONE: Record<string, 'ok' | 'wait' | 'neutral'> = {
  active: 'ok',
  pending_activation: 'wait',
  disabled: 'neutral',
};

export function TeamPage() {
  const { t } = useT();
  const toast = useToast();
  const [members, setMembers] = useState<Member[]>([]);
  const [open, setOpen] = useState(false);
  const [f, setF] = useState({ name: '', email: '', phone: '', role: 'cashier' });
  const [err, setErr] = useState<Record<string, string>>({});
  const load = () =>
    api<{ members: Member[] }>('GET', '/app/team').then((r) => setMembers(r.members));
  useEffect(() => {
    void load();
  }, []);

  async function run(p: Promise<unknown>) {
    try {
      await p;
      await load();
    } catch (e) {
      toast(
        e instanceof ApiError && e.code === 'conflict'
          ? t('team.lastOwner')
          : t('errors.server_error'),
        'error',
      );
    }
  }
  async function create() {
    try {
      await api('POST', '/app/team', f);
      toast(t('team.invited'));
      setOpen(false);
      setF({ name: '', email: '', phone: '', role: 'cashier' });
      setErr({});
      await load();
    } catch (e) {
      setErr(e instanceof ApiError ? ((e.details.fields ?? {}) as Record<string, string>) : {});
      if (e instanceof ApiError && e.code === 'conflict') setErr({ email: 'conflict' });
    }
  }

  return (
    <div>
      <div className="row">
        <h1>{t('team.title')}</h1>
        <Button onClick={() => setOpen(true)}>{t('team.add')}</Button>
      </div>
      <Table
        rowKey={(m: Member) => m.id}
        rows={members}
        columns={[
          { key: 'e', header: t('team.email'), render: (m) => <b>{m.email}</b> },
          {
            key: 'r',
            header: t('team.role'),
            render: (m) =>
              m.role_code === 'owner' ? (
                t('role.owner')
              ) : (
                <select
                  value={m.role_code}
                  onChange={(e) =>
                    run(api('PUT', `/app/team/${m.id}/role`, { role: e.target.value }))
                  }
                >
                  {ROLES.map((r) => (
                    <option key={r} value={r}>
                      {t(`role.${r}`)}
                    </option>
                  ))}
                </select>
              ),
          },
          {
            key: 'p',
            header: 'Droits',
            render: (m) =>
              m.role_code === 'owner' ? (
                ''
              ) : (
                <span className="perms">
                  {TEAM_TOGGLES.map((p) => (
                    <label key={p}>
                      {t(`perm.${p}`)}{' '}
                      <Switch
                        checked={isTeamPermissionChecked(m, p)}
                        onChange={(v) =>
                          run(api('PUT', `/app/team/${m.id}/permissions/${p}`, { granted: v }))
                        }
                        label={t(`perm.${p}`)}
                      />
                    </label>
                  ))}
                </span>
              ),
          },
          {
            key: 's',
            header: '',
            render: (m) => (
              <Badge tone={TONE[m.status] ?? 'neutral'}>{t(`team.status.${m.status}`)}</Badge>
            ),
          },
          {
            key: 'a',
            header: '',
            render: (m) =>
              m.status === 'disabled' ? (
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => run(api('POST', `/app/team/${m.id}/reactivate`))}
                >
                  {t('team.reactivate')}
                </Button>
              ) : (
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => run(api('POST', `/app/team/${m.id}/deactivate`))}
                >
                  {t('team.deactivate')}
                </Button>
              ),
          },
        ]}
      />
      {open && (
        <Modal title={t('team.add')} onClose={() => setOpen(false)}>
          <div className="stack">
            <Field
              label={t('team.name')}
              value={f.name}
              onChange={(e) => setF({ ...f, name: e.target.value })}
              error={err.name ? t('common.required') : undefined}
            />
            <Field
              label={t('team.email')}
              type="email"
              value={f.email}
              onChange={(e) => setF({ ...f, email: e.target.value })}
              error={err.email ? t('apply.invalid') : undefined}
            />
            <Field
              label={t('team.phone')}
              type="tel"
              value={f.phone}
              onChange={(e) => setF({ ...f, phone: e.target.value })}
              error={err.phone ? t('apply.invalid') : undefined}
            />
            <select
              value={f.role}
              onChange={(e) => setF({ ...f, role: e.target.value })}
              aria-label={t('team.role')}
            >
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {t(`role.${r}`)}
                </option>
              ))}
            </select>
            <div className="actions">
              <Button variant="secondary" onClick={() => setOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button onClick={create}>{t('team.create')}</Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
