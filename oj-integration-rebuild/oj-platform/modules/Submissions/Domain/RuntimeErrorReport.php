<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

final class RuntimeErrorReport
{
    /**
     * @param list<RuntimeDiagnostic> $diagnostics
     */
    public function __construct(
        private readonly array $diagnostics,
        private readonly bool $recognized,
        private readonly bool $truncated,
        private readonly string $rawExcerpt
    ) {
    }

    /** @return list<RuntimeDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function recognized(): bool
    {
        return $this->recognized;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function rawExcerpt(): string
    {
        return $this->rawExcerpt;
    }
}
