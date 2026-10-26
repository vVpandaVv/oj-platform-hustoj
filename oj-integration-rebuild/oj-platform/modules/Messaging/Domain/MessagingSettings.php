<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Domain;

use InvalidArgumentException;

final class MessagingSettings
{
    public function __construct(
        private readonly MessagingScope $scope,
        private readonly int $sendRateLimit,
        private readonly int $maxMessageLength
    ) {
        if ($this->sendRateLimit < 1 || $this->sendRateLimit > 30) {
            throw new InvalidArgumentException('쪽지 발송 제한은 1~30건이어야 합니다.');
        }

        if ($this->maxMessageLength < 100 || $this->maxMessageLength > 5000) {
            throw new InvalidArgumentException('쪽지 최대 길이는 100~5000자여야 합니다.');
        }
    }

    public static function defaults(): self
    {
        return new self(MessagingScope::Open, 10, 1000);
    }

    public function scope(): MessagingScope
    {
        return $this->scope;
    }

    public function sendRateLimit(): int
    {
        return $this->sendRateLimit;
    }

    public function maxMessageLength(): int
    {
        return $this->maxMessageLength;
    }
}
