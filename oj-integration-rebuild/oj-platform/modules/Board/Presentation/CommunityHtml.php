<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\Board\Application\BoardSettingsService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityListing;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityPostView;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityAttachment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Ui\Editor\RichTextEditor;

final class CommunityHtml
{
    public function __construct(
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly BoardSettingsService $settings
    ) {
    }

    public function listing(
        CommunityListing $listing,
        string $detailBase,
        string $emptyMessage
    ): string {
        if ($listing->items() === []) {
            return '<div class="ojp-empty-state"><p>'
                . $this->escape($emptyMessage) . '</p></div>';
        }
        $rows = '';
        foreach ($listing->items() as $item) {
            $post = $item->post();
            $badges = ($post->isPinned()
                ? '<span class="ojp-status-badge ojp-status-badge--info">고정</span>'
                : '')
                . ($post->isImportant()
                    ? '<span class="ojp-status-badge ojp-status-badge--warning">중요</span>'
                    : '')
                . ($post->isHidden()
                    ? '<span class="ojp-status-badge ojp-status-badge--danger">숨김</span>'
                    : '')
                . ($post->isSecret()
                    ? '<span class="ojp-status-badge ojp-status-badge--neutral">🔒 비밀글</span>'
                    : '')
                . ($item->isOrphaned()
                    ? '<span class="ojp-status-badge ojp-status-badge--danger">연결 대상 없음</span>'
                    : '');
            $rows .= '<tr><td><div class="ojp-table-title">' . $badges
                . '<a href="' . $this->escape($detailBase . $post->id()) . '">'
                . $this->escape($post->title()) . '</a></div>'
                . '<span class="ojp-table-meta">' . $this->escape(
                    $post->context()->label()
                ) . '</span></td><td>' . $this->authorLink(
                    $post->authorUserId(),
                    $item->authorName()
                )
                . '</td><td>' . $this->escape($post->updatedAt())
                . '</td><td><span class="ojp-status-badge '
                . ($item->isAnswered()
                    ? 'ojp-status-badge--success'
                    : 'ojp-status-badge--neutral')
                . '">' . ($item->isAnswered() ? '답변 있음' : '답변 대기')
                . ' · ' . $item->commentCount() . '</span></td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>제목</th><th>작성자</th><th>최근 변경</th>'
            . '<th>답변</th></tr></thead><tbody>' . $rows
            . '</tbody></table></div>';
    }

    public function pagination(
        CommunityListing $listing,
        string $basePath,
        array $query = []
    ): string {
        if ($listing->pages() <= 1) {
            return '';
        }
        $links = '';
        for ($page = 1; $page <= $listing->pages(); $page++) {
            $parameters = [...$query, 'page' => $page];
            $links .= '<a class="ojp-pagination__link'
                . ($page === $listing->page() ? ' is-active' : '')
                . '" href="' . $this->escape(
                    $basePath . '?' . http_build_query($parameters)
                ) . '">' . $page . '</a>';
        }

        return '<nav class="ojp-pagination ojp-pagination--pages" aria-label="페이지 이동">'
            . $links . '</nav>';
    }

    /**
     * @param list<CommunityContextAccess> $choices
     */
    public function contextSelect(
        array $choices,
        string $selectedKey = 'global:0',
        bool $includeAll = false
    ): string {
        $options = $includeAll
            ? '<option value="">전체 연결 공간</option>'
            : '';
        foreach ($choices as $choice) {
            $context = $choice->context();
            $key = $context->key();
            $options .= '<option value="' . $this->escape($key) . '"'
                . ($key === $selectedKey ? ' selected' : '') . '>'
                . $this->escape($context->label()) . '</option>';
        }

        return '<select class="ojp-select" name="context_key">' . $options
            . '</select>';
    }

    public function problemSearchForm(string $actionPath): string
    {
        return '<form class="ojp-inline-form" method="get" action="'
            . $this->escape($actionPath) . '">'
            . '<input type="hidden" name="context" value="problem">'
            . '<label class="ojp-sr-only" for="board-problem-search">문제 번호로 찾기</label>'
            . '<input class="ojp-input" type="number" min="1" id="board-problem-search" name="target" placeholder="문제 번호">'
            . '<button class="ojp-button" type="submit">문제로 이동</button></form>';
    }

    public function postForm(
        string $action,
        string $submitLabel,
        ?CommunityPost $post,
        string $contextControl,
        int $attachmentSlots,
        array $attachments = [],
        bool $notice = false,
        bool $allowSecret = true
    ): string {
        $title = $post?->title() ?? '';
        $body = $post?->body() ?? '';
        $postId = $post?->id() ?? 0;
        $noticeFields = $notice
            ? $this->noticeFields($post)
            : '';
        $attachmentSlots = min(
            $attachmentSlots,
            $this->settings->current()->maxPostFiles()
        );

        return '<form class="ojp-form ojp-community-editor" method="post" enctype="multipart/form-data" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields($action)
            . ($postId > 0
                ? '<input type="hidden" name="post_id" value="' . $postId . '">'
                : '')
            . '<div class="ojp-field"><label for="community-title">제목</label>'
            . '<input class="ojp-input" id="community-title" name="title" maxlength="120" required value="'
            . $this->escape($title) . '"></div>'
            . ($contextControl === ''
                ? ''
                : '<div class="ojp-field"><label>연결 공간</label>'
                    . $contextControl . '</div>')
            . (new RichTextEditor())->render('body', '본문', $body, 12, true)
            . (! $notice && $allowSecret
                ? '<label class="ojp-toggle-row"><input type="checkbox" name="secret" value="1"'
                    . ($post?->isSecret() ? ' checked' : '')
                    . '><span>비밀글로 등록 (작성자와 운영자만 볼 수 있습니다)</span></label>'
                : '')
            . $noticeFields
            . $this->existingAttachments($attachments)
            . $this->attachmentInputs($attachmentSlots)
            . '<div class="ojp-command-group"><button class="ojp-button ojp-button--primary" type="submit">'
            . $this->escape($submitLabel) . '</button></div></form>';
    }

    public function detail(CommunityPostView $view, string $editUrl): string
    {
        $post = $view->post();
        $actions = $view->canEdit() && $editUrl !== ''
            ? '<a class="ojp-button" href="' . $this->escape($editUrl)
                . '">수정</a>'
            : '';
        $body = $view->bodyVisible()
            ? '<div class="ojp-rich-text">' . $this->safeBody($post->body()) . '</div>'
                . $this->attachments($view->attachments())
            : ($post->isSecret() && ! $post->isHidden()
                ? '<div class="ojp-notice ojp-notice--warning"><strong>비밀글입니다.</strong>'
                    . '<p>작성자와 운영자만 본문과 첨부파일을 볼 수 있습니다.</p></div>'
                : '<div class="ojp-notice ojp-notice--warning"><strong>운영 처리된 글입니다.</strong>'
                    . '<p>작성자에게는 상태만 표시되며 본문과 첨부파일은 공개하지 않습니다.</p></div>');

        return '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . $this->escape($post->context()->label()) . '</p><h1>'
            . $this->escape($post->title()) . '</h1><p>'
            . $this->authorLink($post->authorUserId(), $view->authorName()) . ' · '
            . $this->escape($post->createdAt()) . '</p></div>'
            . '<div class="ojp-command-group">' . $actions . '</div></header>'
            . '<section class="ojp-content-section">' . $body . '</section>'
            . $this->comments($view);
    }

    public function comments(CommunityPostView $view): string
    {
        $items = '';
        foreach ($view->comments() as $commentView) {
            $comment = $commentView->comment();
            $state = $comment->isTrashed()
                ? '<span class="ojp-status-badge ojp-status-badge--danger">휴지통</span>'
                : ($comment->isHidden()
                    ? '<span class="ojp-status-badge ojp-status-badge--warning">숨김</span>'
                    : '');
            $items .= '<article class="ojp-community-comment" id="comment-'
                . $comment->id() . '"><header><strong>'
                . $this->authorLink($comment->authorUserId(), $commentView->authorName())
                . '</strong><span>'
                . $this->escape($comment->createdAt()) . '</span>' . $state
                . '</header><div class="ojp-rich-text">'
                . $this->safeBody($comment->body()) . '</div>'
                . $this->attachments($commentView->attachments()) . '</article>';
        }
        if ($items === '') {
            $items = '<div class="ojp-empty-state"><p>등록된 댓글이 없습니다.</p></div>';
        }
        $form = $view->canComment()
            ? $this->commentForm(
                $view->post()->kind() === 'board'
                    ? 'oj_board_add_comment'
                    : 'oj_notice_add_comment',
                $view->post()->id(),
                $view->post()->kind() === 'board'
                    ? $this->settings->current()->maxCommentFiles()
                    : 0
            )
            : '';

        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>댓글</h2></div></div><div class="ojp-community-comments">'
            . $items . '</div>' . $form . '</section>';
    }

    public function moderationForm(
        string $action,
        int $postId,
        string $label,
        array $fields = [],
        string $tone = '',
        string $reasonPlaceholder = '운영 처리 사유'
    ): string {
        $hidden = '<input type="hidden" name="post_id" value="' . $postId . '">';
        foreach ($fields as $name => $value) {
            $hidden .= '<input type="hidden" name="' . $this->escape((string) $name)
                . '" value="' . $this->escape((string) $value) . '">';
        }

        return '<form class="ojp-inline-form" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields($action) . $hidden
            . '<label class="ojp-field"><span class="ojp-sr-only">'
            . $this->escape($reasonPlaceholder) . '</span>'
            . '<input class="ojp-input" type="text" name="reason" minlength="3"'
            . ' maxlength="500" required placeholder="'
            . $this->escape($reasonPlaceholder) . '"></label>'
            . '<button class="ojp-button'
            . ($tone === 'danger' ? ' ojp-button--danger' : '')
            . '" type="submit">' . $this->escape($label) . '</button></form>';
    }

    private function commentForm(
        string $action,
        int $postId,
        int $attachmentSlots
    ): string {
        return '<form class="ojp-form ojp-community-comment-form" method="post" enctype="multipart/form-data" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields($action)
            . '<input type="hidden" name="post_id" value="' . $postId . '">'
            . '<div class="ojp-field"><label for="community-comment">댓글 작성</label>'
            . '<textarea class="ojp-textarea" id="community-comment" name="body" maxlength="5000" required></textarea></div>'
            . $this->attachmentInputs($attachmentSlots)
            . '<button class="ojp-button ojp-button--primary" type="submit">댓글 등록</button></form>';
    }

    private function noticeFields(?CommunityPost $post): string
    {
        $status = $post?->status() ?? 'publish';
        $publishAt = $this->dateInput($post?->publishAt());
        $endsAt = $this->dateInput($post?->endsAt());
        $commentsAllowed = $post === null
            ? $this->settings->current()->noticeCommentsDefault()
            : $post->commentsAllowed();

        return '<input type="hidden" name="comments_allowed_present" value="1">'
            . '<div class="ojp-form-grid"><div class="ojp-field"><label>게시 상태</label>'
            . '<select class="ojp-select" name="status">'
            . $this->option('draft', '초안', $status)
            . $this->option('future', '예약', $status)
            . $this->option('publish', '게시', $status)
            . '</select></div><div class="ojp-field"><label>게시 시작</label>'
            . '<input class="ojp-input" type="datetime-local" name="publish_at" value="'
            . $this->escape($publishAt) . '"></div><div class="ojp-field">'
            . '<label>게시 종료</label><input class="ojp-input" type="datetime-local" name="ends_at" value="'
            . $this->escape($endsAt) . '"></div></div>'
            . '<div class="ojp-check-grid"><label><input type="checkbox" name="important" value="1"'
            . ($post?->isImportant() ? ' checked' : '') . '> 중요 공지</label>'
            . '<label><input type="checkbox" name="pinned" value="1"'
            . ($post?->isPinned() ? ' checked' : '') . '> 상단 고정</label>'
            . '<label><input type="checkbox" name="comments_allowed" value="1"'
            . ($commentsAllowed ? ' checked' : '') . '> 댓글 허용</label></div>';
    }

    private function attachmentInputs(int $count): string
    {
        if ($count < 1 || ! $this->settings->current()->attachments()) {
            return '';
        }
        $inputs = '';
        for ($index = 1; $index <= $count; $index++) {
            $inputs .= '<label class="ojp-file-input"><span>파일 ' . $index
                . '</span><input type="file" name="attachment_' . $index
                . '"></label>';
        }

        return '<fieldset class="ojp-fieldset"><legend>첨부파일</legend>'
            . '<div class="ojp-attachment-inputs">' . $inputs
            . '</div></fieldset>';
    }

    /**
     * @param list<CommunityAttachment> $attachments
     */
    private function existingAttachments(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }
        $items = '';
        foreach ($attachments as $attachment) {
            $items .= '<label class="ojp-attachment-existing"><input type="checkbox" name="remove_attachment_ids[]" value="'
                . $attachment->id() . '"><span>' . $this->escape(
                    $attachment->displayName()
                ) . ' 제거</span></label>';
        }

        return '<fieldset class="ojp-fieldset"><legend>기존 첨부파일</legend>'
            . $items . '</fieldset>';
    }

    /**
     * @param list<CommunityAttachment> $attachments
     */
    private function attachments(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }
        $items = '';
        $containsZip = false;
        foreach ($attachments as $attachment) {
            $containsZip = $containsZip || strtolower(pathinfo(
                $attachment->displayName(),
                PATHINFO_EXTENSION
            )) === 'zip';
            $items .= '<li><a href="/community/attachments/'
                . $attachment->id() . '">' . $this->escape(
                    $attachment->displayName()
                ) . '</a><span>' . number_format($attachment->size()) . ' bytes</span></li>';
        }
        $warning = $containsZip
            ? '<div class="ojp-notice ojp-notice--warning"><p>'
                . '압축 파일은 내부 파일의 안전 여부를 확인한 뒤 사용하세요.</p></div>'
            : '';

        return '<div class="ojp-community-attachments"><h3>첨부파일</h3>'
            . $warning . '<ul>' . $items . '</ul></div>';
    }

    private function authorLink(int $authorUserId, string $authorName): string
    {
        if ($authorUserId < 1) {
            return $this->escape($authorName);
        }

        return '<a class="ojp-row-link" href="/users/' . $authorUserId . '">'
            . $this->escape($authorName) . '</a>';
    }

    private function safeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        if (function_exists('wp_kses_post')) {
            $body = (string) wp_kses_post($body);

            return function_exists('wpautop') ? wpautop($body) : $body;
        }

        return nl2br(htmlspecialchars(
            strip_tags($body),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ));
    }

    private function option(string $value, string $label, string $selected): string
    {
        return '<option value="' . $value . '"'
            . ($value === $selected ? ' selected' : '') . '>'
            . $this->escape($label) . '</option>';
    }

    private function dateInput(?string $value): string
    {
        return $value === null ? '' : str_replace(' ', 'T', substr($value, 0, 16));
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
