<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Console;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiUsageStore;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiUsageFilter;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use InvalidArgumentException;
use Throwable;

final class AiUsagePageHandler implements RouteHandler
{
    private const GROUPS = ['task_type' => '작업', 'profile_key' => '연결 프로필', 'provider_key' => 'Provider', 'model_key' => '모델', 'purpose' => '사용 구분', 'addon_id' => '애드온'];
    private const PURPOSES = ['core' => '플랫폼', 'addon' => '애드온', 'probe' => '연결 검사'];

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly AiUsageStore $usage,
        private readonly AiProviderCatalog $catalog,
        private readonly Clock $siteClock,
        private readonly RouteUrlProvider $urls,
        private readonly ErrorReporter $errors,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {}

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $content = OperationsHtml::header('운영', 'AI 사용량', '플랫폼과 애드온에서 사용한 AI 호출을 함께 확인합니다.');
        $status = 200;
        $error = '';
        try {
            $filter = new AiUsageFilter($request->query(), $this->siteClock);
        } catch (InvalidArgumentException $exception) {
            $filter = new AiUsageFilter([], $this->siteClock);
            $error = $exception->getMessage();
            $status = 400;
        }
        $content .= $this->filters($filter);
        if ($status === 200) {
            try {
                $report = $this->usage->report($filter);
                $content .= $this->summary($report['summary'])
                    . $this->groups($report['groups'], $filter)
                    . $this->items($report, $filter);
            } catch (Throwable $exception) {
                $this->errors->report($exception);
                $error = '사용량을 불러오지 못했습니다. 플랫폼 설치 상태와 시스템 기록을 확인해 주세요.';
                $status = 503;
            }
        }
        if ($error !== '') {
            $content .= '<div class="ojp-notice ojp-notice--danger" role="alert">' . OperationsHtml::escape($error) . '</div>';
        }
        return $this->pages->respond('AI 사용량', $content, 'operations.ai_usage', $request, $status);
    }

    private function filters(AiUsageFilter $filter): string
    {
        $html = '<section class="ojp-section"><form method="get" action="' . OperationsHtml::escape($this->url()) . '">'
            . '<div class="ojp-form-grid">'
            . $this->input('from', '시작일', $filter->from, 'date')
            . $this->input('to', '종료일', $filter->to, 'date')
            . $this->select('purpose', '사용 구분', ['' => '전체'] + self::PURPOSES, $filter->matches['purpose'] ?? '')
            . $this->select('group_by', '집계 기준', self::GROUPS, $filter->groupBy)
            . '</div><details class="ojp-section"><summary>세부 조건</summary><div class="ojp-form-grid">';
        foreach (self::GROUPS as $key => $label) {
            if ($key === 'purpose') { continue; }
            $html .= $this->input($key, $label . ' 키', $filter->matches[$key] ?? '');
        }
        return $html . '</div></details><div class="ojp-command-group">'
            . '<button type="submit" class="ojp-button ojp-button--primary">조회</button>'
            . '<a class="ojp-button" href="' . OperationsHtml::escape($this->url()) . '">이번 달</a>'
            . '<a class="ojp-button" href="' . OperationsHtml::escape($this->url([
                'from' => $this->siteClock->now()->format('Y-m-d'), 'to' => $this->siteClock->now()->format('Y-m-d'),
            ])) . '">오늘</a></div></form><p class="ojp-muted">시간 기준: '
            . OperationsHtml::escape($this->dates->timezoneLabel()) . '</p></section>';
    }

    private function summary(array $summary): string
    {
        $count = (int) $summary['request_count'];
        $metrics = [
            '입력 토큰' => $this->tokens($summary['input_tokens'] ?? null, $count === 0),
            '출력 토큰' => $this->tokens($summary['output_tokens'] ?? null, $count === 0),
            '전체 토큰' => $this->tokens($summary['total_tokens'] ?? null, $count === 0),
            '호출 수' => number_format($count),
        ];
        $html = '<section class="ojp-section"><h2>기간 합계</h2><dl class="ojp-metrics">';
        foreach ($metrics as $label => $value) {
            $html .= '<div><dt>' . $label . '</dt><dd>' . $value . '</dd></div>';
        }
        $html .= '</dl><p>성공 ' . number_format((int) ($summary['success_count'] ?? 0))
            . ' · 실패 ' . number_format((int) ($summary['failure_count'] ?? 0))
            . ' · 평균 응답 ' . $this->duration($summary['average_ms'] ?? null) . '</p>';
        $html .= '<p>전체 토큰 확인 ' . number_format((int) ($summary['total_measured_count'] ?? 0)) . ' / ' . number_format($count)
            . '건 · 부분 측정 ' . number_format((int) ($summary['partial_count'] ?? 0))
            . '건 · 측정 불가 ' . number_format((int) ($summary['unavailable_count'] ?? 0)) . '건</p>';
        return $html . '<p class="ojp-muted">확인된 토큰만 합산합니다. 실패한 호출에도 토큰이 사용될 수 있으며, 재시도와 대체 연결은 각각 집계됩니다. '
            . '캐시·추론 토큰은 전체에 다시 더하지 않습니다. 전체 토큰은 Provider 합계를 우선하며, Gemini는 입력·출력 외 추론 등이 포함될 수 있습니다.</p>'
            . '<details><summary>캐시·추론 사용량</summary><p>캐시 입력: ' . $this->tokens($summary['cached_input_tokens'] ?? null, $count === 0)
            . ' · 추론: ' . $this->tokens($summary['reasoning_tokens'] ?? null, $count === 0) . '</p></details></section>';
    }

    private function groups(array $groups, AiUsageFilter $filter): string
    {
        $rows = '';
        foreach ($groups as $group) {
            $key = (string) $group['group_key'];
            $label = $this->label($filter->groupBy, $key);
            $heading = OperationsHtml::escape($label);
            if ($key !== '') {
                $heading = '<a href="' . OperationsHtml::escape($this->url(array_replace($filter->query(), [$filter->groupBy => $key]))) . '">' . $heading . '</a>';
            }
            $rows .= '<tr><th scope="row">' . $heading . '</th><td>' . number_format((int) $group['request_count'])
                . '</td><td>' . number_format((int) ($group['failure_count'] ?? 0)) . '</td><td>' . $this->tokens($group['input_tokens'] ?? null)
                . '</td><td>' . $this->tokens($group['output_tokens'] ?? null) . '</td><td>' . $this->tokens($group['total_tokens'] ?? null)
                . '</td><td>' . (int) ($group['total_measured_count'] ?? 0) . ' / ' . (int) $group['request_count'] . '</td></tr>';
        }
        return '<section class="ojp-section"><h2>' . self::GROUPS[$filter->groupBy] . '별 사용량</h2>'
            . $this->table(['구분', '호출', '실패', '입력 토큰', '출력 토큰', '전체 토큰', '전체 토큰 확인'], $rows)
            . (count($groups) === 100 ? '<p class="ojp-muted">호출 수 기준 상위 100개입니다. 세부 조건으로 조회 범위를 좁힐 수 있습니다.</p>' : '') . '</section>';
    }

    private function items(array $report, AiUsageFilter $filter): string
    {
        $rows = '';
        foreach ($report['items'] as $item) {
            $result = $item['outcome'] === 'succeeded' ? '성공' : '실패';
            $details = '<details><summary>측정 정보</summary><p>요청 ID: ' . OperationsHtml::escape((string) $item['request_id'])
                . '<br>캐시 입력: ' . $this->tokens($item['cached_input_tokens']) . '<br>추론: ' . $this->tokens($item['reasoning_tokens'])
                . '<br>합계 출처: ' . match ($item['total_source']) { 'provider' => 'Provider 응답', 'sum' => '입력 + 출력', default => '측정 불가' }
                . '<br>HTTP: ' . OperationsHtml::escape((string) ($item['http_status'] ?? '-'))
                . ($item['error_code'] !== '' ? '<br>오류: ' . OperationsHtml::escape((string) $item['error_code']) : '') . '</p></details>';
            $rows .= '<tr><td>' . OperationsHtml::escape($this->dates->format((string) $item['occurred_at'], 'Y-m-d H:i:s'))
                . '</td><td>' . OperationsHtml::escape(self::PURPOSES[$item['purpose']] ?? $item['purpose'])
                . '<br>' . OperationsHtml::escape($this->label('task_type', (string) $item['task_type']))
                . ($item['addon_id'] !== '' ? '<br>' . OperationsHtml::escape((string) $item['addon_id']) : '')
                . '</td><td>' . OperationsHtml::escape((string) $item['profile_key'])
                . '<br>' . OperationsHtml::escape((string) $item['provider_key'])
                . '<br>' . OperationsHtml::escape((string) $item['model_key']) . '</td><td>' . $result
                . '<br>' . $this->duration($item['duration_ms']) . '</td><td>' . $this->tokens($item['input_tokens'])
                . '</td><td>' . $this->tokens($item['output_tokens']) . '</td><td>' . $this->tokens($item['total_tokens'])
                . $details . '</td></tr>';
        }
        $html = '<section class="ojp-section"><h2>호출 내역</h2><p class="ojp-muted">성공·실패는 AI 호출 결과이며, 생성 결과의 업무 적용 여부와는 별개입니다.</p>'
            . $this->table(['호출 시각', '사용 구분 · 작업', '연결 · 모델', '결과 · 소요 시간', '입력 토큰', '출력 토큰', '전체 토큰'], $rows, true)
            . '<nav class="ojp-command-group" aria-label="호출 내역 페이지">';
        foreach ([-1 => '이전', 1 => '다음'] as $offset => $label) {
            $page = $report['page'] + $offset;
            if ($page < 1 || $page > $report['pages']) { continue; }
            $html .= '<a class="ojp-button" href="' . OperationsHtml::escape($this->url($filter->query() + ['page' => $page])) . '">' . $label . '</a>';
        }
        return $html . '<span>' . (int) $report['page'] . ' / ' . (int) $report['pages'] . '</span></nav></section>';
    }

    private function label(string $group, string $key): string
    {
        if ($group === 'purpose') { return self::PURPOSES[$key] ?? $key; }
        if ($group === 'task_type' && $this->catalog->supportsTask($key)) { return $this->catalog->taskLabel($key); }
        return $key === '' ? '해당 없음' : $key;
    }

    private function url(array $query = []): string
    {
        return $this->urls->path('console.settings.ai_usage') . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private function input(string $name, string $label, string $value, string $type = 'text'): string
    {
        return '<label class="ojp-field">' . $label . '<input class="ojp-input" type="' . $type . '" name="' . $name . '" value="' . OperationsHtml::escape($value) . '"></label>';
    }

    private function select(string $name, string $label, array $options, string $selected): string
    {
        $html = '<label class="ojp-field">' . $label . '<select class="ojp-select" name="' . $name . '">';
        foreach ($options as $key => $text) {
            $html .= '<option value="' . OperationsHtml::escape($key) . '"' . OperationsHtml::selected($key, $selected) . '>' . $text . '</option>';
        }
        return $html . '</select></label>';
    }

    private function table(array $headings, string $rows, bool $wide = false): string
    {
        return '<div class="ojp-table-wrap"><table class="ojp-table' . ($wide ? ' ojp-table--matrix' : '') . '"><thead><tr><th scope="col">'
            . implode('</th><th scope="col">', $headings) . '</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="' . count($headings) . '">조회 기간에 기록된 호출이 없습니다.</td></tr>') . '</tbody></table></div>';
    }

    private function tokens(mixed $value, bool $empty = false): string
    {
        return $value === null ? ($empty ? '0' : '측정 불가') : number_format((float) $value, 0);
    }

    private function duration(mixed $value): string
    {
        return $value === null ? '-' : number_format((float) $value / 1000, 2) . '초';
    }
}
