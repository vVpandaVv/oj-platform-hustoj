<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjResearch\Domain\{ClassroomProtocol as C, PilotProtocol as P, ClassroomSettings};

final class ClassroomInput
{
    public static function read(HttpRequest $r): array
    {
        $input=['workflow'=>C::WORKFLOW,'groups'=>$r->bodyIntList('groups'),'title'=>$r->bodyString('title'),
            'practice'=>$r->bodyString('practice'),'pretest'=>$r->bodyString('pretest'),'posttest'=>$r->bodyString('posttest'),
            'lessons_count'=>$r->bodyString('lessons_count','1'),'request_limit'=>$r->bodyString('request_limit','3'),
            'test_minutes'=>$r->bodyString('test_minutes','45'),'allocation'=>$r->bodyString('allocation','stratified'),
            'level_timing'=>$r->bodyString('level_timing','baseline'),'design'=>$r->bodyString('design','adaptation'),
            'survey_max'=>$r->bodyString('survey_max','5'),'before_items'=>$r->bodyString('before_items'),
            'survey_items'=>$r->bodyString('survey_items',implode("\n",C::AFTER)),
            'covariance'=>$r->bodyString('covariance','auto'),'scoring'=>$r->bodyString('scoring','ac'),
            'errors'=>$r->bodyString('errors','first'),'pause_time'=>$r->bodyString('pause_time','exclude')];
        $design=$input['design'];
        foreach(['A','B']as$arm){
            $input['arms'][$arm]=['name'=>$r->bodyString('arm_'.$arm.'_name',$arm==='A'?'수준별 피드백':'비교 수업'),
                'ai'=>$design==='custom'?$r->bodyString('arm_'.$arm.'_ai',$arm==='A'?'level':'common'):($arm==='A'?'level':($design==='feedback'?'off':'common')),
                'recommendation'=>$design==='custom'?$r->bodyString('arm_'.$arm.'_recommendation','common'):'common',
                'weight'=>$r->bodyString('arm_'.$arm.'_weight','1'),'minutes'=>$design==='custom'?$r->bodyString('arm_'.$arm.'_minutes',$r->bodyString('minutes','45')):$r->bodyString('minutes','45'),
                'conditions'=>$r->bodyString('arm_'.$arm.'_conditions')];
            foreach(['pretest','posttest']as$phase){$input['support'][$phase][$arm]=$design==='custom'?$r->bodyString('support_'.$phase.'_'.$arm,'off'):'off';}
        }
        if($r->bodyString('common_instructions')!==''){$input['common_instructions']=$r->bodyString('common_instructions');}
        foreach(P::NOTES as$key=>$label){$input['notes'][$key]=$r->bodyString('note_'.$key);}
        if($r->bodyString('guided_settings')==='1'){
            $setup=['choices'=>[], 'notes'=>$input['notes'], 'arm_conditions'=>[]];
            foreach(ClassroomSettings::CHOICES as$key=>$field){$setup['choices'][$key]=$r->bodyString('setup_'.$key);}
            foreach(['reference','storage_detail','start_date','end_date']as$key){$setup[$key]=$r->bodyString('setup_'.$key);}
            foreach(['topics','metrics']as$key){$setup[$key]=$r->body()['setup_'.$key]??[];}
            foreach(['A','B']as$arm){$setup['arm_conditions'][$arm]=$input['arms'][$arm]['conditions'];}
            $input['setup']=$setup;
        }
        if($input['allocation']==='manual'){throw new \DomainException('간편 수업의 배정 방법을 선택해 주세요. 학생별 수동 배정은 상세 연구에서 사용할 수 있습니다.');}
        return $input;
    }
}
