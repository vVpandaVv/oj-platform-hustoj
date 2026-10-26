<?php
declare(strict_types=1);
require_once __DIR__.'/legacy-play-catalog.php';
use Legacy_Play_Catalog as Catalog;

function play_solution(string $game,string $level): array {
    $second=$level==='board-02';
    switch($game){
        case 'outlier-detection':
            return $second?array(array('type'=>'policy','station'=>0,'policy'=>'repair'),array('type'=>'run','steps'=>6)):array(array('type'=>'policy','station'=>1,'policy'=>'repair'),array('type'=>'policy','station'=>3,'policy'=>'celsius'),array('type'=>'run','steps'=>6));
        case 'missing-values':
            if($second)return array_map(static fn($i)=>array('type'=>'walk','cell'=>$i),array(15,20,21,22,23,24,19,14));
            $moves=array();foreach(array(11,12,13) as $i){$moves[]=array('type'=>'estimate','cell'=>$i,'low'=>1,'high'=>3);$moves[]=array('type'=>'walk','cell'=>$i);}$moves[]=array('type'=>'walk','cell'=>14);return $moves;
        case 'data-visualization':
            $moves=array(array('type'=>'handoff'));
            foreach($second?array(2,1,0):array(0,1,2) as $p){$moves[]=array('type'=>'assign','ship'=>0,'port'=>$p,'cargo'=>6);$moves[]=array('type'=>'assign','ship'=>1,'port'=>($p+1)%3,'cargo'=>2);$moves[]=array('type'=>'sail');}return $moves;
        case 'data-extraction':
            $moves=array();
            foreach(array(array(0,'filter',$second?'all':'large'),array(1,'filter','red'),array(2,'project','all'),array(1,'filter',$second?'large':'blue'),array(4,'project','all')) as $i=>$n)$moves[]=array('type'=>'node','node'=>$i+1,'source'=>$n[0],'kind'=>$n[1],'rule'=>$n[2],'fields'=>array('id','name'));
            return array_merge($moves,array_fill(0,3,array('type'=>'run')));
        case 'data-structure-lab':
            $class=Catalog::game($game);$best=-1;$result=array();
            foreach(array('queue','stack') as $container)foreach(array('ESWN','SENW','WNES','NWSE') as $order){$moves=array(array('type'=>'configure','container'=>$container,'order'=>$order),array('type'=>'run','steps'=>49));$s=$class::create($level);foreach($moves as $m)$s=$class::apply($s,$m);if($s['score']>$best&&$s['status']==='won'){$result=$moves;$best=$s['score'];}}
            return $result;
        case 'ai-classifier-lab':
            return array_merge(array(array('type'=>'gates','gates'=>$second?array(array(0,6,4,10),array(6,0,10,4)):array(array(3,3,7,7)))),array_fill(0,4,array('type'=>'run')));
        case 'ai-model-lab':
            $bank=(require EDU_GAMES_PATH.'src/Games/Modules/AI_Model_Lab/deck-experiments.php')['boards'][$level];$best=-1;$mask=0;
            foreach($bank['decks'] as $key=>$prediction){if(!str_ends_with($key,':1'))continue;$good=0;foreach($prediction['validation'] as $i=>$p)if($p===$bank['validation'][$i]['good'])$good++;if($good>$best){$best=$good;$mask=(int)explode(':',$key)[0];}}
            $cards=array();for($i=0;$i<8;$i++)if($mask&(1<<$i))$cards[]=$i;
            return array(array('type'=>'audit','card'=>6),array('type'=>'deck','cards'=>$cards),array('type'=>'train'),array('type'=>'deploy'));
    }
    throw new RuntimeException('Unknown game');
}
