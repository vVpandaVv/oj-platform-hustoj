<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Application;

use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;

final class ClassSaveCommand
{
    /**
     * @param list<int> $instructorUserIds
     * @param list<int> $groupIds
     * @param list<int> $problemIds
     * @param list<ClassProblem> $problemConfigurations
     */
    public function __construct(
        private readonly int $classId,
        private readonly string $name,
        private readonly string $slug,
        private readonly string $description,
        private readonly string $visibility,
        private readonly string $progressVisibility,
        private readonly bool $enabled,
        private readonly ?string $startsAt,
        private readonly ?string $endsAt,
        private readonly ?string $password,
        private readonly ?string $blocklyProfileKey,
        private readonly array $instructorUserIds,
        private readonly array $groupIds,
        private readonly array $problemIds,
        private readonly array $problemConfigurations = [],
        private readonly bool $approvalRequired = false
    ) {
    }

    public function classId(): int { return $this->classId; }
    public function name(): string { return trim($this->name); }
    public function slug(): string { return strtolower(trim($this->slug)); }
    public function description(): string { return trim($this->description); }
    public function visibility(): string { return $this->visibility; }
    public function progressVisibility(): string { return $this->progressVisibility; }
    public function isEnabled(): bool { return $this->enabled; }
    public function requiresApproval(): bool { return $this->approvalRequired; }
    public function startsAt(): ?string { return self::nullable($this->startsAt); }
    public function endsAt(): ?string { return self::nullable($this->endsAt); }
    public function password(): ?string { return self::nullable($this->password); }
    public function blocklyProfileKey(): ?string { return self::nullable($this->blocklyProfileKey); }

    /** @return list<int> */
    public function instructorUserIds(): array { return $this->positiveIds($this->instructorUserIds); }

    /** @return list<int> */
    public function groupIds(): array { return $this->positiveIds($this->groupIds); }

    /** @return list<int> */
    public function problemIds(): array { return $this->positiveIds($this->problemIds); }


    /** @return array<int, ClassProblem> */
    public function problemConfigurations(): array
    {
        $allowed = array_flip($this->problemIds());
        $configurations = [];
        foreach ($this->problemConfigurations as $configuration) {
            if (
                $configuration instanceof ClassProblem
                && isset($allowed[$configuration->problemId()])
            ) {
                $configurations[$configuration->problemId()] = $configuration;
            }
        }

        return $configurations;
    }
    private static function nullable(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function positiveIds(array $values): array
    {
        return array_values(array_unique(array_filter(
            $values,
            static fn (mixed $value): bool => is_int($value) && $value > 0
        )));
    }
}
