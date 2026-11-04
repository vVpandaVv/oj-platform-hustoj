<?php
declare(strict_types=1);
require __DIR__.'/research.php';
use CodingPanda\OjResearch\Domain\{ClassroomProtocol as C,PilotProtocol as P};
$begin=$checks;
researchAssert(count(C::BEFORE)===3&&array_slice(C::AFTER,0,3)===C::BEFORE,'Pre/post self-report questions retain identical wording');
researchAssert(count(C::AFTER)===7&&str_contains(C::AFTER[3],'만족'),'Satisfaction remains separate from three AI experience items');
$scale=C::scaleOptions(5);researchAssert(count($scale)===5&&$scale[2]==='2 · 그렇지 않다'&&$scale[3]==='3 · 보통이다'&&$scale[4]==='4 · 그렇다','All default scale anchors are explicit');
researchAssert(count(C::scaleOptions(7))===7&&str_contains(C::scaleOptions(7)[7],'매우 그렇다'),'Customized scale keeps its actual range');
researchAssert(str_contains(C::surveyGuidance('after'),'읽지 않았다면 해당 없음'),'Nonexposure instruction is explicit');
researchAssert(C::itemPurpose('교사 도움에 대한 자체 문항')==='교사가 설정한 문항','Custom questionnaire is not mislabeled as validated default');
$oldBefore=['프로그래밍 문제를 풀어 본 경험이 있다.','조건문과 반복문을 활용할 수 있다.','오류가 나면 원인을 찾아 수정할 수 있다.'];
$oldAfter=['피드백의 설명을 이해할 수 있었다.','피드백이 다음에 무엇을 고칠지 생각하는 데 도움이 되었다.','스스로 문제를 해결할 기회가 있었다.','이번 학습 활동에 만족한다.','다음 수업에서도 이 학습 방식을 사용하고 싶다.'];
$legacy=P::validate(['workflow'=>C::WORKFLOW,'title'=>'이전 설문','groups'=>[1],'practice'=>[1000],'before_items'=>implode("\n",$oldBefore),'survey_items'=>implode("\n",$oldAfter)]);
researchAssert($legacy['before_items']===$oldBefore&&$legacy['survey_items']===$oldAfter,'Stored older and customized questionnaires are not replaced');
$people=[];for($i=1;$i<=30;++$i){$people[]=['id'=>$i,'group_id'=>$i<=5?1:2,'baseline'=>['level'=>'intermediate']];}
$arms=['A'=>['weight'=>1],'B'=>['weight'=>1]];
$map=P::allocate($people,'random',$arms,'synthetic');$counts=array_count_values($map);researchAssert($counts['A']===15&&$counts['B']===15,'Equal allocation weights target equal student counts');
$arms['A']['weight']=2;$map=P::allocate($people,'stratified',$arms,'synthetic');$counts=array_count_values($map);researchAssert($counts['A']===20&&$counts['B']===10,'Two-to-one weights produce 20/10 for one 30-person stratum');
$map=P::allocate($people,'class',$arms,'synthetic');$group1=[];$group2=[];foreach($people as$person){if($person['group_id']===1){$group1[]=$map[$person['id']];}else{$group2[]=$map[$person['id']];}}
researchAssert(count(array_unique($group1))===1&&count(array_unique($group2))===1&&$group1[0]!==$group2[0],'Class assignment preserves groups even when target student ratio is impossible');
echo 'Survey review and allocation passed: '.($checks-$begin)." assertions; wording, scale, legacy questions, student weights and intact groups\n";
