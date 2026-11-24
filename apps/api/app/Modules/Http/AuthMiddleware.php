<?php
declare(strict_types=1);

namespace Sanara\Modules\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sanara\Common\Auth\JwtService;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Tenancy\TenantContext;

/** Lit le jeton Bearer, pose userId/role et le contexte tenant (jamais déduit d'un paramètre client : T018). */
final class AuthMiddleware implements MiddlewareInterface
{
    /** @param bool $allowSetup accepte le jeton limité « setup » (super admin sans 2FA) : réservé aux routes /auth/mfa */
    public function __construct(private readonly JwtService $jwt, private readonly TenantContext $tenant, private readonly bool $allowSetup = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!preg_match('/^Bearer\s+(\S+)$/', $request->getHeaderLine('Authorization'), $m)) {
            throw new ApiException('unauthorized', 401);
        }
        $claims = $this->jwt->verify($m[1]);
        if (($claims['pur'] ?? null) === 'mfa') {
            throw new ApiException('invalid_token', 401); // jeton intermédiaire : jamais un accès
        }
        if (($claims['scope'] ?? null) === 'setup' && !$this->allowSetup) {
            throw new ApiException('mfa_required', 403);
        }
        $this->tenant->clear();
        if (!empty($claims['tid'])) {
            $this->tenant->set((string) $claims['tid']);
        }
        return $handler->handle($request->withAttribute('userId', (string) $claims['sub'])->withAttribute('role', (string) $claims['role'])->withAttribute('tenantId', $claims['tid'] ?? null)->withAttribute('scope', $claims['scope'] ?? null));
    }
}
