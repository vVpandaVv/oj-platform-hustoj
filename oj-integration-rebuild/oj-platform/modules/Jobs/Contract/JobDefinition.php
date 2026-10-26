<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use InvalidArgumentException;

final class JobDefinition implements RegistryDefinition
{
    public function __construct(
        private readonly string $jobKey,
        private readonly ModuleId $moduleOwner,
        private readonly string $definitionVersion,
        private readonly int $maximumAttempts,
        private readonly bool $usesSteps
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->jobKey) !== 1) {
            throw new InvalidArgumentException('작업 종류 키 형식이 올바르지 않습니다.');
        }

        if (preg_match('/^[1-9][0-9]*$/', $this->definitionVersion) !== 1) {
            throw new InvalidArgumentException('작업 정의 버전 형식이 올바르지 않습니다.');
        }

        if ($this->maximumAttempts < 1 || $this->maximumAttempts > 10) {
            throw new InvalidArgumentException('작업 최대 시도 횟수는 1~10이어야 합니다.');
        }

        if ($this->usesSteps && $this->maximumAttempts !== 1) {
            throw new InvalidArgumentException('단계 작업은 자동 전체 재시도를 허용하지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['key'] ?? ''),
            new ModuleId((string) ($definition['owner_module'] ?? '')),
            (string) ($definition['definition_version'] ?? ''),
            (int) ($definition['max_attempts'] ?? 0),
            (bool) ($definition['uses_steps'] ?? false)
        );
    }

    public function key(): string
    {
        return $this->jobKey;
    }

    public function owner(): ModuleId
    {
        return $this->moduleOwner;
    }

    public function definitionVersion(): string
    {
        return $this->definitionVersion;
    }

    public function maxAttempts(): int
    {
        return $this->maximumAttempts;
    }

    public function usesSteps(): bool
    {
        return $this->usesSteps;
    }
}
