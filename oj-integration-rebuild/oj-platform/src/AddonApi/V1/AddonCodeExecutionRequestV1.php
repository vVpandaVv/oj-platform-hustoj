<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use InvalidArgumentException;

final class AddonCodeExecutionRequestV1
{
    public function __construct(private readonly int $actorUserId, private readonly string $language,
        private readonly string $source, private readonly string $input, private readonly string $idempotencyKey)
    {
        if ($actorUserId < 1) { throw new InvalidArgumentException('Invalid execution actor.'); }
        Internal\ValueValidator::oneOf($language, ['python', 'c', 'cpp', 'java'], 'language');
        Internal\ValueValidator::text($source, 'source', 24000, false, true);
        Internal\ValueValidator::text($input, 'input', 16000, true, true);
        Internal\ValueValidator::safeToken($idempotencyKey, 'idempotencyKey', 160);
        if (str_contains($source, "\0") || str_contains($input, "\0")) {
            throw new InvalidArgumentException('Execution does not support NUL bytes.');
        }
    }
    public function actorUserId(): int { return $this->actorUserId; }
    public function language(): string { return $this->language; }
    public function source(): string { return $this->source; }
    public function input(): string { return $this->input; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
}
