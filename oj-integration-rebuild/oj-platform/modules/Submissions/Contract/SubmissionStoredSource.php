<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

final class SubmissionStoredSource
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        private readonly string $displaySource,
        private readonly string $format,
        private readonly array $metadata = []
    ) {
    }

    public function displaySource(): string
    {
        return $this->displaySource;
    }

    public function format(): string
    {
        return $this->format;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
