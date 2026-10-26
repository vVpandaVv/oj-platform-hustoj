<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackReviewService;
use CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionAdminDetailPanel;

final class FeedbackReviewPageHandler implements RouteHandler, SubmissionAdminDetailPanel
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly FeedbackReviewService $reviews,
        private readonly WordPressUserGateway $users,
        private readonly AiHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }
        if (! $this->reviews->canReview($identity->userId())) {
            return $this->pages->respond('AI 피드백 기록', '<p>AI 피드백 관리 권한이 필요합니다.</p>', 'submissions.ai_feedback.records', $request, 403);
        }
        $user = trim($request->queryString('user'));
        $class = $this->positive($request->queryString('class_id'));
        $problem = $this->positive($request->queryString('problem_id'));
        $before = $this->positive($request->queryString('before')) ?? 0;
        foreach (['class_id', 'problem_id', 'before'] as $key) {
            if ($request->queryString($key) !== '' && $this->positive($request->queryString($key)) === null) {
                return $this->pages->respond('AI 피드백 기록', '<p>번호는 1 이상의 정수로 입력하세요.</p>', 'submissions.ai_feedback.records', $request, 400);
            }
        }
        $page = $this->reviews->page($identity->userId(), $identity->userLogin(), $user, $class, $problem, $before);
        $content = '<header class="ojp-page-header"><div><h1>AI 피드백 기록</h1><p>학습자에게 생성된 저장 피드백을 확인합니다.</p></div></header>'
            . '<form class="ojp-filter-bar" method="get" action="/oj-console/submissions/ai-feedback/records">'
            . '<label class="ojp-field"><span>사용자 아이디</span><input class="ojp-input" name="user" value="' . $this->html->escape($user) . '"></label>'
            . '<label class="ojp-field"><span>수업 번호</span><input class="ojp-input" type="number" min="1" name="class_id" value="' . ($class ?? '') . '"></label>'
            . '<label class="ojp-field"><span>문제 번호</span><input class="ojp-input" type="number" min="1" name="problem_id" value="' . ($problem ?? '') . '"></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">조회</button><a class="ojp-button" href="/oj-console/submissions/ai-feedback/records">초기화</a></form>'
            . '<div class="ojp-notice ojp-notice--info">열람 권한은 사용자 → 역할과 권한에서 AI 피드백 관리 및 제출 관리·상세·코드 열람 권한을 확인하세요. 담당 범위의 제출만 표시합니다.</div>';
        $rows = '';
        foreach ($page['rows'] as $entry) {
            $feedback = $entry['feedback'];
            $submission = $entry['submission'];
            $id = $feedback->submissionId();
            $owner = $this->users->findByLogin($submission->userLogin());
            $rows .= '<tr><td>' . $this->html->escape($owner?->displayName() ?? $submission->userLogin())
                . '<br><small>' . $this->html->escape($submission->userLogin()) . '</small></td>'
                . '<td>' . ($entry['class_id'] ?? '—') . '</td><td><a href="/problems/' . $submission->problemId() . '">' . $submission->problemId() . '</a></td>'
                . '<td>' . $this->html->escape($this->html->date($feedback->updatedAt())) . '</td><td><details><summary>피드백 보기</summary>'
                . $this->storedContent($entry) . '</details></td><td><a class="ojp-button ojp-button--small" href="/oj-console/submissions/' . $id . '">제출 #' . $id . '</a></td></tr>';
        }
        $content .= '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr><th>사용자</th><th>수업</th><th>문제</th><th>생성·갱신 시각</th><th>피드백</th><th>제출</th></tr></thead><tbody>'
            . ($rows === '' ? '<tr><td colspan="6">조건과 열람 권한에 맞는 피드백이 없습니다.</td></tr>' : $rows) . '</tbody></table></div>';
        $params = array_filter(['user' => $user, 'class_id' => $class, 'problem_id' => $problem], static fn ($value): bool => $value !== null && $value !== '');
        $content .= '<div class="ojp-page-actions"><a class="ojp-button" href="/oj-console/submissions/ai-feedback/records?' . $this->html->escape(http_build_query($params)) . '">처음</a>';
        if ($page['next'] > 0) {
            $content .= '<a class="ojp-button" href="/oj-console/submissions/ai-feedback/records?' . $this->html->escape(http_build_query($params + ['before' => $page['next']])) . '">다음</a>';
        }
        return $this->pages->respond('AI 피드백 기록', $content . '</div>', 'submissions.ai_feedback.records', $request);
    }

    public function renderAdmin(int $actorUserId, string $actorUserLogin, int $submissionId): string
    {
        $entry = $this->reviews->find($actorUserId, $actorUserLogin, $submissionId);
        if ($entry === null) {
            return '';
        }
        return '<section class="ojp-section"><h2>학습자 AI 피드백</h2>' . $this->storedContent($entry) . '</section>';
    }

    private function storedContent(array $entry): string
    {
        $feedback = $entry['feedback'];
        $submission = $entry['submission'];
        $notice = '<p class="ojp-field-help">저장된 생성 시점의 피드백 · ' . $this->html->escape($feedback->modelKey()) . '</p>';
        if ($feedback->resultCode() !== $submission->resultCode()) {
            $notice .= '<div class="ojp-notice ojp-notice--warning">생성 이후 채점 결과가 변경되었습니다.</div>';
        }
        return $notice . $this->html->feedback($feedback);
    }

    private function positive(string $value): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $number === false ? null : $number;
    }
}
