<?php
declare(strict_types=1);
namespace EduGames\Arcade;
use EduGames\Games\Modules\Character_Code\Character_Code_Module;
final class Vault_Rules {
    public const VERSION='vault-v1', COUNT=24;
    public static function codebook(): array { static $book;return $book??=Character_Code_Module::rules()['codeTable']; }
    public static function vaults(string $seed,string $difficulty='standard',int $count=self::COUNT): array {
        $rules=Character_Code_Module::rules();$pool=$rules['difficulties'][$difficulty==='challenge'?'normal':'easy']['phrases'];$out=[];
        usort($pool,static fn($a,$b)=>strcmp(hash('sha256',$seed.$a),hash('sha256',$seed.$b)));
        for($i=0;$i<$count;$i++) {
            $tier=[1,2,1,3,2,1][$i%6];$mode=$tier===3?'rebuild':($i%3===1?'encode':'decode');
            $text=$pool[$i%count($pool)];
            if($tier===1&&strlen($text)>8)$text=rtrim(substr($text,0,8));
            if($i>=count($pool))$text.=' '.($i+1);
            $out[]=['id'=>$i,'tier'=>$tier,'mode'=>$mode,'text'=>$text,'opened'=>false,'startedAt'=>0,'penaltyMs'=>0,'repaired'=>$mode!=='rebuild','points'=>0,'owner'=>0,'leaseUntil'=>0,'effect'=>null,'order'=>range(0,strlen($text)-1),'repairIndex'=>min(1,strlen($text)-1)];
        }
        return $out;
    }
    public static function create(string $pack,string $seed,string $difficulty,int $now): array {
        return ['game'=>'cipher-vault','ruleset'=>self::VERSION,'pack'=>$pack,'difficulty'=>$difficulty,'status'=>'playing','startedAt'=>0,'createdAt'=>$now,'finishedAt'=>0,'active'=>null,'score'=>0,'correct'=>0,'wrong'=>0,'penaltyMs'=>0,'vaults'=>self::vaults($seed,$difficulty),'notice'=>'금고를 선택하고 Enter로 열어 보세요.','operations'=>[]];
    }
    public static function codes(array $v): array {$book=self::codebook();return array_map(static fn($c)=>(string)$book[$c],str_split($v['text']));}
    public static function bonus(array $v,int $now): int {
        $base=$v['tier']*100;$reference=[1=>20000,2=>35000,3=>50000][$v['tier']];
        return (int)round($base*$reference/($reference+max(0,$now-$v['startedAt'])+$v['penaltyMs']));
    }
    public static function valid_answer(array $v,mixed $answer): bool {
        if(!is_string($answer)||strlen($answer)>256)return false;
        if($v['mode']==='encode') {
            $parts=preg_split('/[\s,]+/',trim($answer));
            if(!$parts||array_filter($parts,static fn($p)=>!preg_match('/^\d{1,2}$/D',$p)))return false;
            return array_map(static fn($p)=>str_pad($p,2,'0',STR_PAD_LEFT),$parts)===self::codes($v);
        }
        return strtoupper(trim($answer))===$v['text'];
    }
    public static function problem(array $v): array {
        $codes=self::codes($v);$masked=($v['mode']==='rebuild'&&!$v['repaired'])||$v['effect']==='erase';$shuffled=$v['effect']==='shuffle';
        $problem=['id'=>$v['id'],'tier'=>$v['tier'],'mode'=>$v['mode'],'baseScore'=>$v['tier']*100,'startedAt'=>$v['startedAt'],'penaltyMs'=>$v['penaltyMs'],'repaired'=>$v['repaired'],'effect'=>$v['effect'],'masked'=>$masked,'shuffled'=>$shuffled,'packets'=>[]];
        foreach($v['order'] as $index) $problem['packets'][]=['position'=>$index,'value'=>$v['mode']==='encode'?($v['text'][$index]===' '?'공백':$v['text'][$index]):($masked&&$index===$v['repairIndex']?'??':$codes[$index])];
        if($masked)$problem['backup']=['position'=>$v['repairIndex'],'symbol'=>$v['text'][$v['repairIndex']]];
        return $problem;
    }
    public static function apply(array $s,array $move,int $now): array {
        $type=$move['type']??'';
        if($s['status']!=='playing')throw new \InvalidArgumentException('이미 종료된 도전입니다.');
        if($type==='stop'){ $s['status']='stopped';$s['finishedAt']=$now;$s['active']=null;return $s; }
        if($type==='close'){$s['active']=null;return $s;}
        if($type==='open') {
            $id=$move['vault']??null;if(!is_int($id)||!isset($s['vaults'][$id])||$s['vaults'][$id]['opened'])throw new \InvalidArgumentException('열 금고를 선택하세요.');
            $s['startedAt']=$s['startedAt']?:$now;$s['vaults'][$id]['startedAt']=$s['vaults'][$id]['startedAt']?:$now;$s['active']=$id;$s['notice']='코드표를 보고 변환하세요.';return $s;
        }
        if($s['active']===null)throw new \InvalidArgumentException('먼저 금고를 선택하세요.');
        $v=&$s['vaults'][$s['active']];
        if($type==='repair') {
            $code=$move['code']??null;$expected=self::codes($v)[$v['repairIndex']];
            if(!is_string($code)||str_pad(trim($code),2,'0',STR_PAD_LEFT)!==$expected)return self::wrong($s,'복구 코드가 다릅니다. 시간 +2초');
            $v['repaired']=true;$v['effect']=null;$s['notice']='코드 복구 완료 · 전체 메시지를 변환하세요.';return $s;
        }
        if($type!=='answer'||!$v['repaired']||$v['effect'])throw new \InvalidArgumentException('코드 복구를 먼저 완료하세요.');
        if(!self::valid_answer($v,$move['answer']??null))return self::wrong($s,'잠금 유지 · 공백과 숫자 문자도 확인하세요. 시간 +2초');
        $base=$v['tier']*100;$bonus=self::bonus($v,$now);$v['points']=$base+$bonus;$v['opened']=true;$s['score']+=$v['points'];$s['correct']++;
        $s['notice']='금고 열림 · 기본 '.$base.' + 시간 보너스 '.$bonus.' = +'.$v['points'].'점';$s['active']=null;
        if($s['correct']===self::COUNT){$s['status']='finished';$s['finishedAt']=$now;}
        return $s;
    }
    private static function wrong(array $s,string $text): array {$s['wrong']++;$s['penaltyMs']+=2000;$s['vaults'][$s['active']]['penaltyMs']+=2000;$s['notice']=$text;return $s;}
    public static function elapsed(array $s,int $now): int {return $s['startedAt']?max(0,($s['finishedAt']?:$now)-$s['startedAt'])+$s['penaltyMs']:0;}
    public static function view(array $s,int $now): array {
        $v=array_intersect_key($s,array_flip(['game','ruleset','pack','difficulty','status','startedAt','finishedAt','score','correct','wrong','penaltyMs','notice','active']));
        $v['elapsedMs']=self::elapsed($s,$now);$v['serverNow']=$now;
        $v['vaults']=array_map(static fn($v)=>array_intersect_key($v,array_flip(['id','tier','mode','opened','points'])),$s['vaults']);
        $v['problem']=$s['active']===null?null:self::problem($s['vaults'][$s['active']])+['bonus'=>self::bonus($s['vaults'][$s['active']],$now)];return $v;
    }
}
