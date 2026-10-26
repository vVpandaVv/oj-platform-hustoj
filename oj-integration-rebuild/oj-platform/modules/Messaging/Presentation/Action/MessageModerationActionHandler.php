<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessageReportService;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class MessageModerationActionHandler implements ActionHandler
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly MessageReportService $reports,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        $action = $request->bodyString('action');
        try {
            $actor = $this->guard->actor($action, $request, 'oj_manage_messaging');
            $userId = $actor->userId();
            $redirect = $this->execute($action, $userId, $request);

            return $this->responses->redirect(
                $redirect,
                new UiMessage(
                    'messaging.moderation_completed',
                    MessageTone::Success,
                    '쪽지 운영 처리 완료',
                    $this->successMessage($action)
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/messages',
                new UiMessage(
                    'messaging.moderation_failed',
                    MessageTone::Danger,
                    '쪽지 운영 처리 실패',
                    ($error instanceof DomainException || $error instanceof InvalidArgumentException)
                        ? $error->getMessage()
                        : '요청을 처리하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                ),
                $userId
            );
        }
    }

    private function execute(string $action, int $userId, HttpRequest $request): string
    {
        if ($action === 'oj_message_admin_hide') {
            $messageId = $request->bodyInt('message_id');
            $message = $this->messaging->find($messageId);
            if ($message === null) {
                throw new DomainException('쪽지를 찾을 수 없습니다.');
            }

            $this->messaging->setHidden($messageId, $request->bodyBool('hidden'), $userId, $request->bodyString('reason'));

            return '/oj-console/messages/thread/' . $message->threadKey();
        }

        if ($action === 'oj_message_report_resolve') {
            $this->reports->resolve(
                $userId,
                $request->bodyInt('report_id'),
                $request->bodyString('resolution'),
                $request->bodyString('note')
            );

            return '/oj-console/messages/reports';
        }

        throw new DomainException('등록되지 않은 작업입니다.');
    }

    private function successMessage(string $action): string
    {
        return match ($action) {
            'oj_message_admin_hide' => '쪽지 노출 상태를 변경했습니다.',
            'oj_message_report_resolve' => '신고를 처리했습니다.',
            default => '요청을 처리했습니다.',
        };
    }
}
