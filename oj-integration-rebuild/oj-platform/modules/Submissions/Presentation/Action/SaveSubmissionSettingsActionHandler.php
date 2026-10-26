<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveSubmissionSettingsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_submission_settings';

    public function __construct(
        private readonly SubmissionSettingsService $settings,
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
                'oj_manage_submission_settings'
            );
            $actorUserId = $actor->userId();
            if ($request->bodyString('settings_section') === 'rules') {
                $this->settings->saveRules(
                    $request->bodyInt('max_source_kb', 60),
                    $request->bodyInt('cooldown_seconds', 3),
                    $request->bodyBool('allow_resubmit_after_accept'),
                    $request->bodyInt('expected_revision'),
                    $request->bodyBool('request_limit_enabled'),
                    $request->bodyInt('request_limit_count', 30),
                    $request->bodyInt('request_limit_seconds', 60),
                    $request->bodyBool('clipboard_settings_present') ? $request->bodyBool('allow_code_copy') : null,
                    $request->bodyBool('clipboard_settings_present') ? $request->bodyBool('allow_code_paste') : null
                );
            } else {
                $this->settings->saveLanguages(
                    $actorUserId,
                    $request->bodyIntList('enabled_language_ids'),
                    $request->bodyInt('page_size', 50),
                    $request->bodyInt('maximum_poll_seconds', 180),
                    $request->bodyInt('expected_revision')
                );
            }

            return $this->response(
                $actorUserId,
                MessageTone::Success,
                '제출 설정 저장 완료',
                '변경한 설정을 새 제출과 목록에 적용합니다.'
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->response(
                $actorUserId,
                MessageTone::Danger,
                '제출 설정 저장 실패',
                $error instanceof \DomainException
                    || $error instanceof \InvalidArgumentException
                        ? $error->getMessage()
                        : '설정이 다른 곳에서 변경되었습니다. 화면을 새로 고친 뒤 다시 저장해 주세요.'
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
            '/oj-console/submissions/settings',
            new UiMessage(
                'submissions.settings',
                $tone,
                $title,
                $body
            ),
            $actorUserId
        );
    }
}
