<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonAiTaskRequestV1
{
    /** @param array<string, mixed> $input */
    public function __construct(
        private readonly string $taskKey,
        private readonly string $contractVersion,
        private readonly int $actorUserId,
        private readonly string $idempotencyKey,
        private readonly array $input
    ) {
        ValueValidator::contributionKey($this->taskKey, 'taskKey');
        ValueValidator::safeToken($this->contractVersion, 'contractVersion', 64);
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::safeToken($this->idempotencyKey, 'idempotencyKey', 128);
        ValueValidator::jsonMap($this->input, 'input', 1048576);
    }

    public function taskKey(): string { return $this->taskKey; }
    public function contractVersion(): string { return $this->contractVersion; }
    public function actorUserId(): int { return $this->actorUserId; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    /** @return array<string, mixed> */
    public function input(): array { return $this->input; }
}
