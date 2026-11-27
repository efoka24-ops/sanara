<?php
declare(strict_types=1);

namespace Sanara\Modules\Http;

use Sanara\Common\Auth\AuthService;
use Sanara\Common\Auth\JwtService;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Auth\RefreshTokenService;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\RateLimit\RateLimiter;
use Sanara\Common\Rbac\Authorizer;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Modules\Applications\ActivationService;
use Sanara\Modules\Applications\ApplicationService;
use Sanara\Modules\Applications\PublicPharmacyService;
use Sanara\Modules\Applications\ReviewService;
use Sanara\Modules\Notifications\Email\EmailTemplates;
use Sanara\Modules\Notifications\Email\LogMailer;
use Sanara\Modules\Notifications\Email\SmtpMailer;
use Sanara\Modules\Notifications\MailJobHandler;
use Sanara\Modules\Security\CaptchaVerifier;
use Sanara\Modules\Stock\StockService;

/** Composition des services d'une requête. Les tests la construisent avec une base SQLite et une horloge figée. */
final class Services
{
    public readonly JwtService $jwt;
    public readonly AuthService $auth;
    public readonly Authorizer $authz;
    public readonly RateLimiter $limiter;
    public readonly JobQueue $jobs;
    public readonly EncryptedStorage $storage;
    public readonly ApplicationService $applications;
    public readonly ReviewService $review;
    public readonly ActivationService $activation;
    public readonly TenantContext $tenant;
    public readonly MailJobHandler $mail;
    public readonly \Sanara\Common\Audit\AuditLogger $audit;
    public readonly \Sanara\Modules\Tenants\TenantSetupService $setup;
    public readonly \Sanara\Modules\Team\TeamService $team;
    public readonly \Sanara\Modules\Applications\ActivationResendService $activationResend;
    public readonly \Sanara\Modules\Catalog\CatalogService $catalog;
    public readonly \Sanara\Modules\Catalog\CatalogTransfer $catalogTransfer;
    public readonly \Sanara\Modules\Catalog\ReferenceCatalog $reference;
    public readonly StockService $stock;
    public readonly \Sanara\Modules\Stock\InventoryService $inventory;
    public readonly \Sanara\Modules\Sales\SalesService $sales;
    public readonly \Sanara\Modules\Sales\CashService $cash;
    public readonly \Sanara\Modules\Sales\ReturnsService $returns;
    public readonly EmailTemplates $templates;
    public readonly \Sanara\Modules\Admin\AdminService $admin;
    public readonly \Sanara\Modules\Security\MfaService $mfa;
    public readonly \Sanara\Modules\Security\PasswordResetService $passwordReset;
    public readonly \Sanara\Modules\Security\SessionService $sessions;
    public readonly \Sanara\Modules\Security\PinService $pin;
    public readonly PublicPharmacyService $publicPharmacies;
    public readonly \Sanara\Modules\Applications\PublicPharmacyProjection $publicProjection;
    public readonly CaptchaVerifier $captcha;
    public readonly \Sanara\Modules\Sync\SyncService $sync;
    public readonly \Sanara\Modules\Sync\DeviceService $devices;

    public function __construct(public readonly Db $db, public readonly Clock $clock, public readonly Config $config, ?EncryptedStorage $storage = null)
    {
        $users = new UserRepository($db, $clock);
        $hasher = new PasswordHasher();
        $this->jwt = new JwtService($config->jwtSecret, $clock);
        $this->authz = new Authorizer($db);
        $this->limiter = new RateLimiter($db, $clock);
        $this->jobs = new JobQueue($db, $clock);
        $key = base64_decode($config->storageKey, true);
        $box = new \Sanara\Common\Crypto\SecretBox($key !== false && strlen($key) === 32 ? $key : sodium_crypto_generichash('sanara-mfa|' . $config->jwtSecret));
        $this->mfa = new \Sanara\Modules\Security\MfaService($db, $clock, $box, 'Sanara');
        $this->auth = new AuthService($users, $hasher, $this->jwt, new RefreshTokenService($db, $clock), $clock, $this->mfa, $config->enforceAdminMfa, function (array $u) use ($db): void {
            // Prévient le titulaire de la pharmacie (ou l'intéressé s'il n'y en a pas) qu'un compte vient d'être verrouillé.
            $to = $u['tenant_id'] !== null
                ? array_column($db->all("SELECT email FROM users WHERE tenant_id = ? AND role_code = 'owner' AND status = 'active'", [$u['tenant_id']]), 'email')
                : [];
            foreach ($to !== [] ? $to : [$u['email']] as $email) {
                $this->jobs->push('mail', ['template' => 'account_locked', 'to' => $email, 'locale' => 'fr', 'vars' => ['compte' => $u['email']]]);
            }
        });
        $this->passwordReset = new \Sanara\Modules\Security\PasswordResetService($db, $clock, $hasher, $this->jobs, $config->appUrl);
        $this->sessions = new \Sanara\Modules\Security\SessionService($db, $clock);
        $this->pin = new \Sanara\Modules\Security\PinService($db, $hasher);
        $this->captcha = new CaptchaVerifier($config->captchaEnabled);
        $this->tenant = new TenantContext();
        $this->storage = $storage ?? new EncryptedStorage(
            $config->storageDir !== '' ? $config->storageDir : dirname(__DIR__, 4) . '/storage-private',
            (string) base64_decode($config->storageKey, true),
            hash_hmac('sha256', 'storage-url', $config->jwtSecret),
        );
        $this->applications = new ApplicationService($db, $clock, $this->storage, $this->jobs);
        $this->publicPharmacies = new PublicPharmacyService($db);
        $this->publicProjection = new \Sanara\Modules\Applications\PublicPharmacyProjection($db, $clock);
        $this->review = new ReviewService($db, $clock, $this->jobs, $config->appUrl);
        $this->activation = new ActivationService($db, $clock, $hasher);
        $mailer = match (true) {
            $config->mailDir !== '' && $config->env !== 'production' => new \Sanara\Modules\Notifications\Email\FileMailer($config->mailDir),
            $config->mailHost !== '' => new SmtpMailer($config->mailHost, $config->mailPort, $config->mailEncryption, $config->mailUser, $config->mailPassword, $config->mailFromName),
            default => new LogMailer(),
        };
        $this->templates = new EmailTemplates($db);
        $this->mail = new MailJobHandler($this->jobs, $mailer, $this->templates);
        $this->admin = new \Sanara\Modules\Admin\AdminService($db, $clock, $this->templates);
        $this->activationResend = new \Sanara\Modules\Applications\ActivationResendService($db, $clock, $this->activation, $this->jobs, $config->appUrl);
        $this->audit = new \Sanara\Common\Audit\AuditLogger($db, $clock);
        $this->setup = new \Sanara\Modules\Tenants\TenantSetupService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $this->tenant, $clock);
        $this->catalog = new \Sanara\Modules\Catalog\CatalogService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $clock);
        $this->catalogTransfer = new \Sanara\Modules\Catalog\CatalogTransfer($this->catalog);
        $this->reference = new \Sanara\Modules\Catalog\ReferenceCatalog($db, $this->catalog);
        $this->stock = new StockService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $clock, $this->tenant);
        $this->inventory = new \Sanara\Modules\Stock\InventoryService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $clock);
        $seq = new \Sanara\Modules\Sales\Sequences($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant));
        $this->cash = new \Sanara\Modules\Sales\CashService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $clock, $seq);
        $this->sales = new \Sanara\Modules\Sales\SalesService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $clock, $this->stock, $this->cash, $seq, $this->authz, $this->pin, $this->tenant);
        $this->returns = new \Sanara\Modules\Sales\ReturnsService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $clock, $this->sales, $this->cash, $seq, $this->authz, $this->pin, $this->tenant);
        $this->devices = new \Sanara\Modules\Sync\DeviceService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $clock, $this->audit, $this->tenant);
        $this->sync = new \Sanara\Modules\Sync\SyncService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $clock, $this->sales, $this->cash, $this->authz, $this->audit, $this->tenant);
        $this->team = new \Sanara\Modules\Team\TeamService($db, $clock, $users, $this->authz, $this->activation, new RefreshTokenService($db, $clock), $this->jobs, $config->appUrl);
    }

    /** Contrôles quotidiens (péremptions, ruptures) de chaque pharmacie active ; sans effet répété grâce aux clés de dédoublonnage. */
    public function runDaily(): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT id FROM tenants WHERE status = 'active'") as $t) {
            $this->tenant->set((string) $t['id']);
            try {
                $r = $this->stock->runDailyChecks();
                $n += $r['expiry_alerts'] + $r['stock_alerts'] + $r['expired_blocked'];
            } finally {
                $this->tenant->clear();
            }
        }
        return $n;
    }
}
