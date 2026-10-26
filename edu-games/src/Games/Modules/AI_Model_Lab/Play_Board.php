<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\AI_Model_Lab;
use EduGames\Play\Board;

final class Play_Board extends Board {
    public static function levels(): array {return array('board-01'=>array('title'=>'한쪽 작업장의 표본'),'board-02'=>array('title'=>'두 작업장의 다른 재료'));}
    private static function bank(): array {static $bank=null;if($bank===null)$bank=require __DIR__.'/deck-experiments.php';return $bank;}
    public static function create(string $id): array {
        $s=self::begin($id);return $s+array('objective'=>'4개의 표본으로 로봇을 훈련하세요. 실험 오류를 보고 표본을 바꾼 뒤 새 재료 12개로 최종 작업을 합니다.','deck'=>array(0,1,2,3),'audits'=>array(),'repaired'=>0,'trained'=>null,'trials'=>0,'results'=>array(),'metrics'=>array(),'phase'=>'collect');
    }
    private static function key(array $s): string {$mask=0;foreach($s['deck'] as $id)$mask|=1<<$id;return $mask.':'.$s['repaired'];}
    private static function evaluate(array $s,string $split): array {
        $bank=self::bank()['boards'][$s['level']];$predictions=$bank['decks'][$s['trained']][$split];$results=array();
        foreach($bank[$split] as $i=>$p)$results[]=$p+array('prediction'=>$predictions[$i],'correct'=>$predictions[$i]===$p['good']);
        return $results;
    }
    public static function apply(array $s,array $m): array {
        $type=self::guard($s,$m,array('deck','audit','train','deploy'));
        if($s['trials']>=3&&in_array($type,array('deck','audit'),true))throw new \InvalidArgumentException('훈련 3회를 마쳤습니다. 현재 로봇으로 최종 작업을 시작하세요.');
        if($type==='deck'){
            $deck=$m['cards']??null;if(!is_array($deck)||count($deck)!==4||count(array_unique($deck,SORT_REGULAR))!==4)throw new \InvalidArgumentException('서로 다른 표본 4개를 선택하세요.');
            foreach($deck as $i)self::integer($i,0,7);sort($deck);$s['deck']=$deck;
            return self::note($s,'표본 덱을 바꿨습니다. 다시 훈련해야 로봇에 적용됩니다.');
        }
        if($type==='audit'){
            $i=self::integer($m['card']??null,0,7);if(in_array($i,$s['audits'],true))throw new \InvalidArgumentException('이미 검수한 표본입니다.');
            if(count($s['audits'])>=3)throw new \InvalidArgumentException('검수는 3개까지입니다.');
            $s['audits'][]=$i;if($i===6)$s['repaired']=1;
            $truth=self::bank()['boards'][$s['level']]['cards'][$i]['truth'];
            return self::note($s,($i+1).'번 원본 재료 검사: '.($truth?'재활용 가능':'재활용 불가').'. '.($i===6?'뒤바뀐 라벨을 바로잡았습니다.':'라벨이 원본과 같습니다.'));
        }
        if($type==='train'){
            if($s['trials']>=3)throw new \InvalidArgumentException('훈련 3회를 사용했습니다. 최종 작업을 시작하세요.');
            $s['trained']=self::key($s);$s['trials']++;$s['phase']='experiment';$s['results']=self::evaluate($s,'validation');
            $correct=count(array_filter($s['results'],static fn($p)=>$p['correct']));
            $s['metrics']=array('실험 성공'=>$correct,'실험 재료'=>12,'훈련 횟수'=>$s['trials']);
            return self::note($s,'실험 재료 '.$correct.'/12개 분류 성공. 틀린 재료와 가까운 학습 표본을 비교하세요.');
        }
        if($s['trained']===null)throw new \InvalidArgumentException('먼저 로봇을 훈련하세요.');
        if($s['trained']!==self::key($s))throw new \InvalidArgumentException('덱 변경 후 아직 훈련하지 않았습니다. 훈련하거나 마지막 덱으로 되돌리세요.');
        $s['results']=self::evaluate($s,'test');$s['phase']='final';
        $tp=0;$tn=0;$positive=0;$negative=0;
        foreach($s['results'] as $p){if($p['good']){$positive++;if($p['correct'])$tp++;}else{$negative++;if($p['correct'])$tn++;}}
        $s['metrics']=array('새 재료 성공'=>$tp+$tn,'전체'=>12,'가능 재료 성공'=>$tp.'/'.$positive,'불가 재료 성공'=>$tn.'/'.$negative);
        return self::finish($s,(int)round(500*($tp/$positive+$tn/$negative)),$tp+$tn>=10,'최종 작업 종료. 한 종류의 표본을 많이 넣는 것보다 작업 환경을 대표하는 표본이 중요합니다.');
    }
    public static function view(array $s): array {
        $bank=self::bank();$s['engine']=$bank['engine'].' '.$bank['engineVersion'].' / 1-최근접 이웃';
        $s['cards']=array_map(static function($c) use($s){if($s['repaired']&&$c['id']===6)$c['label']=$c['truth'];unset($c['truth']);return $c;},$bank['boards'][$s['level']]['cards']);
        return parent::view($s);
    }
}
