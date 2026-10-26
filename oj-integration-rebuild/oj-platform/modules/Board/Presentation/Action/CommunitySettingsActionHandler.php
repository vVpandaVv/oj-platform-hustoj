<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Board\Application\BoardSettingsService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityFeaturePolicy;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class CommunitySettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly BoardSettingsService $settings,
        private readonly CommunityFeaturePolicy $features,
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
                'oj_save_board_settings',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $this->features->requireAny();
            $this->settings->save(
                CommunityActionInput::extensions(
                    $request->bodyString('allowed_extensions')
                ),
                $request->bodyBool('global_posting'),
                $request->bodyBool('board_comments'),
                $request->bodyBool('problem_questions'),
                $request->bodyBool('class_questions'),
                $request->bodyBool('contest_questions'),
                $request->bodyBool('group_questions'),
                $request->bodyBool('notice_comments_default'),
                $request->bodyBool('attachments'),
                $request->bodyBool('zip_allowed'),
                $request->bodyInt('max_file_mb', 10) * 1048576,
                $request->bodyInt('max_post_files', 5),
                $request->bodyInt('max_comment_files', 3),
                $request->bodyInt('expected_revision'),
                $request->bodyInt('post_rate_limit', 5),
                $request->bodyInt('comment_rate_limit', 20)
            );

            return $this->responses->redirect(
                '/oj-console/community/settings',
                new UiMessage(
                    'community.settings_saved',
                    MessageTone::Success,
                    '커뮤니티 설정 저장 완료',
                    '새 요청부터 변경한 게시·댓글·첨부 정책을 적용합니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/community/settings',
                new UiMessage(
                    'community.settings_failed',
                    MessageTone::Danger,
                    '커뮤니티 설정 저장 실패',
                    ($error instanceof DomainException || $error instanceof InvalidArgumentException)
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $userId
            );
        }
    }
}
