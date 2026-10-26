<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

final class JudgeExecutionReport
{
    /**
     * @param list<array{file: string, size: ?int, result: string, memory: string, time: string, exit_code: ?int, diagnostics: RuntimeErrorReport}> $testCases
     * @param list<array{file: string, rows: list<array{expected: string, actual: string}>}> $comparisons
     */
    public function __construct(
        private readonly array $testCases,
        private readonly array $comparisons,
        private readonly RuntimeErrorReport $diagnostics,
        private readonly string $rawExcerpt,
        private readonly bool $truncated
    ) {
    }

    public function testCases(): array { return $this->testCases; }
    public function comparisons(): array { return $this->comparisons; }
    public function diagnostics(): RuntimeErrorReport { return $this->diagnostics; }
    public function rawExcerpt(): string { return $this->rawExcerpt; }
    public function truncated(): bool { return $this->truncated; }
}
