<?php
declare(strict_types=1);

namespace Sanara\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sanara\Common\BodyGuardMiddleware;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class BodyGuardMiddlewareTest extends TestCase
{
    private function send(string $method, string $type, string $body): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, '/x')
            ->withHeader('Content-Type', $type)
            ->withBody((new StreamFactory())->createStream($body));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
        return (new BodyGuardMiddleware(new ResponseFactory()))->process($req, $handler);
    }

    public function testValidJsonWithApostropheAndAccentsPasses(): void
    {
        $res = $this->send('POST', 'application/json', json_encode(['text' => "l'agrément d'officine, café à Garoua"], JSON_UNESCAPED_UNICODE));
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testInvalidJsonIsRejectedWith400(): void
    {
        $res = $this->send('POST', 'application/json', '{"text":');
        $this->assertSame(400, $res->getStatusCode());
        $this->assertStringContainsString('invalid_json', (string) $res->getBody());
    }

    public function testInvalidUtf8InJsonIsRejectedWith400(): void
    {
        $res = $this->send('POST', 'application/json', "{\"text\":\"agr\xE9ment\"}");
        $this->assertSame(400, $res->getStatusCode());
        $this->assertStringContainsString('invalid_encoding', (string) $res->getBody());
    }

    public function testInvalidUtf8InFormIsRejectedWith400(): void
    {
        $res = $this->send('POST', 'application/x-www-form-urlencoded', 'text=agr%E9ment');
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testValidFormPasses(): void
    {
        $res = $this->send('POST', 'application/x-www-form-urlencoded', 'text=agr%C3%A9ment');
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testGetIsNeverInspected(): void
    {
        $this->assertSame(200, $this->send('GET', 'application/json', '{bad')->getStatusCode());
    }
}
