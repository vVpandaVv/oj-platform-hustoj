<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Keyword_Rain;
use EduGames\Learning\Question as Q;

final class Learning_Content {
    public static function glossary(): array {
        return array(
            array('python','if','조건에 따라 실행을 나눈다.','reserved','if ready:'),
            array('python','for','반복 가능한 값들을 차례로 순회한다.','reserved','for x in items:'),
            array('python','return','함수 실행을 끝내고 값을 돌려준다.','reserved','return total'),
            array('python','len','객체의 항목 수를 구하는 내장 함수다.','builtin','len(items)'),
            array('python','append','리스트 끝에 항목 하나를 추가하는 메서드다.','method','items.append(3)'),
            array('python','self','관례적으로 인스턴스를 가리키는 매개변수 이름이다.','identifier','def run(self):'),
            array('c','int','정수 자료형을 나타내는 키워드다.','reserved','int count = 0;'),
            array('c','while','조건이 참인 동안 반복한다.','reserved','while (n > 0)'),
            array('c','sizeof','자료형이나 객체의 크기를 바이트 단위로 구하는 연산자다.','reserved','sizeof(int)'),
            array('c','printf','서식에 맞추어 출력하는 표준 라이브러리 함수다.','library','printf("%d", n);'),
            array('c','main','호스티드 C 프로그램의 시작 함수 이름이다.','identifier','int main(void)'),
            array('c','pointer','다른 객체나 함수를 가리키는 값을 다루는 개념이다.','concept','int *p = &n;'),
            array('javascript','const','재할당할 수 없는 바인딩을 선언한다. 객체 내부까지 불변인 것은 아니다.','reserved','const items = [];'),
            array('javascript','let','블록 범위 변수를 선언한다.','reserved','let count = 0;'),
            array('javascript','await','async 함수 안에서 Promise가 처리되기를 기다리는 표현식이다.','reserved','await task();'),
            array('javascript','push','배열 끝에 항목을 추가하는 메서드다.','method','items.push(3);'),
            array('javascript','map','각 원소에 함수를 적용한 새 배열을 만드는 메서드다.','method','items.map(x => x * 2)'),
            array('javascript','filter','조건을 만족하는 원소로 새 배열을 만드는 메서드다.','method','items.filter(x => x > 0)'),
            array('web','div','HTML에서 의미 없는 일반 구획을 나타내는 요소다.','html','<div>내용</div>'),
            array('web','label','HTML에서 입력 컨트롤의 이름을 연결하는 요소다.','html','<label for="name">이름</label>'),
            array('web','alt','이미지를 대신할 텍스트를 지정하는 HTML 속성이다.','attribute','<img src="map.png" alt="학교 지도">'),
            array('web','color','글자의 전경색을 지정하는 CSS 속성이다.','css','color: red;'),
            array('web','margin','테두리 바깥 여백을 지정하는 CSS 속성이다.','css','margin: 8px;'),
            array('web','padding','내용과 테두리 사이 안쪽 여백을 지정하는 CSS 속성이다.','css','padding: 8px;'),
            array('data','median','정렬한 자료에서 가운데 위치를 대표하는 값이다.','concept','1, 3, 8의 중앙값 = 3'),
            array('data','mean','관측값 합을 관측 수로 나눈 값이다.','concept','2, 4, 6의 평균 = 4'),
            array('data','mode','자료에서 가장 자주 나타나는 값이다. 동률일 수 있다.','concept','2, 2, 5의 최빈값 = 2'),
            array('data','outlier','정해진 기준이나 맥락에서 다른 관측과 크게 다른 값이다.','concept','이상치가 곧 입력 오류인 것은 아님'),
            array('data','feature','모델이 입력으로 사용하는 관측 특성이다.','concept','부품 폭, 높이'),
            array('data','label','지도 학습에서 예측할 대상 값이다.','concept','검수한 부품의 A/B 분류'),
        );
    }
    public static function questions(): array {
        $categories=array('reserved'=>'예약어·키워드','builtin'=>'내장 함수','library'=>'라이브러리 함수','method'=>'메서드','identifier'=>'식별자·관례 이름','concept'=>'개념 용어','html'=>'HTML 요소','attribute'=>'HTML 속성','css'=>'CSS 속성');
        $bank=self::glossary();$out=array();
        foreach($bank as $i=>$entry){[$language,$term,$meaning,$kind,$example]=$entry;$options=array();
            foreach($bank as $candidate){if($candidate[0]===$language){$options[$candidate[1]]=$candidate[1];}}
            $out[]=Q::make('meaning-'.$i,'용어와 분류','easy',strtoupper($language).' 분야: '.$meaning,array('rows'=>array()),array(Q::field('term','뜻에 맞는 용어','choice',$options),Q::field('kind','용어의 분류','choice',$categories)),array('term'=>array($term),'kind'=>array($kind)), $term.' · '.$categories[$kind].' · 예: '.$example,'예약어, 함수, 메서드, 개발자가 정한 이름은 서로 다릅니다.',array('type'=>'table'));
        }
        return $out;
    }
}
