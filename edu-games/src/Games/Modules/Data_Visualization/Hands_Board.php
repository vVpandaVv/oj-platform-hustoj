<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Visualization;
use EduGames\Play\Hands_Board as Base;

final class Hands_Board extends Base {
    public const CONTENT_VERSION='chart-action-v4';
    public const VIEWS=array('hour'=>'1시간씩','pair'=>'2시간씩 합계','total'=>'오전 전체 합계');
    public static function levels(): array {
        $levels=array('board-01'=>array('title'=>'오늘의 출동 · 120초 · '.\EduGames\Support\Clock::today()),'practice'=>array('title'=>'전체 장면 연습 · 시간제한 없음','unranked'=>true));
        foreach(Visualization_Scenes::NAMES as $scene=>$name)$levels['practice-'.$scene]=array('title'=>$name.' 연습','unranked'=>true);
        return $levels;
    }
    public static function create(string $level): array {
        $s=self::fresh($level,$level==='board-01'?120:7200);$s['schema']=4;$s['unranked']=$level!=='board-01';
        $s['rescued']=0;$s['bestCombo']=0;$s['sceneBag']=array();$s['sceneStats']=array();return self::batch($s);
    }
    private static function batch(array $s): array {
        if(!$s['sceneBag'])$s['sceneBag']=self::shuffle($s,array('hour','line','pie','histogram','scatter','total','pair'));
        $s['kind']=array_shift($s['sceneBag']);
        if(str_starts_with($s['level'],'practice-')){$kind=substr($s['level'],9);$s['kind']=$kind==='bar'?array('hour','total','pair')[$s['turn']%3]:$kind;}
        $s['phase']='choose';$s['rows']=array();unset($s['feedback'],$s['supply'],$s['budget'],$s['binStart'],$s['binWidth'],$s['binCount'],$s['window'],$s['scatterGoal'],$s['minDistance']);
        if(!isset(self::VIEWS[$s['kind']]))return Visualization_Scenes::populate($s);
        $s['scene']='bar';$s['capacity']='구조선 1척';$s['contextNote']='모의 출동 기록 · 매시간 새로 접수한 요청 · 1건당 1명, 중복 없음';
        foreach(array('동쪽','중앙','서쪽') as $r=>$region)foreach(array('09시','10시','11시','12시') as $t=>$time){
            $value=self::number($s,2,45);
            $s['rows'][]=array('region'=>$region,'time'=>$time,'value'=>$value);
        }
        $missions=array('hour'=>array('한 시간 긴급 출동','한 지역의 한 시간만 지원할 수 있습니다. 그 한 시간에 요청이 가장 많았던 곳으로 출동하세요.'),'pair'=>array('두 시간 연속 출동','09~10시 또는 11~12시를 맡습니다. 두 시간의 요청 합계가 가장 큰 지역과 시간대를 고르세요.'),'total'=>array('오전 전담 출동','한 지역의 오전 전체를 맡습니다. 09~12시 요청의 합계가 가장 큰 지역을 고르세요.'));
        [$s['mission'],$s['objective']]=$missions[$s['kind']];
        return $s;
    }
    public static function bars(array $s,string $view): array {
        self::choice($view,array_keys(self::VIEWS));$bars=array();
        foreach($s['rows'] as $row){
            $period=$view==='total'?'오전 전체':($view==='pair'?($row['time']<'11시'?'09~10시':'11~12시'):$row['time']);
            $id=$row['region'].'|'.$period;
            if(!isset($bars[$id]))$bars[$id]=array('id'=>$id,'region'=>$row['region'],'period'=>$period,'value'=>0,'parts'=>array());
            $bars[$id]['value']+=$row['value'];$bars[$id]['parts'][]=$row;
        }
        return array_values($bars);
    }
    private static function done(array $s): array {
        $s['status']='won';return self::note($s,($s['unranked']?'연습':'출동').' 완료 · 임무 성공 '.$s['completed'].'회'.($s['unranked']?' · 순위 제외':' · '.$s['score'].'점'));
    }
    public static function apply(array $s,array $m): array {
        if(($s['schema']??0)!==4)throw new \InvalidArgumentException('그래프 출동 규칙이 변경되었습니다. 새 도전을 시작하세요.');
        self::guard($s,$m,array('dispatch','next','finish'));
        if($m['type']==='finish'||(!$s['unranked']&&$s['phase']!=='review'&&microtime(true)>=$s['deadline']))return self::done($s);
        if($m['type']==='next'){
            if($s['phase']!=='review')throw new \InvalidArgumentException('먼저 이번 출동을 완료하세요.');
            if(!$s['unranked'])$s['deadline']=microtime(true)+$s['pausedRemaining'];
            unset($s['pausedRemaining']);
            return self::batch($s);
        }
        if($s['phase']!=='choose')throw new \InvalidArgumentException('이미 출동한 임무입니다. 다음 임무로 이동하세요.');
        if($s['scene']!=='bar')return self::resolve($s,Visualization_Scenes::judge($s,$m));
        $view=self::choice($m['view']??null,array_keys(self::VIEWS));
        if($view!==$s['kind'])throw new \InvalidArgumentException('출동 가능한 시간 범위와 그래프의 시간 묶음을 맞춰 주세요.');
        $bars=self::bars($s,$view);$id=self::choice($m['bar']??null,array_column($bars,'id'));$selected=$bars[array_search($id,array_column($bars,'id'),true)];
        $best=$bars[0];foreach($bars as $bar)if($bar['value']>$best['value'])$best=$bar;
        $comparisons=array();foreach(array_keys(self::VIEWS) as $mode){$set=self::bars($s,$mode);usort($set,fn($a,$b)=>$b['value']<=>$a['value']);$comparisons[$mode]=$set[0];}
        $s['rescued']+=$selected['value'];
        return self::resolve($s,array('correct'=>$selected['value']===$best['value'],'selected'=>$selected,'best'=>$best,'comparisons'=>$comparisons));
    }
    private static function resolve(array $s,array $feedback): array {
        $correct=$feedback['correct'];$before=$s['score'];
        $s['combo']=$correct?$s['combo']+1:0;$s['bestCombo']=max($s['bestCombo'],$s['combo']);$s['turn']++;
        if($correct)$s['completed']++;
        $base=array('hour'=>100,'total'=>120,'pair'=>140,'line'=>150,'pie'=>200,'histogram'=>150,'scatter'=>140)[$s['kind']];
        $s['score']=$s['unranked']?0:max(0,$s['score']+($correct?$base+min(5,$s['combo'])*10:-100));
        $stats=$s['sceneStats'][$s['scene']]??array('attempts'=>0,'success'=>0);$stats['attempts']++;if($correct)$stats['success']++;
        $s['sceneStats'][$s['scene']]=$stats;
        if(!$s['unranked'])$s['pausedRemaining']=max(0,$s['deadline']-microtime(true));
        $s['feedback']=$feedback+array('delta'=>$s['score']-$before);$s['phase']='review';
        return self::note($s,$correct?'임무 성공 · 판단 근거를 확인해 보세요.':'임무 조건과 그래프의 정보를 다시 비교해 보세요.');
    }
    public static function view(array $s): array {
        $v=parent::view($s);$v['timerPaused']=!$s['unranked']&&$s['phase']==='review';
        if($v['timerPaused'])$v['remaining']=round($s['pausedRemaining'],2);
        if($s['unranked'])$v['remaining']=null;
        unset($v['sceneBag'],$v['pausedRemaining']);return $v;
    }
}
