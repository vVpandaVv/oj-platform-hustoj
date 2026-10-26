<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Application;

final class GroupSaveCommand
{
    public function __construct(
        private readonly int $groupId,
        private readonly string $name,
        private readonly string $slug,
        private readonly string $description,
        private readonly string $visibility,
        private readonly string $approvalMode,
        private readonly bool $enabled,
        private readonly ?string $blocklyProfileKey,
        private readonly string $evaluationMode,
        private readonly ?string $joinCode
    ) {
    }

    public function groupId(): int { return $this->groupId; }
    public function name(): string { return trim($this->name); }
    public function slug(): string { return strtolower(trim($this->slug)); }
    public function description(): string { return trim($this->description); }
    public function visibility(): string { return $this->visibility; }
    public function approvalMode(): string { return $this->approvalMode; }
    public function isEnabled(): bool { return $this->enabled; }
    public function blocklyProfileKey(): ?string
    {
        return self::nullable($this->blocklyProfileKey);
    }
    public function evaluationMode(): string { return $this->evaluationMode; }
    public function joinCode(): ?string { return self::nullable($this->joinCode); }

    private static function nullable(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
