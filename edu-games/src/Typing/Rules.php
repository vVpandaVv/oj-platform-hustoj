<?php
declare(strict_types=1);
namespace EduGames\Typing;

final class Rules {
    public const VERSION = 'typing-verified-v3';
    public const LEGACY_VERSION = 'typing-verified-v2';
    public static function supports(string $version): bool {return in_array($version,array(self::VERSION,self::LEGACY_VERSION),true);}
    public static function chars(string $text): array {return preg_split('//u',str_replace(array("\r\n","\r"),"\n",$text),-1,PREG_SPLIT_NO_EMPTY) ?: array();}
    public static function code_count(string $text): int {return count(self::chars((string)preg_replace('/\s/u','',$text)));}
    public static function create(array $samples,int $seconds,float $threshold,float $now,string $version=self::VERSION,string $lineEnding='enter'): array {
        if(!$samples||!in_array($seconds,array(60,180,300,600),true)||$threshold<70||$threshold>100||!self::supports($version)||!in_array($lineEnding,array('enter','space-enter'),true))throw new \InvalidArgumentException('검정 설정을 확인하세요.');
        foreach($samples as &$sample)$sample['content']=implode('',self::chars($sample['content']));unset($sample);
        return array('version'=>$version,'lineEnding'=>$lineEnding,'status'=>'running','samples'=>$samples,'sampleIndex'=>0,'input'=>'',
            'durationSeconds'=>$seconds,'minAccuracy'=>$threshold,'startedAt'=>$now,'deadline'=>$now+$seconds,
            'attempted'=>0,'correctAttempts'=>0,'mistakes'=>0,'insertions'=>0,'credited'=>array(),
            'completed'=>0,'completedCode'=>0,'completedCharacters'=>0,'lastAt'=>-1,'journal'=>array());
    }
    public static function apply(array $s,array $events,float $now,bool $finish=false): array {
        if($s['status']!=='running')throw new \InvalidArgumentException('이미 종료된 검정입니다.');
        if(!array_is_list($events)||count($events)>400)throw new \InvalidArgumentException('입력 묶음의 크기를 확인하세요.');
        // A short transport grace accepts the final in-flight batch, never events after the exam clock.
        if($now>$s['deadline']+2){$s['lateBatch']=!empty($events);if($events)$s['verificationIssue']='late_batch';$s['status']='finished';return $s;}
        if($finish&&$now<$s['deadline'])throw new \InvalidArgumentException('제한시간이 끝나면 검정 결과가 확정됩니다.');
        foreach($events as $e){
            if(!is_array($e)||!is_int($e['start']??null)||!is_int($e['delete']??null)||!is_int($e['at']??null)
                ||!is_string($e['text']??null)||!in_array($e['kind']??null,array('type','tab','delete','linebreak'),true))throw new \InvalidArgumentException('입력 이력 형식이 올바르지 않습니다.');
            if($e['at']<$s['lastAt']||$e['at']<0||$e['at']>$s['durationSeconds']*1000||$e['at']>($now-$s['startedAt']+1)*1000)throw new \InvalidArgumentException('입력 시간 순서를 확인하세요.');
            if(($now-$s['startedAt'])*1000-$e['at']>8000)$s['verificationIssue']='stale_batch';
            $input=self::chars($s['input']);$text=self::chars($e['text']);$n=count($text);
            if($e['start']<0||$e['delete']<0||$e['start']+$e['delete']>count($input)||$n>32||(!$n&&!$e['delete'])
                ||($e['kind']==='tab'&&$e['text']!=='    ')||($e['kind']==='delete'&&$n)
                ||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$e['text']))throw new \InvalidArgumentException('허용되지 않은 편집입니다.');
            $target=self::chars($s['samples'][$s['sampleIndex']%count($s['samples'])]['content']);
            if($e['kind']==='linebreak'&&(($s['lineEnding']??'enter')!=='space-enter'||$e['text']!=="\n"||$e['delete']!==0||($target[$e['start']]??null)!=="\n"))throw new \InvalidArgumentException('줄 끝 입력 설정을 확인하세요.');
            foreach($text as $i=>$char){
                $pos=$e['start']+$i;
                if(($target[$pos]??null)===$char){
                    // Repeating already-correct positions must not wash prior mistakes out of accuracy.
                    if(empty($s['credited'][$pos])){$s['credited'][$pos]=true;$s['correctAttempts']++;$s['attempted']++;}
                }else{$s['mistakes']++;$s['attempted']++;}
            }
            array_splice($input,$e['start'],$e['delete'],$text);$s['input']=implode('',$input);
            if(count($input)>count($target)+4096)throw new \InvalidArgumentException('현재 예제의 입력 길이를 초과했습니다.');
            $s['insertions']+=$n;$s['lastAt']=$e['at'];
            if($s['insertions']>max(80,($now-$s['startedAt']+1)*80)||count($s['journal'])>=30000)throw new \InvalidArgumentException('입력 이력의 양을 확인하세요.');
            $s['journal'][]=array_intersect_key($e,array_flip(array('start','delete','text','kind','at')));
            if($input===$target){
                $s['completed']++;$s['completedCode']+=self::code_count($s['input']);$s['completedCharacters']+=count($target);
                $s['sampleIndex']++;$s['input']='';$s['credited']=array();
            }
        }
        if($finish)$s['status']='finished';
        return $s;
    }
    public static function metrics(array $s,float $now): array {
        $target=self::chars($s['samples'][$s['sampleIndex']%count($s['samples'])]['content']);$input=self::chars($s['input']);
        $prefix='';$matching=0;$matchedCode=0;$continuous=true;
        foreach($input as $i=>$char){$same=($target[$i]??null)===$char;if($same){$matching++;$matchedCode+=self::code_count($char);}if(!$same)$continuous=false;if($continuous)$prefix.=$char;}
        $valid=$s['completedCode']+(($s['version']??self::LEGACY_VERSION)===self::LEGACY_VERSION?self::code_count($prefix):$matchedCode);
        $accuracy=$s['attempted']?100*$s['correctAttempts']/$s['attempted']:0;
        $length=$s['completedCharacters']+count($input);
        $final=$length?100*($s['completedCharacters']+$matching)/$length:0;
        $seconds=$s['status']==='finished'?$s['durationSeconds']:max(1,min($s['durationSeconds'],$now-$s['startedAt']));
        $cpm=round($valid*60/$seconds,1);$qualified=$valid>0&&$accuracy>=$s['minAccuracy']&&empty($s['verificationIssue']);
        return array('effectiveCpm'=>$cpm,'inputAccuracy'=>round($accuracy,2),'finalAccuracy'=>round($final,2),
            'validCharacters'=>$valid,'mistakes'=>$s['mistakes'],'attempted'=>$s['attempted'],'correctAttempts'=>$s['correctAttempts'],
            'samplesCompleted'=>$s['completed'],'qualified'=>$qualified,'verificationIssue'=>$s['verificationIssue']??null,'score'=>$qualified?(int)round($cpm*10):0,
            'durationSeconds'=>$s['durationSeconds'],'remaining'=>round(max(0,$s['deadline']-$now),2));
    }
    public static function view(array $s,float $now): array {
        return array('version'=>$s['version'],'lineEnding'=>$s['lineEnding']??'enter','status'=>$s['status'],'samples'=>$s['samples'],'sampleIndex'=>$s['sampleIndex'],
            'input'=>$s['input'],'credited'=>$s['credited'],'completedCode'=>$s['completedCode'],'completedCharacters'=>$s['completedCharacters'],
            'lastAt'=>$s['lastAt'],'minAccuracy'=>$s['minAccuracy'],'metrics'=>self::metrics($s,$now),'lateBatch'=>$s['lateBatch']??false);
    }
}
