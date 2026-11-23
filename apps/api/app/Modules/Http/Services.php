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

    public function __construct(public readonly Db $db, public readonly Clock $clock, public readonly Config $config, ?EncryptedStorage $storage = null)
    {
        $users = new UserRepository($db, $clock);
        $hasher = new PasswordHasher();
        $this->jwt = new JwtService($config->jwtSecret, $clock);
        $this->auth = new AuthService($users, $hasher, $this->jwt, new RefreshTokenService($db, $clock), $clock);
        $this->authz = new Authorizer($db);
        $this->limiter = new RateLimiter($db, $clock);
        $this->jobs = new JobQueue($db, $clock);
        $this->tenant = new TenantContext();
        $this->storage = $storage ?? new EncryptedStorage(
            $config->storageDir !== '' ? $config->storageDir : dirname(__DIR__, 4) . '/storage-private',
            (string) base64_decode($config->storageKey, true),
            hash_hmac('sha256', 'storage-url', $config->jwtSecret),
        );
        $this->applications = new ApplicationService($db, $clock, $this->storage, $this->jobs);
        $this->review = new ReviewService($db, $clock, $this->jobs, $config->appUrl);
        $this->activation = new ActivationService($db, $clock, $hasher);
        $mailer = $config->mailHost !== ''
            ? new SmtpMailer($config->mailHost, $config->mailPort, $config->mailEncryption, $config->mailUser, $config->mailPassword, $config->mailFromName)
            : new LogMailer();
        $this->mail = new MailJobHandler($this->jobs, $mailer, new EmailTemplates());
    }
}
