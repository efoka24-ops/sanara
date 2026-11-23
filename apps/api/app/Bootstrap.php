<?php
declare(strict_types=1);

namespace Sanara;

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Sanara\Common\BodyGuardMiddleware;
use Sanara\Common\Config;
use Sanara\Common\Database;
use Sanara\Common\JsonErrorHandler;
use Slim\App;
use Slim\Factory\AppFactory;

final class Bootstrap
{
    public static function createApp(string $coreDir): App
    {
        if (is_file($coreDir . '/.env')) {
            Dotenv::createImmutable($coreDir)->safeLoad();
        }
        $config = Config::fromEnv($_ENV);
        Database::configure($config);

        $app = AppFactory::create();

        // Sous /api quand on passe par Camoo ; vide en dev (php -S).
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDir !== '' && $scriptDir !== '/') {
            $app->setBasePath($scriptDir);
        }

        $app->addBodyParsingMiddleware();
        // Ajouté après le parseur : s'exécute avant lui et rejette les corps invalides en 400.
        $app->add(new BodyGuardMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $errors = $app->addErrorMiddleware($config->debug, true, true);
        $errors->setDefaultErrorHandler(new JsonErrorHandler($app, $config->debug));

        self::routes($app, $config);
        return $app;
    }

    private static function routes(App $app, Config $config): void
    {
        $json = static function (Response $res, array $data, int $status = 200): Response {
            $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            return $res->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
        };

        $app->get('/health', function (Request $req, Response $res) use ($json, $config) {
            $db = ['ok' => false];
            try {
                $handle = Database::db();
                $db = ['ok' => $handle->ping(), 'version' => $handle->version()];

