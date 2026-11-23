<?php
declare(strict_types=1);

namespace Sanara\Common\Tenancy;

/** Pharmacie courante de la requête (T018). Posée par le middleware depuis le jeton, jamais depuis un paramètre client. */
final class TenantContext
{
    private ?string $tenantId = null;

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function require(): string
    {
        return $this->tenantId ?? throw new TenantMissing('Aucune pharmacie dans le contexte de la requête');
    }
}
