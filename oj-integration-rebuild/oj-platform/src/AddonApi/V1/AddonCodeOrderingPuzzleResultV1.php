<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonCodeOrderingPuzzleResultV1
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly bool $successful,
        private readonly array $payload,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        if ($successful === ($problem instanceof AddonApiProblemV1) || (! $successful && $payload !== [])) {
            throw new InvalidArgumentException('Invalid code ordering puzzle result.');
        }
        if (! $successful) { return; }
        ValueValidator::jsonMap($payload, 'payload', 1048576);
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['blocks', 'language', 'problem_id', 'template_hash']
            || ! is_int($payload['problem_id']) || $payload['problem_id'] < 1
            || ! is_string($payload['language']) || ! is_string($payload['template_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['template_hash']) !== 1
            || ! is_array($payload['blocks']) || ! array_is_list($payload['blocks'])
            || count($payload['blocks']) < 2 || count($payload['blocks']) > 200) {
            throw new InvalidArgumentException('Invalid public code ordering puzzle.');
        }
        ValueValidator::oneOf($payload['language'], ['python', 'c', 'cpp', 'java'], 'language');
        $seen = [];
        $bytes = 0;
        foreach ($payload['blocks'] as $block) {
            if (! is_array($block) || count($block) !== 2
                || ! is_string($block['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $block['id']) !== 1
                || isset($seen[$block['id']]) || ! is_string($block['code'] ?? null)
                || trim($block['code']) === '') {
                throw new InvalidArgumentException('Invalid public code ordering block.');
            }
            ValueValidator::text($block['code'], 'code', 65537, false, true);
            $seen[$block['id']] = true;
            $bytes += strlen($block['code']);
        }
        if ($bytes > 65537) {
            throw new InvalidArgumentException('Code ordering source is too large.');
        }
    }

    public function isSuccessful(): bool { return $this->successful; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
