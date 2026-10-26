<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonLearningCheckContextQueryV1
{
    public function __construct(
        private readonly int $actorUserId,
        private readonly int $problemId,
        private readonly ?int $submissionId = null,
        private readonly string $contextType = 'general',
        private readonly ?int $contextId = null,
        private readonly string $projection = 'full'
    ) {
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::positiveUserId($this->problemId, 'problemId');
        if ($this->submissionId !== null) {
            ValueValidator::positiveUserId($this->submissionId, 'submissionId');
        }
        ValueValidator::oneOf($this->contextType, ['general', 'class', 'group', 'contest'], 'contextType');
        ValueValidator::oneOf($this->projection, ['full', 'problem', 'latest'], 'projection');
        if ($this->projection === 'problem' && $this->submissionId !== null) {
            throw new InvalidArgumentException('Problem-only checks cannot name a submission.');
        }
        if ($this->contextType === 'general') {
            if ($this->contextId !== null) {
                throw new InvalidArgumentException('General learning checks cannot name a context.');
            }
        } else {
            ValueValidator::positiveUserId($this->contextId ?? 0, 'contextId');
        }
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function problemId(): int { return $this->problemId; }
    public function submissionId(): ?int { return $this->submissionId; }
    public function contextType(): string { return $this->contextType; }
    public function contextId(): ?int { return $this->contextId; }
    public function projection(): string { return $this->projection; }
}
