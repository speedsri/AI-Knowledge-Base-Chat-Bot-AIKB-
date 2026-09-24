<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Encrypts/decrypts secrets (e.g. ai_providers.api_key_encrypted) using
 * libsodium secretbox. The key comes ONLY from SECRETS_ENCRYPTION_KEY in
 * .env — it is never stored in the database and must never be logged.
 */
final class Encryptor
{
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $key = self::key();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt payload (wrong key or tampered data).');
        }
        return $plaintext;
    }

    private static function key(): string
    {
        $hex = Config::get('secrets.encryption_key', '');
        if (!is_string($hex) || $hex === '') {
            throw new \RuntimeException(
                'SECRETS_ENCRYPTION_KEY is not set. Generate one with: ' .
                'php -r "echo sodium_bin2hex(sodium_crypto_secretbox_keygen());"'
            );
        }
        $key = sodium_hex2bin($hex);
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('SECRETS_ENCRYPTION_KEY must be a 32-byte value encoded as hex.');
        }
        return $key;
    }
}
