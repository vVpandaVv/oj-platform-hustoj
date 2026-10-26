<?php
declare(strict_types=1);
namespace EduGames\Reports;
use EduGames\Addon\AiModelLab\Runtime_Bridge;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;

final class Roster {
    public static function contexts(string $cursor = ''): array|\WP_Error {
        $gateway=Runtime_Bridge::contexts();
        if(!$gateway) return self::error('학급 연동을 사용할 수 없습니다.',503);
        $result=$gateway->listTeachingContexts(new AddonTeachingContextQueryV1(get_current_user_id(),array('class','group'),'',$cursor,100));
        if(!$result->isSuccessful()) return self::error('담당 학급 목록을 조회할 수 없습니다.',403);
        return array('items'=>array_map(static fn($item)=>array('type'=>$item->type(),'id'=>$item->id(),'label'=>$item->label(),'revision'=>$item->revision()),$result->items()),'nextCursor'=>$result->nextCursor());
    }
    public static function learners(string $type, string $id, string $revision): array|\WP_Error {
        $gateway=Runtime_Bridge::roster_gateway();
        if(!$gateway) return self::error('학급 명단 연동을 사용할 수 없습니다.',503);
        if(!$revision) return self::error('학급 목록을 새로 조회하세요.',409);
        $users=array();$cursor='';$snapshot=null;
        for($page=0;$page<20;$page++) {
            $result=$gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(get_current_user_id(),$type,$id,$revision,$snapshot,$cursor,100));
            if(!$result->isSuccessful()) return self::error('명단이 변경되었거나 이 학급을 조회할 권한이 없습니다. 학급 목록을 새로 조회하세요.',403);
            foreach($result->items() as $learner) $users[$learner->learnerUserId()]=$learner->learnerUserId();
            $snapshot=$result->rosterRevision();$cursor=$result->nextCursor();
            if($cursor==='')return array_values($users);
        }
        return self::error('명단이 너무 큽니다. 더 작은 학급 또는 그룹을 선택하세요.',400);
    }
    private static function error(string $text,int $status): \WP_Error { return new \WP_Error('edu_games_report_context',$text,array('status'=>$status)); }
}
