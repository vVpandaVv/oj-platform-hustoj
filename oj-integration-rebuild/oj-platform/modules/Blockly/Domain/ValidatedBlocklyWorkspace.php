<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Domain;

final class ValidatedBlocklyWorkspace
{
    /**
     * @param array<string, mixed> $workspace
     */
    public function __construct(
        private readonly string $profile,
        private readonly array $workspace,
        private readonly string $canonicalJson,
        private readonly int $blockCount,
        private readonly bool $usesInput
    ) {
    }

    public function profile(): string
    {
        return $this->profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(): array
    {
        return $this->workspace;
    }

    public function canonicalJson(): string
    {
        return $this->canonicalJson;
    }

    public function blockCount(): int
    {
        return $this->blockCount;
    }

    public function usesInput(): bool
    {
        return $this->usesInput;
    }
}
