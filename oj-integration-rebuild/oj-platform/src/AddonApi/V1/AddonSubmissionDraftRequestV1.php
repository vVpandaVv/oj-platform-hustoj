<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonSubmissionDraftRequestV1
{
    public function __construct(private readonly int $actorUserId, private readonly int $problemId,
        private readonly string $contextType, private readonly int $contextId, private readonly string $language,
        private readonly string $source)
    {
        if ($actorUserId < 1 || $problemId < 1 || $contextId < 1) { throw new InvalidArgumentException('Invalid submission draft identity.'); }
        ValueValidator::oneOf($contextType, ['class', 'group'], 'contextType');
        ValueValidator::oneOf($language, ['python', 'c', 'cpp', 'java'], 'language');
        ValueValidator::text($source, 'source', 65536, false, true);
        if (str_contains($source, "\0")) { throw new InvalidArgumentException('Invalid submission draft source.'); }
    }
    public function actorUserId(): int { return $this->actorUserId; }
    public function problemId(): int { return $this->problemId; }
    public function contextType(): string { return $this->contextType; }
    public function contextId(): int { return $this->contextId; }
    public function language(): string { return $this->language; }
    public function source(): string { return $this->source; }
}
