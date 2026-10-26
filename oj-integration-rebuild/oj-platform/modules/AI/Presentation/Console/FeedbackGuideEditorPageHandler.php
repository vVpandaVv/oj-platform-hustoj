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

final class FeedbackGuideEditorPageHandler implements RouteHandler
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
        $key = trim((string) ($route->parameters()['prompt_file'] ?? ''));
        $guide = $key === '' ? null : $this->guides->find($key);
        if ($guide?->isBuiltIn()) {
            return $this->pages->respond(
                'AI 답변 지침',
                '<header class="ojp-page-header"><div><h1>기본 지침은 직접 수정할 수 없습니다.</h1>'
                    . '<p>목록에서 복제한 뒤 새 지침을 수정해 주세요.</p></div></header>'
                    . '<a class="ojp-button" href="/oj-console/submissions/ai-guides">'
                    . '지침 목록</a>',
                'submissions.ai_guides',
                $request,
                409
            );
        }
        if ($key !== '' && $guide === null) {
            return $this->pages->respond(
                'AI 답변 지침',
                '<header class="ojp-page-header"><div><h1>지침을 찾을 수 없습니다.</h1>'
                    . '</div></header>',
                'submissions.ai_guides',
                $request,
                404
            );
        }
        $level = $guide?->level() ?? 'insufficient';
        $preset = $request->queryString('preset');
        $pack = \CodingPanda\OjPlatform\Modules\AI\Domain\ClassroomFeedbackPrompts::class;
        $usePreset = $guide === null && isset($pack::LEVELS[$preset]);
        if ($usePreset) { $level = $preset; }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">AI 답변 지침</p><h1>'
            . ($guide === null ? '지침 추가' : '지침 수정')
            . '</h1><p>저장할 때마다 이전 내용을 보존한 새 개정을 만듭니다.</p>'
            . '</div></header><section class="ojp-section"><form '
            . 'class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_ai_persona_save')
            . '<input type="hidden" name="guide_key" value="'
            . $this->html->escape($guide?->key() ?? '') . '">'
            . '<div class="ojp-form-grid"><label class="ojp-field">'
            . '<span class="ojp-label">지침 이름</span><input class="ojp-input"'
            . ' name="name" maxlength="100" required value="'
            . $this->html->escape($guide?->name() ?? ($usePreset ? $pack::name($preset) : '')) . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">적용 수준</span>'
            . '<select class="ojp-select" name="level">'
            . $this->levelOptions($level) . '</select></label>'
            . '<label class="ojp-field ojp-field--wide"><span class="ojp-label">'
            . '사용 목적</span><input class="ojp-input" name="purpose"'
            . ' maxlength="500" value="'
            . $this->html->escape($guide?->purpose() ?? ($usePreset ? '중학교 정보 수업의 수준별 사고 지원. '.$pack::VERSION : '')) . '"></label>'
            . '<label class="ojp-field ojp-field--wide"><span class="ojp-label">'
            . '답변 지침</span><textarea class="ojp-textarea" name="instructions"'
            . ' rows="12" maxlength="10000" required>'
            . $this->html->escape($guide?->instructions() ?? ($usePreset ? $pack::instructions($preset) : ''))
            . '</textarea></label></div><p class="ojp-field-help">'
            . '정답 코드 전체, 개인정보 요청과 실행 가능한 HTML은 저장할 수 없습니다.</p>'
            . '<div class="ojp-form-actions"><a class="ojp-button"'
            . ' href="/oj-console/submissions/ai-guides">취소</a>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '지침 저장</button></div></form></section>';

        return $this->pages->respond(
            $guide === null ? 'AI 답변 지침 추가' : 'AI 답변 지침 수정',
            $content,
            'submissions.ai_guides',
            $request
        );
    }

    private function levelOptions(string $selected): string
    {
        $options = '';
        foreach ([
            'insufficient' => '자료 부족',
            'beginner' => '초급',
            'intermediate' => '중급',
            'advanced' => '고급',
        ] as $value => $label) {
            $options .= '<option value="' . $value . '"'
                . ($value === $selected ? ' selected' : '')
                . '>' . $label . '</option>';
        }

        return $options;
    }
}
