<?php
declare(strict_types=1);

namespace Sanara;

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Sanara\Common\BodyGuardMiddleware;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Database;
use Sanara\Common\JsonErrorHandler;
use Sanara\Modules\Http\Routes;
use Sanara\Modules\Http\Services;
use Slim\App;
use Slim\Factory\AppFactory;

final class Bootstrap
{
    public static function createApp(string $coreDir, ?Services $services = null): App
    {
        $injected = $services !== null;
        if ($services === null) {
            if (is_file($coreDir . '/.env')) {
                Dotenv::createImmutable($coreDir)->safeLoad();
            }
            $config = Config::fromEnv($_ENV);
            Database::configure($config);
            $services = new Services(Database::db(), new Clock(), $config);
        }
        $config = $services->config;

        $app = AppFactory::create();

        // Sous /api quand on passe par Camoo ; vide en dev (php -S).
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if (!$injected && $scriptDir !== '' && $scriptDir !== '/') {
            $app->setBasePath($scriptDir);
        }

        $app->addBodyParsingMiddleware();
        // Ajouté après le parseur : s'exécute avant lui et rejette les corps invalides en 400.
        $app->add(new BodyGuardMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $errors = $app->addErrorMiddleware($config->debug, true, true);
        $errors->setDefaultErrorHandler(new JsonErrorHandler($app, $config->debug));

        self::health($app, $config, $services);
        Routes::register($app, $services);
        return $app;
    }

    private static function health(App $app, Config $config, Services $services): void
    {
        $json = static function (Response $res, array $data, int $status = 200): Response {
            $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            return $res->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
        };

        $app->get('/health', function (Request $req, Response $res) use ($json, $config, $services) {
            $db = ['ok' => false];
            try {
                $db = ['ok' => $services->db->ping(), 'version' => $services->db->version()];
            } catch (\Throwable $e) {
                $db['error'] = $config->debug ? $e->getMessage() : 'unavailable';
                $db['code'] = (string) $e->getCode(); // code SQLSTATE/MySQL, sans donnée sensible
            }
            return $json($res, [
                'app' => 'Sanara',
                'status' => $db['ok'] ? 'ok' : 'degraded',
                'php' => PHP_VERSION,
                'env' => $config->env,
                'time' => gmdate('c'),
                'limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                ],
                'database' => $db,
            ], $db['ok'] ? 200 : 503);
        });

        // Test de non-régression du pare-feu (apostrophes, accents) : renvoie ce qu'il reçoit.
        $app->post('/echo', function (Request $req, Response $res) use ($json) {
            $body = (array) $req->getParsedBody();
            return $json($res, ['received' => $body, 'length' => mb_strlen((string) ($body['text'] ?? ''))]);
        });
    }
}
