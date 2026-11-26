<?php
declare(strict_types=1);

namespace Sanara\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sanara\Bootstrap;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\MethodOverrideMiddleware;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/** L'hébergeur refuse PUT et DELETE (403 Apache) : le client les envoie en POST avec un en-tête. */
final class MethodOverrideMiddlewareTest extends TestCase
{
    private function methodSeen(string $method, ?string $override): string
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, '/x');
        if ($override !== null) {
            $req = $req->withHeader('X-HTTP-Method-Override', $override);
        }
        $handler = new class implements RequestHandlerInterface {
            public string $seen = '';

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request->getMethod();
                return (new ResponseFactory())->createResponse(200);
            }
        };
        (new MethodOverrideMiddleware())->process($req, $handler);
        return $handler->seen;
    }

    public function testPostIsRewrittenToPutPatchOrDelete(): void
    {
        $this->assertSame('PUT', $this->methodSeen('POST', 'PUT'));
        $this->assertSame('PATCH', $this->methodSeen('POST', 'patch'));
        $this->assertSame('DELETE', $this->methodSeen('POST', 'DELETE'));
    }

    public function testOnlyPostCanBeRewritten(): void
    {
        $this->assertSame('GET', $this->methodSeen('GET', 'DELETE'));
    }

    public function testOtherTargetsAndPlainRequestsAreLeftAlone(): void
    {
        $this->assertSame('POST', $this->methodSeen('POST', 'GET'));
        $this->assertSame('POST', $this->methodSeen('POST', 'TRACE'));
        $this->assertSame('POST', $this->methodSeen('POST', null));
    }

    public function testApplicationRoutesTheOverriddenMethod(): void
    {
        $services = new Services(TestDb::create(), new Clock(), Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $app = Bootstrap::createApp(sys_get_temp_dir(), $services);
        $post = (new ServerRequestFactory())->createServerRequest('POST', '/auth/pin');

        $this->assertSame(405, $app->handle($post)->getStatusCode());
        // La route PUT est trouvée : sans jeton, elle répond 401 et non 405.
        $this->assertSame(401, $app->handle($post->withHeader('X-HTTP-Method-Override', 'PUT'))->getStatusCode());
    }
}
