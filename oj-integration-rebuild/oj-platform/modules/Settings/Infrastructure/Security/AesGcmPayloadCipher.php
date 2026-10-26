<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Security;

use CodingPanda\OjPlatform\Modules\Settings\Contract\PayloadCipher;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SecretKeyRing;
use JsonException;
use RuntimeException;

final class AesGcmPayloadCipher implements PayloadCipher
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly SecretKeyRing $keys)
    {
    }

    public function encrypt(mixed $value, string $associatedData): array
    {
        $this->assertAvailable();
        if ($associatedData === '') {
            throw new RuntimeException('암호화 연결 정보가 필요합니다.');
        }
        try {
            $plaintext = json_encode(
                ['value' => $value],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new RuntimeException('암호화할 값의 형식이 올바르지 않습니다.', 0, $error);
        }
        $keyId = $this->keys->primaryKeyId();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->keys->encryptionKey($keyId),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData,
            16
        );
        if (! is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('값을 암호화하지 못했습니다.');
        }

        return [
            'version' => 2,
            'key_id' => $keyId,
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    public function decrypt(array $record, string $associatedData): mixed
    {
        $this->assertAvailable();
        if ((int) ($record['version'] ?? 0) !== 2 || $associatedData === '') {
            throw new RuntimeException('암호화된 값의 형식이 올바르지 않습니다.');
        }
        $plaintext = openssl_decrypt(
            $this->decode((string) ($record['ciphertext'] ?? ''), null),
            self::CIPHER,
            $this->keys->encryptionKey((string) ($record['key_id'] ?? '')),
            OPENSSL_RAW_DATA,
            $this->decode((string) ($record['nonce'] ?? ''), 12),
            $this->decode((string) ($record['tag'] ?? ''), 16),
            $associatedData
        );
        if (! is_string($plaintext)) {
            throw new RuntimeException('암호화된 값을 해석하지 못했습니다.');
        }
        try {
            $decoded = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('암호화된 값의 내용이 올바르지 않습니다.', 0, $error);
        }
        if (! is_array($decoded) || ! array_key_exists('value', $decoded)) {
            throw new RuntimeException('암호화된 값의 내용이 올바르지 않습니다.');
        }

        return $decoded['value'];
    }

    private function decode(string $value, ?int $expectedLength): string
    {
        $decoded = base64_decode($value, true);
        if (
            ! is_string($decoded)
            || ($expectedLength !== null && strlen($decoded) !== $expectedLength)
        ) {
            throw new RuntimeException('암호화된 값의 인코딩이 올바르지 않습니다.');
        }

        return $decoded;
    }

    private function assertAvailable(): void
    {
        if (
            ! function_exists('openssl_encrypt')
            || ! in_array(self::CIPHER, openssl_get_cipher_methods(), true)
        ) {
            throw new RuntimeException('AES-256-GCM 암호화를 사용할 수 없습니다.');
        }
    }
}
