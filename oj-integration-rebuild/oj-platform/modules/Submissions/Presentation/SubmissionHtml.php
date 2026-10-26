<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation;

use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class SubmissionHtml
{
    public function __construct(
        private readonly SubmissionLanguageCatalog $languages,
        private readonly SubmissionQueryService $submissions,
        private readonly JudgeExecutionHtml $judgeExecution,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    public function resultBadge(int $resultCode): string
    {
        $result = $this->submissions->result($resultCode);

        return '<span class="' . $this->escape($result->badgeClass()) . '">'
            . $this->escape($result->label()) . '</span>';
    }

    public function language(int $languageId): string
    {
        return $this->escape($this->languages->label($languageId));
    }

    public function date(?string $value, string $format = 'Y-m-d H:i'): string
    {
        return $this->dates->format($value, $format);
    }

    public function judgeReport(string $message, bool $showRaw = false, array $display = []): string
    {
        return $this->judgeExecution->render($message, $showRaw, $display);
    }

    /**
     * @param list<SubmissionSummary> $rows
     * @param array{show_time: bool, show_memory: bool, show_language: bool} $display
     */
    public function table(
        array $rows,
        bool $showUser,
        bool $selectable,
        bool $console,
        array $display,
        bool $detailsAllowed = true
    ): string {
        if (! $detailsAllowed) {
            $display = [
                'show_time' => false,
                'show_memory' => false,
                'show_language' => false,
            ];
        }
        $headers = $selectable ? '<th class="ojp-table-check">선택</th>' : '';
        $headers .= '<th>제출 번호</th><th>문제</th>';
        if ($showUser) {
            $headers .= '<th>사용자</th>';
        }
        $headers .= '<th>결과</th>';
        if ($display['show_language']) {
            $headers .= '<th>언어</th>';
        }
        if ($display['show_time']) {
            $headers .= '<th>시간</th>';
        }
        if ($display['show_memory']) {
            $headers .= '<th>메모리</th>';
        }
        $headers .= '<th>제출 시각</th>';

        $body = '';
        foreach ($rows as $submission) {
            $id = $submission->submissionId()->value();
            $detailPath = $console
                ? '/oj-console/submissions/' . $id
                : '/submissions/' . $id;
            $body .= '<tr>';
            if ($selectable) {
                $body .= '<td class="ojp-table-check"><input type="checkbox"'
                    . ' name="submission_ids[]" value="' . $id
                    . '" aria-label="제출 ' . $id . ' 선택"></td>';
            }
            $submissionCell = $detailsAllowed
                ? '<a class="ojp-row-link" href="' . $detailPath
                    . '">#' . $id . '</a>'
                : '<strong>#' . $id . '</strong>';
            $body .= '<td>' . $submissionCell . '</td>'
                . '<td><a class="ojp-row-link" href="/problems/'
                . $submission->problemId() . '">문제 '
                . $submission->problemId() . '</a></td>';
            if ($showUser) {
                $body .= '<td><strong>'
                    . $this->escape($submission->nickname())
                    . '</strong><span class="ojp-table-subvalue">'
                    . $this->escape($submission->userLogin())
                    . '</span></td>';
            }
            $body .= '<td>' . $this->resultBadge($submission->resultCode())
                . ($submission->isValid()
                    ? ''
                    : '<span class="ojp-table-subvalue">무효 처리됨</span>')
                . '</td>';
            if ($display['show_language']) {
                $body .= '<td>' . $this->language($submission->languageId())
                    . '</td>';
            }
            if ($display['show_time']) {
                $body .= '<td>' . number_format($submission->timeMilliseconds())
                    . 'ms</td>';
            }
            if ($display['show_memory']) {
                $body .= '<td>' . number_format($submission->memoryKilobytes())
                    . 'KB</td>';
            }
            $body .= '<td><time>' . $this->escape($this->date($submission->submittedAt()))
                . '</time></td></tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="' . $this->columnCount(
                $showUser,
                $selectable,
                $display
            ) . '">조건에 맞는 제출이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr>' . $headers . '</tr></thead><tbody>'
            . $body . '</tbody></table></div>';
    }

    public function pagination(
        int $page,
        int $total,
        int $pageSize,
        string $basePath,
        array $query = []
    ): string {
        $pages = max(1, (int) ceil($total / max(1, $pageSize)));
        if ($pages <= 1) {
            return '';
        }
        unset($query['page']);
        $queryString = $query === [] ? '' : '&' . http_build_query($query);

        return '<nav class="ojp-pagination" aria-label="제출 목록 페이지">'
            . ($page > 1
                ? '<a class="ojp-button ojp-button--small" href="'
                    . $this->escape($basePath) . '?page=' . ($page - 1)
                    . $this->escape($queryString) . '">이전</a>'
                : '<span></span>')
            . '<span>' . $page . ' / ' . $pages . '</span>'
            . ($page < $pages
                ? '<a class="ojp-button ojp-button--small" href="'
                    . $this->escape($basePath) . '?page=' . ($page + 1)
                    . $this->escape($queryString) . '">다음</a>'
                : '<span></span>')
            . '</nav>';
    }

    /**
     * @param array{show_time: bool, show_memory: bool, show_language: bool} $display
     */
    private function columnCount(
        bool $showUser,
        bool $selectable,
        array $display
    ): int {
        return 4
            + ($showUser ? 1 : 0)
            + ($selectable ? 1 : 0)
            + ($display['show_language'] ? 1 : 0)
            + ($display['show_time'] ? 1 : 0)
            + ($display['show_memory'] ? 1 : 0);
    }
}
