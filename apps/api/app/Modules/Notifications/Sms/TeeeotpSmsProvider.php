<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Sms;

/** SMS via le service mutualisé teeeotp (POST /v1/sms). La clé reste côté serveur. */
final class TeeeotpSmsProvider implements SmsProvider
{
    /** @var callable(string, string, list<string>, ?string): array{status: int, body: string} */
    private $transport;

    /** @param (callable(string, string, list<string>, ?string): array{status: int, body: string})|null $transport */
    public function __construct(private readonly string $baseUrl, private readonly string $apiKey, ?callable $transport = null)
    {
        $this->transport = $transport ?? $this->curl(...);
    }

    public function send(string $phone, string $message): string
    {
        $res = ($this->transport)('POST', rtrim($this->baseUrl, '/') . '/v1/sms', [
            'X-Api-Key: ' . $this->apiKey, 'Content-Type: application/json',
        ], json_encode(['phone' => self::normalise($phone), 'message' => $message], JSON_UNESCAPED_UNICODE));
        $body = json_decode($res['body'], true) ?: [];
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return (string) ($body['id'] ?? '');
        }
        throw new SmsException((string) ($body['error']['code'] ?? 'UNREACHABLE'), 'Échec d\'envoi SMS (HTTP ' . $res['status'] . ')', isset($body['retryAfter']) ? (int) $body['retryAfter'] : null);
    }

    /** Mobile camerounais : 9 chiffres commençant par 6. */
    public static function normalise(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '237') && strlen($d) === 12) {
            $d = substr($d, 3);
        }
        if (!preg_match('/^6\d{8}$/', $d)) {
            throw new SmsException('INVALID_PHONE', 'Numéro de mobile camerounais attendu');
        }
        return $d;
    }

    /** @param list<string> $headers @return array{status: int, body: string} */
    private function curl(string $method, string $url, array $headers, ?string $json): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new SmsException('UNREACHABLE', 'Service SMS injoignable');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'body' => (string) $body];
    }
}
