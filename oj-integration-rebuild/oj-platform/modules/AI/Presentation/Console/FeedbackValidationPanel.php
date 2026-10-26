<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackValidationService;
use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackGuide;
use CodingPanda\OjPlatform\Modules\AI\Domain\ClassroomFeedbackPrompts as Prompts;
use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackValidationCases as Cases;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Action\FeedbackValidationActionHandler as Action;
use CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;

final class FeedbackValidationPanel
{
    public function __construct(private readonly FeedbackValidationService $validation,private readonly ActionUrlProvider $urls,private readonly ActionSecurity $security,private readonly AiHtml $html) {}
    public function render(FeedbackGuide $guide, int $page = 1, string $selectedId = ''): string
    {
        $e=fn($s)=>$this->html->escape((string)$s);
        $listing = $this->validation->page($guide->key(), $page);
        $records = $listing['records'];
        $selected = $this->validation->find($guide->key(), $selectedId);
        $options='';
        foreach(Cases::all() as $key=>$case) {$options.='<option value="'.$e($key).'">'.$e($case['title']).' · '.($case['split']==='development'?'개발용':'확인용').'</option>';}
        $body='<section class="ojp-section" id="validation"><div class="ojp-section-heading"><div><p class="ojp-eyebrow">프롬프트 사전 검증</p><h2>같은 코드로 기존·신규 응답 비교</h2><p>학생 이력에 남지 않는 합성 사례만 사용합니다. 버튼 한 번에 실제 AI 요청 1건이 실행되며 연결된 모델의 사용량이 발생합니다.</p></div></div>'
            .'<p><a href="/oj-console/settings/ai-routing">AI 작업 연결</a>에서 「피드백 프롬프트 검증 (합성 코드)」에 검증용 모델을 연결하세요. 실제 학생 제출 피드백의 모델·코드 처리 승인은 변경되지 않습니다.</p>'
            .'<p>비교 기준: '.$e(Prompts::BASELINES[$guide->level()]).' v1 · 현재 지침: '.$e($guide->name()).' v'.$guide->revision().'</p>'
            .$this->form($guide->key()).'<input type="hidden" name="run_token" value="'.bin2hex(random_bytes(16)).'">'
            .'<div class="ojp-form-grid"><label class="ojp-field"><span class="ojp-label">검증 사례</span><select class="ojp-select" name="case_key">'.$options.'</select></label>'
            .'<label class="ojp-field"><span class="ojp-label">비교할 지침</span><select class="ojp-select" name="variant"><option value="baseline">기존 한 줄 지침</option><option value="candidate">현재 지침</option></select></label></div>'
            .'<div class="ojp-form-actions"><button class="ojp-button ojp-button--primary" type="submit">선택 조건으로 AI 검증 실행</button></div></form>'
            .'<p>개발용 사례로 수정한 뒤 확인용 사례를 평가하세요. 자동 검사는 형식·도움 개수·길이·수준명 노출만 확인합니다. 내용 정확성, 수준 적합성, 정답의 의미상 노출 여부는 교사 검토가 필요합니다.</p>'
            .$this->form($guide->key()).'<input type="hidden" name="operation" value="export"><button class="ojp-button" type="submit">검증 원자료 JSON 내려받기</button></form>'
            .'<h3>실행 기록 '.$listing['total'].'건</h3>';
        foreach($records as $record) {
            $checks=$record['checks'];$pass=$checks!==[]&&!in_array(false,$checks,true);
            $body.='<details class="ojp-section" data-validation-id="'.$e($record['id']).'"><summary>'.$e(Cases::get($record['case_key'])['title']).' · '.($record['variant']==='baseline'?'기존':'현재').' · '.$e($record['status']).' · '.($pass?'자동 항목 통과':'자동 항목 확인 필요').'</summary>'
                .'<p>'.$e($this->html->date((string) ($record['started_at'] ?? ''))).' · 모델 '.$e($record['model']['model']??'미기록').' · '.$e($record['elapsed_ms']??'-').'ms · 교사 평가 미실시</p>';
            $body .= '<a class="ojp-button" href="?validation_page=' . $listing['page']
                . '&amp;validation_record=' . $e($record['id']) . '#validation-response">응답 보기</a>';
            $body.='<p>'.$e($record['validation_error']??$record['error']??'').'</p><pre class="ojp-code-block">'.$e(json_encode($checks,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)).'</pre></details>';
        }
        $body .= '<nav class="ojp-pagination" aria-label="검증 기록 페이지">';
        if ($listing['page'] > 1) {
            $body .= '<a class="ojp-button" href="?validation_page=' . ($listing['page'] - 1) . '#validation">이전</a>';
        }
        $body .= '<span>' . $listing['page'] . ' / ' . $listing['pages'] . '</span>';
        if ($listing['page'] < $listing['pages']) {
            $body .= '<a class="ojp-button" href="?validation_page=' . ($listing['page'] + 1) . '#validation">다음</a>';
        }
        $body .= '</nav>';
        if ($selected !== null) {
            $body .= '<article class="ojp-section" id="validation-response"><h3>선택한 응답</h3>';
            foreach (['result_understanding' => '결과 이해', 'step_hints' => '풀이 도움', 'review_question' => '자기 점검'] as $field => $label) {
                $value = $selected['output'][$field] ?? '';
                $text = is_array($value) ? implode("\n", array_filter($value, 'is_string')) : (is_string($value) ? $value : '');
                $body .= '<h4>' . $label . '</h4><p>' . nl2br($e($text)) . '</p>';
            }
            $body .= '<p>' . $e($selected['validation_error'] ?? $selected['error'] ?? '') . '</p></article>';
        }
        $body .= '</section>';
        return $body;
    }
    private function form(string $key): string
    {
        return '<form class="ojp-form-stack" method="post" action="'.$this->html->escape($this->urls->actionUrl()).'">'.$this->security->hiddenFields(Action::ACTION).'<input type="hidden" name="guide_key" value="'.$this->html->escape($key).'">';
    }
}
