<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Structure_Lab;
use EduGames\Play\Board;

final class Play_Board extends Board {
    public static function levels(): array {return array('board-01'=>array('title'=>'깊은 갈림길'),'board-02'=>array('title'=>'넓은 광장의 출구'));}
    public static function create(string $id): array {
        $s=self::begin($id);
        $map=$id==='board-01'?array('S.....#','.####.#','....#.#','###.#.#','....#.#','.####.#','.....G#'):array('S......','.......','.......','...G...','.......','.......','.......');
        return $s+array('objective'=>'대기 목록을 운영해 출구까지의 경로를 찾으세요. 탐색 칸 수와 최대 메모리를 줄이는 것이 목표입니다.','map'=>$map,'container'=>'queue','order'=>'ESWN','frontier'=>array(0),'seen'=>array(0),'visited'=>array(),'parents'=>array(0=>null),'path'=>array(),'peak'=>1,'limit'=>$id==='board-01'?12:18,'goal'=>$id==='board-01'?47:24);
    }
    public static function apply(array $s,array $m): array {
        $type=self::guard($s,$m,array('configure','run'));
        if($type==='configure'){
            $s['container']=self::choice($m['container']??null,array('queue','stack'));
            $s['order']=self::choice($m['order']??null,array('ESWN','SENW','WNES','NWSE'));
            return self::note($s,'기존 대기 목록은 보존했습니다. '.($s['container']==='queue'?'먼저 들어온 칸':'마지막에 넣은 칸').'부터 꺼냅니다.');
        }
        $steps=self::integer($m['steps']??3,1,49);
        for($k=0;$k<$steps&&$s['status']==='playing';$k++){
            if(!$s['frontier'])return self::finish($s,0,false,'더 탐색할 칸이 없습니다.');
            // SPL enforces the actual FIFO/LIFO operation; the saved array is insertion order.
            if($s['container']==='queue'){$work=new \SplQueue();foreach($s['frontier'] as $v)$work->enqueue($v);$current=$work->dequeue();$s['frontier']=iterator_to_array($work,false);}
            else{$work=new \SplStack();foreach($s['frontier'] as $v)$work->push($v);$current=$work->pop();$s['frontier']=array_reverse(iterator_to_array($work,false));}
            $s['visited'][]=$current;$s['turn']++;
            if($current===$s['goal']){
                for($v=$current;$v!==null;$v=$s['parents'][$v])array_unshift($s['path'],$v);
                $score=max(1,1500-count($s['visited'])*15-$s['peak']*10-(count($s['path'])-1)*5);
                $s=self::finish($s,$score,true,'출구 연결 완료. 점선은 실제 부모 기록으로 복원한 경로입니다.');break;
            }
            foreach(str_split($s['order']) as $dir){
                $r=intdiv($current,7);$c=$current%7;
                if($dir==='E')$c++;elseif($dir==='W')$c--;elseif($dir==='N')$r--;else $r++;
                if($r<0||$r>6||$c<0||$c>6)continue;$next=$r*7+$c;
                if($s['map'][$r][$c]==='#'||in_array($next,$s['seen'],true))continue;
                $s['seen'][]=$next;$s['frontier'][]=$next;$s['parents'][$next]=$current;
            }
            $s['peak']=max($s['peak'],count($s['frontier']));
            if(count($s['frontier'])>$s['limit'])$s=self::finish($s,0,false,'대기 목록이 메모리 한도를 넘었습니다. 탐색 방식이나 넣는 방향을 바꿔 보세요.');
        }
        $s['metrics']=array('탐색한 칸'=>count($s['visited']),'최대 대기 칸'=>$s['peak'],'경로 길이'=>max(0,count($s['path'])-1));
        return $s;
    }
    public static function view(array $s): array {unset($s['parents']);return parent::view($s);}
}

