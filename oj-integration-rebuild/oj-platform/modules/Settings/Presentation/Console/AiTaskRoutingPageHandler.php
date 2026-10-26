<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskRoutingService;

final class AiTaskRoutingPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly AiProviderCatalog $catalog,
        private readonly AiProviderSettingsService $providers,
        private readonly AiTaskRoutingService $routing,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->routing->snapshot();
        $assignments = $this->routing->assignments();
        $rows = '';
        foreach ($this->catalog->allTaskKeys() as $taskType) {
            $assignment = $assignments[$taskType] ?? null;
            $primary = $assignment?->primaryProfileKey() ?? '';
            $fallback = $assignment?->fallbackProfileKey() ?? '';
            $options = '<option value="">배정 안 함</option>';
            $fallbackOptions = '<option value="">없음</option>';
            foreach ($this->providers->profiles() as $profile) {
                if (! $profile->allowsTask($taskType)) {
                    continue;
                }
                $option = '<option value="' . OperationsHtml::escape($profile->key()) . '"';
                $options .= $option . OperationsHtml::selected($profile->key(), $primary)
                    . '>' . OperationsHtml::escape($profile->name()) . '</option>';
                $fallbackOptions .= $option
                    . OperationsHtml::selected($profile->key(), $fallback)
                    . '>' . OperationsHtml::escape($profile->name()) . '</option>';
            }
            $badge = $this->catalog->isAddonTask($taskType)
                ? ' <span class="ojp-badge">애드온</span>'
                : '';
            $rows .= '<tr><td><strong>'
                . OperationsHtml::escape($this->catalog->taskLabel($taskType))
                . '</strong>' . $badge . '<input type="hidden" name="assignments['
                . OperationsHtml::escape($taskType) . '][task_type]" value="'
                . OperationsHtml::escape($taskType) . '"></td><td><select class="ojp-select"'
                . ' name="assignments[' . OperationsHtml::escape($taskType)
                . '][primary_profile_key]">' . $options . '</select></td><td>'
                . '<select class="ojp-select" name="assignments['
                . OperationsHtml::escape($taskType) . '][fallback_profile_key]">'
                . $fallbackOptions . '</select></td><td><input class="ojp-input" type="number"'
                . ' min="128" max="32768" name="assignments['
                . OperationsHtml::escape($taskType) . '][max_output_tokens]" value="'
                . ($assignment?->maxOutputTokens() ?? 1200) . '"></td><td>'
                . '<select class="ojp-select" name="assignments['
                . OperationsHtml::escape($taskType) . '][fallback_policy]">'
                . '<option value="none"'
                . OperationsHtml::selected($assignment?->fallbackPolicy() ?? 'none', 'none')
                . '>대체 없음</option><option value="transient_only"'
                . OperationsHtml::selected(
                    $assignment?->fallbackPolicy() ?? 'none',
                    'transient_only'
                ) . '>일시 오류만 대체</option></select></td><td>'
                . '<details><summary>Ollama 설정</summary><label>Temperature'
                . '<input class="ojp-input" type="number" min="0" max="2" step="0.05"'
                . ' placeholder="자동" name="assignments[' . OperationsHtml::escape($taskType)
                . '][ollama_options][temperature]" value="'
                . OperationsHtml::escape((string) ($assignment?->ollamaOptions()['temperature'] ?? ''))
                . '"></label><label>문맥 토큰'
                . '<input class="ojp-input" type="number" min="2048" max="1048576" step="1"'
                . ' placeholder="서버 기본값" name="assignments[' . OperationsHtml::escape($taskType)
                . '][ollama_options][num_ctx]" value="'
                . OperationsHtml::escape((string) ($assignment?->ollamaOptions()['num_ctx'] ?? ''))
                . '"></label></details></td></tr>';
        }
        $content = OperationsHtml::header(
            '공통 AI 기반',
            '작업별 AI 배정',
            '검사를 통과한 프로필만 작업별 주 연결과 대체 연결로 선택할 수 있습니다.'
        )
            . '<section class="ojp-section"><form method="post" action="'
            . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_ai_task_routing')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '"><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>작업</th><th>주 프로필</th><th>대체 프로필</th>'
            . '<th>출력 토큰</th><th>대체 정책</th><th>생성 설정</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div><div class="ojp-command-group">'
            . '<button class="ojp-button ojp-button--primary" type="submit">작업 배정 저장</button>'
            . '</div></form></section>';

        return $this->pages->respond(
            '작업별 AI 배정',
            $content,
            'operations.ai_routing',
            $request
        );
    }
}
