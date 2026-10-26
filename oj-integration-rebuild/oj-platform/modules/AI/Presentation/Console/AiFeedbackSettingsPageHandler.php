<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AI\Application\AiFeedbackSettingsService;
use CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackGuide;
use CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;

final class AiFeedbackSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly AiFeedbackSettingsService $settings,
        private readonly FeedbackGuideRepository $guides,
        private readonly SubmissionFeedbackJobService $feedback,
        private readonly AiTaskRouter $ai,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly AiHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $current = $this->settings->current();
        $guides = $this->guides->all();
        $selectors = '';
        foreach ([
            'insufficient' => ['자료 부족 기본 지침', '수준을 추정하지 않고 결과 이해를 돕습니다.'],
            'beginner' => ['초급 지침', '확인할 위치와 행동을 구체적으로 좁힙니다.'],
            'intermediate' => ['중급 지침', '변수 값과 실행 흐름을 추적하게 합니다.'],
            'advanced' => ['고급 지침', '경계값과 복잡도를 스스로 검토하게 합니다.'],
        ] as $level => [$label, $description]) {
            $selectors .= '<div class="ojp-setting-row"><div><strong>'
                . $label . '</strong><span>' . $description
                . '</span></div><label class="ojp-field"><span class="ojp-label">'
                . '사용 지침</span><select class="ojp-select" name="guide_'
                . $level . '">' . $this->options(
                    $guides,
                    $level,
                    $current->guideKey($level)
                ) . '</select></label></div>';
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출 관리</p><h1>AI 피드백</h1>'
            . '<p>제출 결과와 학습 수준에 따라 풀이 도움 정책을 관리합니다.</p>'
            . '</div></header><div class="ojp-layout-with-aside"><section>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_ai_feedback_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '"><section class="ojp-section">'
            . '<div class="ojp-section-heading"><div><h2>사용 설정</h2>'
            . '<p>기능을 꺼도 기존 피드백과 학습 수준 데이터는 보존합니다.</p>'
            . '</div></div><label class="ojp-check"><input type="checkbox"'
            . ' name="enabled" value="1"'
            . ($current->isEnabled() ? ' checked' : '')
            . '><span>채점 완료 뒤 명시적 AI 피드백 요청 허용</span></label>'
            . '<label class="ojp-field"><span class="ojp-label">요청 가능 결과</span>'
            . '<select class="ojp-select" name="result_scope"><option value="all"'
            . ($current->resultScope() === 'all' ? ' selected' : '')
            . '>정답·오답·오류 모두</option><option value="incorrect"'
            . ($current->resultScope() === 'incorrect' ? ' selected' : '')
            . '>오답·오류만</option></select></label></section>'
            . '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>수준별 답변 정책</h2><p>수준마다 활성 지침 하나를 선택합니다.</p>'
            . '</div></div>' . $selectors
            . '<div class="ojp-form-actions"><button class="ojp-button '
            . 'ojp-button--primary" type="submit">AI 피드백 설정 저장</button>'
            . '</div></section></form></section><aside class="ojp-section">'
            . '<h2>운영 상태</h2><dl class="ojp-description-grid">'
            . '<div><dt>공통 AI 연결</dt><dd><span class="ojp-badge ojp-badge--'
            . ($this->ai->canRoute('submission_feedback', true)
                ? 'success">사용 가능'
                : 'warning">확인 필요')
            . '</span></dd></div><div><dt>활성 답변 지침</dt><dd>'
            . count($guides) . '개</dd></div><div><dt>최근 생성 결과</dt><dd>'
            . count($this->feedback->recent(100)) . '건</dd></div></dl>'
            . '<p class="ojp-field-help">공급자와 모델 연결은 운영의 공통 AI 설정에서 관리합니다.</p>'
            . '</aside></div>';

        return $this->pages->respond(
            'AI 피드백',
            $content,
            'submissions.ai_feedback',
            $request
        );
    }

    /**
     * @param list<FeedbackGuide> $guides
     */
    private function options(
        array $guides,
        string $level,
        string $selected
    ): string {
        $options = '';
        foreach ($guides as $guide) {
            if ($guide->level() !== $level || ! $guide->isActive()) {
                continue;
            }
            $options .= '<option value="' . $this->html->escape($guide->key())
                . '"' . ($guide->key() === $selected ? ' selected' : '')
                . '>' . $this->html->escape($guide->name()) . '</option>';
        }

        return $options;
    }
}
