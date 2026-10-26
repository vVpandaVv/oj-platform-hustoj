<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class ClearLastFatalActionHandler implements ActionHandler
{
    public function __construct(
        private readonly SettingsManager $settings,
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
                'oj_platform_clear_last_fatal',
                $request,
                'oj_manage_system'
            );
            $userId = $actor->userId();
            $this->settings->save(
                'last_fatal',
                null,
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/system-status',
                new UiMessage(
                    'system_status.last_fatal_cleared',
                    MessageTone::Success,
                    '마지막 오류 표시 지움',
                    '서버 로그는 보존했으며 화면의 안전한 요약만 지웠습니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/system-status',
                new UiMessage(
                    'system_status.last_fatal_clear_failed',
                    MessageTone::Danger,
                    '마지막 오류 표시를 지우지 못했습니다',
                    '화면을 새로 고친 뒤 다시 시도해 주세요.'
                ),
                $userId
            );
        }
    }
}
