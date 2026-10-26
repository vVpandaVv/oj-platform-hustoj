<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataSettingsService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveProblemTestDataSettingsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_problem_test_data_settings';

    public function __construct(
        private readonly ProblemTestDataSettingsService $settings,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->actor(
                self::ACTION,
                $request,
                'oj_manage_system'
            );
            $actorUserId = $actor->userId();
            $revision = $request->bodyInt('expected_revision');
            $section = $request->bodyString('settings_section');
            if ($section === 'root') {
                $this->settings->saveRoot(
                    $request->bodyString('root'),
                    $revision
                );
            } elseif ($section === 'spj') {
                $this->settings->saveSpj(
                    $request->bodyString('c_compiler'),
                    $request->bodyString('cpp_compiler'),
                    $request->bodyString('limit_executable'),
                    $request->bodyString('sandbox_executable'),
                    $revision
                );
            } else {
                throw new \DomainException('저장할 테스트 환경 항목이 올바르지 않습니다.');
            }

            return $this->response(
                $actorUserId,
                MessageTone::Success,
                '테스트 환경 저장 완료',
                '새 테스트 데이터 작업부터 변경한 환경을 사용합니다.'
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->response(
                $actorUserId,
                MessageTone::Danger,
                '테스트 환경 저장 실패',
                $error instanceof \DomainException
                    || $error instanceof \InvalidArgumentException
                        ? $error->getMessage()
                        : '설정이 변경되었거나 서버 경로를 확인할 수 없습니다.'
            );
        }
    }

    private function response(
        int $actorUserId,
        MessageTone $tone,
        string $title,
        string $body
    ): HttpResponse {
        return $this->responses->redirect(
            '/oj-console/settings/test-data',
            new UiMessage(
                'problems.test_data_settings',
                $tone,
                $title,
                $body
            ),
            $actorUserId
        );
    }
}
