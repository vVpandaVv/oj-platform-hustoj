<?php
declare(strict_types=1);
namespace EduGames\Arcade;
final class Dance_Rules {
    public const VERSION='dance-v2';
    public const MOVES=['left','right','jump','light'];
    public static function routines(string $seed,string $difficulty): array {
        $recipes=[
            ['signal','발걸음과 조명',[[['left','right'],2],[['jump'],1],[['light','jump'],2]]],
            ['greeting','인사 뒤 두 가지 스텝',[[['light'],1],[['left','jump'],3],[['right','light'],1],[['jump','right'],2]]],
            ['spotlight','조명 사이 방향 전환',[[['jump','light'],3],[['left','right','left'],1],[['light','right'],2]]],
            ['switch','스텝 뒤 조명 세 번',[[['left','light'],1],[['right','jump','left'],2],[['jump'],1],[['light'],3]]],
            ['mirror','서로 다른 두 반복',[[['left','left','jump'],2],[['light','right'],1],[['right','jump'],3]]],
            ['bridge','두 반복을 잇는 동작',[[['light','jump','right'],1],[['left','right','light'],2],[['jump','left'],1],[['right','light'],2]]],
            ['turn','점프 후 새 방향으로',[[['jump','right'],3],[['left','light','left'],1],[['left','right','jump'],2]]],
            ['finale','인사·스텝·마무리',[[['left','right'],1],[['jump','light','right'],2],[['light'],1],[['left','jump'],3]]],
        ];
        usort($recipes,static fn($a,$b)=>strcmp(hash('sha256',$seed.$a[0]),hash('sha256',$seed.$b[0])));
        return array_map(static function($recipe)use($difficulty){
            $program=array_map(static fn($block)=>['commands'=>$block[0],'repeat'=>$block[1]+($difficulty==='challenge'&&$block[1]>1?1:0)],$recipe[2]);
            return ['id'=>$recipe[0],'title'=>$recipe[1],'program'=>$program];
        },$recipes);
    }
    public static function create(string $pack,string $seed,string $difficulty,int $now): array {
        $routines=self::routines($seed,$difficulty);$targets=array_map(static fn($r)=>self::expand($r['program'])['steps'],$routines);
        return ['game'=>'dance-bot','ruleset'=>self::VERSION,'pack'=>$pack,'difficulty'=>$difficulty,'status'=>'playing','startedAt'=>$now,'finishedAt'=>0,'score'=>0,'correct'=>0,'wrong'=>0,'penaltyMs'=>0,'targets'=>$targets,'titles'=>array_column($routines,'title'),'index'=>0,'awaitingNext'=>false,'roundStartedAt'=>$now,'run'=>null,'lastResult'=>null,'notice'=>'순차 동작과 서로 다른 반복을 묶어 보세요.','operations'=>[]];
    }
    public static function expand(mixed $program): array {
        if(!is_array($program)||!array_is_list($program)||!$program||count($program)>12)throw new \InvalidArgumentException('명령 묶음을 확인하세요.');
        $expanded=[];$cost=0;
        foreach($program as $block){
            if(!is_array($block)||!is_array($block['commands']??null)||!array_is_list($block['commands'])||!count($block['commands'])||count($block['commands'])>32||!is_int($block['repeat']??null)||$block['repeat']<1||$block['repeat']>8)throw new \InvalidArgumentException('반복은 1~8회, 묶음마다 동작은 1~32개입니다.');
            foreach($block['commands'] as $c)if(!is_string($c)||!in_array($c,self::MOVES,true))throw new \InvalidArgumentException('지원하지 않는 동작입니다.');
            $cost+=count($block['commands'])+($block['repeat']>1?1:0);$expanded=array_merge($expanded,...array_fill(0,$block['repeat'],$block['commands']));
            if(count($expanded)>64)throw new \InvalidArgumentException('한 안무는 최대 64박자입니다.');
        }
        return ['steps'=>$expanded,'cost'=>$cost];
    }
    public static function apply(array $s,array $move,int $now): array {
        if($s['status']!=='playing')throw new \InvalidArgumentException('이미 끝난 도전입니다.');
        $type=$move['type']??'';
        if($type==='stop'){$s['status']='stopped';$s['finishedAt']=$now;$s['run']=null;return $s;}
        if($type==='next') {if(!$s['awaitingNext'])throw new \InvalidArgumentException('현재 안무를 먼저 완료하세요.');$s['awaitingNext']=false;$s['roundStartedAt']=$now;$s['notice']='다음 안무 · 반복 구조를 찾아보세요.';return $s;}
        if($s['awaitingNext'])throw new \InvalidArgumentException('완료 결과를 확인하고 다음 안무를 여세요.');
        if($type==='run'){
            $program=self::expand($move['program']??null);$s['run']=$program+['startedAt'=>$now,'until'=>$now+count($program['steps'])*300];$s['notice']='프로그램 실행 중';return $s;
        }
        if($type!=='commit'||!$s['run'])throw new \InvalidArgumentException('먼저 안무를 재생하세요.');
        if($now<$s['run']['until'])throw new \InvalidArgumentException('안무 실행이 끝나면 결과를 확인합니다.');
        $target=$s['targets'][$s['index']];$run=$s['run'];$ok=$run['steps']===$target;$s['run']=null;
        if(!$ok){$s['wrong']++;$s['notice']='안무 불일치 · 명령 순서와 반복 횟수를 확인하세요.';$s['lastResult']=['correct'=>false,'steps'=>$run['steps'],'target'=>$target,'points'=>0];return $s;}
        $base=count($target)*100;$saved=max(0,count($target)-$run['cost'])*25;$speed=max(0,200-(int)floor(max(0,$now-$s['roundStartedAt'])/1000)*5);$points=$base+$saved+$speed;
        $s['score']+=$points;$s['correct']++;$s['index']++;$s['awaitingNext']=true;$s['lastResult']=['correct'=>true,'steps'=>$run['steps'],'target'=>$target,'base'=>$base,'saved'=>$saved,'speed'=>$speed,'points'=>$points,'commands'=>$run['cost']];
        $s['notice']='안무 완성 · 기본 '.$base.' + 명령 절약 '.$saved.' + 속도 '.$speed.' = +'.$points.'점';
        if($s['index']===count($s['targets'])){$s['status']='finished';$s['finishedAt']=$now;}
        return $s;
    }
    public static function elapsed(array $s,int $now): int {return max(0,($s['finishedAt']?:$now)-$s['startedAt']);}
    public static function view(array $s,int $now): array {
        $index=min($s['index']-($s['awaitingNext']?1:0),count($s['targets'])-1);
        return array_intersect_key($s,array_flip(['game','ruleset','pack','difficulty','status','startedAt','finishedAt','score','correct','wrong','notice','index','awaitingNext','lastResult']))+[
            'serverNow'=>$now,'elapsedMs'=>self::elapsed($s,$now),'target'=>$s['targets'][$index],'targetTitle'=>$s['titles'][$index]??'안무 '.($index+1),'total'=>count($s['targets']),
            'run'=>$s['run']?array_intersect_key($s['run'],array_flip(['steps','cost','startedAt','until'])):null];
    }
}
