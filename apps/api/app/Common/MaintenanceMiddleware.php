<?php
declare(strict_types=1);

namespace Sanara\Common;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sanara\Common\Errors\ErrorCatalog;
use Sanara\Common\Persistence\Db;

/**
 * T312 : mode maintenance de la console. Tout est fermé (503) sauf l'état du service, la console du
 * super admin et sa connexion : il doit pouvoir rouvrir.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PlatformSettings $settings, private readonly Db $db, private readonly ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->settings->on('maintenance') || $this->allowed($request)) {
            return $handler->handle($request);
        }
        $locale = str_starts_with(strtolower($request->getHeaderLine('Accept-Language')), 'en') ? 'en' : 'fr';
        $res = $this->responses->createResponse(503)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Retry-After', '600');
        $res->getBody()->write((string) json_encode(['error' => ['code' => 'maintenance', 'message' => ErrorCatalog::message('maintenance', $locale)]], JSON_UNESCAPED_UNICODE));
        return $res;
    }

    private function allowed(ServerRequestInterface $request): bool
    {
        $path = (string) preg_replace('#^/api(?=/)#', '', $request->getUri()->getPath());
        if (in_array($path, ['/health', '/public/status'], true) || str_starts_with($path, '/admin/')) {
            return true;
        }
        if (in_array($path, ['/auth/mfa/verify', '/auth/mfa/setup', '/auth/mfa/enable', '/auth/refresh', '/auth/logout', '/auth/me'], true)) {
            return true;
        }
        if ($path === '/auth/login') {
            $email = (string) ((array) ($request->getParsedBody() ?? []))['email'] ?? '';
            $user = $this->db->one('SELECT role_code FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
            return ($user['role_code'] ?? '') === 'super_admin';
        }
        return false;
    }
}
