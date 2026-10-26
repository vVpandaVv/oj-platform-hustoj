<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\HomeSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockSetting;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveHomeSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly HomeSettingsService $settings,
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
                'oj_save_home_settings',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $body = $request->body();
            $items = is_array($body['blocks'] ?? null) ? $body['blocks'] : [];
            $blocks = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $item['enabled'] = isset($item['enabled']);
                $blocks[] = HomeBlockSetting::fromArray($item);
            }
            $this->settings->save(
                $blocks,
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/settings/home',
                new UiMessage(
                    'home_settings.saved',
                    MessageTone::Success,
                    '홈 구성 저장 완료',
                    '다음 홈 요청부터 새 순서와 표시 수를 적용합니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/home',
                new UiMessage(
                    'home_settings.failed',
                    MessageTone::Danger,
                    '홈 구성 저장 실패',
                    $error instanceof \DomainException || $error instanceof \InvalidArgumentException
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $userId
            );
        }
    }
}
