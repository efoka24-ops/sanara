<?php
declare(strict_types=1);

namespace Sanara\Common\Audit;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/** Journal d'audit en ajout seul : aucune méthode de modification ni de suppression, et masquage des secrets. */
final class AuditLogger
{
    private const SECRET_KEYS = ['password', 'password_hash', 'token', 'refresh_token', 'access_token', 'secret', 'totp_secret', 'pin', 'code'];

    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    /** @param array{tenant_id?: ?string, user_id?: ?string, device_id?: ?string, entity?: ?string, entity_id?: ?string, before?: mixed, after?: mixed, ip?: ?string} $ctx */
    public function record(string $action, array $ctx = []): void
    {
        $this->db->insert('audit_logs', [
            'id' => Ulid::generate(), 'tenant_id' => $ctx['tenant_id'] ?? null, 'user_id' => $ctx['user_id'] ?? null,
            'device_id' => $ctx['device_id'] ?? null, 'action' => $action, 'entity' => $ctx['entity'] ?? null,
            'entity_id' => $ctx['entity_id'] ?? null,
            'before_json' => isset($ctx['before']) ? json_encode($this->mask($ctx['before']), JSON_UNESCAPED_UNICODE) : null,
            'after_json' => isset($ctx['after']) ? json_encode($this->mask($ctx['after']), JSON_UNESCAPED_UNICODE) : null,
            'ip' => $ctx['ip'] ?? null, 'created_at' => $this->clock->stamp(),
        ]);
    }

    private function mask(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $val) {
            $out[$k] = is_string($k) && in_array(strtolower($k), self::SECRET_KEYS, true) ? '[masqué]' : $this->mask($val);
        }
        return $out;
    }
}
