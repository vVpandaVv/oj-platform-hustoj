<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonCodeCompletionAssemblyResultV1
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly bool $successful,
        private readonly array $payload,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        if ($successful === ($problem instanceof AddonApiProblemV1) || (! $successful && $payload !== [])) {
            throw new InvalidArgumentException('Invalid code completion assembly result.');
        }
        if (! $successful) { return; }
        // A 64KB starter plus up to thirty 64KB block answers can exceed 64KB.
        ValueValidator::jsonMap($payload, 'payload', 16777216);
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['language', 'problem_id', 'source', 'template_hash']
            || ! is_int($payload['problem_id']) || $payload['problem_id'] < 1
            || ! is_string($payload['language']) || ! is_string($payload['template_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['template_hash']) !== 1
            || ! is_string($payload['source']) || strlen($payload['source']) > 2097152
            || str_contains($payload['source'], "\0")) {
            throw new InvalidArgumentException('Invalid public code completion assembly.');
        }
        ValueValidator::oneOf($payload['language'], ['c', 'cpp', 'c++', 'java', 'python', 'python3', 'php', 'csharp', 'javascript', 'js', 'go'], 'language');
    }

    public function isSuccessful(): bool { return $this->successful; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
