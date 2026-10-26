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
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingSettingsService;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageDraft;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class MessagingActionHandler implements ActionHandler
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly MessageReportService $reports,
        private readonly MessagingSettingsService $settings,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors,
        private readonly ?\CodingPanda\OjPlatform\Modules\Auth\Contract\FixedWindowAttemptLimiter $sendLimiter = null
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $action = $request->bodyString('action');
        $userId = 0;
        try {
            $actor = $this->guard->authenticatedActor($action, $request);
            $userId = $actor->userId();
            $redirect = $this->execute($action, $userId, $request);

            return $this->responses->redirect(
                $redirect,
                new UiMessage(
                    'messaging.action_completed',
                    MessageTone::Success,
                    '쪽지 처리 완료',
                    $this->successMessage($action)
                ),
                $userId
            );
        } catch (Throwable $error) {
            if (! $error instanceof DomainException && ! $error instanceof InvalidArgumentException) {
                $this->errors->report($error);
            }

            return $this->responses->redirect(
                '/messages',
                new UiMessage(
                    'messaging.action_failed',
                    MessageTone::Danger,
                    '쪽지 처리 실패',
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
        if ($action === 'oj_message_send') {
            $recipientUserId = $request->bodyInt('recipient_user_id');
            $this->messaging->send($userId, $recipientUserId, $request->bodyString('body'), function () use ($userId): void {
                $limit = $this->settings->current()->sendRateLimit();
                if ($this->sendLimiter !== null) {
                    if (! $this->sendLimiter->consumeFixedWindow('messaging_send', (string) $userId, $limit, 600)) {
                        throw new DomainException(sprintf('쪽지 발송 한도(10분에 %d건)에 도달했습니다. 잠시 후 다시 시도해 주세요.', $limit));
                    }
                } else {
                    $this->guard->throttle('messaging_send', (string) $userId, $limit, 600);
                }
            });

            return '/messages/thread/' . MessageDraft::threadKey($userId, $recipientUserId);
        }

        if ($action === 'oj_message_delete') {
            $this->messaging->softDelete($userId, $request->bodyInt('message_id'));

            return '/messages';
        }

        if ($action === 'oj_message_report') {
            $this->guard->throttle('messaging_report', (string) $userId, 5, 600);
            $this->reports->report(
                $userId,
                $request->bodyInt('message_id'),
                $request->bodyString('reason')
            );

            return '/messages';
        }

        throw new DomainException('등록되지 않은 작업입니다.');
    }

    private function successMessage(string $action): string
    {
        return match ($action) {
            'oj_message_send' => '쪽지를 보냈습니다.',
            'oj_message_delete' => '쪽지를 삭제했습니다.',
            'oj_message_report' => '신고를 접수했습니다.',
            default => '요청을 처리했습니다.',
        };
    }
}
