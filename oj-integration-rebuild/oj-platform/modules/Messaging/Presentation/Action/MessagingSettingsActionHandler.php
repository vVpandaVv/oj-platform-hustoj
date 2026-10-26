<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingSettingsService;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessagingScope;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class MessagingSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly MessagingSettingsService $settings,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        try {
            $actor = $this->guard->actor(
                'oj_save_messaging_settings',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $scope = MessagingScope::tryFrom($request->bodyString('scope'));
            if ($scope === null) {
                throw new InvalidArgumentException('발송 범위 값이 올바르지 않습니다.');
            }

            $this->settings->save(
                $scope,
                $request->bodyInt('send_rate_limit', 10),
                $request->bodyInt('max_message_length', 1000),
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/messages/settings',
                new UiMessage(
                    'messaging.settings_saved',
                    MessageTone::Success,
                    '쪽지 설정 저장 완료',
                    '새 요청부터 변경한 발송 범위와 제한값을 적용합니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/messages/settings',
                new UiMessage(
                    'messaging.settings_failed',
                    MessageTone::Danger,
                    '쪽지 설정 저장 실패',
                    ($error instanceof DomainException || $error instanceof InvalidArgumentException)
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $userId
            );
        }
    }
}
