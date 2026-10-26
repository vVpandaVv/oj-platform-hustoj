<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Application;

use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageReportRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageReport;
use DomainException;

final class MessageReportService
{
    private const ALLOWED_ACTIONS = ['none', 'message_hidden'];

    public function __construct(
        private readonly MessageReportRepository $reports,
        private readonly MessageRepository $messages
    ) {
    }

    public function report(int $reporterUserId, int $messageId, string $reason): void
    {
        $message = $this->messages->find($messageId);
        if ($message === null || ! $message->isParticipant($reporterUserId)) {
            throw new DomainException('신고할 쪽지를 찾을 수 없습니다.');
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new DomainException('신고 사유를 입력해야 합니다.');
        }

        if ($this->reports->existsForReporter($messageId, $reporterUserId)) {
            throw new DomainException('이미 신고한 쪽지입니다.');
        }

        $this->reports->save($messageId, $reporterUserId, $trimmedReason);
    }

    /**
     * @return list<MessageReport>
     */
    public function listQueue(int $limit, int $offset): array
    {
        return $this->reports->pendingQueue($limit, $offset);
    }

    public function resolve(int $actorUserId, int $reportId, string $action, string $note): void
    {
        if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new DomainException('처리 방법이 올바르지 않습니다.');
        }

        $report = $this->reports->find($reportId);
        if ($report === null) {
            throw new DomainException('신고 내역을 찾을 수 없습니다.');
        }

        if (! $report->isPending()) {
            throw new DomainException('이미 처리된 신고입니다.');
        }

        if ($action === 'message_hidden') {
            $this->messages->setHidden(
                $report->messageId(),
                true,
                $actorUserId,
                trim($note) === '' ? '신고 처리로 숨김' : trim($note)
            );
        }

        $this->reports->resolve($reportId, $actorUserId, $action, trim($note));
    }
}
