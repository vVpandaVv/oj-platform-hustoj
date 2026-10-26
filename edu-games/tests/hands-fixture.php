<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
    $class=EduGames\Play\Catalog::game($input['game']);
    if($input['action']==='catalog'){echo json_encode(array('levels'=>$class::levels()),JSON_THROW_ON_ERROR);exit;}
    $s=$input['action']==='start'?$class::create($input['level']):($input['action']==='view'?$input['state']:$class::apply($input['state'],$input['move']));
    echo json_encode(array('state'=>$s,'view'=>$class::view($s)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException $e){echo json_encode(array('error'=>$e->getMessage()),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
