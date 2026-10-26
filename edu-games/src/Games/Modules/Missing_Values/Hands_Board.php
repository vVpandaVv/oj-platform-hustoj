<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Missing_Values;
use EduGames\Play\Hands_Board as Base;

final class Hands_Board extends Base {
    public const CONTENT_VERSION='missing-repair-v3';
    public const METHODS=array('linear'=>'선형보간','mean'=>'평균','median'=>'중앙값','mode'=>'최빈값','previous'=>'앞값 채우기','next'=>'뒷값 채우기','seasonal'=>'같은 주기값','nearest'=>'가장 닮은 자료','unknown'=>'미상으로 보류');

    public static function levels(): array {
        $levels=parent::levels();
        foreach(self::METHODS as $id=>$name)$levels['example-'.$id]=array('title'=>'방법 연습 · '.$name,'unranked'=>true);
        return $levels;
    }
    public static function create(string $level): array {
        $s=self::fresh($level,$level==='board-01'?120:7200);
        $s['schema']=2;$s['unranked']=$level!=='board-01';$s['queue']=array();
        return self::batch($s);
    }
    private static function batch(array $s): array {
        if($s['unranked'])$family=substr($s['level'],8);
        else{
            if(!$s['queue'])$s['queue']=self::shuffle($s,array_keys(self::METHODS));
            $family=array_shift($s['queue']);
        }
        $s['family']=$family;$s['phase']='solve';$s['rows']=array();$s['targets']=array();$s['truth']=array();
        unset($s['feedback'],$s['group'],$s['featureNames'],$s['xUnit']);
        $base=self::number($s,25,55);$variant=$s['variants'][$family]??self::number($s,0,1);$s['variants'][$family]=1-$variant;$s['unit']='';$s['tolerance']=1.1;
        if(in_array($family,array('linear','previous','next','seasonal'),true)){
            $s['kind']='series';$s['xUnit']='시점';
            if($family==='linear'){
                $s['context']=$variant?'일정한 속도로 자라는 새싹':'일정한 속도로 물을 채우는 탱크';$s['unit']=$variant?'mm':'L';
                $s['evidence']='중간에 속도가 바뀌거나 멈춘 기록은 없습니다. 관측 시각의 간격은 서로 다릅니다.';
                $times=array(0,2,3,5,7,8,11,12);$slope=self::number($s,2,5);$values=array_map(fn($t)=>$base+$slope*$t,$times);$holes=array(2,3,6);
                $s['methods']=array('mean','linear','previous','next');
                $s['reason']='시간 간격에 비례해 양쪽 관측값을 연결해야 합니다. 전체 평균이나 한쪽 값은 증가 흐름을 잃습니다.';
            }elseif($family==='previous'){
                $s['context']=$variant?'변경 시각이 남아 있는 조명 밝기':'명령이 바뀔 때만 달라지는 모터 속도';$s['unit']=$variant?'%':'rpm';
                $s['evidence']='새 명령이 내려오기 전까지 설정은 유지됩니다. 다음 변경은 시점 4입니다.';
                $times=range(0,7);$values=array($base,$base,$base,$base,$base+30,$base+30,$base+30,$base+30);$holes=array(2,3,6);
                $s['methods']=array('previous','linear','next','mean');
                $s['reason']='명령 사이에는 설정값이 유지되므로 마지막으로 확인한 앞값을 이어 씁니다. 보간하면 없었던 중간 설정을 만들어 냅니다.';
            }elseif($family==='next'){
                $s['context']=$variant?'구간 종료에 기록된 가열 장치 설정':'작업 종료에 기록된 기계 설정';$s['unit']=$variant?'°C':'단위';
                $s['evidence']='모든 작업이 끝난 뒤 복원하는 기록입니다. 시점 3의 값은 (0, 3] 구간 전체, 시점 6의 값은 (3, 6] 구간 전체에 적용됩니다.';
                $times=range(0,7);$values=array($base-20,$base,$base,$base,$base+30,$base+30,$base+30,$base+30);$holes=array(1,2,4);
                $s['methods']=array('linear','previous','next','mean');
                $s['reason']='이 자료는 구간 끝에서 그 구간 전체의 값을 보고합니다. 다음 기록으로 앞의 빈칸을 채웁니다. 미래를 예측하는 자료에 그대로 적용하면 안 됩니다.';
            }else{
                $s['context']=$variant?'매일 비슷하게 반복되는 도서관 이용':'아침·점심·저녁에 반복되는 급수량';$s['unit']=$variant?'명':'L';
                $s['evidence']='3일 동안 시간대별 패턴이 반복됩니다. 시점 간격보다 같은 시간대인지가 중요합니다.';
                $times=range(0,8);$cycle=array($base,$base+40,$base+10);$values=array_map(fn($i)=>$cycle[$i%3],$times);$holes=array(4,6,8);
                $s['methods']=array('linear','seasonal','mean','previous');
                $s['reason']='점심 빈칸에는 다른 날의 점심 관측을 사용합니다. 앞 시간대나 하루 전체 평균은 반복되는 봉우리를 지웁니다.';
            }
            foreach($times as $i=>$time){
                $row=array('x'=>$time,'label'=>(string)$time,'value'=>in_array($i,$holes,true)?null:$values[$i]);
                if($family==='seasonal'){$row['period']=array('아침','점심','저녁')[$i%3];$row['label']=((int)floor($i/3)+1).'일 '.$row['period'];}
                $s['rows'][]=$row;
                if(in_array($i,$holes,true)){$s['targets'][]=$row;$s['truth'][]=$values[$i];}
            }
        }elseif(in_array($family,array('mean','median','mode'),true)){
            $s['kind']='cohort';
            $groups=$family==='mode'?array('연극부','미술부','방송부'):($variant?array('북쪽 화단','남쪽 화단','온실 화단'):array('작은 나무상자','큰 나무상자','작은 철상자'));
            $g=self::number($s,0,2);$s['group']=$groups[$g];$s['unit']=$family==='mode'?'':($variant?'cm':'g');
            $s['context']=$family==='mode'?'동아리별 선호 재료 설문':($variant?'같은 환경에서 키운 모종 높이':'같은 규격으로 만든 포장의 측정값');
            if($family==='mean'){
                $offsets=array(-12,-8,-4,8,16);$s['truth']=array($base-1,$base,$base+1);
                $s['evidence']='반복 측정에 작은 쪽·큰 쪽 오차가 섞였습니다. 비교 대상의 관측 전체를 같은 비중으로 반영해 세 측정 누락을 복원합니다.';
                $s['reason']='이번 관측은 양쪽 오차가 상쇄되는 모음입니다. 전체를 평균하면 가려 둔 세 관측에 가깝고, 중앙값은 작은 쪽으로 치우쳤습니다. 실제 자료에서 평균이 늘 더 좋은 것은 아닙니다.';
                $s['methods']=array('mean','median','mode');
            }elseif($family==='median'){
                $offsets=array(-2,-1,0,1,42);$s['truth']=array($base-1,$base,$base+1);
                $s['evidence']='같은 대상의 관측 대부분은 모여 있는데, 한 번의 측정만 크게 튀었습니다. 그 관측도 원본에는 남아 있습니다.';
                $s['reason']='중앙값은 크게 튄 한 값에 덜 끌려갑니다. 이번 평균은 그 값 때문에 세 빈칸을 모두 크게 채웠습니다.';
                $s['methods']=array('mean','median','mode');
            }else{
                $palette=$variant?array('종이','나무','천'):array('파랑','초록','노랑');$popular=$palette[self::number($s,0,2)];$other=array_values(array_diff($palette,array($popular)));
                $values=array($popular,$popular,$other[0],$popular,$other[1]);$s['truth']=array($popular,$popular,$popular);
                $s['evidence']='숫자가 아닌 종류 자료이며 응답 순서는 의미가 없습니다. 비어 있는 세 설문에 같은 동아리의 대표 응답을 임시로 넣습니다. 실제 개인의 답을 확정하는 것은 아닙니다.';
                $s['reason']='종류 자료에는 산술 평균이나 중앙값을 적용할 수 없습니다. 가장 자주 나온 응답은 임시 대체의 한 방법이며, 실제 미응답자의 선호를 알아냈다는 뜻은 아닙니다.';
                $s['methods']=array('mode','previous','next');
            }
            if($family!=='mode')$values=array_map(fn($o)=>$base+$o,$offsets);
            foreach($values as $i=>$value)$s['rows'][]=array('label'=>'관측 '.($i+1),'group'=>$groups[$g],'x'=>$i,'value'=>$value);
            foreach(array(1,2) as $d)foreach(array(0,1) as $i)$s['rows'][]=array('label'=>'관측 '.(count($s['rows'])+1),'group'=>$groups[($g+$d)%3],'x'=>5+$d*2+$i,'value'=>$family==='mode'?$other[$d-1]:$base+45+$d*10+$i);
            $s['rows']=self::shuffle($s,$s['rows']);
            foreach(array(2.5,3.5,4.5) as $i=>$x)$s['targets'][]=array('x'=>$x,'label'=>'누락 '.($i+1),'group'=>$s['group'],'value'=>null);
        }elseif($family==='nearest'){
            $s['kind']='neighbors';$s['context']=$variant?'크기가 비슷한 통의 물 용량':'크기가 비슷한 화분의 흙 양';$s['unit']='단위';
            $s['featureNames']=array('가로 cm','높이 cm');$s['evidence']='전체 평균보다 가로와 높이가 비슷한 물건을 참고할 수 있습니다. 두 길이는 같은 cm 단위입니다.';
            $features=array(array(10,10),array(20,10),array(10,20),array(30,20),array(20,30),array(30,30));$offsets=array(0,8,13,29,41,54);
            foreach($features as $i=>$f)$s['rows'][]=array('label'=>'물건 '.($i+1),'features'=>$f,'value'=>$base+$offsets[$i]);
            foreach(array(1,3,4) as $i=>$id){$f=$features[$id];$f[0]+=1;$s['targets'][]=array('label'=>'누락 '.($i+1),'features'=>$f,'value'=>null);$s['truth'][]=$base+$offsets[$id];}
            $s['rows']=self::shuffle($s,$s['rows']);$s['methods']=array('mean','nearest','median');
            $s['reason']='각 빈칸에 가로·높이가 가장 가까운 물건의 값을 가져왔습니다. 세 대상의 크기가 다르므로 전체 평균 하나로 채우면 차이를 잃습니다.';
        }else{
            $s['kind']='unknown';$s['context']=$variant?'기록을 잃어버린 독립 추첨 결과':'자료가 남지 않은 별도 실험 결과';
            $s['evidence']='각 시행은 독립적이고 반복 관측이나 관련 특성이 없습니다. 필요한 것은 대략적인 대표값이 아니라 실제 시행 결과입니다.';
            foreach(array(12,75,28,90,43) as $i=>$v)$s['rows'][]=array('label'=>'다른 시행 '.($i+1),'value'=>$v);
            foreach(range(1,3) as $i)$s['targets'][]=array('label'=>'유실 시행 '.$i,'value'=>null);
            $s['truth']=array(null,null,null);$s['methods']=array('mean','unknown','median');
            $s['reason']='평균이나 중앙값을 계산할 수 있다는 것과 유실된 실제 결과를 복원할 수 있다는 것은 다릅니다. 미상으로 남기고 추가 자료를 확보해야 합니다.';
        }
        $s['methods']=self::shuffle($s,$s['methods']);
        return $s;
    }
    public static function source_ids(array $s): array {
        $ids=array();foreach($s['rows'] as $i=>$r)if($r['value']!==null&&($s['kind']!=='cohort'||$r['group']===$s['group']))$ids[]=$i;
        return $ids;
    }
    public static function predictions(array $s,string $method,array $ids): array {
        $rows=array_map(fn($id)=>$s['rows'][$id],$ids);$rows=array_values(array_filter($rows,fn($r)=>$r['value']!==null));
        $result=array();
        foreach($s['targets'] as $target){
            $use=$rows;$value=null;$formula='근거 부족';
            if($method==='unknown'){$result[]=array('value'=>null,'sources'=>array(),'formula'=>'미상 보류');continue;}
            if($method==='seasonal')$use=array_values(array_filter($rows,fn($r)=>($r['period']??null)===($target['period']??'')));
            if(in_array($method,array('mean','median','mode','seasonal'),true)&&$use){
                $values=array_column($use,'value');$numeric=count(array_filter($values,'is_numeric'))===count($values);
                if($method==='mode'){
                    $counts=array_count_values($values);$max=max($counts);$modes=array_keys(array_filter($counts,fn($n)=>$n===$max));
                    if(count($modes)===1){$value=$numeric?(float)$modes[0]:$modes[0];$formula='최다 '.$max.'회';}else $formula='최빈값 동률 · 한 값으로 정할 수 없음';
                }elseif($numeric){sort($values,SORT_NUMERIC);$n=count($values);$value=$method==='median'?($values[(int)floor(($n-1)/2)]+$values[(int)floor($n/2)])/2:array_sum($values)/$n;$formula=$method==='median'?'정렬 후 가운데 값':array_sum($values).' ÷ '.$n;}
            }elseif(in_array($method,array('previous','next','linear'),true)){
                $before=array_values(array_filter($rows,fn($r)=>isset($r['x'])&&$r['x']<$target['x']));$after=array_values(array_filter($rows,fn($r)=>isset($r['x'])&&$r['x']>$target['x']));
                usort($before,fn($a,$b)=>$b['x']<=>$a['x']);usort($after,fn($a,$b)=>$a['x']<=>$b['x']);$a=$before[0]??null;$b=$after[0]??null;
                $use=array();if($method==='previous'&&$a)$use=array($a);if($method==='next'&&$b)$use=array($b);
                if($use){$value=$use[0]['value'];$formula='시점 '.$use[0]['x'].'의 값';}
                if($method==='linear'&&$a&&$b&&is_numeric($a['value'])&&is_numeric($b['value'])){$use=array($a,$b);$value=$a['value']+($b['value']-$a['value'])*($target['x']-$a['x'])/($b['x']-$a['x']);$formula=$a['value'].' + ('.$b['value'].' − '.$a['value'].') × ('.$target['x'].' − '.$a['x'].') ÷ ('.$b['x'].' − '.$a['x'].')';}
            }elseif($method==='nearest'&&$rows){
                $distance=fn($r)=>array_sum(array_map(fn($a,$b)=>($a-$b)**2,$r['features'],$target['features']));
                usort($use,fn($a,$b)=>$distance($a)<=>$distance($b));$use=array($use[0]);$value=$use[0]['value'];$formula='거리 제곱 '.$distance($use[0]);
            }
            $result[]=array('value'=>is_float($value)?round($value,2):$value,'sources'=>array_column($use,'label'),'formula'=>$formula);
        }
        return $result;
    }
    private static function quality(array $s,array $predictions): array {
        $hits=0;$errors=array();foreach($s['truth'] as $i=>$actual){$v=$predictions[$i]['value'];$error=$actual===null?($v===null?0:null):(is_numeric($actual)&&is_numeric($v)?round(abs($v-$actual),2):($v===$actual?0:null));$errors[]=$error;if($error!==null&&$error<=$s['tolerance'])$hits++;}
        return array('hits'=>$hits,'errors'=>$errors);
    }
    public static function apply(array $s,array $m): array {
        if(($s['schema']??0)!==2)throw new \InvalidArgumentException('복원 규칙이 바뀌었습니다. 새 도전을 시작하세요.');
        if($s['status']!=='playing')throw new \InvalidArgumentException('완료된 도전입니다. 새 도전을 시작하세요.');
        if(($m['type']??'')==='finish'||($s['phase']!=='review'&&microtime(true)>=$s['deadline'])){if(!$s['unranked'])return self::end($s);$s['status']='won';return self::note($s,'방법 연습 완료 · 복원 성공 '.$s['completed'].'회. 순위에는 포함하지 않습니다.');}
        $type=self::guard($s,$m,array('restore','next'));
        if($type==='next'){
            if($s['phase']!=='review')throw new \InvalidArgumentException('먼저 복원 결과를 제출하세요.');
            if(!$s['unranked'])$s['deadline']=microtime(true)+($s['pausedRemaining']??max(0,$s['deadline']-microtime(true)));
            unset($s['pausedRemaining']);return self::batch($s);
        }
        if($s['phase']!=='solve')throw new \InvalidArgumentException('이미 평가한 데이터입니다. 다음 데이터를 여세요.');
        $method=self::choice($m['method']??null,$s['methods']);$eligible=self::source_ids($s);
        $ids=$s['kind']==='cohort'?self::ids($m['ids']??null,count($s['rows'])):$eligible;
        if($s['kind']==='cohort'&&count($ids)<2)throw new \InvalidArgumentException('비교할 관측을 두 개 이상 고르세요.');
        $sorted=$ids;sort($sorted);$scopeOk=$sorted===$eligible;
        $predictions=self::predictions($s,$method,$ids);$quality=self::quality($s,$predictions);$perfect=$scopeOk&&$quality['hits']===count($s['truth']);
        $comparisons=array();foreach($s['methods'] as $candidate){$p=self::predictions($s,$candidate,$eligible);$comparisons[]=array('method'=>$candidate,'predictions'=>$p)+self::quality($s,$p);}
        $before=$s['score'];$s=self::award($s,$perfect?60:-20,$perfect,$perfect?'세 빈칸 복원 성공. 다른 방법의 결과도 비교해 보세요.':'이번 복원은 통과하지 못했습니다. 원본과 비교해 보세요.');
        if($s['unranked'])$s['score']=0;
        $s['phase']='review';$s['feedback']=array('method'=>$method,'ids'=>$ids,'predictions'=>$predictions,'observed'=>$s['truth'],'scopeOk'=>$scopeOk,'comparisons'=>$comparisons,'reason'=>$s['reason'],'delta'=>$s['score']-$before)+$quality;
        if(!$s['unranked'])$s['pausedRemaining']=max(0,$s['deadline']-microtime(true));
        return $s;
    }
    public static function view(array $s): array {
        $v=parent::view($s);unset($v['queue'],$v['family'],$v['reason'],$v['variants']);
        $v['methodNames']=self::METHODS;
        $v['timerPaused']=!$s['unranked']&&$s['phase']==='review';
        if($v['timerPaused'])$v['remaining']=round($s['pausedRemaining']??$v['remaining'],2);
        unset($v['pausedRemaining']);
        if($s['unranked'])$v['remaining']=null;
        return $v;
    }
}
