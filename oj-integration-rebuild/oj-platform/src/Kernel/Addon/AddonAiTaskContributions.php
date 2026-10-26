<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskInstructionV1;
use DomainException;

/**
 * Mutable sink populated during addon catalog composition and read as an
 * immutable task descriptor registry afterwards.
 *
 * @internal
 */
final class AddonAiTaskContributions
{
    /** @var array<string, AddonAiTaskDefinitionV1> task key => accepted definition */
    private array $tasks = [];

    public function add(AddonAiTaskDefinitionV1 $definition): void
    {
        $this->tasks[$definition->key()] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->tasks[$key]);
    }

    public function label(string $key): string
    {
        return $this->require($key)->label();
    }

    public function requiresSubmissionSource(string $key): bool
    {
        return isset($this->tasks[$key])
            && $this->tasks[$key]->sourceKind() === 'platform_submission';
    }

    public function instruction(string $key): ?AddonAiTaskInstructionV1
    {
        return $this->require($key)->instruction();
    }

    public function contractFingerprint(string $key): string
    {
        $definition = $this->require($key);
        $instruction = $definition->instruction();

        return hash('sha256', implode('|', [
            'addon-task-contract-v1',
            $definition->key(),
            $definition->inputContractVersion(),
            $definition->sourceKind(),
            $instruction?->contractVersion() ?? '',
            $instruction?->contentHash() ?? '',
        ]));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->tasks);
    }

    private function require(string $key): AddonAiTaskDefinitionV1
    {
        return $this->tasks[$key]
            ?? throw new DomainException('등록되지 않은 애드온 AI 작업입니다.');
    }
}
