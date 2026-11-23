<?php
declare(strict_types=1);

namespace Sanara\Common;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuse proprement (400 JSON) les corps mal encodés ou en JSON invalide,
 * avant que le parseur de Slim ne les voie : jamais de 500 pour une erreur client.
 */
final class BodyGuardMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'DELETE'], true)) {
            return $handler->handle($request);
        }

        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        $body = $request->getBody();
        $raw = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        if ($raw === '') {
            return $handler->handle($request);
        }

        if (str_contains($type, 'json')) {
            if (!mb_check_encoding($raw, 'UTF-8')) {
                return $this->reject('invalid_encoding');
            }
            json_decode($raw);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->reject('invalid_json');
            }
        } elseif ($type === 'application/x-www-form-urlencoded') {
            if (!mb_check_encoding(rawurldecode(str_replace('+', ' ', $raw)), 'UTF-8')) {
                return $this->reject('invalid_encoding');
            }
        }

        return $handler->handle($request);
    }

    private function reject(string $code): ResponseInterface
    {
        $res = $this->responses->createResponse(400);
        $res->getBody()->write(json_encode(['error' => ['code' => $code]]));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
