<?php
declare(strict_types=1);

namespace Sanara\Modules\Tenants;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/** US3 : paramètres de l'officine, horaires, progression de l'assistant de configuration, visibilité publique. */
final class TenantSetupService
{
    public const STEPS = ['hours', 'team', 'catalog', 'visibility'];
    private const SETTING_KEYS = ['ticket_footer', 'vat_default_bp', 'rounding', 'printer', 'logo_storage_id', 'locale', 'urgent_escalation_minutes', 'pickup_hold_hours', 'max_discount_bp', 'allow_negative_stock', 'ticket_width', 'invoice_header', 'station_code', 'expiry_alert_days', 'return_days'];

    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly TenantContext $ctx, private readonly Clock $clock)
    {
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $out = [];
        foreach ($this->tdb->select('tenant_settings') as $r) {
            $out[(string) $r['setting_key']] = json_decode((string) $r['setting_value'], true);
        }
        return $out;
    }

    /** @param array<string, mixed> $values */
    public function setSettings(array $values): void
    {
        $bad = array_diff(array_keys($values), self::SETTING_KEYS);
        if ($bad !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => array_fill_keys($bad, 'unknown')]);
        }
        foreach ($values as $k => $v) {
            $json = json_encode($v, JSON_UNESCAPED_UNICODE);
            $n = $this->tdb->update('tenant_settings', ['setting_value' => $json, 'updated_at' => $this->clock->stamp()], ['setting_key' => $k]);
            if ($n === 0) {
                $this->tdb->insert('tenant_settings', ['id' => Ulid::generate(), 'setting_key' => $k, 'setting_value' => $json, 'updated_at' => $this->clock->stamp()]);
            }
        }
    }

    /** @return list<array{weekday: int, is_open: bool, opens: ?string, closes: ?string}> */
    public function openingHours(): array
    {
        return array_map(static fn ($r) => ['weekday' => (int) $r['weekday'], 'is_open' => (int) $r['is_open'] === 1, 'opens' => $r['opens'], 'closes' => $r['closes']], $this->tdb->select('opening_hours', [], 'weekday'));
    }

    /** @param list<array{weekday: int, is_open: bool, opens?: ?string, closes?: ?string}> $days */
    public function setOpeningHours(array $days): void
    {
        $errors = [];
        foreach ($days as $i => $d) {
            $wd = (int) ($d['weekday'] ?? 0);
            if ($wd < 1 || $wd > 7) {
                $errors["day$i"] = 'weekday';
            } elseif (!empty($d['is_open'])) {
                $o = (string) ($d['opens'] ?? '');
                $c = (string) ($d['closes'] ?? '');
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $o) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $c) || $c <= $o) {
                    $errors["day$i"] = 'time';
                }
            }
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }
        $this->db->transaction(function () use ($days): void {
            $this->tdb->delete('opening_hours', []);
            foreach ($days as $d) {
                $open = !empty($d['is_open']);
                $this->tdb->insert('opening_hours', ['id' => Ulid::generate(), 'weekday' => (int) $d['weekday'], 'is_open' => $open ? 1 : 0, 'opens' => $open ? $d['opens'] : null, 'closes' => $open ? $d['closes'] : null]);
            }
        });
    }

    /** @return array{steps: array<string, string>, current: ?string, percent: int, finished: bool} */
    public function onboarding(): array
    {
        $status = array_fill_keys(self::STEPS, 'todo');
        foreach ($this->tdb->select('onboarding_progress') as $r) {
            $status[(string) $r['step_key']] = (string) $r['status'];
        }
        $done = count(array_filter($status, static fn ($s) => $s !== 'todo'));
        $current = null;
        foreach ($status as $k => $s) {
            if ($s === 'todo') {
                $current = $k;
                break;
            }
        }
        return ['steps' => $status, 'current' => $current, 'percent' => intdiv($done * 100, count(self::STEPS)), 'finished' => $current === null];
    }

    public function completeStep(string $step): void
    {
        $this->mark($step, 'done');
    }

    public function skipStep(string $step): void
    {
        $this->mark($step, 'skipped');
    }

    public function setVisibility(bool $visible): void
    {
        $this->db->execute('UPDATE tenants SET public_visible = ?, updated_at = ? WHERE id = ?', [$visible ? 1 : 0, $this->clock->stamp(), $this->ctx->require()]);
    }

    private function mark(string $step, string $status): void
    {
        if (!in_array($step, self::STEPS, true)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['step' => 'unknown']]);
        }
        $n = $this->tdb->update('onboarding_progress', ['status' => $status, 'updated_at' => $this->clock->stamp()], ['step_key' => $step]);
        if ($n === 0) {
            $this->tdb->insert('onboarding_progress', ['id' => Ulid::generate(), 'step_key' => $step, 'status' => $status, 'updated_at' => $this->clock->stamp()]);
        }
    }
}
