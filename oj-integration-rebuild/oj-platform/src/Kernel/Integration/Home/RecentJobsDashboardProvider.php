<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessPolicy;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\RecentJobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class RecentJobsDashboardProvider implements DashboardBlockProvider
{
    public function __construct(
        private readonly RecentJobQuery $jobs,
        private readonly JobAccessPolicy $access,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function key(): string { return 'jobs.recent'; }
    public function title(): string { return '최근 작업'; }
    public function kind(): string { return 'jobs'; }
    public function priority(): int { return 50; }

    public function load(DashboardContext $context): DashboardBlock
    {
        $items = [];
        foreach ($this->jobs->recent(32) as $job) {
            if (! $this->access->canView($context->userId(), $job)) {
                continue;
            }
            $processed = $job->succeededCount()
                + $job->failedCount()
                + $job->cancelledCount();
            $progress = $job->totalCount() < 1
                ? 0
                : (int) floor(($processed / $job->totalCount()) * 100);
            $items[] = new DashboardItem(
                $this->jobLabel($job->jobType()),
                $this->dateLabel($job->createdAt()),
                RouteTarget::route('console.jobs.show', ['job_id' => $job->id()]),
                null,
                $this->statusLabel($job->status()),
                $this->statusTone($job->status()),
                $this->progressLabel($job),
                min(100, max(0, $progress))
            );
            if (count($items) >= 8) {
                break;
            }
        }

        return new DashboardBlock(
            $this->key(),
            $this->title(),
            '현재 조회 권한이 있는 작업만 표시합니다.',
            $this->kind(),
            $items
        );
    }

    private function jobLabel(string $jobType): string
    {
        return [
            'platform_install' => '플랫폼 설치',
            'problem_metadata_generate' => '문제 메타데이터 생성',
            'problem_authoring_generate' => '문제 자동 생성',
            'problem_testcase_generate' => '테스트케이스 생성',
            'submission_ai_feedback_generate' => '제출 AI 피드백',
            'user_csv_import' => '사용자 CSV 등록',
            'user_account_erasure' => '사용자 계정 삭제',
            'platform_migration' => '플랫폼 데이터 이전',
            'problem_number_change' => '문제 번호 변경',
            'problem_testcase_apply' => '테스트케이스 적용',
            'problem_spj_compile' => '특수 채점기 컴파일',
            'quiz_revision_reapply' => '퀴즈 게시 버전 재적용',
        ][$jobType] ?? '백그라운드 작업';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => '대기',
            'running' => '진행 중',
            'completed' => '완료',
            'partial', 'needs_attention' => '확인 필요',
            'failed' => '실패',
            'cancelled' => '취소',
            default => '상태 확인',
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'queued', 'running' => 'info',
            'completed' => 'success',
            'partial', 'needs_attention' => 'warning',
            'failed' => 'danger',
            default => 'neutral',
        };
    }

    private function progressLabel(JobDetails $job): string
    {
        if ($job->totalCount() < 1) {
            return $this->statusLabel($job->status());
        }

        return $job->succeededCount() . ' 성공 · '
            . $job->failedCount() . ' 실패 · '
            . $job->totalCount() . ' 전체';
    }

    private function dateLabel(string $value): string
    {
        return $this->dates->format($value);
    }
}
