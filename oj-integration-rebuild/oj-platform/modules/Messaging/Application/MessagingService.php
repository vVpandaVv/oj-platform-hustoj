<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\Message;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageDraft;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Clock\SiteDateBoundaryConverter;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;
use DomainException;
use InvalidArgumentException;

final class MessagingService
{
    private readonly SiteDateBoundaryConverter $dateBoundaries;

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly UserProfileRepository $profiles,
        private readonly MessagingScopePolicy $scopePolicy,
        private readonly MessagingSettingsService $settings,
        Clock $siteClock = new SystemClock()
    ) {
        $this->dateBoundaries = new SiteDateBoundaryConverter($siteClock);
    }

    public function send(int $senderUserId, int $recipientUserId, string $body, ?callable $beforeSave = null): int
    {
        $recipientProfile = $this->profiles->find($recipientUserId);
        if ($recipientProfile === null || $recipientProfile->accessStatus() !== AccessStatus::Active) {
            throw new DomainException('쪽지를 받을 수 없는 사용자입니다.');
        }

        if (! $this->scopePolicy->canMessage($senderUserId, $recipientUserId)) {
            throw new DomainException('현재 설정에서는 이 사용자에게 쪽지를 보낼 수 없습니다.');
        }

        $draft = new MessageDraft(
            $senderUserId,
            $recipientUserId,
            $body,
            $this->settings->current()->maxMessageLength()
        );

        if ($beforeSave !== null) { $beforeSave(); }
        return $this->messages->save($draft);
    }

    /**
     * @return list<Message>
     */
    public function inbox(int $userId, int $limit, int $offset, bool $unreadOnly = false): array
    {
        if ($unreadOnly && $this->messages instanceof \CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageInboxStatus) {
            return $this->messages->unreadInboxThreads($userId, $limit, $offset);
        }
        return $this->messages->inboxThreads($userId, $limit, $offset);
    }

    public function canReceiveFrom(int $senderId, int $recipientId): bool
    {
        return $senderId !== $recipientId
            && $this->profiles->find($recipientId)?->accessStatus() === AccessStatus::Active
            && $this->scopePolicy->canMessage($senderId, $recipientId);
    }

    /** @param list<string> $keys @return list<string> */
    public function unreadThreadKeys(int $userId, array $keys): array
    {
        return $this->messages instanceof \CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageInboxStatus
            ? $this->messages->unreadThreadKeys($userId, $keys) : [];
    }

    /**
     * @return list<Message>
     */
    public function sent(int $userId, int $limit, int $offset): array
    {
        return $this->messages->sent($userId, $limit, $offset);
    }

    public function unreadCount(int $userId): int
    {
        return $this->messages->unreadCount($userId);
    }

    /**
     * @return list<Message>
     */
    public function thread(int $viewerUserId, string $threadKey, int $limit, int $offset): array
    {
        $messages = $this->messages->threadMessages($threadKey, $viewerUserId, $limit, $offset);
        $this->messages->markThreadRead($threadKey, $viewerUserId);

        return $messages;
    }

    public function softDelete(int $userId, int $messageId): void
    {
        $message = $this->messages->find($messageId);
        if ($message === null || ! $message->isParticipant($userId)) {
            throw new DomainException('삭제할 쪽지를 찾을 수 없습니다.');
        }

        if ($userId === $message->senderUserId()) {
            $this->messages->softDeleteForSender($messageId);

            return;
        }

        $this->messages->softDeleteForRecipient($messageId);
    }

    public function find(int $messageId): ?Message
    {
        return $this->messages->find($messageId);
    }

    /**
     * @return list<Message>
     */
    public function adminSearch(int $userId, string $fromDate, string $toDate, int $limit, int $offset): array
    {
        try {
            $from = $this->dateBoundaries->startUtc($fromDate) ?? '';
            $to = $this->dateBoundaries->endUtc($toDate) ?? '';
        } catch (InvalidArgumentException) {
            throw new DomainException('조회 기간은 올바른 날짜로 입력해 주세요.');
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new DomainException('조회 시작일은 종료일보다 늦을 수 없습니다.');
        }

        return $this->messages->adminSearch($userId, $from, $to, $limit, $offset);
    }

    /**
     * @return list<Message>
     */
    public function adminThread(string $threadKey, int $limit, int $offset): array
    {
        return $this->messages->adminThreadMessages($threadKey, $limit, $offset);
    }

    public function setHidden(int $messageId, bool $hidden, int $actorUserId, string $reason): void
    {
        $this->messages->setHidden($messageId, $hidden, $actorUserId, $reason);
    }
}
