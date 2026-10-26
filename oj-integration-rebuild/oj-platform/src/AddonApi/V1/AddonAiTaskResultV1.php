<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonAiTaskResultV1
{
    /** @param array<string, mixed> $output */
    public function __construct(
        private readonly bool $successful,
        private readonly array $output,
        private readonly AddonAiTaskRoutingSnapshotV1 $routingSnapshot,
        private readonly string $correlationId,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        ValueValidator::jsonMap($this->output, 'output');
        ValueValidator::correlationId($this->correlationId);
        if (
            ($this->successful && $this->problem !== null)
            || (! $this->successful && $this->problem === null)
        ) {
            throw new InvalidArgumentException('Addon API AI 작업 결과가 올바르지 않습니다.');
        }
    }

    public function isSuccessful(): bool { return $this->successful; }
    /** @return array<string, mixed> */
    public function output(): array { return $this->output; }
    public function routingSnapshot(): AddonAiTaskRoutingSnapshotV1 { return $this->routingSnapshot; }
    public function correlationId(): string { return $this->correlationId; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
