<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonApiProblemV1
{
    /** @param array<string, bool|int|float|string|null> $details */
    public function __construct(
        private readonly string $code,
        private readonly string $message,
        private readonly string $correlationId,
        private readonly bool $retryable,
        private readonly array $details = []
    ) {
        ValueValidator::errorCode($this->code);
        ValueValidator::text($this->message, 'message', 500);
        ValueValidator::correlationId($this->correlationId);
        ValueValidator::scalarMap($this->details, 'details');
    }

    public function code(): string { return $this->code; }
    public function message(): string { return $this->message; }
    public function correlationId(): string { return $this->correlationId; }
    public function retryable(): bool { return $this->retryable; }
    /** @return array<string, bool|int|float|string|null> */
    public function details(): array { return $this->details; }
}
