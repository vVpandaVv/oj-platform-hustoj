<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class ResetPublicMenuSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly PublicMenuSettingsService $settings,
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
                'oj_reset_public_menu_settings',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $this->settings->reset($request->bodyInt('expected_revision'));

            return $this->responses->redirect(
                '/oj-console/settings/menu',
                new UiMessage(
                    'public_menu.reset',
                    MessageTone::Success,
                    '기본 메뉴 복원 완료',
                    '확정된 기본 1차·2차 메뉴 구성을 적용했습니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/menu',
                new UiMessage(
                    'public_menu.reset_failed',
                    MessageTone::Danger,
                    '기본 메뉴 복원 실패',
                    '화면을 새로 고친 뒤 다시 시도해 주세요.'
                ),
                $userId
            );
        }
    }
}
