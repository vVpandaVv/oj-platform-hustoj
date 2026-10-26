<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation;

use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class AiHtml
{
    public function __construct(
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function feedback(SubmissionFeedback $feedback): string
    {
        $content = $feedback->content();
        $hints = '';
        foreach (($content['step_hints'] ?? []) as $hint) {
            if (is_string($hint)) {
                $hints .= '<li>' . $this->escape($hint) . '</li>';
            }
        }

        return '<div class="ojp-feedback-content" data-oj-ai-feedback-content>'
            . '<div class="ojp-feedback-block"><h3>결과 이해</h3><p>'
            . $this->escape((string) ($content['result_understanding'] ?? ''))
            . '</p></div><div class="ojp-feedback-block"><h3>풀이 도움</h3>'
            . '<ol>' . $hints . '</ol></div><div class="ojp-feedback-block">'
            . '<h3>다시 확인할 점</h3><p>'
            . $this->escape((string) ($content['review_question'] ?? ''))
            . '</p></div></div>';
    }

    public function analysisStatus(string $status): string
    {
        return match ($status) {
            'insufficient' => '자료 부족',
            'provisional' => '임시 분석',
            'classified' => '분석 완료',
            'reliable' => '신뢰 자료 충족',
            default => '확인 필요',
        };
    }

    public function level(?string $level): string
    {
        return match ($level) {
            'beginner' => '초급',
            'intermediate' => '중급',
            'advanced' => '고급',
            default => '아직 표시하지 않음',
        };
    }

    public function profileSummary(LearnerLevelProfile $profile): string
    {
        $score = $profile->metric('skill_score');

        return '<dl class="ojp-description-grid"><div><dt>분석 상태</dt><dd>'
            . $this->analysisStatus($profile->analysisStatus())
            . '</dd></div><div><dt>학습 수준</dt><dd>'
            . $this->level($profile->level())
            . '</dd></div><div><dt>학습 점수</dt><dd>'
            . ($score === null ? '-' : number_format($score, 1) . ' / 100')
            . '</dd></div><div><dt>증명 난이도</dt><dd>'
            . ($profile->provenTier() === null
                ? '-'
                : $profile->provenTier() . '단계')
            . '</dd></div></dl>';
    }

    public function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    public function date(?string $value, string $format = 'Y-m-d H:i'): string
    {
        return $this->dates->format($value, $format);
    }
}
