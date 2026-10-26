<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessPolicy;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemDetails;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class JobDetailsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly JobQuery $jobs,
        private readonly JobAccessPolicy $access,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $jobId = (int) ($route->parameters()['job_id'] ?? 0);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $job = $this->jobs->find($jobId);
        if ($job === null || ! $this->access->canView($userId, $job)) {
            return $this->pages->respond(
                '작업 상태',
                '<div class="ojp-empty-state"><h1>작업을 확인할 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/oj-console">관리 홈</a></div>',
                'dashboard',
                $request,
                404
            );
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">Jobs '
            . $job->id() . '</p><h1>' . $this->escape($this->jobLabel($job->jobType()))
            . '</h1><p>입력 원문과 비밀값을 제외한 실행 상태입니다.</p></div>'
            . '<div class="ojp-heading-actions"><span class="ojp-status-badge ojp-status-badge--'
            . $this->statusTone($job->status()) . '">'
            . $this->escape($this->statusLabel($job->status()))
            . '</span>' . $this->controls($userId, $job) . '</div></header>'
            . $this->summary($job)
            . $this->pollForm($job)
            . $this->items($job, $userId)
            . $this->steps($job);

        return $this->pages->respond(
            '작업 상태',
            $content,
            'dashboard',
            $request
        );
    }

    private function summary(JobDetails $job): string
    {
        return '<div class="ojp-metric-grid"><article><strong>'
            . $job->totalCount() . '</strong><span>전체</span></article>'
            . '<article><strong>' . $job->succeededCount()
            . '</strong><span>완료</span></article><article><strong>'
            . $job->failedCount() . '</strong><span>실패·확인 필요</span></article>'
            . '<article><strong>' . $job->cancelledCount()
            . '</strong><span>취소</span></article></div>'
            . '<dl class="ojp-summary-list"><div><dt>등록 시각</dt><dd>'
            . $this->escape($this->dates->format($job->createdAt()))
            . '</dd></div><div><dt>완료 시각</dt><dd>'
            . $this->escape($job->completedAt() === null
                ? '진행 중'
                : $this->dates->format($job->completedAt()))
            . '</dd></div></dl>';
    }

    private function pollForm(JobDetails $job): string
    {
        if ($job->isTerminal()) {
            return '';
        }

        return '<form class="ojp-job-poll" method="post" action="'
            . $this->escape($this->actionUrls->ajaxUrl())
            . '" data-ojp-job-poll data-job-status="' . $this->escape($job->status()) . '">'
            . $this->security->hiddenFields('oj_job_status')
            . '<input type="hidden" name="job_id" value="' . $job->id() . '">'
            . '<span aria-live="polite">작업 상태를 자동으로 갱신합니다.</span></form>';
    }

    private function controls(int $userId, JobDetails $job): string
    {
        $controls = '<a class="ojp-button" href="/oj-console/jobs/'
            . $job->id() . '/export">결과 내보내기</a>';
        if (! $this->access->canControl($userId, $job)) {
            return $controls;
        }
        if (! $job->isTerminal()) {
            $controls .= $this->actionForm(
                'oj_job_cancel',
                $job->id(),
                '작업 취소',
                'ojp-button ojp-button--danger'
            );
        } elseif ($job->failedCount() > 0) {
            $controls .= $this->actionForm(
                'oj_job_retry_failed',
                $job->id(),
                '실패 항목 재시도',
                'ojp-button ojp-button--primary'
            );
        }

        return $controls;
    }

    private function actionForm(
        string $actionKey,
        int $jobId,
        string $label,
        string $buttonClass
    ): string {
        return '<form method="post" action="'
            . $this->escape($this->actionUrls->actionUrl())
            . '">' . $this->security->hiddenFields($actionKey)
            . '<input type="hidden" name="job_id" value="' . $jobId . '">'
            . '<button type="submit" class="' . $this->escape($buttonClass)
            . '">' . $this->escape($label) . '</button></form>';
    }

    private function items(JobDetails $job, int $userId): string
    {
        $targets = [];
        if ($job->jobType() === 'problem_metadata_generate' && $this->access->canControl($userId, $job)) {
            foreach ($job->items() as $item) {
                $target = $this->metadataDraftTarget($item);
                if ($target !== null) {
                    $targets[] = $target;
                }
            }
        }
        $canApprove = $targets !== [];
        $rows = '';
        foreach ($job->items() as $item) {
            $target = $this->metadataDraftTarget($item);
            $rows .= '<tr>'
                . ($canApprove
                    ? '<td>' . ($target !== null
                        ? '<input type="checkbox" name="targets[]" value="'
                            . $this->escape($target) . '" data-ojp-select-item'
                            . ' form="ojp-metadata-bulk-approve-form" aria-label="'
                            . $item->id() . '번 선택">'
                        : '') . '</td>'
                    : '')
                . '<td>' . $item->id() . '</td><td>'
                . $this->escape($item->targetType() . ' ' . $item->targetKey())
                . '</td><td><span class="ojp-status-badge ojp-status-badge--'
                . $this->statusTone($item->status()) . '">'
                . $this->escape($this->statusLabel($item->status()))
                . '</span></td><td>' . $item->attemptCount() . ' / '
                . $item->maxAttempts() . '</td><td>'
                . $this->escape($item->resultMessage() ?? '대기 중')
                . (($item->safeResult()['retry_exhausted'] ?? false)
                    ? '<span class="ojp-table-subvalue">재시도 한도에 도달했습니다.</span>'
                    : '')
                . ($item->resultCode() === null ? '' : '<span class="ojp-table-subvalue">'
                    . $this->escape($item->resultCode()) . '</span>')
                . $this->topicValidationDetails($item)
                . '</td><td>' . $this->draftLink($item) . '</td></tr>';
        }

        return '<section class="ojp-section"><header class="ojp-section-heading"><div>'
            . '<h2>대상별 결과</h2></div></header>'
            . ($canApprove ? $this->metadataBulkBar($job->id(), $targets) : '')
            . ($canApprove ? '<div data-ojp-bulk-select>' : '')
            . '<div class="ojp-table-wrap">'
            . '<table class="ojp-table"><thead><tr>'
            . ($canApprove ? '<th><input type="checkbox" data-ojp-select-all aria-label="전체 선택"></th>' : '')
            . '<th>항목</th><th>대상</th>'
            . '<th>상태</th><th>시도</th><th>결과</th><th>초안</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . ($canApprove ? '</div>' : '')
            . '</section>';
    }

    private function topicValidationDetails(JobItemDetails $item): string
    {
        if ($item->resultCode() !== 'problem.ai_topics_invalid') {
            return '';
        }
        $issues = $item->safeResult()['topic_validation'] ?? null;
        if (! is_array($issues)) {
            return '';
        }
        $rows = '';
        foreach (array_slice($issues, 0, 3) as $issue) {
            if (! is_array($issue) || ! is_string($issue['key'] ?? null)
                || ! is_string($issue['label'] ?? null) || ! is_string($issue['reason'] ?? null)) {
                continue;
            }
            $rows .= '<tr><td><code>' . $this->escape($issue['key']) . '</code></td>'
                . '<td>' . $this->escape($issue['label'] === '' ? '미등록' : $issue['label'])
                . '</td><td>' . $this->escape($issue['reason']) . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }
        return '<details class="ojp-disclosure"><summary>주제 검증 상세</summary>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>AI 반환 키</th><th>등록 주제명</th><th>거부 사유</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p>메타데이터는 저장되지 않았습니다. 목록의 활성 세부 주제 키만 사용할 수 있으며, 해당 주제가 없으면 빈 목록을 반환해야 합니다.</p>'
            . '</details>';
    }

    private function metadataDraftTarget(JobItemDetails $item): ?string
    {
        if ($item->resultCode() !== 'problem.metadata_draft_ready') {
            return null;
        }
        $result = $item->safeResult();
        $reference = $result['draft_reference'] ?? null;
        $problemId = $result['problem_id'] ?? null;
        if (
            ! is_string($reference)
            || preg_match('/^draft:v1:[a-f0-9-]{36}$/', $reference) !== 1
            || ! is_int($problemId)
            || $problemId < 1
        ) {
            return null;
        }

        return $problemId . '|' . $reference;
    }

    /**
     * @param list<string> $targets
     */
    private function metadataBulkBar(int $jobId, array $targets): string
    {
        $allInputs = '';
        foreach ($targets as $target) {
            $allInputs .= '<input type="hidden" name="targets[]" value="'
                . $this->escape($target) . '">';
        }

        return '<form id="ojp-metadata-bulk-approve-form" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '" data-ojp-bulk-copy>'
            . $this->security->hiddenFields('oj_problem_bulk_apply_metadata_draft')
            . '<input type="hidden" name="job_id" value="' . $jobId . '"></form>'
            . '<form id="ojp-metadata-approve-all-form" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_bulk_apply_metadata_draft')
            . '<input type="hidden" name="job_id" value="' . $jobId . '">'
            . $allInputs . '</form>'
            . '<div class="ojp-command-group">'
            . '<div class="ojp-bulk-bar" data-ojp-bulk-bar hidden>'
            . '<span><strong data-ojp-selected-count>0</strong>개 선택</span>'
            . '<button class="ojp-button" type="submit" form="ojp-metadata-bulk-approve-form">'
            . '선택 항목 승인</button></div>'
            . '<button class="ojp-button ojp-button--primary" type="submit" form="ojp-metadata-approve-all-form">'
            . '전체 승인 (' . count($targets) . '개)</button></div>';
    }

    private function steps(JobDetails $job): string
    {
        if ($job->steps() === []) {
            return '';
        }
        $rows = '';
        foreach ($job->steps() as $step) {
            $rows .= '<tr><td>' . $step->itemId() . '</td><td><code>'
                . $this->escape($step->stepKey()) . '</code></td><td>'
                . $this->escape($this->statusLabel($step->status()))
                . '</td><td>' . $this->escape($step->compensationStatus() ?? '-')
                . '</td><td>' . $this->escape($step->completedAt() === null
                    ? '진행 중'
                    : $this->dates->format($step->completedAt()))
                . '</td></tr>';
        }

        return '<section class="ojp-section"><header class="ojp-section-heading"><div>'
            . '<h2>단계 기록</h2></div></header><div class="ojp-table-wrap">'
            . '<table class="ojp-table"><thead><tr><th>항목</th><th>단계</th>'
            . '<th>상태</th><th>보상</th><th>완료</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
    }

    private function draftLink(JobItemDetails $item): string
    {
        $result = $item->safeResult();
        $reference = $result['draft_reference'] ?? null;
        if (
            ! is_string($reference)
            || preg_match('/^draft:v1:[a-f0-9-]{36}$/', $reference) !== 1
        ) {
            return '-';
        }
        $encoded = rawurlencode($reference);
        $problemId = is_int($result['problem_id'] ?? null)
            ? $result['problem_id']
            : 0;
        if ($item->resultCode() === 'problem.metadata_draft_ready' && $problemId > 0) {
            $url = '/oj-console/problems/' . $problemId . '/edit?metadata_draft=' . $encoded;
        } elseif ($item->resultCode() === 'problem.authoring_draft_ready') {
            $url = $problemId > 0
                ? '/oj-console/problems/' . $problemId . '/edit?authoring_draft=' . $encoded
                : '/oj-console/problems/new?authoring_draft=' . $encoded;
        } elseif ($item->resultCode() === 'problem.testcase_draft_ready' && $problemId > 0) {
            $url = '/oj-console/problems/' . $problemId . '/test-data?testcase_draft=' . $encoded;
        } else {
            return '-';
        }

        return '<a class="ojp-button ojp-button--small" href="'
            . $this->escape($url) . '">검토</a>';
    }

    private function jobLabel(string $jobType): string
    {
        return match ($jobType) {
            'problem_metadata_generate' => '문제 메타데이터 생성',
            'problem_authoring_generate' => '문제 작성 초안 생성',
            'problem_testcase_generate' => '테스트케이스 초안 생성',
            'problem_testcase_apply' => '테스트데이터 적용',
            'problem_spj_compile' => '특수 채점기 컴파일',
            'problem_number_change' => '문제 번호 변경',
            default => $jobType,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => '대기',
            'running' => '진행 중',
            'completed' => '완료',
            'partial' => '일부 완료',
            'failed' => '실패',
            'cancelled' => '취소',
            'needs_attention' => '확인 필요',
            'compensated' => '복구 완료',
            'compensation_failed' => '복구 실패',
            default => $status,
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'failed', 'compensation_failed' => 'danger',
            'partial', 'needs_attention' => 'warning',
            default => 'neutral',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
