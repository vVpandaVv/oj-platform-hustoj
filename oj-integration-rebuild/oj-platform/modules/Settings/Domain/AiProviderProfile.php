<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use InvalidArgumentException;
use JsonException;

final class AiProviderProfile
{
    /**
     * @param list<string> $allowedTaskTypes
     * @param list<string> $resolvedIps
     */
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly string $providerKey,
        private readonly string $baseUrl,
        private readonly string $authenticationMethod,
        private readonly string $secret,
        private readonly string $model,
        private readonly string $modelRevision,
        private readonly array $allowedTaskTypes,
        private readonly bool $allowSubmissionSource,
        private readonly int $timeoutSeconds,
        private readonly array $resolvedIps,
        private readonly string $fingerprint,
        private readonly bool $active = true
    ) {
        if (
            preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $this->key) !== 1
            || trim($this->name) === ''
            || strlen($this->name) > 100
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->providerKey) !== 1
            || filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false
            || ! in_array($this->authenticationMethod, ['bearer', 'x_api_key'], true)
            || $this->secret === ''
            || trim($this->model) === ''
            || strlen($this->model) > 191
            || trim($this->modelRevision) === ''
            || $this->timeoutSeconds < 10
            || $this->timeoutSeconds > 120
            || preg_match('/^[a-f0-9]{64}$/', $this->fingerprint) !== 1
        ) {
            throw new InvalidArgumentException('AI 연결 프로필이 올바르지 않습니다.');
        }
        $tasks = array_values(array_unique(array_map('strval', $this->allowedTaskTypes)));
        sort($tasks);
        if ($tasks !== $this->allowedTaskTypes || $tasks === []) {
            throw new InvalidArgumentException('AI 연결 프로필의 허용 작업이 올바르지 않습니다.');
        }
        foreach ($tasks as $taskType) {
            if (
                preg_match(
                    '/^(?:[a-z][a-z0-9_]*|addon\.[a-z][a-z0-9_]{2,63}\.[a-z][a-z0-9_.-]*)$/D',
                    $taskType
                ) !== 1
            ) {
                throw new InvalidArgumentException('AI 허용 작업 키가 올바르지 않습니다.');
            }
        }
        $ips = array_values(array_unique(array_map('strval', $this->resolvedIps)));
        sort($ips);
        if ($ips !== $this->resolvedIps || $ips === []) {
            throw new InvalidArgumentException('AI 연결 프로필의 확인 주소가 올바르지 않습니다.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('AI 연결 프로필의 확인 주소가 올바르지 않습니다.');
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            trim((string) ($value['key'] ?? '')),
            trim((string) ($value['name'] ?? '')),
            trim((string) ($value['provider_key'] ?? '')),
            trim((string) ($value['base_url'] ?? '')),
            trim((string) ($value['authentication_method'] ?? '')),
            (string) ($value['secret'] ?? ''),
            trim((string) ($value['model'] ?? '')),
            trim((string) ($value['model_revision'] ?? '')),
            self::stringList($value['allowed_task_types'] ?? []),
            filter_var(
                $value['allow_submission_source'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
            (int) ($value['timeout_seconds'] ?? 60),
            self::ipList($value['resolved_ips'] ?? []),
            trim((string) ($value['fingerprint'] ?? '')),
            ! array_key_exists('active', $value)
                || filter_var($value['active'], FILTER_VALIDATE_BOOL)
        );
    }

    /**
     * @param list<string> $allowedTaskTypes
     * @param list<string> $resolvedIps
     */
    public static function tested(
        string $key,
        string $name,
        string $providerKey,
        string $baseUrl,
        string $authenticationMethod,
        string $secret,
        string $model,
        string $modelRevision,
        array $allowedTaskTypes,
        bool $allowSubmissionSource,
        int $timeoutSeconds,
        array $resolvedIps
    ): self {
        sort($allowedTaskTypes);
        sort($resolvedIps);
        $fingerprint = self::calculateFingerprint([
            'key' => $key,
            'provider_key' => $providerKey,
            'base_url' => $baseUrl,
            'authentication_method' => $authenticationMethod,
            'secret_hash' => hash('sha256', $secret),
            'model' => $model,
            'model_revision' => $modelRevision,
            'allowed_task_types' => $allowedTaskTypes,
            'allow_submission_source' => $allowSubmissionSource,
            'timeout_seconds' => $timeoutSeconds,
            'resolved_ips' => $resolvedIps,
        ]);

        return new self(
            $key,
            $name,
            $providerKey,
            $baseUrl,
            $authenticationMethod,
            $secret,
            $model,
            $modelRevision,
            $allowedTaskTypes,
            $allowSubmissionSource,
            $timeoutSeconds,
            $resolvedIps,
            $fingerprint
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function authenticationMethod(): string
    {
        return $this->authenticationMethod;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function modelRevision(): string
    {
        return $this->modelRevision;
    }

    /**
     * @return list<string>
     */
    public function allowedTaskTypes(): array
    {
        return $this->allowedTaskTypes;
    }

    public function allowsTask(string $taskType): bool
    {
        return $this->active
            && in_array($taskType, $this->allowedTaskTypes, true);
    }

    public function allowsSubmissionSource(): bool
    {
        return $this->allowSubmissionSource;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * @return list<string>
     */
    public function resolvedIps(): array
    {
        return $this->resolvedIps;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'provider_key' => $this->providerKey,
            'base_url' => $this->baseUrl,
            'authentication_method' => $this->authenticationMethod,
            'secret' => $this->secret,
            'model' => $this->model,
            'model_revision' => $this->modelRevision,
            'allowed_task_types' => $this->allowedTaskTypes,
            'allow_submission_source' => $this->allowSubmissionSource,
            'timeout_seconds' => $this->timeoutSeconds,
            'resolved_ips' => $this->resolvedIps,
            'fingerprint' => $this->fingerprint,
            'active' => $this->active,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function calculateFingerprint(array $value): string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    $value,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'AI 연결 프로필 fingerprint를 만들 수 없습니다.',
                0,
                $error
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }
        $items = array_values(array_unique($items));
        sort($items);

        return $items;
    }

    /**
     * @return list<string>
     */
    private static function ipList(mixed $value): array
    {
        $items = self::stringList($value);
        sort($items);

        return $items;
    }
}
