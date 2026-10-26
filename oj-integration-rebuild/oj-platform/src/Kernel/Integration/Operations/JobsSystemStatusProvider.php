<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobScheduler;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class JobsSystemStatusProvider implements SystemStatusProvider
{
    public function __construct(
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function key(): string
    {
        return 'jobs';
    }

    public function current(): SystemStatusItem
    {
        $next = function_exists('wp_next_scheduled')
            ? wp_next_scheduled(WordPressJobScheduler::HOOK)
            : false;
        $scheduled = is_int($next) && $next > 0;

        return new SystemStatusItem(
            $this->key(),
            '백그라운드 작업',
            $scheduled ? 'success' : 'warning',
            $scheduled
                ? '작업 등록 시 즉시 실행하며 복구 예약도 등록되어 있습니다.'
                : 'Jobs Worker 예약을 확인하지 못했습니다.',
            $scheduled
                ? '실패 작업은 작업 상세 화면에서 확인하세요.'
                : 'WordPress Cron 또는 외부 Worker 실행 구성을 확인하세요.',
            [
                'Worker Hook' => WordPressJobScheduler::HOOK,
                '실행 방식' => '요청 직후 실행 · 대기 작업 연속 처리',
                '다음 복구 확인' => $scheduled
                    ? $this->dates->formatTimestamp(
                        (int) $next,
                        'Y-m-d H:i:s'
                    )
                    : '확인되지 않음',
            ],
            'oj_manage_system'
        );
    }
}
