<?php
declare(strict_types=1);
// Independent edge cases complement the canonical campaign solutions.
use EduGames\Boards\Catalog as Expansion_Catalog;
$expectedCounts=array(18,12,12,18,12,12);$total=0;
foreach(array_values(Expansion_Catalog::GAMES) as $i=>$c){
    $levels=$c::levels();$total+=count($levels);
    check_strategy(count($levels)===$expectedCounts[$i],'full expansion board count');
    check_strategy(count(array_unique(array_column($levels,'title')))===count($levels),'distinct mission titles');
    foreach($levels as $id=>$d){
        $public=json_encode($c::view($c::create($id)),JSON_UNESCAPED_UNICODE);
        foreach(array('"sufficient":','"actual":','"experiments":','"target":','"bad":','"test":') as $private)check_strategy(!str_contains($public,$private),'nested answer privacy '.$id);
    }
}
check_strategy($total===84,'84 expansion boards');
$c=Expansion_Catalog::game('data-extraction');
$loaded=static fn($id)=>$c::apply($c::create($id),array('type'=>'load','slot'=>0));
$s=$loaded('board-16');
$zero=$c::apply($s,array('type'=>'filter','slot'=>0,'field'=>'value','op'=>'eq','value'=>0));
check_strategy(array_column($zero['slots'][0]['rows'],'id')===array('A'),'zero is an observed value, not missing');
$missing=$c::apply($s,array('type'=>'filter','slot'=>0,'field'=>'value','op'=>'missing'));
check_strategy(array_column($missing['slots'][0]['rows'],'id')===array('C','F'),'explicit missing predicate');
$range=$c::apply($loaded('board-05'),array('type'=>'filter','slot'=>0,'field'=>'value','op'=>'range','value'=>3,'upper'=>6));
check_strategy(array_column($range['slots'][0]['rows'],'id')===array('A','B','E'),'inclusive range endpoints');
rejects_strategy(fn()=>$c::apply($s,array('type'=>'filter','slot'=>0,'field'=>'value','op'=>'range','value'=>6,'upper'=>3)));
rejects_strategy(fn()=>$c::apply($s,array('type'=>'group','slot'=>0,'aggregate'=>'mean','missing'=>'strict')));
$mean=$c::apply($loaded('board-15'),array('type'=>'group','slot'=>0,'aggregate'=>'mean','missing'=>'skip'));
check_strategy(array_column($mean['slots'][0]['rows'],'value','group')===array('동쪽'=>4,'서쪽'=>8,'남쪽'=>null),'mean skips null, all-missing remains null');
$count=$c::apply($loaded('board-14'),array('type'=>'group','slot'=>0,'aggregate'=>'count'));
check_strategy(array_column($count['slots'][0]['rows'],'value','group')===array('체육'=>2,'미술'=>3,'음악'=>1),'row count includes missing');
$sum=$c::apply($s,array('type'=>'group','slot'=>0,'aggregate'=>'sum','missing'=>'skip'));
check_strategy(array_column($sum['slots'][0]['rows'],'value','group')===array('교실'=>5,'복도'=>4,'체육관'=>9),'sum independent golden values');
$sorted=$c::apply($s,array('type'=>'sort','slot'=>0,'direction'=>'desc','tie'=>'id'));
check_strategy(array_column($sorted['slots'][0]['rows'],'id')===array('E','B','D','A','C','F'),'null last even descending');
$empty=$c::apply($loaded('board-08'),array('type'=>'filter','slot'=>0,'field'=>'value','op'=>'eq','value'=>0));
$empty=$c::apply($empty,array('type'=>'project','slot'=>0));$empty=$c::apply($empty,array('type'=>'ship','slot'=>0,'order'=>0));
check_strategy($empty['delivered']===array(0),'empty result may be a valid shipment');
$shared=play_strategy('data-extraction','board-12',$c::solution('board-12',true));$separate=play_strategy('data-extraction','board-12',$c::solution('board-12',false));
check_strategy($shared['status']==='won'&&$shared['spent']<$separate['spent']&&$shared['score']>$separate['score'],'shared intermediate plan saves real operations and improves score');

$c=Expansion_Catalog::game('missing-values');
$dayTwo=static fn($id)=>play_strategy('missing-values',$id,array_slice($c::solution($id),0,4));
$s=$dayTwo('board-04');$median=$c::apply($s,array('type'=>'repair','node'=>0,'tool'=>'median'));
check_strategy($median['repairs'][0]['value']===12,'robust peer median');
$mean=$c::apply($s,array('type'=>'repair','node'=>0,'tool'=>'mean'));
check_strategy($mean['repairs'][0]['value']===28,'outlier alters mean, unlike median');
$repeat=$c::apply($median,array('type'=>'repair','node'=>0,'tool'=>'median'));check_strategy($repeat['spent']===$median['spent']+1,'replacing repair does not refund');
$s=$dayTwo('board-12');
rejects_strategy(fn()=>$c::apply($s,array('type'=>'repair','node'=>2,'tool'=>'measure')));
rejects_strategy(fn()=>$c::apply($s,array('type'=>'repair','node'=>2,'tool'=>'mode')));
check_strategy(play_strategy('missing-values','board-12',$c::solution('board-12'))['integrity']===10,'uncertain optional node can remain unknown');
$s=$c::apply($c::create('board-05'),array('type'=>'repair','node'=>1,'tool'=>'carry'));
check_strategy($s['repairs'][1]['value']===0,'state zero is preserved');

$c=Expansion_Catalog::game('outlier-detection');
$moves=array_values(array_filter($c::solution('board-05'),static fn($m)=>$m['type']!=='inspect'));
rejects_strategy(fn()=>play_strategy('outlier-detection','board-05',$moves));
$moves=$c::solution('board-12');foreach($moves as &$m)if(($m['route']??'')==='flag')$m['route']='keep';unset($m);
check_strategy(play_strategy('outlier-detection','board-12',$moves)['status']==='lost','unresolved evidence cannot become certain by keeping');
$moves=$c::solution('board-06');foreach($moves as &$m)if($m['type']==='inspect')$m['evidence']='repeat';unset($m);
$s=$c::create('board-06');foreach($moves as $m){if($s['status']!=='playing')break;$s=$c::apply($s,$m);}
check_strategy($s['status']==='won'&&$s['score']<1000,'measurement assistance cannot earn perfect investigation score');

$c=Expansion_Catalog::game('data-visualization');
foreach(array('pie','doughnut','bar') as $kind){
    $moves=$c::solution('board-11');foreach($moves as &$m)if(($m['key']??'')==='kind')$m['value']=$kind;unset($m);
    if($kind==='bar')$moves=array_values(array_filter($moves,static fn($m)=>($m['key']??'')!=='kind'));
    check_strategy(play_strategy('data-visualization','board-11',$moves)['status']==='won','valid proportion alternative '.$kind);
}
$moves=array_values(array_filter($c::solution('board-11'),static fn($m)=>($m['key']??'')!=='y'));
check_strategy(play_strategy('data-visualization','board-11',$moves)['status']==='won','raw count pie retains correct proportions');
$moves=$c::solution('board-13');foreach($moves as &$m)if($m['type']==='place'&&$m['row']===0)$m['x']=2;unset($m);
check_strategy(play_strategy('data-visualization','board-13',$moves)['status']==='lost','scatter preserves exact pairs');
$moves=array_values(array_filter($c::solution('board-16'),static fn($m)=>($m['panel']??0)===0));
check_strategy(play_strategy('data-visualization','board-16',$moves)['status']==='lost','first panel alone cannot complete compound board');
$s=$c::create('board-18');$s=$c::apply($s,array('type'=>'place','row'=>0,'slot'=>0));
$s=$c::apply($s,array('type'=>'configure','panel'=>1,'key'=>'kind','value'=>'scatter'));
check_strategy($s['plots'][0]['slots'][0]===array(0)&&$s['plots'][1]['positions']===array(),'panel edits remain isolated');
$s=$c::apply($s,array('type'=>'place','panel'=>1,'row'=>0,'x'=>1,'y'=>2));
rejects_strategy(fn()=>$c::apply($s,array('type'=>'place','panel'=>1,'row'=>0,'x'=>2,'y'=>3)));
rejects_strategy(fn()=>$c::apply($s,array('type'=>'place','panel'=>1,'row'=>1,'x'=>11,'y'=>3)));
$moves=$c::solution('board-06');$moves=array_values(array_filter($moves,static fn($m)=>($m['key']??'')!=='kind'));
check_strategy(play_strategy('data-visualization','board-06',$moves)['status']==='lost','irregular time gaps require continuous axis');

$c=Expansion_Catalog::game('ai-classifier-lab');$bank=$c::neighbor_bank();
check_strategy(count($bank['cases'])===12,'twelve nearest-neighbor cases');
foreach($bank['cases'] as $masks){check_strategy(count($masks)===16,'all survey subsets');foreach($masks as $cases){check_strategy(count($cases)===3,'three k choices');foreach($cases as $e){
    check_strategy(count($e['surface'])===100,'complete classifier surface');
    foreach(array('training','validation','test') as $split){$metric=$e[$split];$a=$metric['matrix'];check_strategy(array_sum($a[0])+array_sum($a[1])===$metric['count'],'knn confusion matrix count');check_strategy(abs(100*($a[0][0]+$a[1][1])/$metric['count']-$metric['accuracy'])<.005001,'knn accuracy derives from matrix');}
}}}
$s=$c::apply($c::create('board-12'),array('type'=>'method','method'=>'knn'));
$s=$c::apply($s,array('type'=>'validate'));$old=$c::view($s);
$s=$c::apply($s,array('type'=>'survey','region'=>0));
check_strategy($s['validation']===null&&$c::view($s)['training']['count']===$old['training']['count']+1,'survey changes training set and invalidates validation');
$s=$c::apply($s,array('type'=>'validate'));$s=$c::apply($s,array('type'=>'neighbors','k'=>1));check_strategy($s['validation']===null,'new k invalidates previous performance');
$changes=0;foreach($bank['cases'] as $cases)foreach(array(1,3,5) as $k)if($cases[0][$k]['surface']!==$cases[15][$k]['surface'])$changes++;
check_strategy($changes>0,'additional surveys can change real predicted regions');
$specs=$c::experiment_data();foreach($specs as $spec){$key=static fn($p)=>$p['x'].':'.$p['y'];$train=array_map($key,$spec['training']);$validation=array_map($key,$spec['validation']);$test=array_map($key,$spec['test']);check_strategy(!array_intersect($train,$validation)&&!array_intersect($train,$test)&&!array_intersect($validation,$test),'disjoint classifier splits');}
$c=Expansion_Catalog::game('ai-model-lab');$hashes=array();$chapters=array();
foreach($c::bank()['missions'] as $mission){
    $hashes[]=$mission['datasetHash'];$chapters[]=$mission['chapter'];$splits=$mission['splits'];
    check_strategy(count($splits['pool'])===300&&count($splits['validation'])===150&&count($splits['test'])===150,'AI split sizes');
    check_strategy(count(array_unique(array_merge(...array_values($splits))))===600,'AI training and evaluation splits disjoint');
    $ids=array_merge(...array_column($mission['batches'],'ids'));
    check_strategy(count($ids)===96&&count(array_unique($ids))===96&&!array_diff($ids,$splits['pool']),'nonoverlapping training batches stay in pool');
    foreach($mission['experiments'] as $e)foreach(array('training','validation','test') as $split){
        $metric=$e[$split];$a=$metric['matrix'];$f1=0;
        foreach(array(0,1) as $i){$tp=$a[$i][$i];$row=array_sum($a[$i]);$den=2*$tp+$a[$i][1-$i]+$a[1-$i][$i];$f1+=$den?100*$tp/$den:0;check_strategy(abs(($row?100*$tp/$row:0)-$metric['recall'][$i])<.005001,'AI per-class recall derives from matrix');}
        check_strategy(abs($f1-$metric['macroF1'])<.005001,'AI macro F1 derives from same predictions');
    }
}
check_strategy(count(array_unique($hashes))===12,'twelve distinct AI datasets');
check_strategy(array_count_values($chapters)===array('균형'=>3,'불균형'=>3,'라벨 잡음'=>3,'분포 변화'=>3),'all four AI expansion chapters');
