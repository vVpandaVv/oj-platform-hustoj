<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

use InvalidArgumentException;

final class ContestIpChangeRequest
{
    public function __construct(
        private readonly int $id,
        private readonly int $contestId,
        private readonly int $userId,
        private readonly string $requestedIp,
        private readonly ?string $previousIp,
        private readonly string $reason,
        private readonly string $requestedAt
    ) {
        if (
            $this->id < 1
            || $this->contestId < 1
            || $this->userId < 1
            || filter_var($this->requestedIp, FILTER_VALIDATE_IP) === false
            || trim($this->reason) === ''
        ) {
            throw new InvalidArgumentException('IP 변경 요청 정보가 올바르지 않습니다.');
        }
    }

    public function id(): int { return $this->id; }
    public function contestId(): int { return $this->contestId; }
    public function userId(): int { return $this->userId; }
    public function requestedIp(): string { return $this->requestedIp; }
    public function previousIp(): ?string { return $this->previousIp; }
    public function reason(): string { return $this->reason; }
    public function requestedAt(): string { return $this->requestedAt; }
}
