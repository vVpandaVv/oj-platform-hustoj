<?php
declare(strict_types=1);
namespace EduGames\Learning;

final class Run {
    public static function create(string $game,string $level): array {
        $questions=Catalog::questions($game,$level);
        if(!$questions){throw new \InvalidArgumentException('해당 난이도의 문제가 부족합니다.');}
        $ids=array_column($questions,'id');shuffle($ids);
        return array('ids'=>array_slice($ids,0,6),'index'=>0,'records'=>array(),'hintUsed'=>false,'questionStarted'=>time(),'contentVersion'=>Catalog::VERSION,'rulesVersion'=>'learning-1','rubricVersion'=>'first-answer-1');
    }

    public static function apply(array $state,string $game,string $mode,array $input): array {
        if($state['index']>=count($state['ids'])){throw new \InvalidArgumentException('이미 끝난 세트입니다.');}
        $index=$state['index'];$id=$state['ids'][$index];
        if(($input['questionId']??null)!==$id){throw new \InvalidArgumentException('현재 문항과 답안이 일치하지 않습니다.');}
        $question=Catalog::find($game,$id);
        if(null===$question){throw new \InvalidArgumentException('문제 버전이 변경되었습니다. 새 세트를 시작하세요.');}
        $action=$input['action']??'';
        if('hint'===$action){
            if('assessment'===$mode){throw new \InvalidArgumentException('평가에서는 힌트를 사용할 수 없습니다.');}
            $state['hintUsed']=true;
        } elseif('next'===$action){
            if(!isset($state['records'][$index])){throw new \InvalidArgumentException('답안을 먼저 제출하세요.');}
            $state['index']++;$state['hintUsed']=false;$state['questionStarted']=time();
        } elseif('answer'===$action){
            $answer=$input['answer']??null;
            if(!is_array($answer)||strlen((string)json_encode($answer))>16000||array_diff(array_keys($answer),array_column($question['fields'],'key'))){throw new \InvalidArgumentException('답안 형식을 확인하세요.');}
            $previous=$state['records'][$index]??null;
            if(null!==$previous&&('assessment'===$mode||$previous['result']['correct']||$previous['attempts']>=10)){throw new \InvalidArgumentException('이 문항은 더 이상 수정할 수 없습니다. 다음 문항으로 이동하세요.');}
            $result=Catalog::grade($game,$question,$answer);
            $state['records'][$index]=array(
                'questionId'=>$id,'skill'=>$question['skill'],
                'firstAnswer'=>$previous['firstAnswer']??$answer,'finalAnswer'=>$answer,
                'firstCorrect'=>$previous['firstCorrect']??$result['correct'],
                'hintUsed'=>($previous['hintUsed']??false)||$state['hintUsed']||'guided'===$mode,
                'attempts'=>($previous['attempts']??0)+1,
                'elapsedSeconds'=>max(0,time()-$state['questionStarted']),
                'result'=>$result,
            );
        } else {throw new \InvalidArgumentException('지원하지 않는 작업입니다.');}
        return $state;
    }

    public static function summary(array $state): array {
        $first=$final=$hints=0;$skills=array();
        foreach($state['records'] as $record){$first+=(int)$record['firstCorrect'];$final+=(int)$record['result']['correct'];$hints+=(int)$record['hintUsed'];$skills[]=array('skill'=>$record['skill'],'firstCorrect'=>$record['firstCorrect'],'finalCorrect'=>$record['result']['correct'],'attempts'=>$record['attempts']);}
        return array('total'=>count($state['ids']),'submitted'=>count($state['records']),'firstCorrect'=>$first,'finalCorrect'=>$final,'hintCount'=>$hints,'score'=>round(100*$first/max(1,count($state['ids'])),2),'skills'=>$skills);
    }

    public static function view(array $state,string $game,string $mode): array {
        $done=$state['index']>=count($state['ids']);$q=$done?null:Catalog::find($game,$state['ids'][$state['index']]);
        $record=$state['records'][$state['index']]??null;
        return array('done'=>$done,'position'=>$state['index']+1,'summary'=>self::summary($state),'question'=>$q?Question::public_view($q):null,'record'=>$record,'hint'=>$q&&($state['hintUsed']||$mode==='guided')?$q['hint']:null,'mode'=>$mode,'version'=>Catalog::VERSION);
    }
}
