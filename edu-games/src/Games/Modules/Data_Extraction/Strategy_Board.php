<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Extraction;
use EduGames\Boards\Rules as R;

final class Strategy_Board {
    public static function levels(): array {
        $levels=array(
            'board-01'=>array('title'=>'도서 배송소','goal'=>'과학책 주문과 저렴한 책 주문을 각각 포장하세요.','budget'=>22,'referenceCost'=>10,'names'=>array('우주','식물','화산','역사','미술','해양'),'groups'=>array('과학','과학','과학','인문','예술','과학'),'values'=>array(4,7,3,8,2,6),'orders'=>array(array('label'=>'과학 · 가격 5 이하 · 이름/가격만','group'=>'과학','max'=>5,'sort'=>false),array('label'=>'전체 · 가격 3 이하 · 이름/가격만','group'=>null,'max'=>3,'sort'=>false))),
            'board-02'=>array('title'=>'지역 농산물 센터','goal'=>'두 주문의 필터와 정렬을 공유할 수 있는지 살펴보세요.','budget'=>24,'referenceCost'=>12,'names'=>array('사과','배','포도','딸기','감자','당근'),'groups'=>array('과일','과일','과일','과일','채소','채소'),'values'=>array(8,4,6,3,2,5),'orders'=>array(array('label'=>'과일 · 가격 6 이하 · 가격 오름차순 · 이름/가격','group'=>'과일','max'=>6,'sort'=>true),array('label'=>'전체 · 가격 4 이하 · 가격 오름차순 · 이름/가격','group'=>null,'max'=>4,'sort'=>true))),
            'board-03'=>array('title'=>'행사 매출 집계소','goal'=>'분류별 합계와 낮은 매출 목록을 서로 다른 상자에 담으세요.','budget'=>24,'referenceCost'=>10,'names'=>array('엽서','책갈피','쿠키','주스','리본','배지'),'groups'=>array('문구','문구','음식','음식','장식','장식'),'values'=>array(6,4,9,3,5,3),'orders'=>array(array('label'=>'분류별 매출 합계 · 분류 오름차순','aggregate'=>true),array('label'=>'전체 · 매출 4 이하 · 이름/값만','group'=>null,'max'=>4,'sort'=>false))),
        );
        return Campaign::extend($levels);
    }
    public static function rows(array $d): array {
        $rows=array();foreach($d['names'] as $i=>$name){$rows[]=array('id'=>chr(65+$i),'name'=>$name,'group'=>$d['groups'][$i],'value'=>$d['values'][$i]);}return $rows;
    }
    public static function create(string $id): array { return R::begin($id,self::levels())+array('slots'=>array(null,null,null,null),'delivered'=>array()); }
    private static function expected(array $d,array $order): array {
        $box=array('rows'=>self::rows($d),'shape'=>'full','trail'=>array());
        foreach($order['steps'] as $step)$box=self::operate($box,$step,$d);
        return $box['rows'];
    }
    private static function operate(array $box,array $m,array $d): array {
        $type=$m['type'];
        if(in_array($type,array('filter','project','group'),true)&&$box['shape']!=='full')throw new \InvalidArgumentException('필터·열 선택·집계에는 필요한 열이 남아 있는 원자료가 필요합니다.');
        if($type==='filter'){
            $field=R::choice($m,'field',array('group','value'));$op=$m['op']??($field==='group'?'eq':'lte');
            if(!in_array($op,$field==='group'?array('eq','ne'):array('lte','gte','eq','range','missing','present'),true))throw new \InvalidArgumentException('조건 연산자를 확인하세요.');
            $value=$m['value']??null;$upper=$m['upper']??null;
            if($field==='group'&&(!is_string($value)||!in_array($value,$d['groups'],true)))throw new \InvalidArgumentException('분류를 확인하세요.');
            if($field==='value'&&!in_array($op,array('missing','present'),true)){
                if(!is_int($value)||$value<0||$value>100)throw new \InvalidArgumentException('기준 값은 0~100 정수입니다.');
                if($op==='range'&&(!is_int($upper)||$upper<$value||$upper>100))throw new \InvalidArgumentException('포함 범위의 끝 값을 확인하세요.');
            }
            $box['rows']=array_values(array_filter($box['rows'],static function($r)use($field,$op,$value,$upper){
                $v=$r[$field];if($op==='missing')return $v===null;if($op==='present')return $v!==null;if($v===null)return false;
                return match($op){'eq'=>$v===$value,'ne'=>$v!==$value,'lte'=>$v<=$value,'gte'=>$v>=$value,'range'=>$v>=$value&&$v<=$upper};
            }));
            $labels=array('eq'=>'=','ne'=>'≠','lte'=>'≤','gte'=>'≥','range'=>'범위','missing'=>'결측','present'=>'관측됨');
            $box['trail'][]=($field==='group'?'분류 ':'값 ').$labels[$op].' '.($value??'').($op==='range'?'~'.$upper:'');
        }elseif($type==='project'){
            $box['rows']=array_map(static fn($r)=>array('name'=>$r['name'],'value'=>$r['value']),$box['rows']);$box['shape']='project';$box['trail'][]='이름/값';
        }elseif($type==='group'){
            $aggregate=$m['aggregate']??'sum';$missing=$m['missing']??'strict';
            if(!in_array($aggregate,array('sum','mean','count'),true)||!in_array($missing,array('strict','skip'),true))throw new \InvalidArgumentException('집계 방식을 확인하세요.');
            $groups=array();foreach($box['rows'] as $r)$groups[$r['group']][]=$r['value'];$box['rows']=array();
            foreach($groups as $name=>$values){
                if($aggregate!=='count'&&$missing==='strict'&&in_array(null,$values,true))throw new \InvalidArgumentException('결측값이 있습니다. 관측값만 집계할지 명시하세요.');
                $known=array_values(array_filter($values,static fn($v)=>$v!==null));
                $value=$aggregate==='count'?count($values):(!$known?null:($aggregate==='sum'?array_sum($known):round(array_sum($known)/count($known),2)));
                if(is_float($value)&&floor($value)===$value)$value=(int)$value;
                $box['rows'][]=array('group'=>$name,'value'=>$value);
            }
            $box['shape']='group';$box['trail'][]='분류별 '.array('sum'=>'합계','mean'=>'평균','count'=>'행 수')[$aggregate].($missing==='skip'?' · 관측값만':'');
        }elseif($type==='sort'){
            $direction=$m['direction']??'asc';$tie=$m['tie']??'name';$by=$m['by']??($box['shape']==='group'?'group':'value');
            if(!in_array($direction,array('asc','desc'),true)||!in_array($tie,array('name','id'),true)||!in_array($by,array('value','group'),true))throw new \InvalidArgumentException('정렬 기준을 확인하세요.');
            if($by==='group'&&$box['shape']!=='group')throw new \InvalidArgumentException('분류 집계 결과에 적용할 정렬입니다.');
            if($tie==='id'&&$box['shape']!=='full')throw new \InvalidArgumentException('ID 동률 정렬은 열을 선택하기 전에 실행하세요.');
            usort($box['rows'],static function($a,$b)use($direction,$tie,$by){
                if($a[$by]===null||$b[$by]===null)$cmp=($a[$by]===null?1:0)<=>($b[$by]===null?1:0);
                else $cmp=($a[$by]<=>$b[$by])*($direction==='asc'?1:-1);
                return $cmp?:strcmp((string)($a[$tie]??$a['group']??''),(string)($b[$tie]??$b['group']??''));
            });$box['trail'][]=($by==='group'?'분류':'값').($direction==='asc'?' 오름차순':' 내림차순').' · 동률 '.($tie==='id'?'ID':'이름');
        }
        return $box;
    }
    public static function apply(array $s,array $m): array {
        R::guard($s);$d=self::levels()[$s['level']];$type=R::choice($m,'type',array('load','copy','clear','filter','sort','project','group','ship','finish'));
        if($type==='finish'){R::finish($s,false,$d,'미출고 주문이 남아 도전을 종료했습니다.');return $s;}
        $slot=R::index($m,'slot',4);
        if($type==='load') { if($s['slots'][$slot]!==null)throw new \InvalidArgumentException('빈 상자를 선택하세요.');R::charge($s,1);$s['slots'][$slot]=array('rows'=>self::rows($d),'shape'=>'full','trail'=>array('입고')); }
        elseif($type==='clear') { R::charge($s,1);$s['slots'][$slot]=null; }
        else {
            if($s['slots'][$slot]===null)throw new \InvalidArgumentException('먼저 자료를 입고하세요.');
            $box=&$s['slots'][$slot];
            if($type==='copy') { $to=R::index($m,'to',4);if($s['slots'][$to]!==null)throw new \InvalidArgumentException('복사할 빈 상자가 필요합니다.');R::charge($s,1);$s['slots'][$to]=$box;$s['slots'][$to]['trail'][]='중간 결과 복사'; }
            elseif(in_array($type,array('filter','sort','project','group'),true)) {
                $next=self::operate($box,$m,$d);R::charge($s,$type==='group'?2:1);$box=$next;
            }
            elseif($type==='ship') {
                $order=R::index($m,'order',count($d['orders']));if(in_array($order,$s['delivered'],true))throw new \InvalidArgumentException('이미 출고한 주문입니다.');R::charge($s,1);
                $actual=$box['rows'];$expected=self::expected($d,$d['orders'][$order]);
                if(!$d['orders'][$order]['ordered']){usort($actual,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));usort($expected,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));}
                if($actual===$expected){$s['delivered'][]=$order;$box=null;$s['message']='주문을 출고했습니다.';}else{$s['mistakes']++;$s['message']='주문 조건과 상자의 자료가 다릅니다. 상자는 그대로 남아 있습니다.';}
                if(count($s['delivered'])===count($d['orders']))R::finish($s,true,$d,'모든 주문 출고 완료. 중간 결과를 재사용해 처리 비용을 줄여 보세요.');
            }
            unset($box);
        }
        return R::after($s);
    }
    public static function view(array $s): array { $d=self::levels()[$s['level']];return R::view($s,$d)+array('source'=>self::rows($d),'groups'=>array_values(array_unique($d['groups'])),'orders'=>array_column($d['orders'],'label'),'slots'=>$s['slots'],'delivered'=>$s['delivered']); }
    public static function solution(string $id,bool $reuse=true): array {
        $d=self::levels()[$id];$moves=array();$prefix=0;
        if($reuse&&count($d['orders'])===2){$a=$d['orders'][0]['steps'];$b=$d['orders'][1]['steps'];while(isset($a[$prefix],$b[$prefix])&&$a[$prefix]===$b[$prefix])$prefix++;}
        if($prefix>1){$moves[]=array('type'=>'load','slot'=>0);foreach(array_slice($d['orders'][0]['steps'],0,$prefix) as $step)$moves[]=$step+array('slot'=>0);$moves[]=array('type'=>'copy','slot'=>0,'to'=>1);}else $prefix=0;
        foreach($d['orders'] as $i=>$o){$slot=$prefix?$i:0;if(!$prefix)$moves[]=array('type'=>'load','slot'=>$slot);foreach(array_slice($o['steps'],$prefix) as $step)$moves[]=$step+array('slot'=>$slot);$moves[]=array('type'=>'ship','slot'=>$slot,'order'=>$i);}return $moves;
    }
}
