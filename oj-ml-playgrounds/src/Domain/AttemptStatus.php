<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Domain;

final class AttemptStatus
{
    public const IN_PROGRESS = 'in_progress';
    public const UPLOADING = 'uploading';
    public const QUARANTINED = 'quarantined';
    public const INSPECTING = 'inspecting';
    public const ACCEPTED = 'accepted';
    public const NEEDS_RESUBMIT = 'needs_resubmit';
    public const SYSTEM_PENDING = 'system_pending';
    public const UNDER_REVIEW = 'under_review';
    public const GRADED = 'graded';
    public const CANCELLED = 'cancelled';
    public const UPLOAD_EXPIRED = 'upload_expired';

    /** @return list<string> */
    public static function active(): array
    {
        return [self::IN_PROGRESS, self::UPLOADING];
    }

    /** @return list<string> */
    public static function terminalForUpload(): array
    {
        return [
            self::ACCEPTED,
            self::NEEDS_RESUBMIT,
            self::SYSTEM_PENDING,
            self::UNDER_REVIEW,
            self::GRADED,
        ];
    }

    public static function canReceiveArtifact(string $status): bool
    {
        return in_array($status, self::active(), true);
    }
}
