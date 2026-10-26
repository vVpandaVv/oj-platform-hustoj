<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;

final class FeedbackGuideListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly FeedbackGuideRepository $guides,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly AiHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $rows = '';
        foreach ($this->guides->all(true) as $guide) {
            $state = $guide->isBuiltIn()
                ? '<span class="ojp-badge ojp-badge--success">기본</span>'
                : ($guide->isActive()
                    ? '<span class="ojp-badge ojp-badge--success">활성</span>'
                    : '<span class="ojp-badge">비활성</span>');
            $actions = '<a class="ojp-button ojp-button--small" href="'
                . '/oj-console/submissions/ai-guides/'
                . rawurlencode($guide->key()) . '/preview">미리보기·검증</a>'
                . $this->operation(
                    'oj_ai_persona_duplicate',
                    $guide->key(),
                    '복제'
                );
            if (! $guide->isBuiltIn()) {
                $actions = '<a class="ojp-button ojp-button--small" href="'
                    . '/oj-console/submissions/ai-guides/'
                    . rawurlencode($guide->key()) . '">수정</a>'
                    . '<a class="ojp-button ojp-button--small" href="'
                    . '/oj-console/submissions/ai-guides/'
                    . rawurlencode($guide->key()) . '/revisions">변경 기록</a>'
                    . $actions
                    . $this->operation(
                        $guide->isActive()
                            ? 'oj_ai_persona_delete'
                            : 'oj_ai_persona_restore',
                        $guide->key(),
                        $guide->isActive() ? '보관' : '활성화'
                    );
            }
            $rows .= '<tr><td><strong>'
                . $this->html->escape($guide->name())
                . '</strong><br><small>'
                . $this->html->escape($guide->purpose())
                . '</small></td><td>' . $this->level($guide->level())
                . '</td><td>' . $state . '</td><td>v'
                . $guide->revision() . '</td><td>'
                . $this->html->escape($this->html->date($guide->updatedAt()))
                . '</td><td><div class="ojp-inline-actions">' . $actions
                . '</div></td></tr>';
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">수준별 답변 정책</p><h1>AI 답변 지침</h1>'
            . '<p>풀이 도움의 방향과 적용 수준을 관리합니다.</p></div>'
            . '<a class="ojp-button ojp-button--primary"'
            . ' href="/oj-console/submissions/ai-guides/new">지침 추가</a>'
            . '</header><section class="ojp-section"><div class="ojp-table-wrap">'
            . '<table class="ojp-table"><thead><tr><th>지침</th><th>적용 수준</th>'
            . '<th>상태</th><th>개정</th><th>수정 시각</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        $presets = '<section class="ojp-section"><h2>수업 연구용 지침 만들기</h2><p>기존 지침을 보존하면서 공통 역할과 수준별 교수 전략을 갖춘 새 지침을 만듭니다. 저장 후 미리보기·검증에서 응답을 비교하고 AI 피드백 설정에서 사용할 지침을 선택하세요.</p><div class="ojp-inline-actions">';
        foreach (\CodingPanda\OjPlatform\Modules\AI\Domain\ClassroomFeedbackPrompts::LEVELS as $key=>$label) {
            $presets .= '<a class="ojp-button" href="/oj-console/submissions/ai-guides/new?preset='.$key.'">'.$label.' 연구 지침 추가</a>';
        }
        $content .= $presets.'</div></section>';

        return $this->pages->respond(
            'AI 답변 지침',
            $content,
            'submissions.ai_guides',
            $request
        );
    }

    private function operation(string $action, string $key, string $label): string
    {
        return '<form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '">' . $this->security->hiddenFields($action)
            . '<input type="hidden" name="guide_key" value="'
            . $this->html->escape($key)
            . '"><button class="ojp-button ojp-button--small" type="submit">'
            . $label . '</button></form>';
    }

    private function level(string $level): string
    {
        return match ($level) {
            'insufficient' => '자료 부족',
            'beginner' => '초급',
            'intermediate' => '중급',
            'advanced' => '고급',
            default => '확인 필요',
        };
    }
}
