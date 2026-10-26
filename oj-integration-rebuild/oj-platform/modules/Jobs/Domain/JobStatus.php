<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Domain;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case NeedsAttention = 'needs_attention';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => '대기',
            self::Running => '진행 중',
            self::NeedsAttention => '확인 필요',
            self::Partial => '일부 완료',
            self::Completed => '완료',
            self::Failed => '실패',
            self::Cancelled => '취소',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::NeedsAttention,
            self::Partial,
            self::Completed,
            self::Failed,
            self::Cancelled,
        ], true);
    }
}
