<?php
declare(strict_types=1);

namespace Sanara\Common\Rbac;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Équivalent de `@RequirePermission` : 401 sans utilisateur authentifié, 403 sans la permission. */
final class RequirePermission implements MiddlewareInterface
{
    public function __construct(
        private readonly Authorizer $authz,
        private readonly string $permission,
        private readonly ResponseFactoryInterface $responses,
        private readonly ?\Sanara\Common\Audit\AuditLogger $audit = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $request->getAttribute('userId');
        if (!is_string($userId)) {
            return $this->deny(401, 'unauthorized');
        }
        if (!$this->authz->can($userId, $this->permission)) {
            $this->audit?->record('access.denied', [
                'user_id' => $userId, 'tenant_id' => is_string($request->getAttribute('tenantId')) ? $request->getAttribute('tenantId') : null,
                'entity' => 'permission', 'entity_id' => $this->permission,
                'ip' => (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            ]);
            return $this->deny(403, 'forbidden');
        }
        return $handler->handle($request);
    }

    private function deny(int $status, string $code): ResponseInterface
    {
        $res = $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        $res->getBody()->write(json_encode(['error' => ['code' => $code]]));
        return $res;
    }
}
