<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonCodeCompletionTemplateResultV1
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly bool $successful,
        private readonly array $payload,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        if ($successful === ($problem instanceof AddonApiProblemV1) || (! $successful && $payload !== [])) {
            throw new InvalidArgumentException('Invalid code completion template result.');
        }
        if (! $successful) { return; }
        ValueValidator::jsonMap($payload, 'payload', 1048576);
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['blanks', 'language', 'problem_id', 'starter_code', 'template_hash']
            || ! is_int($payload['problem_id']) || $payload['problem_id'] < 1
            || ! is_string($payload['language']) || ! is_string($payload['template_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['template_hash']) !== 1
            || ! is_string($payload['starter_code']) || strlen($payload['starter_code']) > 65535
            || ! is_array($payload['blanks']) || ! array_is_list($payload['blanks'])
            || count($payload['blanks']) < 1 || count($payload['blanks']) > 30) {
            throw new InvalidArgumentException('Invalid public code completion template.');
        }
        ValueValidator::oneOf($payload['language'], ['c', 'cpp', 'c++', 'java', 'python', 'python3', 'php', 'csharp', 'javascript', 'js', 'go'], 'language');
        $seen = [];
        foreach ($payload['blanks'] as $blank) {
            if (! is_array($blank)) { throw new InvalidArgumentException('Invalid public code completion blank.'); }
            $blankKeys = array_keys($blank);
            sort($blankKeys);
            if ($blankKeys !== ['id', 'kind', 'label', 'max_bytes']
                || ! is_string($blank['id']) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $blank['id']) !== 1
                || isset($seen[$blank['id']]) || ! is_string($blank['label']) || trim($blank['label']) === ''
                || ! in_array($blank['kind'], ['inline', 'block'], true)
                || ! is_int($blank['max_bytes']) || $blank['max_bytes'] < 1 || $blank['max_bytes'] > 65536) {
                throw new InvalidArgumentException('Invalid public code completion blank.');
            }
            $seen[$blank['id']] = true;
        }
    }

    public function isSuccessful(): bool { return $this->successful; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
