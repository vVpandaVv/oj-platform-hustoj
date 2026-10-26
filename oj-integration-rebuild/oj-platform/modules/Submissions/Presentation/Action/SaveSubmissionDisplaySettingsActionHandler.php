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

final class SaveSubmissionDisplaySettingsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_submission_display_settings';

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
            $this->settings->saveDisplay(
                $request->bodyBool('show_time'),
                $request->bodyBool('show_memory'),
                $request->bodyBool('show_language'),
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/submissions/settings',
                new UiMessage(
                    'submissions.display_saved',
                    MessageTone::Success,
                    '목록 표시 설정 저장 완료',
                    '사용자와 관리자 제출 목록에 같은 설정을 적용합니다.'
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/submissions/settings',
                new UiMessage(
                    'submissions.display_failed',
                    MessageTone::Danger,
                    '목록 표시 설정 저장 실패',
                    '설정이 다른 곳에서 변경되었습니다. 화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}
