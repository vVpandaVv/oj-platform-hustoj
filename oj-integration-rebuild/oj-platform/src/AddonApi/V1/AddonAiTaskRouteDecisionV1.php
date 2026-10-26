<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonAiTaskRouteDecisionV1
{
    public function __construct(
        private readonly bool $canRoute,
        private readonly string $code,
        private readonly string $routingRevision,
        private readonly string $correlationId
    ) {
        if ($this->canRoute && $this->code !== '') {
            throw new InvalidArgumentException('실행 가능한 AI 작업에는 오류 코드가 없어야 합니다.');
        }
        if (! $this->canRoute) {
            ValueValidator::errorCode($this->code);
        }
        ValueValidator::safeToken($this->routingRevision, 'routingRevision', 160);
        ValueValidator::correlationId($this->correlationId);
    }

    public function canRoute(): bool { return $this->canRoute; }
    public function code(): string { return $this->code; }
    public function routingRevision(): string { return $this->routingRevision; }
    public function correlationId(): string { return $this->correlationId; }
}
