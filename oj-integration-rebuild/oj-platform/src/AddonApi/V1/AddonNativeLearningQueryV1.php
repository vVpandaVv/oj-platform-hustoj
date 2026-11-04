<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
final class AddonNativeLearningQueryV1
{
    public function __construct(private readonly int $actorUserId, private readonly string $surface, private readonly int $contextId = 0, private readonly int $beforeId = 0, private readonly int $limit = 20)
    {
        ValueValidator::positiveUserId($actorUserId, 'actorUserId');
        ValueValidator::oneOf($surface, ['problems.public', 'problems.teacher', 'class.student', 'class.teacher'], 'surface');
        if ($beforeId < 0 || $limit < 1 || $limit > 50 || (str_starts_with($surface, 'class.') ? $contextId < 1 : $contextId !== 0)) { throw new \InvalidArgumentException('Invalid native catalog scope.'); }
    }
    public function actorUserId(): int { return $this->actorUserId; }
    public function surface(): string { return $this->surface; }
    public function contextId(): int { return $this->contextId; }
    public function beforeId(): int { return $this->beforeId; }
    public function limit(): int { return $this->limit; }
}
