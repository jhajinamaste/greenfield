<?php

declare(strict_types=1);

namespace App\Core\Security;

final class ConfigCrypto
{
    private const PREFIX = 'enc:v1:';

    public function __construct(private readonly string $appKey)
    {
    }

    public function encrypt(string $plainText): string
    {
        $key = hash('sha256', $this->appKey, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plainText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new \RuntimeException('Failed to encrypt config value.');
        }

        return self::PREFIX . base64_encode($iv . $cipher);
    }

    public function decrypt(?string $cipherText): ?string
    {
        if ($cipherText === null || $cipherText === '') {
            return $cipherText;
        }

        if (!str_starts_with($cipherText, self::PREFIX)) {
            return $cipherText;
        }

        $payload = base64_decode(substr($cipherText, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < 17) {
            throw new \RuntimeException('Invalid encrypted config payload.');
        }

        $iv = substr($payload, 0, 16);
        $cipher = substr($payload, 16);
        $key = hash('sha256', $this->appKey, true);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt config value.');
        }

        return $plain;
    }

    public function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }
}
