<?php
declare(strict_types=1);

namespace Sanara\Tests\Common;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Errors\ErrorCatalog;
use Sanara\Common\Logging\Redactor;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Common\Storage\StorageException;

final class CommonTest extends TestCase
{
    public function testErrorCatalogHasFrenchAndEnglishMessages(): void
    {
        foreach (ErrorCatalog::codes() as $code) {
            $this->assertNotSame('', ErrorCatalog::message($code, 'fr'), $code);
            $this->assertNotSame('', ErrorCatalog::message($code, 'en'), $code);
        }
        $this->assertSame('Identifiants incorrects.', ErrorCatalog::message('invalid_credentials', 'fr'));
    }

    public function testApiExceptionCarriesCodeAndStatus(): void
    {
        $e = new ApiException('validation_failed', 422, ['field' => 'email']);
        $this->assertSame(422, $e->status);
        $this->assertSame(['field' => 'email'], $e->details);
    }

    public function testRedactorMasksPersonalData(): void
    {
        $r = new Redactor();
        $out = $r->redact('Contact dr.ngo@pharmacie.cm au +237 690 00 00 00, token Bearer abc.def.ghi, pin=1234');
        $this->assertStringNotContainsString('dr.ngo@pharmacie.cm', $out);
        $this->assertStringNotContainsString('690 00 00 00', $out);
        $this->assertStringNotContainsString('abc.def.ghi', $out);
        $this->assertStringNotContainsString('1234', $out);
        $this->assertStringContainsString('d***@pharmacie.cm', $out);
    }

    public function testRedactorMasksContextKeys(): void
    {
        $ctx = (new Redactor())->context(['email' => 'a@b.cm', 'password' => 'x', 'order' => 5]);
        $this->assertSame('[masqué]', $ctx['password']);
        $this->assertSame(5, $ctx['order']);
    }

    private function storage(): EncryptedStorage
    {
        $dir = sys_get_temp_dir() . '/sanara-st-' . bin2hex(random_bytes(4));
        return new EncryptedStorage($dir, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), 'url-secret-0123456789-0123456789-xx');
    }

    public function testStoresEncryptedAndReadsBack(): void
    {
        $s = $this->storage();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $ref = $s->put($png, 'agrement.png');
        $this->assertSame($png, $s->get($ref['id']));
        $raw = (string) file_get_contents($s->pathFor($ref['id']));
        $this->assertStringNotContainsString('PNG', $raw);
        $this->assertSame('image/png', $ref['mime']);
    }

    public function testRejectsDisallowedTypeAndOversize(): void
    {
        $s = $this->storage();
        $this->expectException(StorageException::class);
        $s->put("<?php echo 1;", 'x.php');
    }

    public function testRejectsFilesOverLimit(): void
    {
        $s = $this->storage();
        $this->expectException(StorageException::class);
        $s->put("%PDF-1.4\n" . str_repeat('a', 5 * 1024 * 1024 + 1), 'gros.pdf');
    }

    public function testSignedUrlExpiresAndCannotBeForged(): void
    {
        $s = $this->storage();
        $ref = $s->put("%PDF-1.4\nok", 'a.pdf');
        $now = 1_000_000;
        $url = $s->sign($ref['id'], 120, $now);
        $this->assertTrue($s->verify($ref['id'], $url['exp'], $url['sig'], $now + 60));
        $this->assertFalse($s->verify($ref['id'], $url['exp'], $url['sig'], $now + 121));
        $this->assertFalse($s->verify($ref['id'], $url['exp'], 'forged', $now + 1));
        $this->assertFalse($s->verify('autre-id', $url['exp'], $url['sig'], $now + 1));
    }
}
