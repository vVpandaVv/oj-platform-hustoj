<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjResearch\Domain\{ClassroomSettings as S,PilotProtocol as P};

final class ClassroomSettingsFields
{
    public static function consent(array $v):string
    {
        $h='<section class="ojp-research-card"><h2>참여 동의와 자료 보관</h2><p>동의서를 올리는 곳이 아닙니다. 실제로 사용할 확인 방법과 보관 계획을 선택하세요. 학생별 동의 확인은 다음 화면에서 합니다.</p>';
        $h.=self::choice($v,'consent_method').'<details data-oj-research-modal><summary>자료 보관 계획 선택 · 용어 안내</summary><p>보관 장소·담당자·기간·폐기 방법을 정하는 항목입니다. 선택한 계획을 보고서에 기록하며, 파일을 옮기거나 자동 삭제하지는 않습니다. 기간은 학교·소속 기관에서 정한 기준에 맞게 선택하세요.</p><div class="ojp-form-grid">';
        foreach(['storage','access','retention','disposal','withdrawal']as$key){$h.=self::choice($v,$key);}
        $h.='</div>'.self::input('동의서 이름 또는 관리번호 · 선택','setup_reference',$v['reference'],'text','예: 2026-알고리즘수업-동의서 또는 연구 참여 동의서 v1').'<p class="ojp-field-help">학교에서 쓰는 문서 번호나 파일 이름을 적으면 됩니다. 관리번호가 없으면 비워 두세요. 학생 이름이나 동의서 내용은 입력하지 않습니다.</p>';
        $h.=self::area('보관·철회 계획의 구체적인 설명 · 선택 / 기타 선택 시 필수','setup_storage_detail',$v['storage_detail'],'예: 종이 동의서는 잠금 서랍에, 분석 자료는 접근 제한 폴더에 보관할 계획입니다. 철회 요청은 담당 교사가 접수합니다. 실제 계획에 맞게 수정하세요.');
        $h.=self::area('기존 기록 또는 직접 작성한 절차','note_consent',$v['notes']['consent'],'직접 작성 방식을 선택했다면 동의 확인 방법, 보관 장소·담당자·기간, 폐기 및 철회 방법을 적으세요.');
        return $h.'<p class="ojp-field-help">‘직접 절차 작성’은 위 기록을 그대로 사용합니다. 미정 상태로 설정 저장은 가능하지만, 학생에게 시작 설문을 열기 전에는 절차를 정해야 합니다. 이 화면의 선택은 참여 동의가 완료되었다는 표시가 아닙니다.</p></details></section>';
    }

    public static function context(array $v):string
    {
        $h='<h3>수업 환경과 운영 계획</h3><p>현재 연구에 맞춘 예시입니다. 실제 조건으로 바꾸세요. 선택한 내용은 보고서 문장으로 정리됩니다.</p><div class="ojp-form-grid">';
        foreach(['grade','experience','language','device','duration','inclusion']as$key){$h.=self::choice($v,$key);}
        $h.=self::input('시작일 · 선택','setup_start_date',$v['start_date'],'date').self::input('종료일 · 선택','setup_end_date',$v['end_date'],'date').'</div>';
        $h.=self::checks('기본 알고리즘 학습 내용','topics',S::TOPICS,$v['topics']).'<h3>두 집단에 동일하게 적용할 수업 규칙</h3><div class="ojp-form-grid">';
        foreach(['teacher_help','explanation','external_ai']as$key){$h.=self::choice($v,$key);}
        $h.='</div><p class="ojp-field-help">이 세 항목은 교사가 지킬 운영 계획입니다. 해설 공개와 외부 AI 접속을 시스템이 자동 차단하는 설정은 아닙니다. 학생·활동별 AI 허용과 한도는 위 AI 조건으로 제어합니다.</p>';
        $h.=self::checks('보고서에서 중점적으로 검토할 지표','metrics',S::METRICS,$v['metrics']).'<p class="ojp-field-help">선택은 분석 계획에 기록됩니다. 기본 제출·설문 지표는 모두 보고서에 남으며, 별도 성취는 시작·마무리 과제를 모두 지정해야 계산됩니다.</p>';
        $h.='<details><summary>보고서용 설명 확인·수정 · 연구 주제 예시 포함</summary><p>선택한 조건 뒤에 붙일 설명입니다. 직접 작성이나 추가 근거가 필요할 때 수정하세요. 기존 수업의 기록은 그대로 유지합니다.</p>';
        foreach(P::NOTES as$key=>$label){if($key!=='consent'){$h.=self::area($label.' · 보충 설명','note_'.$key,$v['notes'][$key],self::hint($key));}}
        return $h.'</details>';
    }

    private static function hint(string $key):string
    {
        return match($key){'environment'=>'실제 학교·학년, 기기나 네트워크의 특이사항. 직접 작성을 선택한 항목을 설명하세요.',
            'period'=>'직접 정한 기간·요일·그룹별 일정을 적으세요.',
            'citation'=>'실제 사용한 수준 분석 연구의 연구자·연도·논문명·출처를 확인해 입력하세요.',
            'teacher'=>'직접 선택한 지원 방식이나 집단별 예외가 있으면 기록하세요.', default=>'추가 설명이 있을 때만 작성하세요.'};
    }
    private static function choice(array $v,string $key):string
    {
        [$label,$options]=S::CHOICES[$key];$value=$v['choices'][$key];
        if($value===''){$options=[''=>'선택 안 함 · 기존 기록 유지']+$options;}
        $h='<label class="ojp-research-field">'.self::e($label).'<select class="ojp-select" name="setup_'.$key.'">';
        foreach($options as$k=>$text){$h.='<option value="'.self::e((string)$k).'"'.($value===(string)$k?' selected':'').'>'.self::e($text).'</option>';}
        return $h.'</select></label>';
    }
    private static function checks(string $label,string $key,array $options,array $values):string
    {
        $h='<fieldset class="ojp-classroom-fieldset"><legend>'.self::e($label).'</legend><div class="ojp-research-choices">';
        foreach($options as$id=>$text){$h.='<label><input type="checkbox" name="setup_'.$key.'[]" value="'.$id.'"'.(in_array($id,$values,true)?' checked':'').'> '.self::e($text).'</label>';}
        return $h.'</div></fieldset>';
    }
    private static function input(string $label,string $name,string $value,string $type,string $placeholder=''):string
    {return '<label class="ojp-research-field">'.self::e($label).'<input class="ojp-input" name="'.$name.'" type="'.$type.'" value="'.self::e($value).'" placeholder="'.self::e($placeholder).'"></label>';}
    private static function area(string $label,string $name,string $value,string $placeholder):string
    {return '<label class="ojp-research-field">'.self::e($label).'<textarea class="ojp-textarea" name="'.$name.'" rows="3" placeholder="'.self::e($placeholder).'">'.self::e($value).'</textarea></label>';}
    private static function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
