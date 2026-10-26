<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use InvalidArgumentException;
use JsonException;

final class SmtpSettings
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly bool $authentication,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName
    ) {
        if (
            trim($this->host) === ''
            || $this->port < 1
            || $this->port > 65535
            || ! in_array($this->encryption, ['starttls', 'smtps'], true)
            || ($this->authentication && (trim($this->username) === '' || $this->password === ''))
            || filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) === false
            || trim($this->fromName) === ''
            || strlen($this->fromName) > 100
        ) {
            throw new InvalidArgumentException('SMTP 설정이 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            filter_var($value['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            trim((string) ($value['host'] ?? '')),
            (int) ($value['port'] ?? 587),
            trim((string) ($value['encryption'] ?? 'starttls')),
            ! array_key_exists('authentication', $value)
                || filter_var($value['authentication'], FILTER_VALIDATE_BOOL),
            trim((string) ($value['username'] ?? '')),
            (string) ($value['password'] ?? ''),
            trim((string) ($value['from_address'] ?? '')),
            trim((string) ($value['from_name'] ?? ''))
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function encryption(): string
    {
        return $this->encryption;
    }

    public function authentication(): bool
    {
        return $this->authentication;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function fromAddress(): string
    {
        return $this->fromAddress;
    }

    public function fromName(): string
    {
        return $this->fromName;
    }

    public function fingerprint(): string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    [
                        'enabled' => $this->enabled,
                        'host' => strtolower($this->host),
                        'port' => $this->port,
                        'encryption' => $this->encryption,
                        'authentication' => $this->authentication,
                        'username' => $this->username,
                        'password_hash' => hash('sha256', $this->password),
                        'from_address' => strtolower($this->fromAddress),
                        'from_name' => $this->fromName,
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'SMTP 후보 fingerprint를 만들 수 없습니다.',
                0,
                $error
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'authentication' => $this->authentication,
            'username' => $this->username,
            'password' => $this->password,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
        ];
    }
}
