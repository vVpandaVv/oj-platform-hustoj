<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Classifier_Lab;

use EduGames\Learning\Question as Q;

final class Learning_Content {
    public static function questions(): array {
        $bank=array();
        for($v=0;$v<24;$v++) {
            $axis=$v%2?'y':'x';$threshold=4+$v%3; $less=$v%4>=2;
            $rule=array('kind'=>'axis','axis'=>$axis,'threshold'=>$threshold,'comparison'=>$less?'lte':'gte');
            $name='x'===$axis?'폭':'높이';$operator=$less?'이하':'이상';
            $points=array();
            foreach(array(array(1,1),array(3,8),array(8,2),array(9,9),array($threshold,2),array(2,$threshold)) as $i=>$xy){
                $is_b=$less?$xy[$axis==='x'?0:1]<=$threshold:$xy[$axis==='x'?0:1]>=$threshold;
                $points[]=array('번호'=>'P'.($i+1),'폭(mm)'=>$xy[0],'높이(mm)'=>$xy[1],'라벨'=>$is_b?'B':'A');
            }
            $coordinate=$threshold+($v%3-1);
            $query=array('x'=>$v%2?2:$coordinate,'y'=>$v%2?$coordinate:2);
            $label=($less?$coordinate<=$threshold:$coordinate>=$threshold)?'B':'A';
            $fields=array();$expected=array();
            if($v>=12){
                $options=array('x-gte'=>"폭이 {$threshold} 이상이면 B",'x-lte'=>"폭이 {$threshold} 이하이면 B",'y-gte'=>"높이가 {$threshold} 이상이면 B",'y-lte'=>"높이가 {$threshold} 이하이면 B");
                $valid=array();
                foreach($options as $key=>$text){[$a,$op]=explode('-',$key);$ok=true;foreach($points as $p){$n=$p[$a==='x'?'폭(mm)':'높이(mm)'];$prediction=($op==='gte'?$n>=$threshold:$n<=$threshold)?'B':'A';if($prediction!==$p['라벨']){$ok=false;}}if($ok){$valid[]=$key;}}
                $fields[]=Q::field('rules','학습점과 일치하는 규칙 모두','multi',$options);$expected['rules']=$valid;
            }
            if($v>=12){$labels=array();foreach($valid as $key){[$a,$op]=explode('-',$key);$labels[]=($op==='gte'?$query[$a]>=$threshold:$query[$a]<=$threshold)?'B':'A';}$label=count(array_unique($labels))>1?'unknown':$labels[0];}
            $fields[]=Q::field('label','새 점의 라벨','choice',array('A'=>'A','B'=>'B','unknown'=>'후보가 다르게 예측하여 추가 자료 필요'));$expected['label']=array($label);
            $fields[]=Q::field('activity','이 활동의 성격','choice',array('apply'=>'주어진/후보 규칙을 자료와 비교하여 적용','real'=>'실제 제품의 품질을 보증'));$expected['activity']=array('apply');
            $prompt=$v<12?"가상 부품의 {$name}이 {$threshold}mm {$operator}이면 B, 아니면 A입니다. 새 점 ({$query['x']},{$query['y']})을 분류하세요.":"가상 부품의 학습점에 모두 맞는 후보 규칙을 고르고 새 점 ({$query['x']},{$query['y']})을 분류하세요. 맞는 후보가 여러 개면 모두 선택합니다.";
            $bank[]=Q::make("boundary-$v",$v<12?'규칙 적용과 경계 포함':'학습점과 후보 규칙 비교',$v<12?'easy':'normal',$prompt,array('rows'=>$points),$fields,$expected,"경계 {$threshold}도 {$operator}에 포함됩니다. 이 모형 규칙이 실제 물체의 품질을 보증하지는 않습니다.",'두 축의 단위를 확인하고 경계 위에 놓인 점도 규칙의 등호에 따라 분류합니다.',array('type'=>'classifier','query'=>$query,'threshold'=>$threshold,'axis'=>$v<12?$axis:null,'less'=>$less));
        }
        for($v=0;$v<6;$v++) {
            $shift=$v%3;$near_b=$v>=3; $points=array();
            foreach(array(array(1,1,'A'),array(2,1,'A'),array(1,2,'A'),array(8,8,'B'),array(9,8,'B'),array(8,9,'B')) as $i=>$p){$points[]=array('번호'=>'P'.($i+1),'폭(mm)'=>$p[0]+$shift,'높이(mm)'=>$p[1],'라벨'=>$p[2]);}
            $query=array('x'=>($near_b?9:2)+$shift,'y'=>$near_b?9:2);
            // SPL provides stable priority ordering; the explicit secondary key resolves distance ties.
            $heap=new \SplPriorityQueue();$heap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
            foreach($points as $i=>$p){$d=($p['폭(mm)']-$query['x'])**2+($p['높이(mm)']-$query['y'])**2;$heap->insert($p,array(-$d,-$i));}
            $neighbors=array();$votes=array('A'=>0,'B'=>0);for($i=0;$i<3;$i++){$p=$heap->extract();$neighbors[]=$p['번호'];$votes[$p['라벨']]++;}
            $options=array_combine(array_column($points,'번호'),array_column($points,'번호'));
            $bank[]=Q::make("neighbors-$v",'가까운 학습점과 다수결','hard',"두 축은 mm입니다. 새 점 ({$query['x']},{$query['y']})과 제곱거리가 가까운 3개를 선택하고 다수결로 예측하세요. 거리 동률은 번호순입니다.",array('rows'=>$points),array(Q::field('neighbors','가까운 이웃 3개','multi',$options),Q::field('label','예측 라벨','choice',array('A'=>'A','B'=>'B'))),array('neighbors'=>$neighbors,'label'=>array($votes['A']>$votes['B']?'A':'B')),'가까운 이웃의 라벨로 새 점을 예측합니다. 새 점의 정답을 학습 자료에 먼저 넣지 않습니다.','거리 제곱은 (폭 차이)²+(높이 차이)²입니다.',array('type'=>'classifier','query'=>$query));
            $total=20+5*$v;$correct=10+4*$v;
            $bank[]=Q::make("generalize-$v",'새 자료로 검증','hard',"가상 분류기가 훈련점 10개를 모두 맞혔고 처음 보는 검증점 {$total}개에서는 {$correct}개를 맞혔습니다. 검증 정확도는 소수 둘째 자리까지 반올림하고 적절한 다음 행동을 선택하세요.",array('rows'=>array(array('자료'=>'훈련','전체'=>10,'정답'=>10),array('자료'=>'검증','전체'=>$total,'정답'=>$correct))),array(Q::field('accuracy','검증 정확도(%)'),Q::field('next','다음 행동','choice',array('review'=>'오류를 분석하고 새 평가 자료는 별도로 보존','perfect'=>'훈련 100%이므로 모든 자료에서 완벽','reuse'=>'검증 정답을 외운 뒤 같은 점을 새 평가로 보고'))),array('accuracy'=>round(100*$correct/$total,2),'next'=>array('review')),'훈련 성능과 새 자료 성능은 다릅니다. 정답을 보고 고친 자료는 더 이상 처음 보는 평가 자료가 아닙니다.','분모는 검증점 수입니다. 훈련점과 합산하지 않습니다.');
        }
        return $bank;
    }
}
