<?php
declare(strict_types=1);

namespace Sanara\Common;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * L'hébergeur refuse PUT, PATCH et DELETE (403 Apache) : le client les envoie en POST avec
 * l'en-tête X-HTTP-Method-Override. Seul un POST peut être réécrit, et vers ces trois méthodes.
 */
final class MethodOverrideMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $target = strtoupper($request->getHeaderLine('X-HTTP-Method-Override'));
        if ($request->getMethod() === 'POST' && in_array($target, ['PUT', 'PATCH', 'DELETE'], true)) {
            $request = $request->withMethod($target);
        }
        return $handler->handle($request);
    }
}
