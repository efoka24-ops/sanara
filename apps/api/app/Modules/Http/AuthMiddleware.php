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
    public function __construct(private readonly JwtService $jwt, private readonly TenantContext $tenant)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!preg_match('/^Bearer\s+(\S+)$/', $request->getHeaderLine('Authorization'), $m)) {
            throw new ApiException('unauthorized', 401);
        }
        $claims = $this->jwt->verify($m[1]);
        $this->tenant->clear();
        if (!empty($claims['tid'])) {
            $this->tenant->set((string) $claims['tid']);
        }
        return $handler->handle($request->withAttribute('userId', (string) $claims['sub'])->withAttribute('role', (string) $claims['role'])->withAttribute('tenantId', $claims['tid'] ?? null));
    }
}
