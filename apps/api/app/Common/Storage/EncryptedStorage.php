<?php
declare(strict_types=1);

namespace Sanara\Common\Storage;

use Sanara\Common\Ulid;

/**
 * Documents chiffrés (sodium secretbox) dans un dossier hors des chemins servis. L'accès passe par l'API
 * avec une URL signée à durée courte. La clé vient de l'environnement, jamais de la base.
 */
final class EncryptedStorage
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(private readonly string $dir, private readonly string $key, private readonly string $urlSecret)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Clé de stockage invalide (32 octets requis)');
        }
    }

    /** @return array{id: string, mime: string, size: int, sha256: string, name: string} */
    public function put(string $bytes, string $originalName): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new StorageException('file_too_large');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new StorageException('file_type_not_allowed');
        }
        $id = Ulid::generate();
        if (!is_dir($this->dir) && !mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new StorageException('server_error');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        file_put_contents($this->pathFor($id), $nonce . sodium_crypto_secretbox($bytes, $nonce, $this->key));
        return ['id' => $id, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'name' => mb_substr(basename($originalName), 0, 120)];
    }

    public function get(string $id): string
    {
        $raw = @file_get_contents($this->pathFor($id));
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new StorageException('not_found');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        return $plain === false ? throw new StorageException('server_error') : $plain;
    }

    public function pathFor(string $id): string
    {
        if (!preg_match('/^[0-9A-Z]{26}$/', $id)) {
            throw new StorageException('not_found');
        }
        return $this->dir . '/' . $id . '.bin';
    }

    /** @return array{exp: int, sig: string} */
    public function sign(string $id, int $ttlSeconds, ?int $now = null): array
    {
        $exp = ($now ?? time()) + $ttlSeconds;
        return ['exp' => $exp, 'sig' => hash_hmac('sha256', $id . '|' . $exp, $this->urlSecret)];
    }

    public function verify(string $id, int $exp, string $sig, ?int $now = null): bool
    {
        return ($now ?? time()) <= $exp && hash_equals(hash_hmac('sha256', $id . '|' . $exp, $this->urlSecret), $sig);
    }
}
