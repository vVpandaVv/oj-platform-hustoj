<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

use InvalidArgumentException;

final class PermissionContext
{
    public function __construct(
        private readonly string $targetType,
        private readonly int $targetId,
        private readonly string $operation
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->targetType) !== 1) {
            throw new InvalidArgumentException('권한 대상 유형이 올바르지 않습니다.');
        }

        if ($this->targetId < 1) {
            throw new InvalidArgumentException('권한 대상 번호는 1 이상이어야 합니다.');
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->operation) !== 1) {
            throw new InvalidArgumentException('권한 작업 이름이 올바르지 않습니다.');
        }
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetId(): int
    {
        return $this->targetId;
    }

    public function operation(): string
    {
        return $this->operation;
    }
}
