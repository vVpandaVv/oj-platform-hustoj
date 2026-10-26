<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use RuntimeException;

final class SecretKeyRing
{
    private const WORDPRESS_KEY_ID = 'wordpress-v1';

    /**
     * @var list<string>
     */
    private const WORDPRESS_SECRET_CONSTANTS = [
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
    ];

    /**
     * @var array<string, string>
     */
    private array $keys = [];

    /**
     * @param array<string, string> $keys
     */
    public function __construct(
        array $keys,
        private readonly ?string $primaryKeyId
    ) {
        foreach ($keys as $keyId => $material) {
            if (
                preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $keyId) !== 1
                || strlen((string) $material) < 32
            ) {
                throw new RuntimeException('비밀값 암호화 키 구성이 올바르지 않습니다.');
            }

            $this->keys[(string) $keyId] = (string) $material;
        }

        if (
            $this->primaryKeyId !== null
            && ! isset($this->keys[$this->primaryKeyId])
        ) {
            throw new RuntimeException('기본 비밀값 암호화 키를 찾을 수 없습니다.');
        }
    }

    public static function fromConstants(): self
    {
        $keys = defined('OJ_PLATFORM_SECRET_KEYS')
            ? constant('OJ_PLATFORM_SECRET_KEYS')
            : [];
        $primary = defined('OJ_PLATFORM_SECRET_PRIMARY_KEY_ID')
            ? constant('OJ_PLATFORM_SECRET_PRIMARY_KEY_ID')
            : null;

        $keys = is_array($keys) ? array_map('strval', $keys) : [];
        $primary = is_scalar($primary) && trim((string) $primary) !== ''
            ? trim((string) $primary)
            : null;

        if ($keys === [] && $primary === null) {
            $wordpressMaterial = self::wordpressKeyMaterial();
            if ($wordpressMaterial !== null) {
                return new self(
                    [self::WORDPRESS_KEY_ID => $wordpressMaterial],
                    self::WORDPRESS_KEY_ID
                );
            }
        }

        return new self($keys, $primary);
    }

    public function primaryKeyId(): string
    {
        if ($this->primaryKeyId === null) {
            throw new RuntimeException('비밀값 저장용 기본 키가 설정되지 않았습니다.');
        }

        return $this->primaryKeyId;
    }

    public function encryptionKey(string $keyId): string
    {
        $material = $this->keys[$keyId] ?? null;
        if ($material === null) {
            throw new RuntimeException('비밀값을 해석할 암호화 키가 없습니다.');
        }

        return hash('sha256', $material, true);
    }

    private static function wordpressKeyMaterial(): ?string
    {
        $secrets = [];
        foreach (self::WORDPRESS_SECRET_CONSTANTS as $name) {
            if (! defined($name)) {
                continue;
            }
            $value = constant($name);
            if (! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || $value === 'put your unique phrase here') {
                continue;
            }
            $secrets[] = $name . '=' . $value;
        }
        if ($secrets === []) {
            return null;
        }

        return hash(
            'sha512',
            "oj-platform-secret-v1\0" . implode("\0", $secrets)
        );
    }
}
