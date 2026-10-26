<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\AI_Classifier_Lab;
use EduGames\Play\Board;

final class Play_Board extends Board {
    public static function levels(): array {return array('board-01'=>array('title'=>'가운데 익은 열매'),'board-02'=>array('title'=>'두 섬의 열매'));}
    private static function samples(string $id,int $wave): array {
        $data=array();
        // Each wave is disjoint; coordinates are visible before processing, labels are not.
        foreach(array(array(2,2),array(4,6),array(6,4),array(8,8),array(3,7),array(7,3),array(5,5),array(9,1)) as $i=>$xy){
            $x=$xy[0]+0.08*$wave-0.16;$y=$xy[1]+0.06*$wave-0.12;
            $ok=$id==='board-01'?($x>=3.5&&$x<=6.5&&$y>=3.5&&$y<=6.5):(($x<3.5&&$y>6.5)||($x>6.5&&$y<3.5));
            $data[]=array('id'=>$wave*8+$i,'x'=>$x,'y'=>$y,'good'=>$ok);
        }
        return $data;
    }
    public static function create(string $id): array {
        $s=self::begin($id);return $s+array('objective'=>'게이트 안은 배송, 밖은 반송됩니다. 먹을 수 있는 열매를 놓치지 않으면서 상한 열매의 배송을 막으세요.','gates'=>array(array(3,3,7,7)),'inspection'=>6,'inspectIds'=>array(),'revealed'=>array(),'history'=>array(),'tp'=>0,'tn'=>0,'fp'=>0,'fn'=>0);
    }
    public static function apply(array $s,array $m): array {
        $type=self::guard($s,$m,array('gates','inspect','run'));
        if($type==='gates'){
            $g=$m['gates']??null;if(!is_array($g)||count($g)<1||count($g)>2)throw new \InvalidArgumentException('게이트는 1~2개입니다.');
            $s['gates']=array();foreach($g as $rect){if(!is_array($rect)||count($rect)!==4)throw new \InvalidArgumentException('경계 네 값을 입력하세요.');$r=array_values($rect);foreach($r as $v)self::integer($v,0,10);if($r[0]>=$r[2]||$r[1]>=$r[3])throw new \InvalidArgumentException('최솟값은 최댓값보다 작아야 합니다.');$s['gates'][]=$r;}return $s;
        }
        $batch=self::samples($s['level'],$s['turn']);
        if($type==='inspect'){
            $id=self::integer($m['id']??null,$s['turn']*8,$s['turn']*8+7);
            if(in_array($id,$s['inspectIds'],true))throw new \InvalidArgumentException('이미 검사했습니다.');
            if($s['inspection']<=0)throw new \InvalidArgumentException('검사 기회를 모두 사용했습니다.');
            $s['inspection']--;$s['inspectIds'][]=$id;$s['revealed'][]=$batch[$id%8];
            return self::note($s,'검사 결과를 지도에 표시했습니다. 검사한 열매는 자동으로 올바른 통로에 보냅니다.');
        }
        $steps=self::integer($m['steps']??1,1,4);
        if($steps>1){for($i=0;$i<$steps&&$s['status']==='playing';$i++)$s=self::apply($s,array('type'=>'run','steps'=>1));return $s;}
        $result=array();
        foreach($batch as $p){
            $accept=false;foreach($s['gates'] as $g)if($p['x']>=$g[0]&&$p['x']<=$g[2]&&$p['y']>=$g[1]&&$p['y']<=$g[3])$accept=true;
            $manual=in_array($p['id'],$s['inspectIds'],true);
            if($manual)$accept=$p['good'];
            $key=$accept?($p['good']?'tp':'fp'):($p['good']?'fn':'tn');$s[$key]++;
            $result[]=$p+array('accept'=>$accept,'manual'=>$manual,'correct'=>$accept===$p['good']);
        }
        $s['history']=$result;$s['turn']++;$s['inspectIds']=array();$s['revealed']=array();
        $s['metrics']=array('먹을 열매 배송'=>$s['tp'],'상한 열매 차단'=>$s['tn'],'잘못 배송'=>$s['fp'],'먹을 열매 놓침'=>$s['fn']);
        $s=self::note($s,$s['turn'].'차 자동 분류 완료. 통과와 차단 양쪽의 실수를 확인하세요.');
        if($s['turn']===4){
            $positive=$s['tp']+$s['fn'];$negative=$s['tn']+$s['fp'];
            $score=(int)round(500*($s['tp']/$positive+$s['tn']/$negative))+5*$s['inspection'];
            return self::finish($s,$score,$s['fp']+$s['fn']<=4,'운영 종료. 이 규칙은 사람이 설치한 경계이며 학습된 AI 모델은 아닙니다.');
        }
        return $s;
    }
    public static function view(array $s): array {
        $s['training']=array_map(static function($p){unset($p['id']);return $p;},self::samples($s['level'],4));
        $s['incoming']=array_map(static function($p){unset($p['good']);return $p;},self::samples($s['level'],min(3,$s['turn'])));
        return parent::view($s);
    }
}
