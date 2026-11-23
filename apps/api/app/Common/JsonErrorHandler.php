<?php
declare(strict_types=1);

namespace Sanara\Common;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\App;
use Throwable;

/** Réponses d'erreur JSON uniformes. Aucun détail interne hors mode debug. */
final class JsonErrorHandler implements ErrorHandlerInterface
{
    public function __construct(private readonly App $app, private readonly bool $debug)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        $code = match (true) {
            $status === 404 => 'not_found',
            $status === 405 => 'method_not_allowed',
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status >= 400 && $status < 500 => 'bad_request',
            default => 'server_error',
        };
        if ($status >= 500) {
            error_log('[sanara] ' . get_class($exception) . ': ' . $exception->getMessage());
        }
        $payload = ['error' => ['code' => $code]];
        if ($this->debug) {
            $payload['error']['message'] = $exception->getMessage();
        }
        $res = $this->app->getResponseFactory()->createResponse($status >= 400 ? $status : 500);
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
