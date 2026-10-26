<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Outlier_Detection;

final class Campaign {
    public static function levels(): array {
        $cases=array(
            4=>array('소수점이 사라진 무게','입력 오류',array(2.1,2.4,2.2,24,2.3,2.5),'kg','0~10kg',array(3=>array('원장에는 2.4kg, 업로드에는 24kg로 적혔습니다.','unit','소수점 누락'))),
            5=>array('하루 종일 걸은 날','정상 극단값',array(4200,4500,4300,4400,43000,4600),'걸음','0~100000걸음',array()),
            6=>array('짧고 강한 소나기','정상 극단값',array(0,1,0,22,0,0),'mm','0~100mm',array()),
            7=>array('배터리 표시 오류','장치 범위',array(98,96,94,92,120,90),'%','0~100%',array(4=>array('잔량 백분율의 허용 범위는 0~100입니다. 표시 회로 이상이 확인됐습니다.','range','백분율 범위 위반'))),
            8=>array('습도 단위 혼합','단위 오류',array(45,46,0.47,49,48,50),'%','0~100%',array(2=>array('원장의 0.47은 비율입니다. 백분율 열에는 47로 변환해야 합니다.','unit','비율과 백분율 혼합'))),
            9=>array('음수가 된 통신 지연','장치 범위',array(20,21,-5,23,21,22),'ms','0~1000ms',array(2=>array('경과 시간은 음수가 될 수 없습니다. 두 시계의 기준이 달랐습니다.','range','시계 기준 불일치'))),
            10=>array('초기화된 누적 계수기','정상 변화',array(1010,1020,5,15,25,35),'회','0~100000회',array()),
            11=>array('두 오류가 섞인 입장 기록','복합 오류',array(18,19,190,20,-19,21),'명','0~50명',array(2=>array('종이 원장의 19명이 업로드 때 190명으로 바뀌었습니다.','unit','입력 자리수 오류'),4=>array('입장 인원은 0명 이상입니다. 부호가 잘못 입력되었습니다.','range','부호 입력 오류'))),
            12=>array('결론을 보류할 관측','불확실성',array(12,13,12,40,14,13),'단위','0~100단위',array()),
        );
        $out=array();foreach($cases as $id=>[$title,$chapter,$values,$unit,$range,$errors]){
            $reference=match($id){4=>array(1.8,2.8),5=>array(3000,6000),6=>array(0,3),7=>array(85,100),8=>array(40,55),9=>array(15,30),10=>array(1000,1050),11=>array(15,25),default=>array(10,16)};
            $records=array();foreach($values as $i=>$value){
                $normalContext='관측 ID '.chr(65+$i).'는 다른 기록과 구별되며 현장 원장과 대조했습니다.';
                if($id===5&&$i===4)$normalContext='장거리 걷기 행사에 참여한 날입니다. 이동 경로와 별도 만보기에서도 43000걸음이 확인됐습니다.';
                if($id===6&&$i===3)$normalContext='소나기가 내린 10분의 기록입니다. 별도 우량계와 현장 사진에서도 강우가 확인됐습니다.';
                if($id===10&&$i===2)$normalContext='기록 B와 C 사이에 계수기를 초기화한 정비 기록이 있습니다. 누적값은 다시 0부터 시작합니다.';
                if($id===10&&$i>2)$normalContext='초기화 뒤 새로 누적된 계수입니다. 관측 구간과 순서가 일치합니다.';
                $evidence=array('context'=>$normalContext,'unit'=>'원장과 화면 단위는 모두 '.$unit.'입니다.','range'=>'이 장치의 허용 범위는 '.$range.'입니다.','repeat'=>'독립적인 관측에서도 '.$value.$unit.'로 확인했습니다.');
                $bad=isset($errors[$i]);$sufficient=array('context','repeat');
                if($bad){[$reason,$kind,$finding]=$errors[$i];$evidence[$kind]=$reason;$evidence['context']=$kind==='context'?$reason:'기록 변환 과정 또는 장치 상태의 확인이 필요합니다.';$evidence['repeat']=$reason.' 재확인에서도 오류 원인이 확인됐습니다.';$sufficient=array($kind,'repeat');}
                if($id===12&&$i===3){$bad=null;$sufficient=array('context','repeat');$evidence['context']='관측 조건 메모가 빠졌고 두 관측자의 값이 서로 다릅니다. 정상 또는 오류로 확정할 근거가 없습니다.';$evidence['repeat']='원장과 독립 관측이 충돌합니다. 재확인으로도 결론을 내리지 못했습니다.';}
                $records[]=array('id'=>chr(65+$i),'value'=>$value,'unit'=>$unit,'group'=>$id===10?($i<2?'초기화 전':'초기화 후'):'같은 관측망','reference'=>$id===10&&$i>=2?array(0,40):$reference,'bad'=>$bad,'evidence'=>$evidence,'sufficient'=>$sufficient,'costs'=>array('context'=>1,'unit'=>1,'range'=>1,'repeat'=>4));
            }
            $out[sprintf('board-%02d',$id)]=array('title'=>$title,'chapter'=>$chapter,'goal'=>'실제 관측은 사용하고, 오류가 확인된 기록은 제외하세요. 사실이 불확실한 기록은 보류할 수 있습니다.','budget'=>24,'referenceCost'=>13,'records'=>$records);
        }return $out;
    }
}
