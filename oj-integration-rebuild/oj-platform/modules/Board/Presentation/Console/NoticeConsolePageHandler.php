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
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityAccessPolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityPostView;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityQueryService;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;
use CodingPanda\OjPlatform\Modules\Board\Presentation\CommunityHtml;

final class NoticeConsolePageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly CommunityQueryService $queries,
        private readonly CommunityAccessPolicy $access,
        private readonly AttachmentService $attachments,
        private readonly CommunityHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $noticeId = (int) ($route->parameters()['notice_id'] ?? 0);

        return match ($route->route()->key()) {
            'console.notices.index' => $this->listing($userId, false, $request),
            'console.notices.trash' => $this->listing($userId, true, $request),
            'console.notices.new' => $this->editor($userId, null, $request),
            'console.notices.edit' => $this->editor($userId, $noticeId, $request),
            'console.notices.comments' => $this->comments(
                $userId,
                $noticeId,
                $request
            ),
            'console.notices.trash_notice' => $this->trashConfirm(
                $userId,
                $noticeId,
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
        $status = $request->queryString('status', 'all');
        $statuses = $trash
            ? ['trash']
            : match ($status) {
                'draft' => ['draft'],
                'future' => ['future'],
                'publish' => ['publish'],
                default => ['draft', 'future', 'publish'],
            };
        $listing = $this->queries->listing(
            $userId,
            new CommunityPostSearch(
                'notice',
                $keyword,
                null,
                null,
                'all',
                $statuses
            ),
            max(1, (int) $request->queryString('page', '1')),
            50,
            true
        );
        $rows = '';
        foreach ($listing->items() as $item) {
            $post = $item->post();
            $rows .= '<tr><td><a href="/oj-console/notices/' . $post->id()
                . '/edit"><strong>' . $this->html->escape($post->title())
                . '</strong></a></td><td>' . $this->status($post)
                . '</td><td>' . $this->html->escape(
                    $post->publishAt() ?? $post->createdAt()
                ) . '</td><td>' . $item->commentCount() . '개</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">'
                . ($trash ? '휴지통이 비어 있습니다.' : '조건에 맞는 공지가 없습니다.')
                . '</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">커뮤니티 관리</p>'
            . '<h1>' . ($trash ? '삭제된 공지' : '공지 관리') . '</h1>'
            . '<p>초안·예약·게시·종료 상태를 한 목록에서 관리합니다.</p></div>'
            . '<div class="ojp-command-group"><a class="ojp-button" href="'
            . ($trash ? '/oj-console/notices' : '/oj-console/notices/trash')
            . '">' . ($trash ? '공지 관리' : '휴지통') . '</a>'
            . '<a class="ojp-button ojp-button--primary" href="/oj-console/notices/new">공지 작성</a>'
            . '</div></header><form class="ojp-filter-bar" method="get">'
            . '<input class="ojp-input" type="search" name="search" placeholder="공지 검색" value="'
            . $this->html->escape($keyword) . '">'
            . ($trash ? '' : '<select class="ojp-select" name="status">'
                . $this->option('all', '모든 게시 상태', $status)
                . $this->option('draft', '초안', $status)
                . $this->option('future', '예약', $status)
                . $this->option('publish', '게시', $status)
                . '</select>')
            . '<button class="ojp-button" type="submit">검색</button></form>'
            . '<section class="ojp-section"><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>제목</th><th>상태</th><th>게시 시각</th><th>댓글</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . $this->html->pagination(
                $listing,
                $trash ? '/oj-console/notices/trash' : '/oj-console/notices',
                ['search' => $keyword, 'status' => $status]
            ) . '</section>';

        return $this->pages->respond(
            $trash ? '삭제된 공지' : '공지 관리',
            $content,
            $trash ? 'community.notice_trash' : 'community.notices',
            $request
        );
    }

    private function editor(
        int $userId,
        ?int $noticeId,
        HttpRequest $request
    ): HttpResponse {
        $post = null;
        $attachments = [];
        $action = 'oj_notice_create_console';
        $title = '공지 작성';
        if ($noticeId !== null) {
            $view = $this->managedView($userId, $noticeId);
            if ($view === null) {
                return $this->notFound($request);
            }
            $post = $view->post();
            if ($post->isTrashed()) {
                $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">공지 관리</p>'
                    . '<h1>삭제된 공지</h1><p>내용을 확인한 뒤 복원할 수 있습니다.</p>'
                    . '</div></header><div class="ojp-command-bar">'
                    . $this->html->moderationForm(
                        'oj_notice_restore',
                        $post->id(),
                        '공지 복원',
                        [],
                        '',
                        '복원 사유'
                    )
                    . '</div>' . $this->html->detail($view, '');

                return $this->pages->respond(
                    '삭제된 공지',
                    $content,
                    'community.notice_trash',
                    $request
                );
            }
            $attachments = $this->attachments->postAttachments($noticeId);
            $action = 'oj_notice_update';
            $title = '공지 수정';
        }
        $controls = '';
        if ($post !== null) {
            $controls = '<div class="ojp-command-bar"><a class="ojp-button" href="/oj-console/notices/'
                . $post->id() . '/comments">댓글 운영</a>'
                . ($post->isTrashed()
                    ? $this->html->moderationForm(
                        'oj_notice_restore',
                        $post->id(),
                        '공지 복원',
                        [],
                        '',
                        '복원 사유'
                    )
                    : '<a class="ojp-button ojp-button--danger" href="/oj-console/notices/'
                        . $post->id() . '/trash">휴지통 이동</a>')
                . '</div>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">공지 관리</p>'
            . '<h1>' . $this->html->escape($title) . '</h1>'
            . '<p>게시 시작과 종료 시각은 사이트 시간 기준으로 적용됩니다.</p>'
            . '</div></header>' . $controls . '<section class="ojp-section">'
            . $this->html->postForm(
                $action,
                $post === null ? '공지 등록' : '변경 저장',
                $post,
                '',
                5,
                $attachments,
                true
            ) . '</section>';

        return $this->pages->respond(
            $title,
            $content,
            $noticeId === null ? 'community.notice_new' : 'community.notices',
            $request
        );
    }

    private function comments(
        int $userId,
        int $noticeId,
        HttpRequest $request
    ): HttpResponse {
        $view = $this->managedView($userId, $noticeId);
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
            . '</td><td>' . $this->html->moderationForm(
                'oj_notice_delete_comment',
                $noticeId,
                $comment->isTrashed() ? '복원' : '휴지통',
                [
                    'comment_id' => $comment->id(),
                    'trashed' => $comment->isTrashed() ? '0' : '1',
                ],
                $comment->isTrashed() ? '' : 'danger',
                $comment->isTrashed() ? '복원 사유' : '휴지통 이동 사유'
            ) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3">운영할 댓글이 없습니다.</td></tr>';
        }
        if ($view->post()->isTrashed()) {
            return $this->editor($userId, $noticeId, $request);
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">공지 관리</p>'
            . '<h1>공지 댓글 운영</h1><p>' . $this->html->escape(
                $view->post()->title()
            ) . '</p></div><a class="ojp-button" href="/oj-console/notices/'
            . $noticeId . '/edit">공지 수정</a></header>'
            . '<section class="ojp-section"><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>댓글</th><th>작성 시각</th><th>운영</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';

        return $this->pages->respond(
            '공지 댓글 운영',
            $content,
            $view->post()->isTrashed()
                ? 'community.notice_trash'
                : 'community.notices',
            $request
        );
    }

    private function trashConfirm(
        int $userId,
        int $noticeId,
        HttpRequest $request
    ): HttpResponse {
        $view = $this->managedView($userId, $noticeId);
        if ($view === null) {
            return $this->notFound($request);
        }
        if ($view->post()->isTrashed()) {
            return $this->editor($userId, $noticeId, $request);
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">공지 관리</p>'
            . '<h1>공지를 휴지통으로 이동할까요?</h1><p>'
            . $this->html->escape($view->post()->title())
            . '</p></div></header><section class="ojp-section">'
            . '<div class="ojp-notice ojp-notice--warning"><strong>홈과 공지 목록에서 즉시 제외됩니다.</strong>'
            . '<p>댓글과 첨부파일은 보존되며 휴지통에서 복원할 수 있습니다.</p></div>'
            . $this->html->moderationForm(
                'oj_notice_trash',
                $noticeId,
                '휴지통으로 이동',
                [],
                'danger',
                '휴지통 이동 사유'
            ) . '</section>';

        return $this->pages->respond(
            '공지 휴지통 이동',
            $content,
            'community.notices',
            $request
        );
    }

    private function managedView(
        int $userId,
        int $noticeId
    ): ?CommunityPostView {
        $view = $this->queries->detail($userId, $noticeId, true);

        return $view !== null && $view->post()->kind() === 'notice'
            ? $view
            : null;
    }

    private function status(CommunityPost $post): string
    {
        $status = $post->status();
        $ended = $status === 'publish' && ! $this->access->isCurrentNotice($post);
        $label = match (true) {
            $ended => '종료',
            $status === 'draft' => '초안',
            $status === 'future' => '예약',
            $status === 'trash' => '휴지통',
            default => '게시',
        };
        $tone = match (true) {
            $ended => 'neutral',
            $status === 'draft' => 'neutral',
            $status === 'future' => 'info',
            $status === 'trash' => 'danger',
            default => 'success',
        };

        return '<span class="ojp-status-badge ojp-status-badge--' . $tone
            . '">' . $label . '</span>';
    }

    private function option(string $value, string $label, string $selected): string
    {
        return '<option value="' . $value . '"'
            . ($value === $selected ? ' selected' : '') . '>'
            . $this->html->escape($label) . '</option>';
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
            '공지 없음',
            '<div class="ojp-empty-state"><h1>공지를 열 수 없습니다.</h1>'
                . '<p>공지 상태와 운영 권한을 확인해 주세요.</p>'
                . '<a class="ojp-button" href="/oj-console/notices">공지 관리</a></div>',
            'community.notices',
            $request,
            404
        );
    }
}
