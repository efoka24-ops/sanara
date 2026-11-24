<?php
declare(strict_types=1);

namespace Sanara\Tests\Tenants;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Auth\RefreshTokenService;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Rbac\Authorizer;
use Sanara\Modules\Applications\ActivationService;
use Sanara\Modules\Team\TeamService;
use Sanara\Tests\Support\TestDb;

final class TeamTest extends TestCase
{
    private Db $db;
    private Clock $clock;
    private TeamService $team;
    private Authorizer $authz;
    private JobQueue $jobs;
    private string $owner;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        $this->clock = Clock::fixed('2026-11-24T10:00:00Z');
        $users = new UserRepository($this->db, $this->clock);
        $this->owner = $users->create(['email' => 'owner@t1.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => 'x']);
        $this->authz = new Authorizer($this->db);
        $this->jobs = new JobQueue($this->db, $this->clock);
        $this->team = new TeamService($this->db, $this->clock, $users, $this->authz, new ActivationService($this->db, $this->clock, new PasswordHasher()), new RefreshTokenService($this->db, $this->clock), $this->jobs, 'https://app.example');
    }

    public function testInviteCreatesPendingMemberWithDefaultRoleAndMail(): void
    {
        $id = $this->team->invite('T1', $this->owner, ['name' => 'Ibrahim Sali', 'email' => 'ibrahim@t1.cm', 'phone' => '+237600000009', 'role' => 'cashier']);
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertSame(['cashier', 'pending_activation', 'T1'], [$u['role_code'], $u['status'], $u['tenant_id']]);
        $mail = $this->jobs->reserve('mail');
        $this->assertSame('member_invited', $mail['payload']['template']);
        $this->assertStringContainsString('/activer/', $mail['payload']['vars']['lien_activation']);
        $this->assertNotNull($this->db->one('SELECT id FROM activation_tokens WHERE user_id = ?', [$id]));
    }

    public function testCannotInviteOwnerOrPlatformRolesOrDuplicates(): void
    {
        foreach (['owner', 'super_admin', 'inconnu'] as $role) {
            try {
                $this->team->invite('T1', $this->owner, ['name' => 'X', 'email' => "$role@x.cm", 'role' => $role]);
                $this->fail($role);
            } catch (ApiException $e) {
                $this->assertSame('validation_failed', $e->errorCode);
            }
        }
        $this->team->invite('T1', $this->owner, ['name' => 'A', 'email' => 'a@x.cm', 'role' => 'cashier']);
        $this->expectException(ApiException::class);
        $this->team->invite('T1', $this->owner, ['name' => 'A', 'email' => 'a@x.cm', 'role' => 'cashier']);
    }

    public function testRoleChangeTakesEffectImmediately(): void
    {
        $id = $this->team->invite('T1', $this->owner, ['name' => 'B', 'email' => 'b@x.cm', 'role' => 'cashier']);
        $this->assertFalse($this->authz->can($id, 'stock.write'));
        $this->team->changeRole('T1', $this->owner, $id, 'storekeeper');
        $this->assertTrue($this->authz->can($id, 'stock.write'));
        $this->assertFalse($this->authz->can($id, 'sales.create'));
    }

    public function testCannotTouchAnotherTenantsMember(): void
    {
        $other = (new UserRepository($this->db, $this->clock))->create(['email' => 'z@t2.cm', 'role_code' => 'cashier', 'tenant_id' => 'T2', 'password_hash' => 'x']);
        foreach ([fn () => $this->team->changeRole('T1', $this->owner, $other, 'storekeeper'), fn () => $this->team->deactivate('T1', $this->owner, $other)] as $f) {
            try {
                $f();
                $this->fail();
            } catch (ApiException $e) {
                $this->assertSame('not_found', $e->errorCode);
            }
        }
    }

    public function testLastActiveOwnerCannotBeDeactivatedOrDemoted(): void
    {
        foreach ([fn () => $this->team->deactivate('T1', $this->owner, $this->owner), fn () => $this->team->changeRole('T1', $this->owner, $this->owner, 'cashier')] as $f) {
            try {
                $f();
                $this->fail();
            } catch (ApiException $e) {
                $this->assertSame('conflict', $e->errorCode);
            }
        }
    }

    public function testDeactivationRevokesSessionsAndIsAudited(): void
    {
        $id = (new UserRepository($this->db, $this->clock))->create(['email' => 'c@t1.cm', 'role_code' => 'cashier', 'tenant_id' => 'T1', 'password_hash' => 'x']);
        (new RefreshTokenService($this->db, $this->clock))->issue($id);
        $this->team->deactivate('T1', $this->owner, $id);
        $this->assertSame('disabled', $this->db->one('SELECT status FROM users WHERE id = ?', [$id])['status']);
        $this->assertNotNull($this->db->one('SELECT revoked_at FROM refresh_tokens WHERE user_id = ?', [$id])['revoked_at']);
        $this->assertNotNull($this->db->one("SELECT id FROM audit_logs WHERE action = 'team.deactivate' AND entity_id = ?", [$id]));
        $this->team->reactivate('T1', $this->owner, $id);
        $this->assertSame('active', $this->db->one('SELECT status FROM users WHERE id = ?', [$id])['status']);
    }

    public function testPermissionOverridesAreAllowListedAndAudited(): void
    {
        $id = $this->team->invite('T1', $this->owner, ['name' => 'D', 'email' => 'd@x.cm', 'role' => 'cashier']);
        $this->team->setOverride('T1', $this->owner, $id, 'sales.cancel', true);
        $this->assertTrue($this->authz->can($id, 'sales.cancel'));
        $this->expectException(ApiException::class);
        $this->team->setOverride('T1', $this->owner, $id, 'platform.manage', true);
    }

    public function testListShowsOnlyOwnTenantMembers(): void
    {
        (new UserRepository($this->db, $this->clock))->create(['email' => 'z@t2.cm', 'role_code' => 'cashier', 'tenant_id' => 'T2', 'password_hash' => 'x']);
        $list = $this->team->members('T1');
        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('password_hash', $list[0]);
    }
}
