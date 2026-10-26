<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Learning\Catalog;
use EduGames\Learning\Question;
use EduGames\Games\Modules\Data_Structure_Lab\Learning_Content as Structures;

function require_learning(bool $condition,string $message): void { if(!$condition){throw new RuntimeException($message);} }
$counts=array('data-extraction'=>24,'missing-values'=>36,'outlier-detection'=>36,'data-visualization'=>30,'data-structure-lab'=>36,'ai-classifier-lab'=>36,'ai-model-lab'=>13,'keyword-rain'=>30,'code-typing'=>24,'device-matching'=>38);
$all=array();$checks=0;
foreach($counts as $game=>$count){
    $questions=Catalog::questions($game);require_learning(count($questions)===$count,"$game count");$ids=array();$fingerprints=array();
    foreach($questions as $q){
        require_learning(!isset($ids[$q['id']]),'duplicate ID');$ids[$q['id']]=true;
        $fingerprint=hash('sha256',json_encode(array($q['prompt'],$q['data'],$q['fields'],$q['expected'])));
        require_learning(!isset($fingerprints[$fingerprint]),"$game duplicate task: {$q['id']}");$fingerprints[$fingerprint]=true;
        require_learning(count($q['fields'])===count($q['expected']),'field/answer mismatch');
        $answer=array();
        foreach($q['fields'] as $field){$expected=$q['expected'][$field['key']];$answer[$field['key']]=$field['type']==='choice'?$expected[0]:$expected;}
        require_learning(Catalog::grade($game,$q,$answer)['correct'],"$game/{$q['id']} canonical answer");
        require_learning(!Question::grade($q,array())['correct'],"$game empty answer accepted");
        $public=Question::public_view($q);require_learning(!isset($public['expected'])&&!isset($public['hint'])&&!isset($public['explanation']),'private data leaked');
        $all[$game][]=array('question'=>$public,'answer'=>$answer,'explanation'=>$q['explanation']);$checks+=5;
    }
}
$trace=Structures::trace('stack',array(array('op'=>'push','value'=>'A'),array('op'=>'push','value'=>'B'),array('op'=>'peek'),array('op'=>'pop')));
require_learning($trace['states']===array(array('A'),array('A','B'),array('A','B'),array('A')),'stack orientation');
require_learning($trace['outputs']===array('B','B'),'stack peek/pop');
$trace=Structures::trace('queue',array(array('op'=>'enqueue','value'=>'A'),array('op'=>'enqueue','value'=>'B'),array('op'=>'front'),array('op'=>'dequeue')));
require_learning($trace['states'][3]===array('B')&&$trace['outputs']===array('A','A'),'queue trace');
$trace=Structures::trace('priority',array(array('op'=>'push','value'=>'A','priority'=>5),array('op'=>'push','value'=>'B','priority'=>5),array('op'=>'pop')));
require_learning($trace['outputs']===array('A')&&$trace['states'][1]===array('A','B'),'priority FIFO tie');
$none=array_filter(Catalog::questions('outlier-detection'),static fn($q)=>$q['expected']['selected']===array());require_learning(count($none)===9,'outlier-none coverage');
if(($argv[1]??'')==='json'){echo json_encode($all,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
echo 'PASS: '.array_sum($counts).' production questions; '.($checks+5)." content checks.\n";
