<?php
declare(strict_types=1);

namespace Sanara\Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Sanara\Modules\Notifications\Sms\SmsException;
use Sanara\Modules\Notifications\Sms\TeeeotpSmsProvider;

final class SmsTest extends TestCase
{
    /** @return array{0: TeeeotpSmsProvider, 1: \ArrayObject<int, array<string, mixed>>} */
    private function provider(int $status, array $body): array
    {
        $calls = new \ArrayObject();
        $p = new TeeeotpSmsProvider('https://trugroup.cm/teeeotp', 'sk_test_x', function (string $method, string $url, array $headers, ?string $json) use ($calls, $status, $body) {
            $calls[] = compact('method', 'url', 'headers', 'json');
            return ['status' => $status, 'body' => json_encode($body)];
        });
        return [$p, $calls];
    }

    public function testSendsAuthenticatedJsonToV1Sms(): void
    {
        [$p, $calls] = $this->provider(202, ['id' => 'abc', 'status' => 'queued']);
        $id = $p->send('690000000', 'Votre commande est prete.');
        $this->assertSame('abc', $id);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://trugroup.cm/teeeotp/v1/sms', $calls[0]['url']);
        $this->assertContains('X-Api-Key: sk_test_x', $calls[0]['headers']);
        $this->assertSame(['phone' => '690000000', 'message' => 'Votre commande est prete.'], json_decode($calls[0]['json'], true));
    }

    public function testNormalisesCameroonNumbers(): void
    {
        [$p, $calls] = $this->provider(202, ['id' => '1']);
        foreach (['+237 690 00 00 00', '237690000000', '690000000'] as $n) {
            $p->send($n, 'x');
        }
        foreach ($calls as $c) {
            $this->assertSame('690000000', json_decode($c['json'], true)['phone']);
        }
    }

    public function testRejectsNonCameroonMobileBeforeCallingTheApi(): void
    {
        [$p, $calls] = $this->provider(202, ['id' => '1']);
        $this->expectException(SmsException::class);
        try {
            $p->send('+33612345678', 'x');
        } finally {
            $this->assertCount(0, $calls);
        }
    }

    public function testMapsRateLimitWithRetryAfter(): void
    {
        [$p] = $this->provider(429, ['error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => '...'], 'retryAfter' => 120]);
        try {
            $p->send('690000000', 'x');
            $this->fail();
        } catch (SmsException $e) {
            $this->assertSame('TOO_MANY_REQUESTS', $e->errorCode);
            $this->assertSame(120, $e->retryAfter);
        }
    }

    public function testNeverLogsOrExposesTheApiKeyInErrors(): void
    {
        [$p] = $this->provider(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'bad']]);
        try {
            $p->send('690000000', 'x');
        } catch (SmsException $e) {
            $this->assertStringNotContainsString('sk_test_x', $e->getMessage());
        }
    }
}
