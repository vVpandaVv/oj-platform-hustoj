<?php
declare(strict_types=1);
require __DIR__.'/classroom.php';
use CodingPanda\OjResearch\Domain\{ClassroomSettings as Settings,PilotProtocol as Protocol,PilotDesign};
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;

$begin=$checks;
$base=Protocol::validate(['title'=>'설정 시험','groups'=>[2],'practice'=>[1000],'design'=>'adaptation']);
$defaults=Settings::forForm($base,true);
researchAssert($defaults['choices']['consent_method']==='pending'&&$defaults['reference']==='','Examples do not claim consent or invent a document number');
$requestFor=static function(array $v,int $id=0,int $revision=0,string $design='adaptation'):HttpRequest{
    $body=['operation'=>'pilot.classroom.save','guided_settings'=>'1','study_id'=>$id,'revision'=>$revision,'title'=>'합성 자료 · 선택형 설정 시험','groups'=>[2],'practice'=>'1000','design'=>$design,'lessons_count'=>'6','minutes'=>'45','before_items'=>'경험이 있다.','survey_items'=>'만족한다.'];
    foreach($v['choices']as$key=>$value){$body['setup_'.$key]=$value;}
    foreach(['topics','metrics','reference','storage_detail','start_date','end_date']as$key){$body['setup_'.$key]=$v[$key];}
    foreach($v['notes']as$key=>$value){$body['note_'.$key]=$value;}
    foreach(['A','B']as$arm){$body['arm_'.$arm.'_conditions']=$v['arm_conditions'][$arm];}
    return new HttpRequest('POST','/',[],$body);
};
$actions->handle(1,$requestFor($defaults));$settingsId=max(array_keys($store->studyRows));
$sv=fn()=>$store->study($settingsId)['revision'];$sp=fn()=>$store->study($settingsId)['data']['protocol'];
researchAssert($sp()['setup']['topics']===['io','condition','loop'],'Checkbox topics survive actual save action');
researchAssert(str_contains($sp()['notes']['environment'],'중학교 2학년')&&str_contains($sp()['notes']['baseline_content'],'반복문'),'Selected conditions become report prose');
researchAssert($sp()['arms']['A']['conditions']===$sp()['arms']['B']['conditions']&&str_contains($sp()['arms']['A']['conditions'],'정답 코드 제외'),'Both arms use the same selected teacher support');
researchDenied(fn()=>$pilot->enroll(1,$settingsId,$sv(),[14,15]),'Unspecified consent plan cannot open start survey');
researchAssert($store->participants($settingsId)===[],'Blocked consent creates no participants');

$ready=$defaults;$ready['choices']=array_replace($ready['choices'],['consent_method'=>'paper','storage'=>'school','access'=>'teacher','retention'=>'report','disposal'=>'destroy','withdrawal'=>'teacher']);
$ready['start_date']='2026-11-03';$ready['end_date']='2026-11-30';
$actions->handle(1,$requestFor($ready,$settingsId,$sv()));$saved=$sp();
$actions->handle(1,$requestFor(Settings::forForm($saved,false),$settingsId,$sv()));
researchAssert($saved===$sp(),'Save-edit-save is stable without duplicating generated sentences');
researchAssert($sp()['setup']['reference']==='','No document control number is required');
researchAssert(str_contains($ui->report(1,$settingsId,'protocol'),'학교의 접근 제한 저장소'),'Selected storage plan appears in independent appendix');
researchAssert(str_contains($ui->edit(1,$store->study($settingsId)),'value="paper" selected'),'Saved choice is shown on edit');

$bad=$ready;$bad['choices']['storage']='unknown';researchDenied(fn()=>Settings::apply($base,$bad),'Forged selection rejected');
$bad=$ready;$bad['topics']=['not-a-topic'];researchDenied(fn()=>Settings::apply($base,$bad),'Forged checkbox rejected');
$bad=$ready;$bad['metrics']='accuracy';researchDenied(fn()=>Settings::apply($base,$bad),'Malformed checkbox array rejected');
$bad=$ready;$bad['choices']='bad';researchDenied(fn()=>Settings::apply($base,$bad),'Malformed structured choices rejected');
$bad=$ready;$bad['end_date']='2026-02-30';researchDenied(fn()=>Settings::apply($base,$bad),'Invalid date rejected');
$bad=$ready;$bad['end_date']='2026-11-01';researchDenied(fn()=>Settings::apply($base,$bad),'Reversed period rejected');
$bad=$ready;$bad['choices']['storage']='custom';researchDenied(fn()=>Settings::requireReady(Settings::apply($base,$bad)),'Custom storage requires explanation');
$bad['storage_detail']='합성 시험 보관 장소';Settings::requireReady(Settings::apply($base,$bad));researchAssert(true,'Custom storage with explanation allowed');
$bad=$ready;$bad['choices']['consent_method']='written';researchDenied(fn()=>Settings::requireReady(Settings::apply($base,$bad)),'Written procedures cannot be empty');

$old=$base;$old['notes']['consent']='기존 연구 동의·보관·철회 절차';$old['notes']['environment']='기존 고등학교 환경';$old['arms']['A']['conditions']=$old['arms']['B']['conditions']='기존 교사 지원';
$legacy=Settings::apply($old,Settings::forForm($old,false));Settings::requireReady($legacy);
researchAssert($legacy['notes']===$old['notes']&&$legacy['arms']===$old['arms'],'Legacy prose and conditions are preserved without inserting example facts');
$feedback=$base;$feedback['design']='feedback';$feedback=Settings::apply($feedback,$defaults);
researchAssert($feedback['notes']['question']===PilotDesign::QUESTIONS['feedback'],'Default research question follows comparator even without JavaScript');
$specific=$defaults;$specific['notes']['question']='연구자가 직접 작성한 질문';researchAssert(Settings::apply($feedback,$specific)['notes']['question']===$specific['notes']['question'],'Custom research question remains unchanged');
$escaped=$ready;$escaped['reference']='<script>synthetic()</script>';$actions->handle(1,$requestFor($escaped,$settingsId,$sv()));
researchAssert(!str_contains($ui->report(1,$settingsId,'protocol'),'<script>synthetic()')&&str_contains($ui->report(1,$settingsId,'protocol'),'&lt;script&gt;'),'Selected plan free text is escaped in report');
$actions->handle(1,$requestFor($ready,$settingsId,$sv()));
$snapshot('settings-saved',$ui->edit(1,$store->study($settingsId)));
$snapshot('settings-protocol',$ui->report(1,$settingsId,'protocol'));
$pilot->enroll(1,$settingsId,$sv(),[14,15]);researchAssert($store->study($settingsId)['status']==='baseline','Complete plan without ID permits start survey');
researchDenied(fn()=>$actions->handle(1,$requestFor($ready,$settingsId,$sv())),'Started classroom keeps conditions frozen');
echo 'Classroom settings passed: '.($checks-$begin)." assertions; structured save, safe defaults, legacy preservation, report and consent gate\n";
