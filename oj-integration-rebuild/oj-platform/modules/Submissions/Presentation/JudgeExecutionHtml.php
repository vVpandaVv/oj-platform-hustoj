<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResult;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Submissions\Application\JudgeExecutionParser;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\RuntimeErrorReport;

final class JudgeExecutionHtml
{
    /** @var array<string, JudgeResult> */
    private array $results = [];

    public function __construct(private readonly JudgeExecutionParser $parser, JudgeResults $results)
    {
        foreach ($results->all() as $result) {
            $this->results[preg_replace('/^OJ_/', '', $result->key())] = $result;
        }
    }

    /** @param array{show_time?: bool, show_memory?: bool} $display */
    public function render(string $raw, bool $showRaw = false, array $display = []): string
    {
        $report = $this->parser->parse($raw);
        if (trim($report->rawExcerpt()) === '') {
            return '';
        }
        $content = $report->truncated()
            ? '<div class="ojp-notice ojp-notice--warning"><strong>일부 기록만 표시합니다.</strong>'
                . '<p>기록이 길어 64KB, 테스트 100개, 비교 50개·200행, 오류별 진단 50행까지만 표시합니다.</p></div>'
            : '';
        if ($report->testCases() !== []) {
            $content .= $this->testCases($report->testCases(), $display);
        }
        $opened = false;
        foreach ($report->testCases() as $case) {
            if ($case['diagnostics']->diagnostics() === []) {
                continue;
            }
            $content .= '<details class="ojp-disclosure" data-oj-case-error' . ($opened ? '' : ' open') . '>'
                . '<summary>' . $this->escape($this->filename($case['file'])) . ' 실행 오류'
                . ($case['exit_code'] === null ? '' : ' · 종료 코드 ' . $case['exit_code']) . '</summary>'
                . $this->diagnostics($case['diagnostics']) . '</details>';
            $opened = true;
        }
        foreach ($report->comparisons() as $index => $comparison) {
            $content .= '<details class="ojp-disclosure" data-oj-output-comparison' . ($index === 0 ? ' open' : '') . '>'
                . '<summary>' . $this->escape($this->filename($comparison['file'])) . ' 출력 비교</summary>'
                . '<div class="ojp-table-wrap"><table class="ojp-table ojp-output-comparison">'
                . '<thead><tr><th scope="col">기대 출력</th><th scope="col">실제 출력</th></tr></thead><tbody>';
            foreach ($comparison['rows'] as $row) {
                $content .= '<tr><td>' . $this->output($row['expected']) . '</td><td>'
                    . $this->output($row['actual']) . '</td></tr>';
            }
            $content .= '</tbody></table></div></details>';
        }
        if ($report->diagnostics()->diagnostics() !== []) {
            $title = $report->diagnostics()->recognized() ? '실행 오류' : '추가 메시지';
            $content .= '<h3>' . $title . '</h3>' . $this->diagnostics($report->diagnostics());
        }
        if ($showRaw) {
            $content .= '<details class="ojp-disclosure"><summary>채점 기록 원문 확인</summary>'
                . '<pre class="ojp-code-block"><code>' . $this->escape($report->rawExcerpt()) . '</code></pre></details>';
        }
        return '<section class="ojp-section ojp-judge-report" data-oj-judge-report><h2>채점 상세</h2>' . $content . '</section>';
    }

    private function testCases(array $cases, array $display): string
    {
        $time = $display['show_time'] ?? true;
        $memory = $display['show_memory'] ?? true;
        $hasExitCode = count(array_filter($cases, static fn (array $case): bool => $case['exit_code'] !== null)) > 0;
        $html = '<div class="ojp-table-wrap" data-oj-test-case-results><table class="ojp-table">'
            . '<caption class="ojp-table-caption">테스트별 결과</caption><thead><tr>'
            . '<th scope="col">테스트 파일</th><th scope="col">결과</th><th scope="col">크기 (B)</th>'
            . ($memory ? '<th scope="col">메모리</th>' : '')
            . ($time ? '<th scope="col">실행 시간</th>' : '')
            . ($hasExitCode ? '<th scope="col">종료 코드</th>' : '') . '</tr></thead><tbody>';
        foreach ($cases as $case) {
            $html .= '<tr><th scope="row">' . $this->escape($this->filename($case['file']))
                . '</th><td>' . $this->result($case['result']) . '</td><td>'
                . ($case['size'] === null ? '-' : number_format($case['size'])) . '</td>'
                . ($memory ? '<td>' . $this->escape($case['memory'] ?: '-') . '</td>' : '')
                . ($time ? '<td>' . $this->escape($case['time'] ?: '-') . '</td>' : '')
                . ($hasExitCode ? '<td>' . ($case['exit_code'] ?? '-') . '</td>' : '') . '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function result(string $key): string
    {
        $lookup = ['TLE' => 'TL', 'MLE' => 'ML', 'OLE' => 'OL'][$key] ?? $key;
        $result = $this->results[$lookup] ?? null;
        if ($result === null) {
            return $this->escape('알 수 없는 결과 (' . $key . ')');
        }
        return '<span class="' . $this->escape($result->badgeClass()) . '">' . $this->escape($result->label()) . '</span>';
    }

    private function diagnostics(RuntimeErrorReport $report): string
    {
        $html = '<div class="ojp-table-wrap" data-oj-runtime-diagnostics><table class="ojp-table">'
            . '<thead><tr><th>구분</th><th>오류 유형</th><th>메시지</th><th>위치</th><th>함수</th></tr></thead><tbody>';
        foreach ($report->diagnostics() as $diagnostic) {
            $location = $this->filename($diagnostic->file());
            if ($diagnostic->line() !== null) {
                $location .= ($location === '' ? '' : ':') . $diagnostic->line();
            }
            $html .= '<tr><td>' . $this->escape($diagnostic->kind()) . '</td><td><strong>'
                . $this->escape($diagnostic->type()) . '</strong></td><td>'
                . $this->escape($diagnostic->message() ?: '-') . '</td><td>'
                . $this->escape($location ?: '-') . '</td><td>'
                . $this->escape($diagnostic->function() ?: '-') . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function output(string $value): string
    {
        // Only judge-provided backtick spans are highlighted; arbitrary Markdown/HTML is never executed.
        $parts = preg_split('/(`[^`\r\n]+`)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        $html = '';
        foreach ($parts as $part) {
            $highlight = strlen($part) >= 2 && $part[0] === '`' && str_ends_with($part, '`');
            $html .= $highlight
                ? '<mark class="ojp-output-difference">' . $this->outputText(substr($part, 1, -1)) . '</mark>'
                : $this->outputText($part);
        }
        return '<pre class="ojp-output-text"' . ($value === '' ? ' aria-label="빈 출력"' : '') . '><code>' . $html . '</code></pre>';
    }

    private function outputText(string $value): string
    {
        // CSS markers expose whitespace without replacing the text selected or copied by users.
        return strtr($this->escape($value), [
            ' ' => '<span class="ojp-output-whitespace" title="공백" aria-label="공백"> </span>',
            "\t" => '<span class="ojp-output-whitespace ojp-output-whitespace--tab" title="탭" aria-label="탭">' . "\t" . '</span>',
            "\u{00A0}" => '<span class="ojp-output-whitespace ojp-output-whitespace--nbsp" title="줄 바꿈 없는 공백" aria-label="줄 바꿈 없는 공백">' . "\u{00A0}" . '</span>',
        ]);
    }

    private function filename(string $value): string
    {
        return $value === '' ? '' : basename(str_replace('\\', '/', $value));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
