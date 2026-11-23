<?php
declare(strict_types=1);

namespace Sanara\Common;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sanara\Common\Auth\AuthException;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Errors\ErrorCatalog;
use Sanara\Common\Logging\Log;
use Sanara\Common\Storage\StorageException;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/** Réponses d'erreur JSON uniformes : {error:{code,message,details?}}. Aucun détail interne hors mode debug. */
final class JsonErrorHandler implements ErrorHandlerInterface
{
    public function __construct(private readonly App $app, private readonly bool $debug)
    {
    }

    public function __invoke(ServerRequestInterface $request, Throwable $e, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails): ResponseInterface
    {
        $details = [];
        if ($e instanceof ApiException) {
            [$status, $code, $details] = [$e->status, $e->errorCode, $e->details];
        } elseif ($e instanceof AuthException) {
            [$status, $code] = [$e->status, $e->errorCode];
        } elseif ($e instanceof StorageException) {
            [$status, $code] = [$e->errorCode === 'not_found' ? 404 : ($e->errorCode === 'server_error' ? 500 : 422), $e->errorCode];
        } else {
            $status = $e instanceof HttpException ? $e->getCode() : 500;
            $code = match (true) {
                $status === 404 => 'not_found', $status === 405 => 'method_not_allowed', $status === 401 => 'unauthorized',
                $status === 403 => 'forbidden', $status >= 400 && $status < 500 => 'bad_request', default => 'server_error',
            };
        }
        $status = $status >= 400 && $status < 600 ? $status : 500;
        if ($status >= 500) {
            Log::write('error', get_class($e) . ': ' . $e->getMessage());
        }
        $locale = str_starts_with(strtolower($request->getHeaderLine('Accept-Language')), 'en') ? 'en' : 'fr';
        $error = ['code' => $code, 'message' => ErrorCatalog::message($code, $locale)];
        if ($details !== []) {
            $error['details'] = $details;
        }
        if ($this->debug) {
            $error['debug'] = $e->getMessage();
        }
        $res = $this->app->getResponseFactory()->createResponse($status);
        $res->getBody()->write(json_encode(['error' => $error], JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
