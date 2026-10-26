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
use CodingPanda\OjPlatform\Modules\Board\Application\CommentService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityFeaturePolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\ModerationService;
use CodingPanda\OjPlatform\Modules\Board\Application\NoticeService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class NoticeActionHandler implements ActionHandler
{
    public function __construct(
        private readonly NoticeService $notices,
        private readonly CommentService $comments,
        private readonly ModerationService $moderation,
        private readonly CommunityFeaturePolicy $features,
        private readonly BoardSettingsService $settings,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $action = $request->bodyString('action');
        $userId = 0;
        try {
            $actor = $action === 'oj_notice_add_comment'
                ? $this->guard->authenticatedActor($action, $request)
                : $this->guard->actor(
                    $action,
                    $request,
                    'oj_manage_notices'
                );
            $userId = $actor->userId();
            $path = $this->execute($action, $userId, $request);

            return $this->responses->redirect(
                $path,
                new UiMessage(
                    'notice.action_completed',
                    MessageTone::Success,
                    '공지 작업 완료',
                    '변경한 내용을 반영했습니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                $this->fallback($action, $request),
                new UiMessage(
                    'notice.action_failed',
                    MessageTone::Danger,
                    '공지 작업 실패',
                    ($error instanceof DomainException || $error instanceof InvalidArgumentException)
                        ? $error->getMessage()
                        : '요청을 처리하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                ),
                $userId
            );
        }
    }

    private function execute(
        string $action,
        int $userId,
        HttpRequest $request
    ): string {
        $this->features->requireNotices();
        $postId = $request->bodyInt('post_id');
        if (in_array($action, ['oj_notice_create', 'oj_notice_create_console'], true)) {
            $postId = $this->notices->create(
                $userId,
                $request->bodyString('title'),
                $request->bodyString('body'),
                $request->bodyString('status', 'publish'),
                $request->bodyString('publish_at'),
                $request->bodyString('ends_at'),
                $request->bodyBool('important'),
                $request->bodyBool('pinned'),
                array_key_exists('comments_allowed_present', $request->body())
                    ? $request->bodyBool('comments_allowed')
                    : null,
                CommunityActionInput::files($request)
            );

            return $action === 'oj_notice_create'
                ? '/notices/' . $postId
                : '/oj-console/notices/' . $postId . '/edit';
        }
        if ($action === 'oj_notice_update') {
            $this->notices->update(
                $userId,
                $postId,
                $request->bodyString('title'),
                $request->bodyString('body'),
                $request->bodyString('status', 'publish'),
                $request->bodyString('publish_at'),
                $request->bodyString('ends_at'),
                $request->bodyBool('important'),
                $request->bodyBool('pinned'),
                $request->bodyBool('comments_allowed'),
                CommunityActionInput::files($request),
                $request->bodyIntList('remove_attachment_ids')
            );
        } elseif ($action === 'oj_notice_add_comment') {
            $this->guard->throttle(
                'notice_comment_create',
                (string) $userId,
                $this->settings->current()->commentRateLimit(),
                600
            );
            $commentId = $this->comments->add(
                $userId,
                $postId,
                'notice',
                $request->bodyString('body')
            );

            return '/notices/' . $postId . '#comment-' . $commentId;
        } elseif ($action === 'oj_notice_trash') {
            $this->moderation->trashPost(
                $userId,
                $postId,
                'notice',
                $request->bodyString('reason')
            );

            return '/oj-console/notices/trash';
        } elseif ($action === 'oj_notice_restore') {
            $this->moderation->restorePost($userId, $postId, 'notice');
        } elseif ($action === 'oj_notice_delete_comment') {
            $this->moderation->setCommentTrashed(
                $userId,
                $request->bodyInt('comment_id'),
                'notice',
                $request->bodyBool('trashed'),
                $request->bodyString('reason')
            );
        } else {
            throw new DomainException('지원하지 않는 공지 작업입니다.');
        }

        return '/oj-console/notices/' . $postId . '/edit';
    }

    private function fallback(string $action, HttpRequest $request): string
    {
        $postId = $request->bodyInt('post_id');
        if ($action === 'oj_notice_add_comment' || $action === 'oj_notice_create') {
            return $postId > 0 ? '/notices/' . $postId : '/notices';
        }

        return $postId > 0
            ? '/oj-console/notices/' . $postId . '/edit'
            : '/oj-console/notices';
    }
}
