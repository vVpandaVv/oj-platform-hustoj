<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Outlier_Detection;

use EduGames\Learning\Question as Q;

final class Learning_Content {
    public static function questions(): array {
        $bank=array();
        for($v=0;$v<12;$v++) {
            $min=10+$v; $max=$min+10;
            $values=array($min,$min+2,$max,$min+4,$min+5,$min+8);
            if($v%4) {$values[$v%6]=$min-2;}
            if($v%4===1) {$values[($v+3)%6]=$max+3;}
            $bank[]=self::make("range-$v",'easy',"가상 부품의 허용 길이는 {$min}mm 이상 {$max}mm 이하입니다. 범위를 벗어난 행을 모두 표시하세요. 없으면 선택 없이 제출합니다.",$values,static fn($value,$i)=>$value<$min||$value>$max,'허용 범위 양끝은 포함합니다. 후보를 표시한 다음 원본을 확인하며 바로 삭제하지 않습니다.');

            $base=$v;
            $values=array_map(static fn($n)=>$n+$base,array(4,5,6,7,8,9,10,$v%4?30:11));
            $rotation=$v%8; $values=array_merge(array_slice($values,$rotation),array_slice($values,0,$rotation));
            $sorted=$values; sort($sorted); $q1=($sorted[1]+$sorted[2])/2; $q3=($sorted[5]+$sorted[6])/2;
            $low=$q1-1.5*($q3-$q1); $high=$q3+1.5*($q3-$q1);
            $q=self::make("iqr-$v",'hard','정렬한 아래 절반·위 절반의 중앙값을 Q1·Q3로 사용합니다. Q1−1.5×IQR 미만 또는 Q3+1.5×IQR 초과인 행을 후보로 표시하세요. 후보가 없을 수도 있습니다.',$values,static fn($value,$i)=>$value<$low||$value>$high,"Q1={$q1}, Q3={$q3}, 하한={$low}, 상한={$high}입니다. 통계적 후보가 실제 오류라는 뜻은 아닙니다.");
            $q['fields'][]=Q::field('lower','하한'); $q['fields'][]=Q::field('upper','상한'); $q['expected']['lower']=$low; $q['expected']['upper']=$high; $bank[]=$q;

            $values=array(4,14,5,16,6,15); $groups=array('A','B','A','B','A','B');
            if($v%4) {$values[$v%6]=$groups[$v%6]==='A'?15:5;}
            if($v%4===2) {$values[($v+3)%6]=$groups[($v+3)%6]==='A'?16:6;}
            $values=array_map(static fn($n)=>$n+$v,$values);
            $bank[]=self::make("group-$v",'normal','기기 A의 정상 범위는 '.(3+$v).'~'.(7+$v).'(양끝 포함), B는 '.(13+$v).'~'.(17+$v).'(양끝 포함)입니다. 자기 기기의 범위를 벗어난 행만 표시하세요.',$values,static fn($value,$i)=>$groups[$i]==='A'?($value<3+$v||$value>7+$v):($value<13+$v||$value>17+$v),'같은 숫자도 비교 집단에 따라 판단이 달라집니다. 서로 다른 장치를 하나의 범위로 합치지 않습니다.',$groups);
        }
        return $bank;
    }

    private static function make(string $id,string $level,string $prompt,array $values,callable $outside,string $explanation,array $groups=array()): array {
        $rows=$options=$expected=array();
        foreach($values as $i=>$value) { $key='R'.($i+1); $row=array('번호'=>$key,'측정값'=>$value); if($groups) {$row['기기']=$groups[$i];} $rows[]=$row; $options[$key]="$key: $value"; if($outside($value,$i)) {$expected[]=$key;} }
        return Q::make($id,'이상치 후보와 원본 확인',$level,$prompt,array('rows'=>$rows),array(Q::field('selected','확인 대상 행 (없으면 선택하지 않음)','multi',$options),Q::field('action','처리','choice',array('keep'=>'후보 없음: 원본 유지','review'=>'후보 표시 후 추가 확인','delete'=>'원본에서 즉시 삭제'))),array('selected'=>$expected,'action'=>array($expected?'review':'keep')),$explanation,'최댓값이라고 반드시 이상치는 아닙니다. 제시된 기준과 각 행을 비교합니다.',array('type'=>'outlier'));
    }
}
