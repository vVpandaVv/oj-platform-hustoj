<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Extraction;

final class Campaign {
    public static function extend(array $levels): array {
        $filter=static fn($field,$op,$value=null,$upper=null)=>array_filter(array('type'=>'filter','field'=>$field,'op'=>$op,'value'=>$value,'upper'=>$upper),static fn($v)=>$v!==null);
        $sort=static fn($direction='asc',$tie='name',$by='value')=>array('type'=>'sort','direction'=>$direction,'tie'=>$tie,'by'=>$by);
        $project=array('type'=>'project');
        $group=static fn($aggregate,$missing='strict')=>array('type'=>'group','aggregate'=>$aggregate,'missing'=>$missing);
        $order=static fn($label,$steps,$ordered=false)=>array('label'=>$label,'steps'=>$steps,'ordered'=>$ordered);
        foreach($levels as $id=>&$d){
            $d['chapter']=$id==='board-01'?'조건 필터':($id==='board-02'?'다중 주문·정렬':'집계·결측');
            foreach($d['orders'] as &$o){
                $steps=array();
                if(!empty($o['aggregate']))$steps=array($group('sum'),$sort('asc','name','group'));
                else {if($o['group']!==null)$steps[]=$filter('group','eq',$o['group']);$steps[]=$filter('value','lte',$o['max']);if($o['sort'])$steps[]=$sort();$steps[]=$project;}
                $o=$order($o['label'],$steps,!empty($o['sort'])||!empty($o['aggregate']));
            }unset($o);
        }unset($d);
        $add=static function($id,$title,$chapter,$rows,$orders)use(&$levels):void {
            $cost=0;foreach($orders as $o){$cost+=2;foreach($o['steps'] as $step)$cost+=$step['type']==='group'?2:1;}
            if(count($orders)===2){$prefix=0;while(isset($orders[0]['steps'][$prefix],$orders[1]['steps'][$prefix])&&$orders[0]['steps'][$prefix]===$orders[1]['steps'][$prefix])$prefix++;if($prefix>1)foreach(array_slice($orders[0]['steps'],0,$prefix) as $step)$cost-=$step['type']==='group'?2:1;}
            $levels[sprintf('board-%02d',$id)]=array('title'=>$title,'chapter'=>$chapter,'goal'=>'모든 주문을 출고하세요. 결측은 0이 아니며, 동률은 주문에 적힌 기준으로 정렬합니다.','budget'=>$cost+10,'referenceCost'=>$cost,'names'=>array_column($rows,0),'groups'=>array_column($rows,1),'values'=>array_column($rows,2),'orders'=>$orders);
        };
        $add(4,'체육용품 분류','조건 필터',array(array('공','실외',8),array('매트','실내',6),array('라켓','실외',4),array('콘','실외',9),array('줄넘기','실내',7),array('배트','실외',3)),array($order('실내가 아닌 용품 · 이름/값',array($filter('group','ne','실내'),$project)),$order('수량 7 이상 · 이름/값',array($filter('value','gte',7),$project))));
        $add(5,'캠핑 재고 점검','조건 필터',array(array('텐트','숙박',3),array('침낭','숙박',6),array('램프','조명',0),array('컵','식기',8),array('접시','식기',4),array('랜턴','조명',0)),array($order('수량 3~6 포함 · 이름/값',array($filter('value','range',3,6),$project)),$order('수량이 정확히 0 · 이름/값',array($filter('value','eq',0),$project))));
        $add(6,'박물관 운송 상자','조건 필터',array(array('항아리','도자',7),array('접시','도자',2),array('붓','서화',1),array('족자','서화',4),array('동전','금속',2),array('종','금속',9)),array($order('도자 · 무게 5 이하 · 이름/값',array($filter('value','lte',5),$filter('group','eq','도자'),$project)),$order('금속이 아닌 것 · 무게 4 이하 · 이름/값',array($filter('value','lte',4),$filter('group','ne','금속'),$project))));
        $add(7,'씨앗 나눔소','조건 필터',array(array('상추','채소',3),array('봉선화','꽃',6),array('토마토','채소',7),array('해바라기','꽃',5),array('완두','채소',4),array('무궁화','꽃',2)),array($order('채소 · 수량 4 이상 · 이름/값',array($filter('group','eq','채소'),$filter('value','gte',4),$project)),$order('꽃 · 수량 2~5 포함 · 이름/값',array($filter('group','eq','꽃'),$filter('value','range',2,5),$project))));
        $add(8,'축제 품절 보고','조건 필터',array(array('팔찌','기념품',2),array('엽서','기념품',4),array('주스','음료',5),array('물','음료',8),array('배지','기념품',6),array('차','음료',3)),array($order('수량 0인 품목 · 해당 없으면 빈 목록을 출고',array($filter('value','eq',0),$project)),$order('음료 · 수량 5 이상 · 이름/값',array($filter('group','eq','음료'),$filter('value','gte',5),$project))));
        $add(9,'도서 예약 동률 정렬','다중 주문·정렬',array(array('화산','과학',3),array('우주','과학',3),array('바다','과학',3),array('역사','인문',2),array('미술','예술',2),array('언어','인문',6)),array($order('과학 · 값 오름차순, 동률은 ID 오름차순 · 이름/값',array($filter('group','eq','과학'),$sort('asc','id'),$project),true),$order('전체 · 값 오름차순, 동률은 이름 오름차순 · 이름/값',array($sort(),$project),true)));
        $add(10,'긴급 지원 물자','다중 주문·정렬',array(array('담요','생활',8),array('물','식품',9),array('빵','식품',6),array('비누','생활',2),array('우비','생활',7),array('쌀','식품',4)),array($order('생활 · 필요도 5 이상 · 값 내림차순 · 이름/값',array($filter('group','eq','생활'),$filter('value','gte',5),$sort('desc'),$project),true),$order('식품 · 값 내림차순 · 이름/값',array($filter('group','eq','식품'),$sort('desc'),$project),true)));
        $add(11,'탐방 장비 세 팀','다중 주문·정렬',array(array('지도','탐색',3),array('나침반','탐색',6),array('모자','의류',5),array('신발','의류',9),array('수통','생활',4),array('배낭','생활',8)),array($order('값 3~6 · 오름차순 · 이름/값',array($filter('value','range',3,6),$sort(),$project),true),$order('값 7 이상 · 내림차순 · 이름/값',array($filter('value','gte',7),$sort('desc'),$project),true),$order('탐색 · 이름/값',array($filter('group','eq','탐색'),$project))));
        $add(12,'공동 처리 농산물','다중 주문·정렬',array(array('사과','과일',3),array('배','과일',6),array('콩','곡물',2),array('보리','곡물',5),array('딸기','과일',9),array('쌀','곡물',8)),array($order('값 6 이하 · 과일 · 값 오름차순 · 이름/값',array($filter('value','lte',6),$sort(),$filter('group','eq','과일'),$project),true),$order('값 6 이하 · 곡물 · 값 오름차순 · 이름/값',array($filter('value','lte',6),$sort(),$filter('group','eq','곡물'),$project),true)));
        $add(13,'문화 행사 입장 배정','다중 주문·정렬',array(array('연극','공연',6),array('무용','공연',6),array('합창','공연',2),array('사진','전시',4),array('조각','전시',4),array('회화','전시',8)),array($order('공연 · 값 내림차순, 동률 ID · 이름/값',array($filter('group','eq','공연'),$sort('desc','id'),$project),true),$order('전시 · 값 오름차순, 동률 이름 · 이름/값',array($filter('group','eq','전시'),$sort(),$project),true),$order('값 4 이하 · 이름/값',array($filter('value','lte',4),$project))));
        $add(14,'창고 품목 수 집계','집계·결측',array(array('공','체육',4),array('라켓','체육',null),array('붓','미술',3),array('종이','미술',8),array('물감','미술',null),array('북','음악',2)),array($order('분류별 모든 행 수 · 분류 오름차순',array($group('count'),$sort('asc','name','group')),true),$order('결측이 아닌 품목만 · 이름/값',array($filter('value','present'),$project))));
        $add(15,'강수량 평균과 결측','집계·결측',array(array('동쪽1','동쪽',2),array('동쪽2','동쪽',6),array('서쪽1','서쪽',null),array('서쪽2','서쪽',8),array('남쪽1','남쪽',null),array('남쪽2','남쪽',null)),array($order('관측값만 분류별 평균 · 관측 없으면 결측 · 분류 오름차순',array($group('mean','skip'),$sort('asc','name','group')),true),$order('값이 결측인 기록 · 이름/값',array($filter('value','missing'),$project))));
        $add(16,'전력 관측 합산','집계·결측',array(array('교실1','교실',0),array('교실2','교실',5),array('복도1','복도',null),array('복도2','복도',4),array('체육관1','체육관',9),array('체육관2','체육관',null)),array($order('관측된 값만 합계 · 분류 오름차순',array($group('sum','skip'),$sort('asc','name','group')),true),$order('실측 0인 기록만 · 결측 제외 · 이름/값',array($filter('value','eq',0),$project))));
        $add(17,'행사 이중 집계','집계·결측',array(array('쿠키','음식',9),array('주스','음식',3),array('리본','장식',2),array('배지','장식',4),array('엽서','문구',8),array('책갈피','문구',6)),array($order('분류별 평균 · 평균 내림차순',array($group('mean'),$sort('desc','name','value')),true),$order('매출 5 이상인 품목의 분류별 행 수 · 분류 오름차순',array($filter('value','gte',5),$group('count'),$sort('asc','name','group')),true)));
        $add(18,'도서 물류 종합 관문','집계·결측',array(array('우주','과학',7),array('생물','과학',5),array('화산','과학',null),array('역사','인문',8),array('언어','인문',2),array('미술','예술',null),array('음악','예술',6)),array($order('결측 기록 · 이름/값',array($filter('value','missing'),$project)),$order('과학 · 값 5 이상 · 내림차순 · 이름/값',array($filter('group','eq','과학'),$filter('value','gte',5),$sort('desc'),$project),true),$order('관측값만 분류별 합계 · 분류 오름차순',array($group('sum','skip'),$sort('asc','name','group')),true)));
        return $levels;
    }
}
