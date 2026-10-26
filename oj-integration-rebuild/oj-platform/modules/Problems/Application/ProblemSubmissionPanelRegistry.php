<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemSubmissionPanelProvider;
use LogicException;

/**
 * Single-slot registration point for an inline submission panel on the
 * problem detail page. Unlike ProblemDetailSectionRegistry, at most one
 * provider ever exists (Submissions), so a keyed collection would be
 * premature.
 */
final class ProblemSubmissionPanelRegistry
{
    private ?ProblemSubmissionPanelProvider $provider = null;

    public function set(ProblemSubmissionPanelProvider $provider): void
    {
        if ($this->provider !== null) {
            throw new LogicException('문제 제출 패널 Provider가 이미 등록되어 있습니다.');
        }
        $this->provider = $provider;
    }

    public function get(): ?ProblemSubmissionPanelProvider
    {
        return $this->provider;
    }
}
