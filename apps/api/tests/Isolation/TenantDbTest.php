<?php
declare(strict_types=1);

namespace Sanara\Tests\Isolation;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Tenancy\TenantMissing;
use Sanara\Common\Ulid;
use Sanara\Tests\Support\TestDb;

final class TenantDbTest extends TestCase
{
    public function testRefusesAccessWithoutTenantContext(): void
    {
        $this->expectException(TenantMissing::class);
        (new TenantDb(TestDb::create(), new TenantContext()))->select('devices');
    }

    public function testRefusesInsertForAnotherTenant(): void
    {
        $ctx = new TenantContext();
        $ctx->set(Ulid::generate());
        $this->expectException(\InvalidArgumentException::class);
        (new TenantDb(TestDb::create(), $ctx))->insert('devices', ['id' => Ulid::generate(), 'tenant_id' => Ulid::generate()]);
    }

    public function testRefusesUnknownOrGlobalTable(): void
    {
        $ctx = new TenantContext();
        $ctx->set(Ulid::generate());
        $this->expectException(\InvalidArgumentException::class);
        (new TenantDb(TestDb::create(), $ctx))->select('countries');
    }

    public function testRefusesBadIdentifiers(): void
    {
        $ctx = new TenantContext();
        $ctx->set(Ulid::generate());
        $this->expectException(\InvalidArgumentException::class);
        (new TenantDb(TestDb::create(), $ctx))->select('devices', ['id; DROP TABLE devices' => 1]);
    }
}
