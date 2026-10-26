<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;

final class SubmissionSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SettingsManager $settings,
        private readonly SubmissionSettingsProvider $submissionSettings,
        private readonly SubmissionLanguageCatalog $languages,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SubmissionHtml $html,
        private readonly ?\CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionSupport $executionSupport = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $submission = $this->settings->read('submission', []);
        $rules = $this->settings->read('submission_rules', []);
        $display = $this->settings->read('submission_display', []);
        $current = $this->submissionSettings->current();
        $requestLimit = $this->submissionSettings->requestLimit();
        $displayValues = $this->submissionSettings->display();
        $languageChecks = '';
        foreach ($this->languages->all() as $id => $label) {
            $enabled = ($current->disabledLanguageMask() & (1 << $id)) === 0;
            $languageChecks .= '<label class="ojp-check">'
                . '<input type="checkbox" name="enabled_language_ids[]" value="'
                . $id . '"' . ($enabled ? ' checked' : '') . '><span>'
                . $this->html->escape($label) . '</span></label>';
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출 관리</p><h1>제출 설정</h1>'
            . '<p>허용 언어, 제출 제한과 결과 목록 표시 기준을 관리합니다.</p>'
            . '</div></header>'
            . '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>언어와 결과 갱신</h2>'
            . '<p>Blockly 제출은 유효한 작업공간일 때 Python 3을 자동 허용합니다.</p>'
            . '</div></div><form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_submission_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $submission->revision() . '"><fieldset class="ojp-fieldset">'
            . '<legend>직접 제출 허용 언어</legend>'
            . '<div class="ojp-check-grid">' . $languageChecks . '</div></fieldset>'
            . '<div class="ojp-form-grid"><label class="ojp-field">'
            . '<span class="ojp-label">목록 한 페이지 수</span>'
            . '<input class="ojp-input" type="number" min="10" max="100"'
            . ' name="page_size" value="' . $current->pageSize() . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">자동 갱신 최대 시간(초)</span>'
            . '<input class="ojp-input" type="number" min="30" max="600"'
            . ' name="maximum_poll_seconds" value="'
            . $current->maximumPollSeconds() . '"></label></div>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '언어 설정 저장</button></form></section>'
            . '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>제출 제한</h2>'
            . '<p>채점 대기열에 제출을 등록하기 전에 검사합니다.</p></div></div>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_submission_settings')
            . '<input type="hidden" name="settings_section" value="rules">'
            . '<input type="hidden" name="expected_revision" value="'
            . $rules->revision() . '"><div class="ojp-form-grid">'
            . '<label class="ojp-field"><span class="ojp-label">최대 코드 크기(KB)</span>'
            . '<input class="ojp-input" type="number" min="1" max="60"'
            . ' name="max_source_kb" value="'
            . (int) floor($current->maxSourceBytes() / 1024) . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">최소 제출 간격(초)</span>'
            . '<input class="ojp-input" type="number" min="0" max="600"'
            . ' name="cooldown_seconds" value="'
            . $current->cooldownSeconds() . '"></label></div>'
            . '<label class="ojp-check"><input type="checkbox"'
            . ' name="allow_resubmit_after_accept" value="1"'
            . ($current->allowResubmitAfterAccept() ? ' checked' : '')
            . '><span>맞힌 문제 재제출 허용</span></label>'
            . '<input type="hidden" name="clipboard_settings_present" value="1">'
            . '<label class="ojp-check"><input type="checkbox" name="allow_code_copy" value="1"'
            . ($current->allowCodeCopy() ? ' checked' : '') . '><span>제출 코드 복사·잘라내기 허용</span></label>'
            . '<label class="ojp-check"><input type="checkbox" name="allow_code_paste" value="1"'
            . ($current->allowCodePaste() ? ' checked' : '') . '><span>제출 코드 붙여넣기 허용</span></label>'
            . '<label class="ojp-check"><input type="checkbox" name="request_limit_enabled" value="1"'
            . ($requestLimit['enabled'] ? ' checked' : '')
            . '><span>사용자별 제출 요청 횟수 제한</span></label>'
            . '<div class="ojp-form-grid"><label class="ojp-field"><span class="ojp-label">허용 요청 횟수</span>'
            . '<input class="ojp-input" type="number" name="request_limit_count" min="1" max="1000" value="'
            . $requestLimit['count'] . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">요청 카운터 만료 시간(초)</span>'
            . '<input class="ojp-input" type="number" name="request_limit_seconds" min="1" max="3600" value="'
            . $requestLimit['seconds'] . '"></label></div>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '제출 제한 저장</button></form></section>'
            . '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>목록 표시</h2>'
            . '<p>사용자와 관리자 제출 목록에 같은 기준을 적용합니다.</p></div></div>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_submission_display_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $display->revision() . '">'
            . $this->check('show_language', '언어 표시', $displayValues['show_language'])
            . $this->check('show_time', '실행 시간 표시', $displayValues['show_time'])
            . $this->check('show_memory', '메모리 표시', $displayValues['show_memory'])
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '표시 설정 저장</button></form></section>';

        $content .= $this->executionControls();
        return $this->pages->respond(
            '제출 설정',
            $content,
            'submissions.settings',
            $request
        );
    }

    private function check(string $name, string $label, bool $checked): string
    {
        return '<label class="ojp-check"><input type="checkbox" name="'
            . $name . '" value="1"' . ($checked ? ' checked' : '')
            . '><span>' . $label . '</span></label>';
    }

    private function executionControls():string
    {
        if($this->executionSupport===null){return '';}
        try{
            $policy=$this->executionSupport->policy();$matrix=$this->executionSupport->matrix();$rows='';
            $labels=['untested'=>'미검사','disabled'=>'설정에서 차단','pending'=>'검사 중','passed'=>'실행 통과','failed'=>'검사 실패'];
            foreach($matrix as $row){
                $form='';
                if($row['configured']){
                    $form='<form method="post" action="'.$this->html->escape($this->actionUrls->actionUrl()).'">'
                        .$this->security->hiddenFields('oj_private_execution_control').'<input type="hidden" name="operation" value="probe">'
                        .'<input type="hidden" name="language" value="'.$row['language'].'"><input type="hidden" name="restart" value="'.($row['state']==='pending'?'0':'1').'">'
                        .'<button class="ojp-button" type="submit">'.($row['state']==='pending'?'결과 조회':'실행 검사').'</button></form>';
                }
                $rows.='<tr><td>'.$row['language'].'</td><td>'.$this->html->escape($labels[$row['state']]??$row['state']).'</td><td>'
                    .$this->html->escape($row['checked_at']).'</td><td>'.$this->html->escape($row['message']).'</td><td>'.$form.'</td></tr>';
            }
            try{$status=$this->executionSupport->status();$statusText='임시 기록 '.(int)($status['total']??0).'건 · 대기/실행 '.(int)($status['pending']??0).'건 · 5분 초과 '.(int)($status['stalled']??0).'건';}
            catch(\Throwable){$statusText='채점 연결을 조회하지 못했습니다. 연결 설정과 채점 데몬을 확인해 주세요.';}
            $limits='';
            foreach(['global_pending'=>'사이트 전체 동시 실행','actor_pending'=>'사용자별 동시 실행','actor_hourly'=>'사용자별 시간당 새 실행'] as $key=>$label){
                $limits.='<label class="ojp-field"><span class="ojp-label">'.$label.'</span><input class="ojp-input" type="number" min="1" max="'.($key==='actor_hourly'?'1000':($key==='actor_pending'?'20':'100')).'" name="'.$key.'" value="'.(int)$policy[$key].'"></label>';
            }
            return '<section class="ojp-section"><div class="ojp-section-heading"><div><h2>예제·실습 실행 지원</h2>'
                .'<p>허용 언어라도 실제 채점기 검사에 통과해야 AI 예제 검증과 학습자 실습에 사용할 수 있습니다. 채점 연결이나 허용 언어 설정을 바꾸면 다시 검사합니다.</p></div></div>'
                .'<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr><th>언어</th><th>상태</th><th>확인 시각(UTC)</th><th>안내</th><th>검사</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
                .'<p>'.$this->html->escape($statusText).'</p><p>5분을 넘긴 실행은 채점 서버를 확인하세요. 진행 중인 작업은 고아 로그를 막기 위해 자동 삭제하지 않습니다. 완료 기록은 독립 시간별 정리에서 24시간 뒤 제거합니다.</p>'
                .'<form class="ojp-form-stack" method="post" action="'.$this->html->escape($this->actionUrls->actionUrl()).'">'
                .$this->security->hiddenFields('oj_private_execution_control').'<input type="hidden" name="operation" value="limits"><input type="hidden" name="expected_revision" value="'.(int)$policy['revision'].'">'
                .'<div class="ojp-form-grid">'.$limits.'</div><button class="ojp-button" type="submit">실습 실행 한도 저장</button></form>'
                .'<form method="post" action="'.$this->html->escape($this->actionUrls->actionUrl()).'">'.$this->security->hiddenFields('oj_private_execution_control')
                .'<input type="hidden" name="operation" value="cleanup"><button class="ojp-button" type="submit">완료된 임시 기록 정리</button></form></section>';
        }catch(\Throwable){return '<section class="ojp-section"><h2>예제·실습 실행 지원</h2><p>실행 지원 설정을 읽지 못했습니다. 플랫폼 설정과 저장소 갱신을 확인해 주세요.</p></section>';}
    }
}
