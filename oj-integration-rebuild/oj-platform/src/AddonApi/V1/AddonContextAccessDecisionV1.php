<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonContextAccessDecisionV1
{
    public function __construct(
        private readonly bool $allowed,
        private readonly string $code,
        private readonly ?AddonContextSnapshotV1 $snapshot,
        private readonly string $correlationId
    ) {
        if ($this->allowed && $this->snapshot === null) {
            throw new InvalidArgumentException('허용된 Context에는 snapshot이 필요합니다.');
        }
        if (! $this->allowed && $this->snapshot !== null) {
            throw new InvalidArgumentException('거부된 Context에는 snapshot을 제공할 수 없습니다.');
        }
        if ($this->allowed) {
            if ($this->code !== '') {
                ValueValidator::safeToken($this->code, 'code', 80);
            }
        } else {
            ValueValidator::errorCode($this->code);
        }
        ValueValidator::correlationId($this->correlationId);
    }

    public function isAllowed(): bool { return $this->allowed; }
    public function code(): string { return $this->code; }
    public function snapshot(): ?AddonContextSnapshotV1 { return $this->snapshot; }
    public function correlationId(): string { return $this->correlationId; }
}
