<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Data_Extraction;

use EduGames\Learning\Question as Q;

final class Learning_Content {
    public static function questions(): array {
        $bank = array();
        $materials = array('색종이', '나사', '건전지', '명찰');
        for ($v = 0; $v < 4; $v++) {
            $limit = 3 + $v;
            $rows = array(array('번호'=>'A','재고'=>$limit,'색'=>'빨강'),array('번호'=>'B','재고'=>$limit-1,'색'=>'파랑'),array('번호'=>'C','재고'=>$limit+4,'색'=>'초록'),array('번호'=>'D','재고'=>0,'색'=>'빨강'));
            if ($v % 2) { $rows = array_reverse($rows); }
            $bank[] = self::extraction("stock-$v", '조건 필터와 출력 열', 'easy', "{$materials[$v]} 재고가 {$limit}개 미만인 행에서 번호와 재고만 추출하세요. 결과는 원본 행 순서입니다.", $rows, array('번호','재고'), $v % 2 ? array('D','B') : array('B','D'), "{$limit}는 미만에 포함되지 않습니다. 0은 관측된 재고이지 결측값이 아닙니다.");

            $pages = 100 + 25 * $v;
            $rows = array(array('번호'=>'A','제목'=>'별 관찰','대출'=>'가능','쪽수'=>$pages),array('번호'=>'B','제목'=>'암석','대출'=>'불가','쪽수'=>90),array('번호'=>'C','제목'=>'바다 지도','대출'=>'가능','쪽수'=>$pages+1),array('번호'=>'D','제목'=>'색의 발견','대출'=>'가능','쪽수'=>80));
            $bank[] = self::extraction("loan-$v", '두 조건 모두 만족', 'easy', "대출 가능하면서 {$pages}쪽 이하인 도서의 번호와 제목을 원본 순서로 추출하세요.", $rows, array('번호','제목'), array('A','D'), 'AND는 두 조건을 모두 만족해야 합니다. 조건에 쓰인 열을 모두 결과에 출력해야 하는 것은 아닙니다.');

            $charge = 20 + 5 * $v;
            $rows = array(array('번호'=>'C','잔량'=>$charge,'장소'=>'서쪽'),array('번호'=>'A','잔량'=>$charge,'장소'=>'동쪽'),array('번호'=>'B','잔량'=>80,'장소'=>'서쪽'));
            $bank[] = self::extraction("sort-$v", '동률 정렬', 'normal', '잔량이 적은 순서로 번호와 잔량을 출력하세요. 잔량이 같으면 번호 오름차순입니다.', $rows, array('번호','잔량'), array('A','C','B'), '첫 정렬 기준이 같은 두 행은 두 번째 기준으로 순서를 정합니다.');

            $n = 4 + $v;
            $rows = array(array('번호'=>'A','사용량'=>3*$n,'인원'=>$n),array('번호'=>'B','사용량'=>4*($n+1),'인원'=>$n+1),array('번호'=>'C','사용량'=>2*($n+2),'인원'=>$n+2));
            $q = self::extraction("ratio-$v", '파생값과 비율', 'normal', '사용량÷인원으로 1인당 사용량을 계산하세요. 1인당 3장 초과인 모둠의 번호와 1인당 사용량을 출력합니다.', $rows, array('번호','1인당'), array('B'), '총량과 1인당 사용량은 다릅니다. A=3, B=4, C=2장입니다. 이 자료만으로 낭비 원인을 단정하지 않습니다.', array('1인당'));
            foreach (array('A'=>3,'B'=>4,'C'=>2) as $id=>$value) { $q['fields'][] = Q::field("value-$id", "$id 모둠 1인당 사용량"); $q['expected']["value-$id"] = $value; }
            $bank[] = $q;

            $threshold = 100 + $v * 10;
            $rows = array(array('번호'=>'S1','측정값'=>$threshold),array('번호'=>'S2','측정값'=>null),array('번호'=>'S3','측정값'=>0),array('번호'=>'S4','측정값'=>$threshold-2));
            $bank[] = self::extraction("empty-$v", '빈 결과와 결측', 'hard', "측정값이 기록되어 있고 {$threshold}을 초과한 행의 번호와 측정값을 출력하세요. 결과가 없을 수도 있습니다.", $rows, array('번호','측정값'), array(), '초과에는 경계값을 포함하지 않습니다. null은 미기록이고 0과 다릅니다. 빈 결과도 올바른 결과입니다.');

            $a=12+$v; $b=8+$v;
            $rows=array(array('번호'=>'r1','구역'=>'A','사용량'=>$a,'날짜'=>1),array('번호'=>'r2','구역'=>'B','사용량'=>$b,'날짜'=>1),array('번호'=>'r3','구역'=>'A','사용량'=>7,'날짜'=>2),array('번호'=>'r4','구역'=>'B','사용량'=>5,'날짜'=>2));
            $bank[] = Q::make("aggregate-$v", '그룹별 합계', 'hard', '구역별 물 사용량(L)을 합산해 결과 표를 완성하세요. 날짜별 원본은 보존합니다.', array('rows'=>$rows), array(Q::field('A','A 구역 합계(L)'),Q::field('B','B 구역 합계(L)')), array('A'=>$a+7,'B'=>$b+5), "A는 {$a}+7, B는 {$b}+5입니다. 마지막 행만 남기는 것과 집계는 다릅니다.", '구역이 같은 행끼리 묶은 뒤 사용량을 더합니다.', array('type'=>'aggregate','groups'=>array('A','B')));
        }
        return $bank;
    }

    private static function extraction(string $id, string $skill, string $level, string $prompt, array $rows, array $columns, array $ids, string $explanation, array $extra = array()): array {
        $options = array_values(array_unique(array_merge(array_keys($rows[0]), $extra)));
        return Q::make($id, $skill, $level, $prompt, array('rows'=>$rows), array(Q::field('columns','출력 열','multi',array_combine($options,$options)),Q::field('rowIds','결과 행 순서','tokens')), array('columns'=>$columns,'rowIds'=>$ids), $explanation, '조건의 경계, 필요한 출력 열, 정렬 기준을 각각 확인합니다.', array('type'=>'extract','columns'=>$options));
    }
}
