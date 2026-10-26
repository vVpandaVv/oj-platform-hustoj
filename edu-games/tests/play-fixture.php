<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/play-solutions.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
    $class=Legacy_Play_Catalog::game($input['game']);
    if($input['action']==='catalog'){
        $solutions=array();foreach($class::levels() as $id=>$d)$solutions[$id]=play_solution($input['game'],$id);
        echo json_encode(array('levels'=>$class::levels(),'solutions'=>$solutions),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    $s=$input['action']==='start'?$class::create($input['level']):$class::apply($input['state'],$input['move']);
    echo json_encode(array('state'=>$s,'view'=>$class::view($s)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException $e){echo json_encode(array('error'=>$e->getMessage()),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
