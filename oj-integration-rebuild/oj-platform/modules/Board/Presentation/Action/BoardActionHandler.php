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
use CodingPanda\OjPlatform\Modules\Board\Application\ThreadService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class BoardActionHandler implements ActionHandler
{
    private const PUBLIC_ACTIONS = [
        'oj_board_add_comment',
        'oj_board_create_thread',
        'oj_board_update_thread_public',
    ];

    public function __construct(
        private readonly ThreadService $threads,
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
            $actor = in_array($action, self::PUBLIC_ACTIONS, true)
                ? $this->guard->authenticatedActor($action, $request)
                : $this->guard->actor(
                    $action,
                    $request,
                    'oj_manage_board'
                );
            $userId = $actor->userId();
            $path = $this->execute($action, $userId, $request);

            return $this->responses->redirect(
                $path,
                new UiMessage(
                    'board.action_completed',
                    MessageTone::Success,
                    '게시판 작업 완료',
                    '변경한 내용을 반영했습니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                $this->fallback($action, $request),
                new UiMessage(
                    'board.action_failed',
                    MessageTone::Danger,
                    '게시판 작업 실패',
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
        $this->features->requireBoard();
        $postId = $request->bodyInt('post_id');
        if ($action === 'oj_board_create_thread' || $action === 'oj_board_add_thread') {
            $this->guard->throttle(
                'board_thread_create',
                (string) $userId,
                $this->settings->current()->postRateLimit(),
                600
            );
            [$type, $targetId] = CommunityActionInput::context(
                $request->bodyString('context_key', 'global:0')
            );
            $postId = $this->threads->create(
                $userId,
                $request->bodyString('title'),
                $request->bodyString('body'),
                $type,
                $targetId,
                CommunityActionInput::files($request),
                $request->bodyBool('secret')
            );

            return $action === 'oj_board_add_thread'
                ? '/oj-console/board/thread/' . $postId
                : '/board/thread/' . $postId;
        }
        if ($action === 'oj_board_update_thread_public') {
            $this->threads->update(
                $userId,
                $postId,
                $request->bodyString('title'),
                $request->bodyString('body'),
                CommunityActionInput::files($request),
                $request->bodyIntList('remove_attachment_ids'),
                secret: $request->bodyBool('secret')
            );

            return '/board/thread/' . $postId;
        }
        if ($action === 'oj_board_update_thread') {
            [$type, $targetId] = CommunityActionInput::context(
                $request->bodyString('context_key')
            );
            $this->threads->update(
                $userId,
                $postId,
                $request->bodyString('title'),
                $request->bodyString('body'),
                CommunityActionInput::files($request),
                $request->bodyIntList('remove_attachment_ids'),
                true,
                $type,
                $targetId
            );

            return '/oj-console/board/thread/' . $postId;
        }
        if ($action === 'oj_board_add_comment') {
            $this->guard->throttle(
                'board_comment_create',
                (string) $userId,
                $this->settings->current()->commentRateLimit(),
                600
            );
            $commentId = $this->comments->add(
                $userId,
                $postId,
                'board',
                $request->bodyString('body'),
                CommunityActionInput::files($request, 3)
            );

            return '/board/thread/' . $postId . '#comment-' . $commentId;
        }
        if ($action === 'oj_board_toggle_thread_hidden') {
            $this->moderation->setThreadHidden(
                $userId,
                $postId,
                $request->bodyBool('hidden'),
                $request->bodyString('reason')
            );
        } elseif ($action === 'oj_board_toggle_thread_pinned') {
            $this->moderation->setThreadPinned(
                $userId,
                $postId,
                $request->bodyBool('pinned')
            );
        } elseif ($action === 'oj_board_trash_thread') {
            $this->moderation->trashPost(
                $userId,
                $postId,
                'board',
                $request->bodyString('reason')
            );

            return '/oj-console/board/trash';
        } elseif ($action === 'oj_board_restore_thread') {
            $this->moderation->restorePost($userId, $postId, 'board');
        } elseif ($action === 'oj_board_toggle_comment_hidden') {
            $this->moderation->setCommentHidden(
                $userId,
                $request->bodyInt('comment_id'),
                'board',
                $request->bodyBool('hidden'),
                $request->bodyString('reason')
            );
        } elseif ($action === 'oj_board_delete_comment') {
            $this->moderation->setCommentTrashed(
                $userId,
                $request->bodyInt('comment_id'),
                'board',
                $request->bodyBool('trashed'),
                $request->bodyString('reason')
            );
        } else {
            throw new DomainException('지원하지 않는 게시판 작업입니다.');
        }

        return '/oj-console/board/thread/' . $postId;
    }

    private function fallback(string $action, HttpRequest $request): string
    {
        $postId = $request->bodyInt('post_id');
        if (in_array($action, self::PUBLIC_ACTIONS, true)) {
            return $postId > 0 ? '/board/thread/' . $postId : '/board';
        }

        return $postId > 0
            ? '/oj-console/board/thread/' . $postId
            : '/oj-console/board';
    }
}
