<?php
declare(strict_types=1);

namespace Sanara\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Rbac\Authorizer;
use Sanara\Common\Rbac\RequirePermission;
use Sanara\Tests\Support\TestDb;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RbacTest extends TestCase
{
    private Db $db;
    private Authorizer $authz;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        $this->users = new UserRepository($this->db, Clock::fixed('2026-11-24T10:00:00Z'));
        $this->authz = new Authorizer($this->db);
    }

    private function user(string $role): string
    {
        return $this->users->create(['email' => $role . '@x.cm', 'role_code' => $role, 'tenant_id' => 'T1', 'password_hash' => 'x']);
    }

    public function testCashierMatrixMatchesTheMockup(): void
    {
        $id = $this->user('cashier');
        foreach (['sales.create', 'pickup.validate'] as $p) {
            $this->assertTrue($this->authz->can($id, $p), $p);
        }
        foreach (['sales.cancel', 'stock.read', 'purchases.write', 'finance.read', 'team.manage', 'narcotics.dispense'] as $p) {
            $this->assertFalse($this->authz->can($id, $p), $p);
        }
    }

    public function testStorekeeperManagesStockButCannotSell(): void
    {
        $id = $this->user('storekeeper');
        $this->assertTrue($this->authz->can($id, 'stock.write'));
        $this->assertTrue($this->authz->can($id, 'purchases.write'));
        $this->assertFalse($this->authz->can($id, 'sales.create'));
    }

    public function testOwnerHasAllTenantPermissionsButNoPlatformOnes(): void
    {
        $id = $this->user('owner');
        foreach (['team.manage', 'assistant.configure', 'finance.read', 'sales.cancel', 'narcotics.dispense', 'settings.write'] as $p) {
            $this->assertTrue($this->authz->can($id, $p), $p);
        }
        $this->assertFalse($this->authz->can($id, 'platform.manage'));
        $this->assertFalse($this->authz->can($id, 'applications.review'));
    }

    public function testSuperAdminOnlyHasPlatformPermissions(): void
    {
        $id = $this->user('super_admin');
        $this->assertTrue($this->authz->can($id, 'applications.review'));
        $this->assertFalse($this->authz->can($id, 'sales.create'));
    }

    public function testUserOverridesGrantAndRevoke(): void
    {
        $id = $this->user('cashier');
        $this->authz->override($id, 'sales.cancel', true);
        $this->authz->override($id, 'sales.create', false);
        $this->assertTrue($this->authz->can($id, 'sales.cancel'));
        $this->assertFalse($this->authz->can($id, 'sales.create'));
    }

    public function testUnknownUserHasNoPermission(): void
    {
        $this->assertFalse($this->authz->can('inconnu', 'sales.create'));
    }

    public function testMiddlewareReturns403WithoutPermissionAnd401WithoutAuth(): void
    {
        $id = $this->user('cashier');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
        $mw = new RequirePermission($this->authz, 'stock.read', new ResponseFactory());
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/stock');
        $this->assertSame(401, $mw->process($req, $handler)->getStatusCode());
        $this->assertSame(403, $mw->process($req->withAttribute('userId', $id), $handler)->getStatusCode());
        $ok = new RequirePermission($this->authz, 'sales.create', new ResponseFactory());
        $this->assertSame(200, $ok->process($req->withAttribute('userId', $id), $handler)->getStatusCode());
    }
}
