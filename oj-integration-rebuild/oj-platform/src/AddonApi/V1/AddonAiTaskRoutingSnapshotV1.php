<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonAiTaskRoutingSnapshotV1
{
    public function __construct(
        private readonly bool $available,
        private readonly string $taskKey,
        private readonly string $providerKey,
        private readonly string $modelKey,
        private readonly string $profileFingerprint,
        private readonly string $routingRevision,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        ValueValidator::contributionKey($this->taskKey, 'taskKey');
        if ($this->available) {
            if ($this->problem !== null) {
                throw new InvalidArgumentException('사용 가능한 AI 경로에는 오류가 없어야 합니다.');
            }
            ValueValidator::safeToken($this->providerKey, 'providerKey', 100);
            ValueValidator::modelKey($this->modelKey, 'modelKey', 160);
            ValueValidator::safeToken($this->profileFingerprint, 'profileFingerprint', 160);
            ValueValidator::safeToken($this->routingRevision, 'routingRevision', 160);
        } elseif ($this->problem === null) {
            throw new InvalidArgumentException('사용할 수 없는 AI 경로에는 오류가 필요합니다.');
        } else {
            foreach ([
                'providerKey' => $this->providerKey,
                'modelKey' => $this->modelKey,
                'profileFingerprint' => $this->profileFingerprint,
                'routingRevision' => $this->routingRevision,
            ] as $field => $value) {
                ValueValidator::text($value, $field, 160, true, false);
            }
        }
    }

    public function isAvailable(): bool { return $this->available; }
    public function taskKey(): string { return $this->taskKey; }
    public function providerKey(): string { return $this->providerKey; }
    public function modelKey(): string { return $this->modelKey; }
    public function profileFingerprint(): string { return $this->profileFingerprint; }
    public function routingRevision(): string { return $this->routingRevision; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
