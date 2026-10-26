<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\AI_Classifier_Lab;
use EduGames\Boards\Rules as R;

final class Strategy_Board {
    public static function levels(): array {
        $levels=array(
            'board-01'=>array('title'=>'동서 분류 구역','goal'=>'검증·최종 평가 정확도 90% 이상. 적은 경계로 A 구역을 설계하세요.','budget'=>22,'referenceCost'=>5,'target'=>array(array('axis'=>'x','op'=>'gte','cut'=>5))),
            'board-02'=>array('title'=>'가운데 안전 구역','goal'=>'두 경계 사이의 A 구역을 설계하세요. 검증·최종 정확도 90% 이상이 목표입니다.','budget'=>24,'referenceCost'=>7,'target'=>array(array('axis'=>'x','op'=>'gte','cut'=>3),array('axis'=>'x','op'=>'lt','cut'=>8))),
            'board-03'=>array('title'=>'북동쪽 생태 구역','goal'=>'두 특성을 함께 고려해 A 구역을 설계하세요. 검증·최종 정확도 90% 이상이 목표입니다.','budget'=>24,'referenceCost'=>7,'target'=>array(array('axis'=>'x','op'=>'gte','cut'=>5),array('axis'=>'y','op'=>'gte','cut'=>5))),
        );
        $boundary=static fn($axis,$op,$cut)=>array('axis'=>$axis,'op'=>$op,'cut'=>$cut);
        $cases=array(
            4=>array('높이로 나누는 구역','축 평행',array($boundary('y','gte',6))),
            5=>array('서쪽 보호 구역','축 평행',array($boundary('x','lt',4))),
            6=>array('양쪽 대피 구역','다중 경계',array($boundary('x','lt',3),$boundary('x','gte',8)),'any'),
            7=>array('엇갈린 라벨 속 직선','잡음',array($boundary('x','gte',5))),
            8=>array('잡음이 섞인 사각 구역','잡음',array($boundary('x','gte',2),$boundary('x','lt',8),$boundary('y','gte',5))),
            9=>array('두 통로의 잘못된 관측','잡음',array($boundary('y','lt',3),$boundary('y','gte',8)),'any'),
            10=>array('가운데 표본이 없는 지도','편중 표본',array($boundary('x','gte',4))),
            11=>array('한쪽에 몰린 관측','편중 표본',array($boundary('y','gte',6))),
            12=>array('둥근 서식지 탐사','편중 표본',array()),
        );
        foreach($cases as $id=>$c){
            $levels[sprintf('board-%02d',$id)]=array('title'=>$c[0],'chapter'=>$c[1],'goal'=>($id===12?'직선 조각만으로는 둥근 경계를 표현하기 어렵습니다. 최근접 이웃과 표본 조사를 비교하세요.':($c[1]==='잡음'?'일부 훈련 라벨이 주변과 다릅니다. 모든 점을 억지로 맞추기 전에 독립 검증을 확인하세요.':'경계 조각 또는 최근접 이웃으로 분류 구역을 구성하세요.')).' 검증·최종 정확도 각각 90% 이상이 목표입니다.','budget'=>26,'referenceCost'=>$id===12?5:2*count($c[2])+3+(($c[3]??'all')==='any'?1:0),'target'=>$c[2],'targetMode'=>$c[3]??'all','noise'=>$c[1]==='잡음','biased'=>$c[1]==='편중 표본','circle'=>$id===12);
        }
        foreach($levels as $id=>&$d){$d['chapter']=$d['chapter']??($id==='board-01'?'축 평행':'다중 경계');$d['threshold']=90;}unset($d);
        return $levels;
    }
    public static function predict(array $p,array $boundaries,string $mode='all'): int {
        $checks=array();foreach($boundaries as $b){if($b!==null)$checks[]=$b['op']==='gte'?$p[$b['axis']]>=$b['cut']:$p[$b['axis']]<$b['cut'];}
        if(!$checks)return 0;return ($mode==='all'?!in_array(false,$checks,true):in_array(true,$checks,true))?1:0;
    }
    private static function truth(array $p,array $d): int {
        return !empty($d['circle'])?(int)(($p['x']-5)**2+($p['y']-5)**2<=9):self::predict($p,$d['target'],$d['targetMode']??'all');
    }
    private static function points(array $d,string $split): array {
        $points=array();$coords=$split==='training'?array(1,4,6,9):($split==='validation'?array(0.5,2.5,4.5,6.5,8.5,9.5):array(1.5,3.5,5.5,7.5));
        $xs=$coords;$ys=$coords;
        if($split==='training'&&!empty($d['biased'])){
            if(!empty($d['circle'])){$xs=array(1,3,5,7,9);$ys=$xs;}
            elseif($d['target'][0]['axis']==='x')$xs=array(1,2,8,9);
            else $ys=array(1,2,8,9);
        }
        foreach($xs as $x)foreach($ys as $y){$p=array('x'=>$x,'y'=>$y);$p['label']=self::truth($p,$d);if($split==='training'&&!empty($d['noise'])&&in_array(count($points),array(3,12),true))$p['label']=1-$p['label'];$points[]=$p;}return $points;
    }
    private static function survey(array $d,int $region): array {
        $coords=!empty($d['biased'])?array(array(3.25,5.25),array(4.25,5.25),array(4.25,6.25),array(6.25,6.25)):array(array(3.25,3.25),array(7.25,3.25),array(3.25,7.25),array(7.25,7.25));
        $p=array('x'=>$coords[$region][0],'y'=>$coords[$region][1]);$p['label']=self::truth($p,$d);return $p;
    }
    public static function experiment_data(): array {
        $out=array();foreach(self::levels() as $id=>$d){$out[$id]=array('training'=>self::points($d,'training'),'validation'=>self::points($d,'validation'),'test'=>self::points($d,'test'),'surveys'=>array_map(static fn($r)=>self::survey($d,$r),range(0,3)),'threshold'=>$d['threshold']);}return $out;
    }
    public static function neighbor_bank(): array {static $bank=null;return $bank??=require __DIR__.'/neighbor-experiments.php';}
    private static function experiment(array $s): array {$mask=0;foreach(array_keys($s['surveys']) as $r)$mask|=1<<$r;return self::neighbor_bank()['cases'][$s['level']][$mask][$s['k']];}
    private static function evaluate(array $s,string $split): array {
        if($s['method']==='knn')return self::experiment($s)[$split];
        $points=self::points(self::levels()[$s['level']],$split);if($split==='training')$points=array_merge($points,array_values($s['surveys']));
        return self::metric($points,$s['boundaries'],$s['mode']);
    }
    private static function metric(array $points,array $boundaries,string $mode): array {
        $matrix=array(array(0,0),array(0,0));foreach($points as $p)$matrix[$p['label']][self::predict($p,$boundaries,$mode)]++;
        return array('accuracy'=>round(100*($matrix[0][0]+$matrix[1][1])/count($points),2),'matrix'=>$matrix,'count'=>count($points));
    }
    public static function create(string $id): array {return R::begin($id,self::levels())+array('boundaries'=>array(null,null,null),'mode'=>'all','method'=>'boundary','k'=>3,'surveys'=>array(),'validation'=>null,'trials'=>0);}
    public static function apply(array $s,array $m): array {
        R::guard($s);$d=self::levels()[$s['level']];$type=R::choice($m,'type',array('boundary','remove','mode','method','neighbors','survey','validate','finish'));
        if($type==='boundary'){$slot=R::index($m,'slot',3);$axis=R::choice($m,'axis',array('x','y'));$op=R::choice($m,'op',array('gte','lt'));$cut=R::index($m,'cut',10);if($cut<1)throw new \InvalidArgumentException('경계는 1~9 눈금에 놓으세요.');R::charge($s,2);$s['boundaries'][$slot]=array('axis'=>$axis,'op'=>$op,'cut'=>$cut);$s['validation']=null;}
        elseif($type==='remove'){$slot=R::index($m,'slot',3);if($s['boundaries'][$slot]===null)throw new \InvalidArgumentException('빈 경계 슬롯입니다.');R::charge($s,1);$s['boundaries'][$slot]=null;$s['validation']=null;}
        elseif($type==='mode'){$mode=R::choice($m,'mode',array('all','any'));if($mode===$s['mode'])throw new \InvalidArgumentException('이미 적용된 조합입니다.');R::charge($s,1);$s['mode']=$mode;$s['validation']=null;}
        elseif($type==='method'){$method=R::choice($m,'method',array('boundary','knn'));if($s['method']===$method)throw new \InvalidArgumentException('이미 적용된 방식입니다.');R::charge($s,1);$s['method']=$method;$s['validation']=null;}
        elseif($type==='neighbors'){$k=$m['k']??null;if(!in_array($k,array(1,3,5),true)||$k===$s['k'])throw new \InvalidArgumentException('다른 이웃 수 1·3·5 중 선택하세요.');R::charge($s,1);$s['k']=$k;$s['validation']=null;}
        elseif($type==='survey'){$region=R::index($m,'region',4);if(isset($s['surveys'][$region]))throw new \InvalidArgumentException('이미 조사한 지역입니다.');R::charge($s,2);$s['surveys'][$region]=self::survey($d,$region);$s['validation']=null;$s['message']='추가 표본을 확보했습니다. 바뀐 관측 자료로 다시 검증하세요.';}
        elseif($type==='validate') {if($s['trials']>=3)throw new \InvalidArgumentException('검증권 3회를 모두 사용했습니다.');if($s['method']==='boundary'&&!array_filter($s['boundaries']))throw new \InvalidArgumentException('경계를 먼저 배치하세요.');R::charge($s,2);$s['validation']=self::evaluate($s,'validation');$s['trials']++;$s['message']='검증 결과를 받았습니다. 최종 평가 표본의 위치와 정답은 공개되지 않습니다.';}
        else {R::charge($s,1);$test=self::evaluate($s,'test');$won=$s['validation']!==null&&$s['validation']['accuracy']>=$d['threshold']&&$test['accuracy']>=$d['threshold'];$s['evaluation']=array('검증 정확도'=>$s['validation']['accuracy']??0,'최종 정확도'=>$test['accuracy'],'분류 방식'=>$s['method']==='knn'?'최근접 이웃 '.$s['k']:'경계 조각','경계 수'=>count(array_filter($s['boundaries'])));R::finish($s,$won,$d,$won?'구역 설계 완료. 성능을 유지하며 조사·설치 비용을 줄여 보세요.':'설계 종료. 현재 경계의 검증 결과 또는 최종 성능이 부족합니다.');}
        return R::after($s);
    }
    public static function view(array $s): array {$d=self::levels()[$s['level']];$sites=array();for($r=0;$r<4;$r++){$p=self::survey($d,$r);unset($p['label']);$sites[]=$p;}return R::view($s,$d)+array_intersect_key($s,array_flip(array('boundaries','mode','method','k','surveys','validation','trials')))+array('points'=>self::points($d,'training'),'sites'=>$sites,'surface'=>$s['method']==='knn'?self::experiment($s)['surface']:null,'training'=>self::evaluate($s,'training'));}
    public static function solution(string $id): array {
        $d=self::levels()[$id];$out=array();
        if(!empty($d['circle'])){$e=self::neighbor_bank()['solutions'][$id];$out[]=array('type'=>'method','method'=>'knn');if($e['k']!==3)$out[]=array('type'=>'neighbors','k'=>$e['k']);for($r=0;$r<4;$r++)if($e['mask']&(1<<$r))$out[]=array('type'=>'survey','region'=>$r);}
        else {foreach($d['target'] as $slot=>$b)$out[]=array('type'=>'boundary','slot'=>$slot)+$b;if(($d['targetMode']??'all')!=='all')$out[]=array('type'=>'mode','mode'=>$d['targetMode']);}
        $out[]=array('type'=>'validate');$out[]=array('type'=>'finish');return $out;
    }
}
