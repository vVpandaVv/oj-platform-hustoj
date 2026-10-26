<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Boards\Catalog;
$p=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$game=$p['game'];$class=Catalog::game($game);
if($p['action']==='catalog'){$solutions=array();foreach($class::levels() as $id=>$d)$solutions[$id]=$class::solution($id);echo json_encode(array('levels'=>$class::levels(),'solutions'=>$solutions),JSON_UNESCAPED_UNICODE);exit;}
$s=$p['action']==='start'?$class::create($p['level']):$p['state'];
try{if($p['action']==='update')$s=$class::apply($s,$p['move']);echo json_encode(array('state'=>$s,'view'=>$class::view($s)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(InvalidArgumentException $e){echo json_encode(array('error'=>$e->getMessage()),JSON_UNESCAPED_UNICODE);}
