<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Code_Typing;
use EduGames\Learning\Question as Q;

final class Learning_Content {
    public static function questions(): array {
        $out=array();
        foreach(array('python','c') as $language){
            for($i=0;$i<12;$i++){
                $n=3+$i;$kind=$i%3;
                if($kind===0){$value=intdiv($n*($n+1),2);$body="total = 0\nfor i in range(1, $n + 1):\n    total += i\nprint(total)";$c="int total = 0;\nfor (int i = 1; i <= $n; i++) total += i;\nprintf(\"%d\\n\", total);";$reason='반복 범위의 양 끝을 확인하고 누적합니다.';}
                elseif($kind===1){$value=$n%2===0?$n/2:$n*3+1;$body="n = $n\nif n % 2 == 0:\n    n = n // 2\nelse:\n    n = n * 3 + 1\nprint(n)";$c="int n = $n;\nif (n % 2 == 0) n = n / 2;\nelse n = n * 3 + 1;\nprintf(\"%d\\n\", n);";$reason='조건에 맞는 한쪽 분기만 실행합니다.';}
                else{$value=$n+2;$body="items = [$n, ".($n+1).', '.($n+2)."]\nprint(items[2])";$c="int items[] = { $n, ".($n+1).', '.($n+2)." };\nprintf(\"%d\\n\", items[2]);";$reason='배열과 리스트의 첫 인덱스는 0입니다.';}
                $code=$language==='python'?$body:"#include <stdio.h>\nint main(void) {\n$c\nreturn 0;\n}";
                $out[]=Q::make($language.'-output-'.$i,'실행 결과 추적',$kind===0?'normal':'easy','표준 입력 없이 실행할 때 출력되는 정수 하나를 구하세요.',array('rows'=>array(array('언어'=>$language==='python'?'Python 3.12':'C17'))),array(Q::field('value','출력값')),array('value'=>$value),$reason.' 출력값은 '.$value.'입니다.','변수의 값이 바뀌는 문장만 순서대로 추적하세요.',array('type'=>'code','code'=>$code));
            }
        }
        return $out;
    }
}
