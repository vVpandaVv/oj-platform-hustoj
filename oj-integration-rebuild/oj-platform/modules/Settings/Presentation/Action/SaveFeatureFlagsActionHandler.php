<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveFeatureFlagsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly FeatureService $features,
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
                'oj_save_feature_flags',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $body = $request->body();
            $raw = is_array($body['features'] ?? null) ? $body['features'] : [];
            $flags = [];
            foreach ($raw as $featureKey => $enabled) {
                if (is_string($featureKey)) {
                    $flags[$featureKey] = in_array(
                        is_scalar($enabled) ? (string) $enabled : '',
                        ['1', 'true', 'yes', 'on'],
                        true
                    );
                }
            }
            $this->features->save(
                $flags,
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/settings/features',
                new UiMessage(
                    'features.saved',
                    MessageTone::Success,
                    '기능 설정 저장 완료',
                    '의존 관계를 통과한 기능 구성을 적용했습니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/features',
                new UiMessage(
                    'features.failed',
                    MessageTone::Danger,
                    '기능 설정 저장 실패',
                    $error instanceof \DomainException
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $userId
            );
        }
    }
}
