<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizResultPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use DateTimeImmutable;
use Throwable;

final class QuizHtml
{
    public function __construct(private readonly ProblemHtml $html)
    {
    }

    public function escape(string $value): string
    {
        return $this->html->escape($value);
    }

    public function pageLinks(string $path, int $page, bool $hasNext): string
    {
        if ($page === 1 && ! $hasNext) { return ''; }
        return '<nav class="ojp-pagination" aria-label="퀴즈 페이지">'
            . ($page > 1 ? '<a class="ojp-button" href="' . $this->escape($path) . '?page=' . ($page - 1) . '">이전</a>' : '')
            . '<span>' . $page . '</span>'
            . ($hasNext ? '<a class="ojp-button" href="' . $this->escape($path) . '?page=' . ($page + 1) . '">다음</a>' : '') . '</nav>';
    }

    public function score(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    public function date(?string $value, string $format = 'Y-m-d H:i'): string
    {
        return $this->html->date($value, $format);
    }

    public function policy(QuizResultPolicy $policy): string
    {
        return match ($policy) {
            QuizResultPolicy::Full => '점수·정오·해설',
            QuizResultPolicy::ScoreOnly => '점수만',
            QuizResultPolicy::AfterEnd => '지정 시각 후 정오·해설',
        };
    }

    public function status(string $status): string
    {
        [$label, $class] = match ($status) {
            'in_progress' => ['진행 중', 'warning'],
            'graded' => ['제출 완료', 'success'],
            'superseded' => ['교체됨', 'neutral'],
            default => ['확인 필요', 'neutral'],
        };

        return '<span class="ojp-status-badge ojp-status-badge--'
            . $class . '">' . $label . '</span>';
    }

    public function duration(string $startedAt, ?string $submittedAt): string
    {
        if ($submittedAt === null) {
            return '-';
        }
        try {
            $seconds = max(
                0,
                (new DateTimeImmutable($submittedAt))->getTimestamp()
                    - (new DateTimeImmutable($startedAt))->getTimestamp()
            );
        } catch (Throwable) {
            return '-';
        }

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public function navigationFields(ProblemNavigationContext $navigation): string
    {
        $fields = [
            'class_id' => $navigation->classId(),
            'contest_id' => $navigation->contestId(),
            'contest_problem_number' => $navigation->contestProblemNumber(),
        ];
        $html = '';
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $html .= '<input type="hidden" name="' . $name . '" value="' . $value . '">';
            }
        }

        return $html;
    }

    public function tabs(
        int $problemId,
        string $active,
        ?ProblemNavigationContext $navigation = null
    ): string {
        $navigation ??= ProblemNavigationContext::general();
        $items = [
            'problem' => [$navigation->detailUrl($problemId), '문제로 돌아가기'],
            'detail' => [$navigation->quizUrl($problemId), '퀴즈'],
            'attempt' => [$navigation->quizUrl($problemId, 'attempt'), '응시'],
            'statistics' => [$navigation->quizUrl($problemId, 'statistics'), '통계'],
        ];
        $links = '';
        foreach ($items as $key => [$href, $label]) {
            $links .= '<a class="ojp-tab'
                . ($key === $active ? ' is-active' : '')
                . '" href="' . $this->escape($href) . '">' . $label . '</a>';
        }

        return '<nav class="ojp-tabs" aria-label="퀴즈 보기">'
            . $links . '</nav>';
    }
}
