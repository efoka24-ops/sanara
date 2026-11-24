<?php
declare(strict_types=1);

namespace Sanara\Modules\Admin;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;
use Sanara\Modules\Notifications\Email\EmailTemplates;

/** Console super admin (US32, US33, US34) : pharmacies, formules, facturation, signalements, modèles, audit, pays, paramètres. */
final class AdminService
{
    public const SETTING_DEFAULTS = [
        'link_validity_hours' => '48',
        'admin_mfa' => '1',
        'request_quota_per_day' => '5',
        'reservation_release_hours' => '24',
        'maintenance' => '0',
        'desktop_version' => '1.0.0',
    ];
    private const REPORT_KINDS = ['false_urgency', 'abuse', 'content', 'other'];

    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly EmailTemplates $templates)
    {
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $n = fn (string $sql, array $p = []): int => (int) ($this->db->one($sql, $p)['n'] ?? 0);
        $now = $this->clock->stamp();
        $mrr = $this->db->one("SELECT COALESCE(SUM(p.price_month), 0) AS n FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.status = 'active'")['n'] ?? 0;
        return [
            'pending_applications' => $n("SELECT COUNT(*) AS n FROM pharmacy_applications WHERE status IN ('pending', 'info_requested')"),
            'active_pharmacies' => $n("SELECT COUNT(*) AS n FROM tenants WHERE status = 'active'"),
            'suspended_pharmacies' => $n("SELECT COUNT(*) AS n FROM tenants WHERE status = 'suspended'"),
            'open_reports' => $n("SELECT COUNT(*) AS n FROM reports WHERE status = 'open'"),
            'overdue_invoices' => $n("SELECT COUNT(*) AS n FROM platform_invoices WHERE status = 'issued' AND due_at < ?", [$now]),
            'mrr' => (int) $mrr,
            'queue' => $this->db->all("SELECT id, number, pharmacy_name, city, region, status, created_at FROM pharmacy_applications WHERE status IN ('pending', 'info_requested') ORDER BY created_at LIMIT 5"),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function pharmacies(?string $q, ?string $status): array
    {
        $sql = "SELECT t.id, t.name, t.status, t.address, t.created_at, t.public_visible, p.name AS plan, s.status AS subscription_status,
                (SELECT email FROM users u WHERE u.tenant_id = t.id AND u.role_code = 'owner' ORDER BY u.created_at LIMIT 1) AS owner_email
                FROM tenants t LEFT JOIN subscriptions s ON s.pharmacy_id = t.id LEFT JOIN plans p ON p.id = s.plan_id WHERE 1 = 1";
        $args = [];
        if ($status !== null && $status !== '') {
            $sql .= ' AND t.status = ?';
            $args[] = $status;
        }
        if ($q !== null && trim($q) !== '') {
            $sql .= " AND t.name LIKE ? ESCAPE '!'";
            $args[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($q)) . '%';
        }
        return $this->db->all($sql . ' ORDER BY t.created_at DESC LIMIT 200', $args);
    }

    /** @return array<string, mixed> */
    public function pharmacy(string $id): array
    {
        $t = $this->db->one('SELECT id, name, status, address, phone, email, license_number, public_visible, currency, created_at FROM tenants WHERE id = ?', [$id]) ?? throw new ApiException('not_found', 404);
        $t['team'] = $this->db->all('SELECT id, email, role_code, status, created_at FROM users WHERE tenant_id = ? ORDER BY created_at', [$id]);
        $t['subscription'] = $this->db->one('SELECT s.status, s.stations, s.trial_ends_at, s.period_end, p.id AS plan_id, p.name AS plan FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.pharmacy_id = ?', [$id]);
        $t['invoices'] = $this->invoices(null, $id);
        $since = gmdate('Y-m-d H:i:s', strtotime($this->clock->stamp() . ' UTC') - 30 * 86400);
        $s = $this->db->one("SELECT COUNT(*) AS c, COALESCE(SUM(total), 0) AS t FROM sales WHERE tenant_id = ? AND created_at >= ?", [$id, $since]);
        $t['activity'] = ['sales_30d' => (int) ($s['c'] ?? 0), 'revenue_30d' => (int) ($s['t'] ?? 0)];
        $t['audit'] = $this->db->all('SELECT action, user_id, created_at FROM audit_logs WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 15', [$id]);
        return $t;
    }

    public function suspend(string $id, string $reason, string $adminId): void
    {
        $t = $this->db->one('SELECT status FROM tenants WHERE id = ?', [$id]) ?? throw new ApiException('not_found', 404);
        if (trim($reason) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['reason' => 'required']]);
        }
        if ($t['status'] === 'suspended') {
            throw new ApiException('conflict', 409);
        }
        $this->db->transaction(function () use ($id): void {
            $now = $this->clock->stamp();
            $this->db->execute("UPDATE tenants SET status = 'suspended', public_visible = 0, updated_at = ? WHERE id = ?", [$now, $id]);
            $this->db->execute('UPDATE public_pharmacies SET visible = 0, updated_at = ? WHERE tenant_id = ?', [$now, $id]);
            $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE revoked_at IS NULL AND user_id IN (SELECT id FROM users WHERE tenant_id = ?)', [$now, $id]);
            $this->db->execute("UPDATE subscriptions SET status = 'suspended', updated_at = ? WHERE pharmacy_id = ?", [$now, $id]);
        });
    }

    public function reactivate(string $id): void
    {
        $t = $this->db->one('SELECT status FROM tenants WHERE id = ?', [$id]) ?? throw new ApiException('not_found', 404);
        if ($t['status'] !== 'suspended') {
            throw new ApiException('conflict', 409);
        }
        $now = $this->clock->stamp();
        $this->db->execute("UPDATE tenants SET status = 'active', updated_at = ? WHERE id = ?", [$now, $id]);
        $this->db->execute("UPDATE subscriptions SET status = 'active', updated_at = ? WHERE pharmacy_id = ? AND status = 'suspended'", [$now, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function plans(): array
    {
        return array_map(function ($p) {
            $p['price_month'] = (int) $p['price_month'];
            $p['max_stations'] = (int) $p['max_stations'];
            $p['active'] = (int) $p['active'];
            $p['pharmacies'] = (int) $this->db->one("SELECT COUNT(*) AS n FROM subscriptions WHERE plan_id = ? AND status IN ('trial', 'active')", [$p['id']])['n'];
            return $p;
        }, $this->db->all('SELECT id, code, name, price_month, max_stations, features, active FROM plans ORDER BY price_month'));
    }

    /** @param array<string, mixed> $in */
    public function savePlan(?string $id, array $in): string
    {
        $name = trim((string) ($in['name'] ?? ''));
        $code = strtolower(trim((string) ($in['code'] ?? '')));
        $price = $in['price_month'] ?? null;
        $stations = $in['max_stations'] ?? null;
        $e = [];
        if ($name === '') {
            $e['name'] = 'required';
        }
        if ($id === null && !preg_match('/^[a-z0-9_]{2,30}$/', $code)) {
            $e['code'] = 'invalid';
        }
        if (!is_int($price) || $price < 0) {
            $e['price_month'] = 'invalid';
        }
        if (!is_int($stations) || $stations < 1) {
            $e['max_stations'] = 'invalid';
        }
        if ($e !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $e]);
        }
        $features = mb_substr(trim((string) ($in['features'] ?? '')), 0, 500);
        if ($id === null) {
            if ($this->db->one('SELECT id FROM plans WHERE code = ?', [$code]) !== null) {
                throw new ApiException('conflict', 409);
            }
            $id = Ulid::generate();
            $this->db->insert('plans', ['id' => $id, 'code' => $code, 'name' => $name, 'price_month' => $price, 'max_stations' => $stations, 'features' => $features, 'active' => 1, 'created_at' => $this->clock->stamp()]);
            return $id;
        }
        if ($this->db->execute('UPDATE plans SET name = ?, price_month = ?, max_stations = ?, features = ?, active = ? WHERE id = ?', [$name, $price, $stations, $features, empty($in['active']) && array_key_exists('active', $in) ? 0 : 1, $id]) === 0 && $this->db->one('SELECT id FROM plans WHERE id = ?', [$id]) === null) {
            throw new ApiException('not_found', 404);
        }
        return $id;
    }

    /** Affecte une formule à une pharmacie (essai de 30 jours par défaut). */
    public function setSubscription(string $pharmacyId, string $planId, int $stations, string $status = 'trial'): void
    {
        $this->db->one('SELECT id FROM tenants WHERE id = ?', [$pharmacyId]) ?? throw new ApiException('not_found', 404);
        $plan = $this->db->one('SELECT max_stations FROM plans WHERE id = ? AND active = 1', [$planId]) ?? throw new ApiException('validation_failed', 422, ['fields' => ['plan_id' => 'invalid']]);
        if ($stations < 1 || $stations > (int) $plan['max_stations'] || !in_array($status, ['trial', 'active', 'grace', 'cancelled'], true)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['stations' => 'invalid']]);
        }
        $now = $this->clock->stamp();
        $trial = $status === 'trial' ? gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + 30 * 86400) . '.000' : null;
        $period = $status === 'active' ? gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + 30 * 86400) . '.000' : null;
        if ($this->db->one('SELECT id FROM subscriptions WHERE pharmacy_id = ?', [$pharmacyId]) === null) {
            $this->db->insert('subscriptions', ['id' => Ulid::generate(), 'pharmacy_id' => $pharmacyId, 'plan_id' => $planId, 'status' => $status, 'stations' => $stations, 'trial_ends_at' => $trial, 'period_end' => $period, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $this->db->execute('UPDATE subscriptions SET plan_id = ?, status = ?, stations = ?, trial_ends_at = ?, period_end = ?, updated_at = ? WHERE pharmacy_id = ?', [$planId, $status, $stations, $trial, $period, $now, $pharmacyId]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function invoices(?string $status, ?string $pharmacyId = null): array
    {
        $now = $this->clock->stamp();
        $sql = 'SELECT i.id, i.number, i.pharmacy_id, t.name AS pharmacy, i.amount, i.status, i.due_at, i.paid_at, i.method, i.reference, i.created_at FROM platform_invoices i LEFT JOIN tenants t ON t.id = i.pharmacy_id WHERE 1 = 1';
        $args = [];
        if ($pharmacyId !== null) {
            $sql .= ' AND i.pharmacy_id = ?';
            $args[] = $pharmacyId;
        }
        $rows = array_map(static function ($r) use ($now) {
            $r['amount'] = (int) $r['amount'];
            if ($r['status'] === 'issued' && (string) $r['due_at'] < $now) {
                $r['status'] = 'overdue';
            }
            return $r;
        }, $this->db->all($sql . ' ORDER BY i.created_at DESC LIMIT 200', $args));
        return $status === null || $status === '' ? $rows : array_values(array_filter($rows, static fn ($r) => $r['status'] === $status));
    }

    public function createInvoice(string $pharmacyId, int $amount, int $dueDays): string
    {
        $this->db->one('SELECT id FROM tenants WHERE id = ?', [$pharmacyId]) ?? throw new ApiException('not_found', 404);
        if ($amount <= 0 || $dueDays < 0 || $dueDays > 365) {
            throw new ApiException('validation_failed', 422, ['fields' => ['amount' => 'invalid']]);
        }
        $year = substr($this->clock->stamp(), 0, 4);
        $n = (int) $this->db->one('SELECT COUNT(*) AS n FROM platform_invoices WHERE number LIKE ?', ["FA-$year-%"])['n'] + 1;
        $id = Ulid::generate();
        $this->db->insert('platform_invoices', ['id' => $id, 'pharmacy_id' => $pharmacyId, 'number' => sprintf('FA-%s-%04d', $year, $n), 'amount' => $amount, 'status' => 'issued',
            'due_at' => gmdate('Y-m-d H:i:s', strtotime($this->clock->stamp() . ' UTC') + $dueDays * 86400) . '.000', 'paid_at' => null, 'method' => null, 'reference' => null, 'created_at' => $this->clock->stamp()]);
        return $id;
    }

    /** Saisie manuelle d'un paiement (v1). */
    public function markPaid(string $id, string $method, string $reference): void
    {
        $i = $this->db->one('SELECT status FROM platform_invoices WHERE id = ?', [$id]) ?? throw new ApiException('not_found', 404);
        if ($i['status'] === 'paid' || $i['status'] === 'void') {
            throw new ApiException('conflict', 409);
        }
        if (!in_array($method, ['momo_mtn', 'momo_orange', 'bank', 'cash'], true) || trim($reference) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['reference' => 'required']]);
        }
        $this->db->execute("UPDATE platform_invoices SET status = 'paid', paid_at = ?, method = ?, reference = ? WHERE id = ?", [$this->clock->stamp(), $method, mb_substr(trim($reference), 0, 80), $id]);
    }

    public function voidInvoice(string $id): void
    {
        $this->db->one('SELECT id FROM platform_invoices WHERE id = ?', [$id]) ?? throw new ApiException('not_found', 404);
        $this->db->execute("UPDATE platform_invoices SET status = 'void' WHERE id = ? AND status <> 'paid'", [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function reports(?string $status): array
    {
        $sql = 'SELECT r.id, r.kind, r.pharmacy_id, t.name AS pharmacy, r.subject, r.details, r.status, r.note, r.created_at, r.handled_at FROM reports r LEFT JOIN tenants t ON t.id = r.pharmacy_id';
        return $status !== null && $status !== ''
            ? $this->db->all($sql . ' WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 200', [$status])
            : $this->db->all($sql . ' ORDER BY r.created_at DESC LIMIT 200');
    }

    public function createReport(string $kind, ?string $pharmacyId, string $subject, ?string $details): string
    {
        if (!in_array($kind, self::REPORT_KINDS, true) || trim($subject) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['subject' => 'required']]);
        }
        $id = Ulid::generate();
        $this->db->insert('reports', ['id' => $id, 'kind' => $kind, 'pharmacy_id' => $pharmacyId, 'subject' => mb_substr(trim($subject), 0, 160), 'details' => $details !== null ? mb_substr($details, 0, 2000) : null,
            'status' => 'open', 'note' => null, 'created_at' => $this->clock->stamp(), 'handled_by' => null, 'handled_at' => null]);
        return $id;
    }

    public function handleReport(string $id, string $decision, ?string $note, string $adminId): void
    {
        $r = $this->db->one('SELECT status FROM reports WHERE id = ?', [$id]) ?? throw new ApiException('not_found', 404);
        if ($r['status'] !== 'open' || !in_array($decision, ['dismissed', 'actioned'], true)) {
            throw new ApiException('conflict', 409);
        }
        $this->db->execute('UPDATE reports SET status = ?, note = ?, handled_by = ?, handled_at = ? WHERE id = ?', [$decision, $note !== null ? mb_substr($note, 0, 190) : null, $adminId, $this->clock->stamp(), $id]);
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        $out = [];
        foreach (EmailTemplates::codes() as $code) {
            foreach (['fr', 'en'] as $loc) {
                $eff = $this->templates->effective($code, $loc);
                $def = EmailTemplates::defaults($code, $loc);
                $out[] = ['code' => $code, 'locale' => $loc, 'subject' => $eff['subject'], 'body' => $eff['body'], 'variables' => EmailTemplates::variables($def['body']), 'customized' => $eff !== $def];
            }
        }
        return $out;
    }

    public function saveTemplate(string $code, string $locale, string $subject, string $body, string $adminId): void
    {
        if (!in_array($code, EmailTemplates::codes(), true) || !in_array($locale, ['fr', 'en'], true)) {
            throw new ApiException('not_found', 404);
        }
        if (trim($subject) === '' || trim($body) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['body' => 'required']]);
        }
        // Un modèle ne peut employer que les variables prévues, sinon l'envoi échouerait.
        $allowed = EmailTemplates::variables(EmailTemplates::defaults($code, $locale)['body'] . ' ' . EmailTemplates::defaults($code, $locale)['subject']);
        $unknown = array_diff(EmailTemplates::variables($body . ' ' . $subject), $allowed);
        if ($unknown !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => ['body' => 'unknown_variable'], 'unknown' => array_values($unknown)]);
        }
        $now = $this->clock->stamp();
        if ($this->db->one('SELECT id FROM message_templates WHERE code = ? AND locale = ?', [$code, $locale]) === null) {
            $this->db->insert('message_templates', ['id' => Ulid::generate(), 'code' => $code, 'locale' => $locale, 'subject' => mb_substr($subject, 0, 190), 'body' => $body, 'updated_by' => $adminId, 'updated_at' => $now]);
        } else {
            $this->db->execute('UPDATE message_templates SET subject = ?, body = ?, updated_by = ?, updated_at = ? WHERE code = ? AND locale = ?', [mb_substr($subject, 0, 190), $body, $adminId, $now, $code, $locale]);
        }
    }

    public function resetTemplate(string $code, string $locale): void
    {
        $this->db->execute('DELETE FROM message_templates WHERE code = ? AND locale = ?', [$code, $locale]);
    }

    /** @param array<string, string> $overrides @return array{subject: string, text: string} */
    public function previewTemplate(string $code, string $locale, ?string $subject = null, ?string $body = null): array
    {
        $def = EmailTemplates::defaults($code, $locale);
        $vars = [];
        foreach (EmailTemplates::variables($def['body'] . ' ' . $def['subject']) as $v) {
            $vars[$v] = '[' . $v . ']';
        }
        $eff = $this->templates->effective($code, $locale);
        $s = $subject ?? $eff['subject'];
        $b = $body ?? $eff['body'];
        $fill = static fn (string $t): string => (string) preg_replace_callback('/\{\{(\w+)\}\}/', static fn ($m) => $vars[$m[1]] ?? '[' . $m[1] . ']', $t);
        return ['subject' => $fill($s), 'text' => $fill($b)];
    }

    /** @return list<array<string, mixed>> */
    public function audit(?string $action, ?string $tenantId, ?string $from, ?string $to, int $limit = 100): array
    {
        $sql = 'SELECT a.id, a.action, a.tenant_id, t.name AS pharmacy, a.user_id, u.email AS user_email, a.entity, a.entity_id, a.ip, a.created_at
                FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tenants t ON t.id = a.tenant_id WHERE 1 = 1';
        $args = [];
        if ($action !== null && $action !== '') {
            $sql .= " AND a.action LIKE ? ESCAPE '!'";
            $args[] = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $action) . '%';
        }
        if ($tenantId !== null && $tenantId !== '') {
            $sql .= ' AND a.tenant_id = ?';
            $args[] = $tenantId;
        }
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $sql .= ' AND a.created_at >= ?';
            $args[] = $from . ' 00:00:00.000';
        }
        if ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $sql .= ' AND a.created_at <= ?';
            $args[] = $to . ' 23:59:59.999';
        }
        return $this->db->all($sql . ' ORDER BY a.created_at DESC LIMIT ' . max(1, min(500, $limit)), $args);
    }

    /** @return array<string, string> */
    public function settings(): array
    {
        $out = self::SETTING_DEFAULTS;
        foreach ($this->db->all('SELECT skey, svalue FROM platform_settings') as $r) {
            $out[(string) $r['skey']] = (string) $r['svalue'];
        }
        return $out;
    }

    /** @param array<string, mixed> $values */
    public function setSettings(array $values): void
    {
        $bad = array_diff(array_keys($values), array_keys(self::SETTING_DEFAULTS));
        if ($bad !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => array_fill_keys($bad, 'unknown')]);
        }
        $rules = ['link_validity_hours' => [1, 720], 'request_quota_per_day' => [1, 100], 'reservation_release_hours' => [1, 168]];
        foreach ($values as $k => $v) {
            $v = is_bool($v) ? ($v ? '1' : '0') : trim((string) $v);
            if (isset($rules[$k]) && (!ctype_digit($v) || (int) $v < $rules[$k][0] || (int) $v > $rules[$k][1])) {
                throw new ApiException('validation_failed', 422, ['fields' => [$k => 'invalid']]);
            }
            if (in_array($k, ['admin_mfa', 'maintenance'], true) && !in_array($v, ['0', '1'], true)) {
                throw new ApiException('validation_failed', 422, ['fields' => [$k => 'invalid']]);
            }
            if ($k === 'desktop_version' && !preg_match('/^\d+\.\d+\.\d+$/', $v)) {
                throw new ApiException('validation_failed', 422, ['fields' => [$k => 'invalid']]);
            }
            $now = $this->clock->stamp();
            if ($this->db->execute('UPDATE platform_settings SET svalue = ?, updated_at = ? WHERE skey = ?', [$v, $now, $k]) === 0) {
                $this->db->insert('platform_settings', ['skey' => $k, 'svalue' => $v, 'updated_at' => $now]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function countries(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT code, name_fr, name_en, currency, timezone, default_locale, dial_code, status FROM countries ORDER BY name_fr') as $c) {
            $cfg = [];
            foreach ($this->db->all('SELECT config_key, config_value FROM country_configs WHERE country_code = ?', [$c['code']]) as $r) {
                $cfg[(string) $r['config_key']] = json_decode((string) $r['config_value'], true);
            }
            $c['authority'] = (string) ($cfg['authority'] ?? '');
            $c['required_documents'] = (array) ($cfg['required_documents'] ?? []);
            $c['retention_years'] = (int) ($cfg['retention_years'] ?? 0);
            $c['pharmacies'] = (int) $this->db->one('SELECT COUNT(*) AS n FROM tenants WHERE country_code = ?', [$c['code']])['n'];
            $out[] = $c;
        }
        return $out;
    }

    /** @param array<string, mixed> $in */
    public function updateCountry(string $code, array $in): void
    {
        $this->db->one('SELECT code FROM countries WHERE code = ?', [$code]) ?? throw new ApiException('not_found', 404);
        if (isset($in['status'])) {
            if (!in_array($in['status'], ['active', 'preparing', 'closed'], true)) {
                throw new ApiException('validation_failed', 422, ['fields' => ['status' => 'invalid']]);
            }
            $this->db->execute('UPDATE countries SET status = ? WHERE code = ?', [$in['status'], $code]);
        }
        $cfg = [];
        if (array_key_exists('authority', $in)) {
            $cfg['authority'] = mb_substr(trim((string) $in['authority']), 0, 160);
        }
        if (array_key_exists('required_documents', $in)) {
            $cfg['required_documents'] = array_values(array_filter(array_map(static fn ($d) => mb_substr(trim((string) $d), 0, 80), (array) $in['required_documents'])));
        }
        if (array_key_exists('retention_years', $in)) {
            if (!is_int($in['retention_years']) || $in['retention_years'] < 0 || $in['retention_years'] > 99) {
                throw new ApiException('validation_failed', 422, ['fields' => ['retention_years' => 'invalid']]);
            }
            $cfg['retention_years'] = $in['retention_years'];
        }
        foreach ($cfg as $k => $v) {
            $json = json_encode($v, JSON_UNESCAPED_UNICODE);
            if ($this->db->execute('UPDATE country_configs SET config_value = ? WHERE country_code = ? AND config_key = ?', [$json, $code, $k]) === 0) {
                $this->db->insert('country_configs', ['id' => Ulid::generate(), 'country_code' => $code, 'config_key' => $k, 'config_value' => $json]);
            }
        }
    }
}
