<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab;

use EduGames\Learning\Question as Q;

final class Learning_Content {
    public static function questions(): array {
        $config=require __DIR__.'/activities/digit_recognition_v2.php';
        $fields=$expected=array();
        foreach($config['labeling_batch'] as $sample){
            $key='digit-'.$sample['id'];$field=Q::field($key,($sample['id']+1).'번 손글씨','choice',array('0'=>'0','1'=>'1','7'=>'7','defer'=>'판단 보류'));
            $field['pixels']=array_values($sample['features']);$fields[]=$field;$expected[$key]=array($sample['true_label']);
        }
        $bank=array(Q::make('digit-labeling','손글씨 라벨링과 품질 비교','easy','손글씨가 0·1·7 중 무엇인지 관찰해 라벨을 붙이세요. 어려운 그림은 판단 보류로 남길 수 있습니다. 모두 판단한 뒤 같은 정답 개수의 사전 모델 실험을 비교합니다.',array('rows'=>array(array('자료'=>'훈련 라벨링','개수'=>15),array('자료'=>'비교용 검증','개수'=>$config['simulation']['validationCount']),array('자료'=>'별도 보존 평가','개수'=>$config['simulation']['reservedTestCount']))),$fields,$expected,'이 활동은 실제 손글씨 관찰과 사전 실험 비교입니다. 현재 답안으로 즉시 모델을 훈련한 것이 아닙니다. 보류와 오답은 결과에서 따로 확인합니다.','숫자의 닫힌 부분과 획 방향을 확인하세요. 모호하면 보류한 뒤 다시 검토할 수 있습니다.',array('type'=>'digits')));
        for($v=0;$v<6;$v++) {
            $a=10+$v;$b=20+$v;$mean=($a+$b)/2;
            $bank[]=Q::make("train-only-$v",'훈련 자료만으로 전처리','normal','훈련 관측값의 평균으로만 결측 대체 규칙을 정하세요. 검증·평가 값은 평균 계산에 사용하지 않습니다.',array('rows'=>array(array('번호'=>'T1','분할'=>'훈련','값'=>$a),array('번호'=>'T2','분할'=>'훈련','값'=>null),array('번호'=>'T3','분할'=>'훈련','값'=>$b),array('번호'=>'V1','분할'=>'검증','값'=>100+$v),array('번호'=>'E1','분할'=>'평가','값'=>200+$v))),array(Q::field('mean','훈련 관측 평균'),Q::field('fill','T2 추정값'),Q::field('source','평균 계산에 포함한 행','multi',array('T1'=>'T1','T2'=>'T2','T3'=>'T3','V1'=>'V1','E1'=>'E1'))),array('mean'=>$mean,'fill'=>$mean,'source'=>array('T1','T3')),"훈련 관측값 {$a}와 {$b}만 사용하므로 평균은 {$mean}입니다. 결측과 검증·평가 행은 분모에 포함하지 않습니다.",'전처리를 맞추기 전에 자료를 분리합니다.');
            $normal=18+2*$v;$defect=2+$v;$fp=$defect;$tn=$normal-$fp;$total=$normal+$defect;
            $bank[]=Q::make("metrics-$v",'정확도와 재현율','hard',"가상 부품 {$total}개 중 불량은 {$defect}개입니다. 불량을 놓치지 않는 것이 우선이며 오경보 {$fp}개까지 허용합니다. 행은 실제, 열은 예측입니다.",array('rows'=>array(array('모델'=>'항상 정상','실제 정상→정상'=>$normal,'실제 정상→불량'=>0,'실제 불량→정상'=>$defect,'실제 불량→불량'=>0),array('모델'=>'불량 탐지','실제 정상→정상'=>$tn,'실제 정상→불량'=>$fp,'실제 불량→정상'=>0,'실제 불량→불량'=>$defect))),array(Q::field('model','목적에 더 적합한 모델','choice',array('normal'=>'항상 정상','detect'=>'불량 탐지')),Q::field('missed','불량 탐지 모델이 놓친 불량 수'),Q::field('recall','불량 탐지 모델의 불량 재현율(%)'),Q::field('same','두 모델의 정확도 비교','choice',array('same'=>'같음','different'=>'다름'))),array('model'=>array('detect'),'missed'=>0,'recall'=>100,'same'=>array('same')),'두 모델은 같은 수를 맞히지만 놓치는 대상이 다릅니다. 정확도만으로 목적에 맞는 모델을 고를 수 없습니다.','불량을 놓친 수는 실제 불량→정상 칸입니다.');
        }
        return $bank;
    }

    public static function grade(array $question,array $answer): array {
        $result=Q::grade($question,$answer);
        if($question['id']!=='digit-labeling'){return $result;}
        $config=require __DIR__.'/activities/digit_recognition_v2.php';$correct=$deferred=0;
        foreach($config['labeling_batch'] as $sample){$label=$answer['digit-'.$sample['id']]??'defer';if(!in_array($label,array('0','1','7'),true)){$deferred++;}elseif($label===$sample['true_label']){$correct++;}}
        $result['labeling']=array('correct'=>$correct,'total'=>15,'deferred'=>$deferred,'coverage'=>round((15-$deferred)/15*100,2),'accuracyOfDecided'=>15>$deferred?round($correct/(15-$deferred)*100,2):0);
        if($deferred===0){
            $models=array();foreach($config['models'] as $model){$row=$config['performance_by_correct_count'][$model['key']][$correct];$models[]=array('key'=>$model['key'],'title'=>$model['label'],'accuracy'=>$row['accuracy'],'precision'=>$row['precision'],'recall'=>$row['recall'],'f1'=>$row['f1'],'matrix'=>$row['confusion_matrix'],'mean'=>$row['mean_metrics'],'std'=>$row['accuracy_std'],'trials'=>$row['trials']);}
            $result['simulation']=array('models'=>$models,'labels'=>array('0','1','7'),'trainingCount'=>15,'validationCount'=>$config['simulation']['validationCount'],'reservedTestCount'=>$config['simulation']['reservedTestCount'],'engineVersion'=>$config['simulation']['engineVersion']);
        }
        return $result;
    }
}
