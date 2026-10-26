<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonSubmissionDraftResultV1
{
    public function __construct(private readonly bool $successful, private readonly array $payload, private readonly ?AddonApiProblemV1 $problem)
    {
        if ($successful === ($problem instanceof AddonApiProblemV1) || (! $successful && $payload !== [])) { throw new InvalidArgumentException('Invalid submission draft result.'); }
        if (! $successful) { return; }
        $keys = array_keys($payload); sort($keys);
        if ($keys !== ['expires_at', 'language', 'url'] || ! is_string($payload['language']) || ! is_int($payload['expires_at']) || $payload['expires_at'] < 1
            || ! is_string($payload['url']) || strlen($payload['url']) > 1000
            || preg_match('~^/submit/[1-9][0-9]*\?(?:class|group)_id=[1-9][0-9]*&draft=draft%3Av1%3A[a-f0-9-]{36}$~D', $payload['url']) !== 1) {
            throw new InvalidArgumentException('Invalid submission draft receipt.');
        }
        ValueValidator::oneOf($payload['language'], ['python', 'c', 'cpp', 'java'], 'language');
    }
    public function isSuccessful(): bool { return $this->successful; }
    public function payload(): array { return $this->payload; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
