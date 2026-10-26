<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Board\Application\AttachmentService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityContextRegistry;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityFeaturePolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityQueryService;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;
use CodingPanda\OjPlatform\Modules\Board\Presentation\CommunityHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class BoardPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly CommunityQueryService $queries,
        private readonly CommunityContextRegistry $contexts,
        private readonly AttachmentService $attachments,
        private readonly CommunityFeaturePolicy $features,
        private readonly CommunityHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;

        return match ($route->route()->key()) {
            'board.index' => $this->index($userId, $request),
            'board.new' => $this->editor($userId, null, $request),
            'board.thread' => $this->detail(
                $userId,
                (int) ($route->parameters()['board_thread_id'] ?? 0),
                $request
            ),
            'board.edit' => $this->editor(
                $userId,
                (int) ($route->parameters()['board_thread_id'] ?? 0),
                $request
            ),
            default => $this->pages->authenticated(
                '페이지 없음',
                '<div class="ojp-empty-state"><p>게시판 페이지를 찾을 수 없습니다.</p></div>',
                $request,
                404
            ),
        };
    }

    private function index(int $userId, HttpRequest $request): HttpResponse
    {
        [$contextType, $contextId, $contextKey] = $this->contextFilter($request);
        $answerState = $request->queryString('answer', 'all');
        if (! in_array($answerState, ['all', 'answered', 'unanswered'], true)) {
            $answerState = 'all';
        }
        $keyword = trim($request->queryString('search'));
        $listing = $this->queries->listing(
            $userId,
            new CommunityPostSearch(
                'board',
                $keyword,
                $contextType,
                $contextId,
                $answerState,
                ['publish']
            ),
            max(1, (int) $request->queryString('page', '1')),
            20
        );
        $contextChoices = [
            ...$this->contexts->choices($userId, 200, false, ['problem']),
            ...$this->contexts->recentProblemChoices($userId, 5),
        ];
        $canCreate = array_filter(
            $contextChoices,
            static fn ($choice): bool =>
                $choice->canPost() || $choice->canModerate()
        ) !== [];
        $filters = '<form class="ojp-filter-bar" method="get" action="/board">'
            . '<input class="ojp-input" type="search" name="search" placeholder="제목과 본문 검색" value="'
            . $this->html->escape($keyword) . '">'
            . $this->html->contextSelect(
                $contextChoices,
                $contextKey,
                true
            )
            . '<select class="ojp-select" name="answer">'
            . $this->option('all', '모든 답변 상태', $answerState)
            . $this->option('answered', '답변 있음', $answerState)
            . $this->option('unanswered', '답변 대기', $answerState)
            . '</select><button class="ojp-button" type="submit">검색</button></form>'
            . $this->html->problemSearchForm('/board');
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">커뮤니티</p>'
            . '<h1>게시판</h1><p>질문을 찾거나 접근 가능한 학습 공간에 새 글을 작성하세요.</p></div>'
            . '<div class="ojp-command-group">'
            . ($this->features->noticesEnabled()
                ? '<a class="ojp-button" href="/notices">공지</a>'
                : '')
            . ($canCreate
                ? '<a class="ojp-button ojp-button--primary" href="/board/new">글쓰기</a>'
                : '')
            . '</div></header>' . $filters
            . '<section class="ojp-section">'
            . $this->html->listing(
                $listing,
                '/board/thread/',
                '조건에 맞는 게시글이 없습니다.'
            )
            . $this->html->pagination($listing, '/board', [
                'search' => $keyword,
                'context_key' => $contextKey,
                'answer' => $answerState,
            ]) . '</section>';

        return $this->pages->authenticated('게시판', $content, $request);
    }

    private function detail(
        int $userId,
        int $postId,
        HttpRequest $request
    ): HttpResponse {
        $view = $this->queries->detail($userId, $postId);
        if ($view === null || $view->post()->kind() !== 'board') {
            return $this->notFound($request);
        }
        $content = '<nav class="ojp-breadcrumb"><a href="/board">게시판</a>'
            . '<span aria-hidden="true">/</span><span>게시글</span></nav>'
            . $this->html->detail($view, '/board/thread/' . $postId . '/edit');

        return $this->pages->authenticated(
            $view->post()->title(),
            $content,
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
        $action = 'oj_board_create_thread';
        $title = '게시글 작성';
        $contextControl = '';
        if ($postId !== null) {
            $view = $this->queries->detail($userId, $postId);
            if (
                $view === null
                || $view->post()->kind() !== 'board'
                || ! $view->canEdit()
            ) {
                return $this->notFound($request);
            }
            $post = $view->post();
            $attachments = $this->attachments->postAttachments($postId);
            $action = 'oj_board_update_thread_public';
            $title = '게시글 수정';
        } else {
            $entry = $this->entryContext($request);
            if ($entry === null) {
                return $this->notFound($request);
            }
            [$type, $target] = $entry;
            $choices = [
                ...$this->contexts->choices($userId, 200, true, ['problem']),
                ...$this->contexts->recentProblemChoices($userId, 5),
            ];
            $access = $this->contexts->resolve($type, $target, $userId);
            $explicitContext = trim($request->queryString('context')) !== ''
                || trim($request->queryString('target')) !== '';
            if (
                $access->isProtected()
                || $access->isOrphaned()
                || (! $access->canPost() && ! $access->canModerate())
            ) {
                if ($explicitContext || $choices === []) {
                    return $this->notFound($request);
                }
                $access = $choices[0];
            } elseif ($type === 'problem' && ! $this->hasContextKey($choices, $access->context()->key())) {
                $choices[] = $access;
            }
            $contextControl = $this->html->contextSelect(
                $choices,
                $access->context()->key()
            ) . $this->html->problemSearchForm('/board/new');
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">게시판</p>'
            . '<h1>' . $this->html->escape($title) . '</h1>'
            . '<p>제목과 본문을 간결하게 작성하고 필요한 파일만 첨부하세요.</p></div></header>'
            . '<section class="ojp-section">'
            . $this->html->postForm(
                $action,
                $post === null ? '게시글 등록' : '변경 저장',
                $post,
                $contextControl,
                5,
                $attachments
            ) . '</section>';

        return $this->pages->authenticated($title, $content, $request);
    }

    /**
     * @return array{?string, ?int, string}
     */
    private function contextFilter(HttpRequest $request): array
    {
        $key = trim($request->queryString('context_key'));
        if ($key === '') {
            $type = trim($request->queryString('context'));
            $target = (int) $request->queryString('target', '0');
            if (in_array($type, ['problem', 'class', 'contest', 'group'], true) && $target > 0) {
                return [$type, $target, $type . ':' . $target];
            }

            return [null, null, ''];
        }
        [$type, $target] = $this->parseContextKey($key);

        return [$type, $target, $key];
    }

    /**
     * @return array{string, int}|null
     */
    private function entryContext(HttpRequest $request): ?array
    {
        $type = trim($request->queryString('context', 'global'));
        $target = (int) $request->queryString('target', '0');
        if ($type === 'global') {
            return $target === 0 ? ['global', 0] : null;
        }
        if (
            ! in_array($type, ['problem', 'class', 'contest', 'group'], true)
            || $target < 1
        ) {
            return null;
        }

        return [$type, $target];
    }

    /**
     * @return array{?string, ?int}
     */
    private function parseContextKey(string $key): array
    {
        if (preg_match('/^(global|problem|class|contest|group):(\d+)$/', $key, $match) !== 1) {
            return [null, null];
        }
        $type = $match[1];
        $target = (int) $match[2];
        if (($type === 'global' && $target !== 0) || ($type !== 'global' && $target < 1)) {
            return [null, null];
        }

        return [$type, $target];
    }

    /**
     * @param list<CommunityContextAccess> $choices
     */
    private function hasContextKey(array $choices, string $key): bool
    {
        foreach ($choices as $choice) {
            if ($choice->context()->key() === $key) {
                return true;
            }
        }

        return false;
    }

    private function option(string $value, string $label, string $selected): string
    {
        return '<option value="' . $value . '"'
            . ($value === $selected ? ' selected' : '') . '>'
            . $this->html->escape($label) . '</option>';
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->authenticated(
            '게시글 없음',
            '<div class="ojp-empty-state"><h1>게시글을 열 수 없습니다.</h1>'
                . '<p>글이 없거나 현재 계정에서 볼 수 없는 학습 공간입니다.</p>'
                . '<a class="ojp-button" href="/board">게시판</a></div>',
            $request,
            404
        );
    }
}
