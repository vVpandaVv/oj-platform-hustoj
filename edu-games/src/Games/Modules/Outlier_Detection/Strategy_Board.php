<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Outlier_Detection;
use EduGames\Boards\Rules as R;

final class Strategy_Board {
    public const VERSION='outlier-2026-09-v3';
    private const REPEAT_LIMIT=2;
    public static function levels(): array {
        $levels=array(
            'board-01'=>array('title'=>'운동장 기상 기록','case'=>'weather','reference'=>array(18,28)),
            'board-02'=>array('title'=>'서로 다른 두 온실','case'=>'greenhouse','reference'=>array(16,22)),
            'board-03'=>array('title'=>'마라톤 기록 보관소','case'=>'race','reference'=>array(170,220)),
        )+Campaign::levels();
        foreach($levels as &$d){
            $count=count(array_filter(self::records($d),self::unusual(...)));
            $d['goal']='평소의 같은 조건과 다른 값을 조사 대상으로 고르세요. 실제 관측은 지키고, 확인된 오류만 제외하세요.';
            $d['budget']=18;$d['referenceCost']=$count*2+1;
        }unset($d);
        return $levels;
    }
    private static function records(array $d): array {
        if(isset($d['records']))return $d['records'];
        $values=match($d['case']){'weather'=>array(21,22,23,95,24,41),'greenhouse'=>array(18,19,34,35,99,36),default=>array(180,185,190,195,205,130)};
        $rows=array();foreach($values as $i=>$v){
            $bad=($d['case']==='weather'&&$i===3)||($d['case']==='greenhouse'&&$i===4);
            $warm=$d['case']==='greenhouse'&&$i>=2;$group=$warm?'열대 온실':($d['case']==='greenhouse'?'서늘한 온실':'같은 관측 조건');$unit=$d['case']==='race'?'분':'°C';
            $context=$d['case']==='race'&&$i===5?'상위권 선수가 공식 대회에서 130분에 완주했습니다. 드물지만 실제 기록입니다.':($d['case']==='weather'&&$i===5?'폭염 당일 운동장 표면에서 41°C가 관측됐습니다. 다른 기록과 측정 조건이 달랐습니다.':($bad?'현장 메모에는 숫자의 정확성에 대한 확인이 없습니다. 원본 또는 측정기를 확인할 필요가 있습니다.':'현장 관측 기록에도 '.$v.$unit.'가 남아 있습니다. 관측 시각과 장소가 일치합니다.'));
            $rows[]=array('id'=>chr(65+$i),'value'=>$v,'unit'=>$unit,'group'=>$group,'reference'=>$warm?array(30,38):$d['reference'],'bad'=>$bad,
                'evidence'=>array('context'=>$context,
                    'unit'=>$d['case']==='weather'&&$bad?'원래 기록은 95°F, 화면에는 95°C. 화씨 95°F는 섭씨 약 35°C인데 단위 변환이 빠졌습니다.':'원장의 단위도 '.$unit.'로 일치합니다.',
                    'range'=>$d['case']==='greenhouse'&&$bad?'센서 자체 점검에서 고장이 확인됐습니다. 온실 제어기의 별도 기록은 35°C입니다.':'측정기의 사용 범위만으로 실제 관측인지 확정할 수 없습니다.',
                    'repeat'=>$bad?'별도 측정기로 확인한 값은 35°C입니다. 기존 기록의 오류가 확인됐습니다.':'독립 재확인에서도 '.$v.$unit.'로 일치합니다.'),
                'sufficient'=>$bad?array($d['case']==='weather'?'unit':'range','repeat'):array('context','repeat'),
                'costs'=>array('context'=>1,'unit'=>1,'range'=>1,'repeat'=>4));
        }
        return $rows;
    }
    private static function unusual(array $row): bool {return $row['value']<$row['reference'][0]||$row['value']>$row['reference'][1];}
    public static function create(string $id): array {
        return R::begin($id,self::levels())+array('phase'=>'compare','marked'=>array(),'initialCandidates'=>null,'revealed'=>array(),'routes'=>array(),'repeatCount'=>0);
    }
    public static function apply(array $s,array $m): array {
        R::guard($s);$d=self::levels()[$s['level']];$rows=self::records($d);
        $type=R::choice($m,'type',array('mark','investigate','inspect','route','finish'));
        if($type==='mark'){
            if($s['phase']!=='compare')throw new \InvalidArgumentException('첫 조사 대상 선택은 이미 확정됐습니다.');
            $i=R::index($m,'record',6);R::charge($s,0);
            if(in_array($i,$s['marked'],true))$s['marked']=array_values(array_diff($s['marked'],array($i)));else $s['marked'][]=$i;
            $s['message']='조사 대상으로 고른 기록 '.count($s['marked']).'개.';
        }elseif($type==='investigate'){
            if($s['phase']!=='compare')throw new \InvalidArgumentException('이미 조사를 시작했습니다.');
            R::charge($s,0);$s['initialCandidates']=$s['marked'];$s['phase']='investigate';$s['routes']=array_fill(0,6,'keep');
            foreach($s['marked'] as $i)unset($s['routes'][$i]);
            $s['message']='첫 선택을 확정했습니다. 선택하지 않은 기록은 사용 대기입니다. 추가 조사는 가능합니다.';
        }else{
            if($s['phase']!=='investigate')throw new \InvalidArgumentException('먼저 평소 자료와 비교해 조사 대상을 확정하세요.');
            if($type==='inspect'){
                $i=R::index($m,'record',6);$e=R::choice($m,'evidence',array('context','unit','range','repeat'));
                if(isset($s['revealed'][$i][$e]))throw new \InvalidArgumentException('이미 확보한 증거입니다.');
                if($e==='repeat'&&$s['repeatCount']>=self::REPEAT_LIMIT)throw new \InvalidArgumentException('재측정은 한 사건에서 2회까지만 가능합니다.');
                R::charge($s,$rows[$i]['costs'][$e]);if($e==='repeat')$s['repeatCount']++;
                if(empty($s['revealed'][$i]))unset($s['routes'][$i]);
                $s['revealed'][$i][$e]=$rows[$i]['evidence'][$e];$s['message']='기록 '.$rows[$i]['id'].' · '.$rows[$i]['evidence'][$e];
            }elseif($type==='route'){
                $i=R::index($m,'record',6);$route=R::choice($m,'route',array('keep','flag','quarantine'));
                if(!self::review($rows[$i],$s['revealed'][$i]??array(),null)['supported'])throw new \InvalidArgumentException('확보한 사실만으로는 아직 판단할 수 없습니다.');
                if(($s['routes'][$i]??'')===$route)throw new \InvalidArgumentException('이미 그 구역에 있습니다.');
                R::charge($s,1);$s['routes'][$i]=$route;$review=self::review($rows[$i],$s['revealed'][$i],$route);
                if($review['state']==='recheck')$s['mistakes']++;
                $s['message']='기록 '.$rows[$i]['id'].' · '.$review['message'];
            }else{
                if(count($s['routes'])!==6)throw new \InvalidArgumentException('조사한 기록의 사용·보류·제외를 결정하세요.');
                R::charge($s,1);$missed=0;$wrong=0;$flags=0;$unsupported=0;$audit=array();$expected=array();
                foreach($rows as $i=>$r){
                    $unusual=self::unusual($r);if($unusual)$expected[]=$i;
                    $route=$s['routes'][$i];$support=array_intersect($r['sufficient'],array_keys($s['revealed'][$i]??array()));
                    if($r['bad']===true&&$route!=='quarantine')$missed++;
                    if($r['bad']===false&&$route==='quarantine')$wrong++;
                    if($r['bad']===false&&$route==='flag')$flags++;
                    if(($r['bad']===null&&$route!=='flag')||($unusual&&!$support))$unsupported++;
                    $audit[]=array('id'=>$r['id'],'unusual'=>$unusual,'selected'=>in_array($i,$s['initialCandidates'],true),'finding'=>$r['bad']===null?'판단 미확정':($r['bad']?'확인된 오류':'정상 기록'),'evidence'=>$r['evidence'][$r['sufficient'][0]]);
                }
                $omitted=count(array_diff($expected,$s['initialCandidates']));$extra=count(array_diff($s['initialCandidates'],$expected));
                $penalty=100*($omitted+$extra)+30*$flags;$won=$missed===0&&$wrong===0&&$unsupported===0;
                $s['audit']=$audit;$s['evaluation']=array('첫 선택에서 놓친 후보'=>$omitted,'불필요하게 고른 후보'=>$extra,'발견 감점'=>100*($omitted+$extra),'추가 조사 감점'=>20*max(0,$s['spent']-$d['referenceCost']),'판단 수정 감점'=>50*$s['mistakes'],'정상 보류 감점'=>30*$flags,'미처리 오류'=>$missed,'정상 오제외'=>$wrong,'근거 없는 확정'=>$unsupported);
                R::finish($s,$won,$d,$won?'사건 해결. 첫 발견과 필요한 조사에 따라 기록이 결정됩니다.':'조사 종료. 놓친 후보와 관측 근거를 확인하세요.',$penalty);
            }
        }
        return R::after($s);
    }
    public static function view(array $s): array {
        $d=self::levels()[$s['level']];$rows=self::records($d);$reviews=array();$audit=$s['audit']??array();
        foreach($rows as $i=>$row)$reviews[]=self::review($row,$s['revealed'][$i]??array(),$s['routes'][$i]??null);
        if($s['status']!=='playing'&&!$audit)foreach($rows as $i=>$row)$audit[]=array('id'=>$row['id'],'unusual'=>self::unusual($row),'selected'=>in_array($i,$s['initialCandidates']??array(),true),'finding'=>$row['bad']===null?'판단 미확정':($row['bad']?'확인된 오류':'정상 기록'),'evidence'=>$row['evidence'][$row['sufficient'][0]]);
        foreach($rows as &$r)unset($r['bad'],$r['evidence'],$r['sufficient']);unset($r);
        $view=R::view($s,$d);unset($view['referenceCost']);
        if($s['phase']==='investigate')$view['goal']='실제 관측은 지키고 확인된 오류만 제외하세요. 결론을 낼 수 없는 기록은 보류하세요.';
        return $view+array('phase'=>$s['phase'],'marked'=>$s['marked'],'initialCandidates'=>$s['initialCandidates'],'repeatRemaining'=>self::REPEAT_LIMIT-$s['repeatCount'],'records'=>$rows,'revealed'=>$s['revealed'],'routes'=>$s['routes'],'reviews'=>$reviews,'audit'=>$audit);
    }
    private static function review(array $row,array $revealed,?string $route): array {
        $support=array_values(array_intersect($row['sufficient'],array_keys($revealed)));
        if(!$revealed)return array('state'=>'unseen','supported'=>false,'message'=>'현장 확인 전입니다. 평소 범위 안의 값도 오류가 전혀 없다는 뜻은 아닙니다.');
        if(!$support)return array('state'=>'investigating','supported'=>false,'message'=>'지금 확인한 사실만으로는 숫자가 실제 관측과 일치하는지 아직 알 수 없습니다.');
        if($route===null)return array('state'=>'ready','supported'=>true,'message'=>'판단할 사실이 확보됐습니다.');
        $appropriate=$row['bad']===null?$route==='flag':($row['bad']?$route==='quarantine':$route!=='quarantine');
        $message=$appropriate?($row['bad']===null?'확인 전까지 보류했습니다. 불확실한 값을 억지로 확정하지 않았습니다.':($row['bad']?'오류가 확인된 기록을 사용 대상에서 제외했습니다.':($route==='flag'?'실제 관측이 확인됐지만 보류 상태입니다. 정상 기록을 사용하면 보류 감점이 없습니다.':'실제로 관측된 값을 지켰습니다. 다른 값과 멀어도 오류는 아닐 수 있습니다.'))):($row['bad']===null?'증거가 서로 충돌합니다. 아직 정상이나 오류로 확정할 수 없습니다.':($row['bad']?'오류가 확인된 기록이 사용·보류 목록에 남아 있습니다.':'실제 관측이 확인된 정상 기록을 제외한 상태입니다.'));
        return array('state'=>$appropriate?'settled':'recheck','supported'=>true,'message'=>$message);
    }
    public static function solution(string $id): array {
        $rows=self::records(self::levels()[$id]);$out=array();
        foreach($rows as $i=>$r)if(self::unusual($r))$out[]=array('type'=>'mark','record'=>$i);
        $out[]=array('type'=>'investigate');
        foreach($rows as $i=>$r)if(self::unusual($r)){$out[]=array('type'=>'inspect','record'=>$i,'evidence'=>$r['sufficient'][0]);$out[]=array('type'=>'route','record'=>$i,'route'=>$r['bad']===null?'flag':($r['bad']?'quarantine':'keep'));}
        $out[]=array('type'=>'finish');return $out;
    }
}
