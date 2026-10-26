<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Domain;

use InvalidArgumentException;

final class MessageDraft
{
    private readonly string $body;

    public function __construct(
        private readonly int $senderUserId,
        private readonly int $recipientUserId,
        string $body,
        int $maxLength = 1000
    ) {
        if ($this->senderUserId < 1 || $this->recipientUserId < 1) {
            throw new InvalidArgumentException('사용자 번호가 올바르지 않습니다.');
        }

        if ($this->senderUserId === $this->recipientUserId) {
            throw new InvalidArgumentException('본인에게는 쪽지를 보낼 수 없습니다.');
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            throw new InvalidArgumentException('쪽지 내용을 입력해야 합니다.');
        }

        if (mb_strlen($trimmed) > $maxLength) {
            throw new InvalidArgumentException(
                '쪽지 내용은 ' . $maxLength . '자 이하여야 합니다.'
            );
        }

        $this->body = $trimmed;
    }

    public static function threadKey(int $userIdA, int $userIdB): string
    {
        return min($userIdA, $userIdB) . '_' . max($userIdA, $userIdB);
    }

    public function senderUserId(): int
    {
        return $this->senderUserId;
    }

    public function recipientUserId(): int
    {
        return $this->recipientUserId;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function threadKeyValue(): string
    {
        return self::threadKey($this->senderUserId, $this->recipientUserId);
    }
}
