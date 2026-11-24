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
use Sanara\Modules\Applications\ReviewService;
use Sanara\Modules\Notifications\Email\EmailTemplates;
use Sanara\Modules\Notifications\Email\LogMailer;
use Sanara\Modules\Notifications\Email\SmtpMailer;
use Sanara\Modules\Notifications\MailJobHandler;

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
    public readonly \Sanara\Modules\Security\MfaService $mfa;
    public readonly \Sanara\Modules\Security\PasswordResetService $passwordReset;
    public readonly \Sanara\Modules\Security\SessionService $sessions;
    public readonly \Sanara\Modules\Security\PinService $pin;

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
        $this->tenant = new TenantContext();
        $this->storage = $storage ?? new EncryptedStorage(
            $config->storageDir !== '' ? $config->storageDir : dirname(__DIR__, 4) . '/storage-private',
            (string) base64_decode($config->storageKey, true),
            hash_hmac('sha256', 'storage-url', $config->jwtSecret),
        );
        $this->applications = new ApplicationService($db, $clock, $this->storage, $this->jobs);
        $this->review = new ReviewService($db, $clock, $this->jobs, $config->appUrl);
        $this->activation = new ActivationService($db, $clock, $hasher);
        $mailer = match (true) {
            $config->mailDir !== '' && $config->env !== 'production' => new \Sanara\Modules\Notifications\Email\FileMailer($config->mailDir),
            $config->mailHost !== '' => new SmtpMailer($config->mailHost, $config->mailPort, $config->mailEncryption, $config->mailUser, $config->mailPassword, $config->mailFromName),
            default => new LogMailer(),
        };
        $this->mail = new MailJobHandler($this->jobs, $mailer, new EmailTemplates());
        $this->activationResend = new \Sanara\Modules\Applications\ActivationResendService($db, $clock, $this->activation, $this->jobs, $config->appUrl);
        $this->audit = new \Sanara\Common\Audit\AuditLogger($db, $clock);
        $this->setup = new \Sanara\Modules\Tenants\TenantSetupService($db, new \Sanara\Common\Tenancy\TenantDb($db, $this->tenant), $this->tenant, $clock);
        $this->team = new \Sanara\Modules\Team\TeamService($db, $clock, $users, $this->authz, $this->activation, new RefreshTokenService($db, $clock), $this->jobs, $config->appUrl);
    }
}
