<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

final class JudgeReadinessReport
{
    /**
     * @param list<string> $missingRequiredTables
     * @param list<string> $missingCompatibilityTables
     * @param array<string, list<string>> $missingRequiredColumns
     * @param list<string> $invalidColumnTypes
     * @param list<string> $presentOptionalTables
     * @param list<string> $presentIgnoredTables
     * @param array<string, string> $tableEngines
     * @param list<string> $errors
     */
    public function __construct(
        private readonly array $missingRequiredTables,
        private readonly array $missingCompatibilityTables,
        private readonly array $missingRequiredColumns,
        private readonly array $invalidColumnTypes,
        private readonly array $presentOptionalTables,
        private readonly array $presentIgnoredTables,
        private readonly array $tableEngines,
        private readonly array $errors
    ) {
    }

    public function isReady(): bool
    {
        return $this->missingRequiredTables === []
            && $this->missingCompatibilityTables === []
            && $this->missingRequiredColumns === []
            && $this->invalidColumnTypes === []
            && $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function missingRequiredTables(): array
    {
        return $this->missingRequiredTables;
    }

    /**
     * @return list<string>
     */
    public function missingCompatibilityTables(): array
    {
        return $this->missingCompatibilityTables;
    }

    /**
     * @return array<string, list<string>>
     */
    public function missingRequiredColumns(): array
    {
        return $this->missingRequiredColumns;
    }

    /**
     * @return list<string>
     */
    public function invalidColumnTypes(): array
    {
        return $this->invalidColumnTypes;
    }

    /**
     * @return list<string>
     */
    public function presentOptionalTables(): array
    {
        return $this->presentOptionalTables;
    }

    /**
     * @return list<string>
     */
    public function presentIgnoredTables(): array
    {
        return $this->presentIgnoredTables;
    }

    /**
     * @return array<string, string>
     */
    public function tableEngines(): array
    {
        return $this->tableEngines;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}