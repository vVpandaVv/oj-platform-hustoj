<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionRejudgeControlRenderer;
use InvalidArgumentException;

final class SubmissionRejudgeControls implements SubmissionRejudgeControlRenderer
{
    public function __construct(
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function render(string $returnPath): string
    {
        if (preg_match(
            '#^/oj-console/(?:classes|contests|groups)/[1-9][0-9]*/submissions$#',
            $returnPath
        ) !== 1) {
            throw new InvalidArgumentException(
                '재채점 후 돌아갈 관리자 경로가 올바르지 않습니다.'
            );
        }

        return '<div class="ojp-bulk-actions" data-oj-submission-bulk>'
            . '<form class="ojp-inline-form" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl())
            . '" data-oj-bulk-operation>'
            . $this->security->hiddenFields('oj_submission_rejudge')
            . '<input type="hidden" name="request_key" value="'
            . bin2hex(random_bytes(32)) . '">'
            . '<input type="hidden" name="return_path" value="'
            . $this->escape($returnPath) . '">'
            . '<span data-oj-selected-submissions></span>'
            . '<label class="ojp-field"><span class="ojp-sr-only">'
            . '재채점 사유</span><input class="ojp-input" type="text"'
            . ' name="reason" minlength="3" maxlength="500" required'
            . ' placeholder="재채점 사유"></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '선택 재채점</button></form></div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
