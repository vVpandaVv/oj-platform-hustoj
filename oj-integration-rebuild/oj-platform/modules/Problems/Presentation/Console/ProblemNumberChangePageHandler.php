<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemNumberChangeService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberChangePlan;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberMap;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use DomainException;

final class ProblemNumberChangePageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemNumberChangeService $service,
        private readonly ProblemGateway $problems,
        private readonly ProblemAccessPolicy $access,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        if (! $this->access->canChangeIds($userId)) {
            return $this->pages->respond(
                '문제 번호 변경',
                '<div class="ojp-error-state"><h1>접근할 수 없습니다.</h1>'
                    . '<p>문제 번호 변경 권한이 필요합니다.</p></div>',
                'problems.change_id',
                $request,
                403
            );
        }
        $mode = $request->queryString('input_mode', 'range');
        if (! in_array($mode, ['range', 'pairs'], true)) {
            $mode = 'range';
        }
        $pairsText = $request->queryString('pairs');
        $plan = null;
        $error = null;
        if ($request->queryString('preview') === '1') {
            try {
                $mapping = $mode === 'range'
                    ? $this->service->fromRange(
                        $this->positiveQueryInt($request, 'source_start'),
                        $this->positiveQueryInt($request, 'source_end'),
                        $this->positiveQueryInt($request, 'target_start')
                    )
                    : $this->service->fromPairsText($pairsText);
                $plan = $this->service->preview($userId, $mapping);
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '전역 관리자 전용</p><h1>문제 번호 변경</h1><p>'
            . '변경 전 모든 직접 참조와 파일 영향을 검사합니다.</p></div></header>'
            . ($error === null
                ? ''
                : '<div class="ojp-notice ojp-notice--danger"><strong>영향 검사 실패</strong>'
                    . '<p>' . $this->html->escape($error) . '</p></div>')
            . $this->inputForm($request, $mode, $pairsText);
        if ($plan instanceof ProblemNumberChangePlan) {
            $content .= $this->preview($plan);
        }

        return $this->pages->respond(
            '문제 번호 변경',
            $content,
            'problems.change_id',
            $request
        );
    }

    private function inputForm(
        HttpRequest $request,
        string $mode,
        string $pairsText
    ): string {
        return '<section class="ojp-section"><header class="ojp-section-heading"><div>'
            . '<h2>변경할 문제 집합</h2><p>한 번에 최대 200개 문제를 검사합니다.</p>'
            . '</div></header><form class="ojp-form-stack" method="get"'
            . ' action="/oj-console/problems/change-id">'
            . '<label class="ojp-field"><span class="ojp-label">입력 방식</span>'
            . '<select class="ojp-select" name="input_mode">'
            . '<option value="range"' . ($mode === 'range' ? ' selected' : '')
            . '>연속 범위</option><option value="pairs"'
            . ($mode === 'pairs' ? ' selected' : '') . '>임의 번호 쌍</option>'
            . '</select></label>'
            . '<div class="ojp-form-grid"><label class="ojp-field"><span class="ojp-label">'
            . '현재 시작 번호</span><input class="ojp-input" type="number" min="1"'
            . ' name="source_start" value="'
            . $this->html->escape($request->queryString('source_start', '1000'))
            . '"></label><label class="ojp-field"><span class="ojp-label">현재 끝 번호'
            . '</span><input class="ojp-input" type="number" min="1" name="source_end" value="'
            . $this->html->escape($request->queryString('source_end', '1002'))
            . '"></label><label class="ojp-field"><span class="ojp-label">새 시작 번호'
            . '</span><input class="ojp-input" type="number" min="1" name="target_start" value="'
            . $this->html->escape($request->queryString('target_start', '2000'))
            . '"></label></div>'
            . '<label class="ojp-field"><span class="ojp-label">임의 번호 쌍</span>'
            . '<textarea class="ojp-textarea" name="pairs" rows="6"'
            . ' placeholder="1000,2000&#10;1005,2001">'
            . $this->html->escape($pairsText) . '</textarea>'
            . '<span class="ojp-field-help">한 줄에 현재 번호와 새 번호를 쉼표 또는 탭으로 구분합니다.</span>'
            . '</label><button class="ojp-button ojp-button--primary" type="submit"'
            . ' name="preview" value="1">영향 확인</button></form></section>';
    }

    private function preview(ProblemNumberChangePlan $plan): string
    {
        $mappingRows = '';
        foreach ($plan->requested()->pairs() as $source => $target) {
            $problem = $this->problems->find(new ProblemId($source));
            $mappingRows .= '<tr><td>' . $source . '</td><td>'
                . $this->html->escape($problem?->title() ?? '문제 없음')
                . '</td><td>' . $target . '</td><td>'
                . $plan->temporary()->pairs()[$source]
                . '</td></tr>';
        }
        $impactRows = '';
        foreach ($plan->impactCounts() as $participant => $counts) {
            foreach ($counts as $target => $count) {
                $impactRows .= '<tr><td>' . $this->participantLabel($participant)
                    . '</td><td><code>' . $this->html->escape($target)
                    . '</code></td><td>' . $count . '</td></tr>';
            }
        }

        return '<section class="ojp-section"><header class="ojp-section-heading"><div>'
            . '<h2>영향 확인 결과</h2><p>모든 번호는 임시 번호를 거쳐 두 단계로 전환됩니다.</p>'
            . '</div><span class="ojp-status-badge ojp-status-badge--warning">'
            . $plan->requested()->count() . '개 매핑</span></header>'
            . '<div class="ojp-split-layout"><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>현재 번호</th><th>문제</th><th>새 번호</th><th>임시 번호</th>'
            . '</tr></thead><tbody>' . $mappingRows . '</tbody></table></div>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>저장소</th><th>참조</th><th>영향</th></tr></thead><tbody>'
            . $impactRows . '</tbody></table></div></div>'
            . '<div class="ojp-notice ojp-notice--warning"><strong>실행 전 재검증</strong>'
            . '<p>참조 수·권한·채점 대기·대상 번호가 달라지면 원본을 바꾸지 않고 확인 필요로 종료합니다.</p>'
            . '</div><form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_change_id')
            . '<input type="hidden" name="mapping_json" value="'
            . $this->html->escape(
                $this->service->serializeMapping($plan->requested())
            ) . '">'
            . '<input type="hidden" name="plan_hash" value="' . $plan->hash() . '">'
            . '<button class="ojp-button ojp-button--danger" type="submit">'
            . '확인한 계획으로 작업 등록</button></form></section>';
    }

    private function positiveQueryInt(HttpRequest $request, string $key): int
    {
        $value = $request->queryString($key);

        return ctype_digit($value) ? (int) $value : 0;
    }

    private function participantLabel(string $participant): string
    {
        return match ($participant) {
            'judge' => 'Judge DB',
            'platform' => '플랫폼 DB',
            'wordpress' => 'WordPress',
            'files' => '문제 파일',
            default => $this->html->escape($participant),
        };
    }
}
