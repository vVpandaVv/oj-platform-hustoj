<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Board\Application\AttachmentService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityContextRegistry;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityPostView;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityQueryService;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;
use CodingPanda\OjPlatform\Modules\Board\Presentation\CommunityHtml;

final class BoardConsolePageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly CommunityQueryService $queries,
        private readonly CommunityContextRegistry $contexts,
        private readonly AttachmentService $attachments,
        private readonly CommunityHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $postId = (int) ($route->parameters()['board_thread_id'] ?? 0);

        return match ($route->route()->key()) {
            'console.board.index' => $this->listing($userId, false, $request),
            'console.board.trash' => $this->listing($userId, true, $request),
            'console.board.new' => $this->editor($userId, null, $request),
            'console.board.thread' => $this->detail($userId, $postId, $request),
            'console.board.edit' => $this->editor($userId, $postId, $request),
            'console.board.comments' => $this->comments($userId, $postId, $request),
            'console.board.trash_thread' => $this->trashConfirm(
                $userId,
                $postId,
                $request
            ),
            default => $this->notFound($request),
        };
    }

    private function listing(
        int $userId,
        bool $trash,
        HttpRequest $request
    ): HttpResponse {
        $keyword = trim($request->queryString('search'));
        $orphaned = $request->queryString('connection') === 'orphaned';
        $listing = $this->queries->listing(
            $userId,
            new CommunityPostSearch(
                'board',
                $keyword,
                null,
                null,
                'all',
                [$trash ? 'trash' : 'publish'],
                $orphaned
            ),
            max(1, (int) $request->queryString('page', '1')),
            50,
            true
        );
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">커뮤니티 관리</p>'
            . '<h1>' . ($trash ? '삭제된 게시글' : '게시판 관리') . '</h1>'
            . '<p>접근 가능한 문맥의 게시글과 운영 상태를 확인합니다.</p></div>'
            . '<div class="ojp-command-group"><a class="ojp-button" href="'
            . ($trash ? '/oj-console/board' : '/oj-console/board/trash')
            . '">' . ($trash ? '게시글 관리' : '휴지통') . '</a>'
            . '<a class="ojp-button ojp-button--primary" href="/oj-console/board/new">게시글 작성</a>'
            . '</div></header><form class="ojp-filter-bar" method="get">'
            . '<input class="ojp-input" type="search" name="search" placeholder="게시글 검색" value="'
            . $this->html->escape($keyword) . '"><select class="ojp-select" name="connection">'
            . '<option value="">모든 연결 상태</option><option value="orphaned"'
            . ($orphaned ? ' selected' : '') . '>연결 대상 없음</option></select>'
            . '<button class="ojp-button" type="submit">검색</button></form>'
            . '<section class="ojp-section">'
            . $this->html->listing(
                $listing,
                '/oj-console/board/thread/',
                $trash ? '휴지통이 비어 있습니다.' : '관리할 게시글이 없습니다.'
            )
            . $this->html->pagination(
                $listing,
                $trash ? '/oj-console/board/trash' : '/oj-console/board',
                [
                    'search' => $keyword,
                    'connection' => $orphaned ? 'orphaned' : '',
                ]
            ) . '</section>';

        return $this->pages->respond(
            $trash ? '삭제된 게시글' : '게시판 관리',
            $content,
            $trash ? 'community.board_trash' : 'community.board',
            $request
        );
    }

    private function detail(
        int $userId,
        int $postId,
        HttpRequest $request
    ): HttpResponse {
        $view = $this->managedView($userId, $postId);
        if ($view === null) {
            return $this->notFound($request);
        }
        $post = $view->post();
        $controls = $post->isTrashed()
            ? '<div class="ojp-command-bar">' . $this->html->moderationForm(
                'oj_board_restore_thread',
                $postId,
                '게시글 복원',
                [],
                '',
                '복원 사유'
            ) . '</div>'
            : '<div class="ojp-command-bar">'
                . $this->html->moderationForm(
                    'oj_board_toggle_thread_hidden',
                    $postId,
                    $post->isHidden() ? '숨김 해제' : '숨김',
                    ['hidden' => $post->isHidden() ? '0' : '1'],
                    '',
                    $post->isHidden() ? '숨김 해제 사유' : '숨김 사유'
                )
                . $this->html->moderationForm(
                    'oj_board_toggle_thread_pinned',
                    $postId,
                    $post->isPinned() ? '고정 해제' : '상단 고정',
                    ['pinned' => $post->isPinned() ? '0' : '1'],
                    '',
                    $post->isPinned() ? '고정 해제 사유' : '고정 사유'
                )
                . '<a class="ojp-button" href="/oj-console/board/thread/' . $postId
                . '/comments">댓글 운영</a><a class="ojp-button ojp-button--danger" href="/oj-console/board/thread/'
                . $postId . '/trash">휴지통 이동</a></div>';
        $content = $controls
            . $this->html->detail(
                $view,
                '/oj-console/board/thread/' . $postId . '/edit'
            );

        return $this->pages->respond(
            $post->title(),
            $content,
            'community.board',
            $request
        );
    }

    private function editor(
        int $userId,
        ?int $postId,
        HttpRequest $request
    ): HttpResponse {
        $post = null;
        $attachments = [];
        $action = 'oj_board_add_thread';
        $title = '게시글 작성';
        $selected = 'global:0';
        if ($postId !== null) {
            $view = $this->managedView($userId, $postId);
            if ($view === null) {
                return $this->notFound($request);
            }
            $post = $view->post();
            if ($post->isTrashed()) {
                return $this->detail($userId, $postId, $request);
            }
            $attachments = $this->attachments->postAttachments($postId);
            $action = 'oj_board_update_thread';
            $title = '게시글 수정';
            $selected = $post->context()->key();
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">게시판 관리</p>'
            . '<h1>' . $this->html->escape($title) . '</h1>'
            . '<p>연결 대상은 이름으로 선택하며 저장할 때 접근 권한을 다시 검사합니다.</p>'
            . '</div></header><section class="ojp-section">'
            . $this->html->postForm(
                $action,
                $post === null ? '게시글 등록' : '변경 저장',
                $post,
                $this->html->contextSelect(
                    $this->contexts->choices($userId, 500),
                    $selected
                ),
                5,
                $attachments,
                false,
                false
            ) . '</section>';

        return $this->pages->respond(
            $title,
            $content,
            $postId === null ? 'community.board_new' : 'community.board',
            $request
        );
    }

    private function comments(
        int $userId,
        int $postId,
        HttpRequest $request
    ): HttpResponse {
        $view = $this->managedView($userId, $postId);
        if ($view === null) {
            return $this->notFound($request);
        }
        $rows = '';
        foreach ($view->comments() as $commentView) {
            $comment = $commentView->comment();
            $rows .= '<tr><td><strong>' . $this->html->escape(
                $commentView->authorName()
            ) . '</strong><p>' . $this->html->escape(
                $this->excerpt($comment->body())
            ) . '</p></td><td>' . $this->html->escape($comment->createdAt())
            . '</td><td><div class="ojp-command-group">'
            . $this->html->moderationForm(
                'oj_board_toggle_comment_hidden',
                $postId,
                $comment->isHidden() ? '숨김 해제' : '숨김',
                [
                    'comment_id' => $comment->id(),
                    'hidden' => $comment->isHidden() ? '0' : '1',
                ],
                '',
                $comment->isHidden() ? '숨김 해제 사유' : '숨김 사유'
            )
            . $this->html->moderationForm(
                'oj_board_delete_comment',
                $postId,
                $comment->isTrashed() ? '복원' : '휴지통',
                [
                    'comment_id' => $comment->id(),
                    'trashed' => $comment->isTrashed() ? '0' : '1',
                ],
                $comment->isTrashed() ? '' : 'danger',
                $comment->isTrashed() ? '복원 사유' : '휴지통 이동 사유'
            ) . '</div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3">운영할 댓글이 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">게시판 관리</p>'
            . '<h1>댓글 운영</h1><p>' . $this->html->escape(
                $view->post()->title()
            ) . '</p></div><a class="ojp-button" href="/oj-console/board/thread/'
            . $postId . '">게시글로 이동</a></header>'
            . '<section class="ojp-section"><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>댓글</th><th>작성 시각</th><th>운영</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';

        return $this->pages->respond(
            '댓글 운영',
            $content,
            $view->post()->isTrashed() ? 'community.board_trash' : 'community.board',
            $request
        );
    }

    private function trashConfirm(
        int $userId,
        int $postId,
        HttpRequest $request
    ): HttpResponse {
        $view = $this->managedView($userId, $postId);
        if ($view === null) {
            return $this->notFound($request);
        }
        if ($view->post()->isTrashed()) {
            return $this->detail($userId, $postId, $request);
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">게시판 관리</p>'
            . '<h1>게시글을 휴지통으로 이동할까요?</h1><p>'
            . $this->html->escape($view->post()->title())
            . '</p></div></header><section class="ojp-section"><div class="ojp-notice ojp-notice--warning">'
            . '<strong>댓글과 첨부파일은 함께 보존됩니다.</strong><p>필요하면 휴지통에서 다시 복원할 수 있습니다.</p></div>'
            . $this->html->moderationForm(
                'oj_board_trash_thread',
                $postId,
                '휴지통으로 이동',
                [],
                'danger',
                '휴지통 이동 사유'
            ) . '</section>';

        return $this->pages->respond(
            '게시글 휴지통 이동',
            $content,
            'community.board',
            $request
        );
    }

    private function managedView(
        int $userId,
        int $postId
    ): ?CommunityPostView {
        $view = $this->queries->detail($userId, $postId, true);

        return $view !== null && $view->post()->kind() === 'board'
            ? $view
            : null;
    }

    private function excerpt(string $body): string
    {
        $plain = trim(strip_tags($body));

        return function_exists('mb_strimwidth')
            ? mb_strimwidth($plain, 0, 120, '...', 'UTF-8')
            : substr($plain, 0, 120);
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->respond(
            '게시글 없음',
            '<div class="ojp-empty-state"><h1>게시글을 열 수 없습니다.</h1>'
                . '<p>연결 공간 운영 권한과 글 상태를 확인해 주세요.</p>'
                . '<a class="ojp-button" href="/oj-console/board">게시판 관리</a></div>',
            'community.board',
            $request,
            404
        );
    }
}
