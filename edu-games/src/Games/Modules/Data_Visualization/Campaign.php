<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Visualization;

final class Campaign {
    public static function levels(): array {
        $out=array();
        $add=static function($id,$title,$chapter,$names,$values,$specs,$times=null,$points=null)use(&$out):void {
            $rows=array();foreach($names as $i=>$name)$rows[]=array('id'=>$i,'name'=>$name,'value'=>$values[$i],'time'=>$times[$i]??$i+1,'x'=>$points[$i][0]??$i+1,'y'=>$points[$i][1]??$values[$i]);
            $panels=array();$cost=1;
            foreach($specs as $spec){$purpose=$spec[0];$panels[]=array('purpose'=>$purpose,'title'=>$spec[1],'goal'=>$spec[2],'unit'=>$spec[3]??'개','direction'=>$spec[4]??'desc','lineOnly'=>$spec[5]??false,'rows'=>$rows);$cost+=count($rows)+match($purpose){'compare'=>0,'trend'=>1+(!empty($spec[5])?1:0),'distribution','share','relationship'=>3};}
            $out[sprintf('board-%02d',$id)]=array('title'=>$title,'chapter'=>$chapter,'goal'=>count($panels)>1?'두 작업판의 제작 조건을 모두 충족하세요. 자료와 수정 자원을 나누어 사용합니다.':$panels[0]['goal'],'budget'=>$cost+12,'referenceCost'=>$cost,'panels'=>$panels);
        };
        $compare=static fn($direction='desc')=>array('compare','범주 비교','전체 범주를 값 '.($direction==='desc'?'큰':'작은').' 순서로 비교하세요. 막대 기준선은 0입니다.','명',$direction);
        $trend=static fn($lineOnly=false)=>array('trend','시간 변화',$lineOnly?'실제 날짜 간격을 유지한 선 차트를 시간 순서로 완성하세요.':'관측 시점 순서로 변화를 보여 주세요. 선 또는 막대가 가능합니다.','cm','asc',$lineOnly);
        $hist=array('distribution','빈도 분포','같은 폭의 구간으로 빈도 분포를 만드세요. 경계 값은 오른쪽 구간에 포함합니다.','분');
        $share=array('share','전체의 구성','모든 범주가 전체에서 차지하는 비중을 보여 주세요. 원·도넛 또는 백분율 막대를 사용할 수 있습니다.','개');
        $relation=array('relationship','두 특성의 관계','X와 Y의 짝을 유지해 좌표에 배치하세요. 가로는 X, 세로는 Y이고 원자료의 모든 점이 필요합니다.','단위');
        $add(1,'체육관 이용 현황','비교',array('농구','수영','배구','배드민턴','탁구','체조'),array(5,8,3,6,4,9),array($compare()));
        $add(2,'새싹 성장 일지','추세',array('4일','1일','6일','2일','5일','3일'),array(7,1,11,3,9,5),array($trend()),array(4,1,6,2,5,3));
        $add(3,'도서관 대기 시간','분포',array('A','B','C','D','E','F'),array(1,3,4,7,8,11),array($hist));
        $add(4,'동률인 동아리 인원','비교',array('합창','연극','미술','무용','사진','공예'),array(6,6,4,9,4,2),array($compare()));
        $add(5,'재고가 없는 품목','비교',array('연필','종이','물감','붓','풀','가위'),array(0,8,4,0,6,2),array($compare('asc')));
        $add(6,'불규칙한 관측 간격','추세',array('6일','0일','15일','1일','10일','3일'),array(6,1,11,2,9,4),array($trend(true)),array(6,0,15,1,10,3));
        $add(7,'변화가 멈춘 구간','추세',array('5일','2일','4일','1일','6일','3일'),array(5,0,2,0,5,2),array($trend()),array(5,2,4,1,6,3));
        $add(8,'같은 값도 각각 한 표본','분포',array('A','B','C','D','E','F'),array(1,1,2,2,2,11),array($hist));
        $add(9,'구간 경계에 놓인 표본','분포',array('A','B','C','D','E','F'),array(0,2,4,6,8,10),array($hist));
        $add(10,'학교 정원 구성','비율',array('꽃밭','나무','텃밭','연못','잔디','산책로'),array(25,20,15,10,20,10),array($share));
        $add(11,'열 개의 선택을 백분율로','비율',array('축구','농구','배구','수영','탁구','체조'),array(3,2,1,2,1,1),array($share));
        $add(12,'0도 빠뜨리지 않는 구성','비율',array('종이','유리','플라스틱','금속','천','기타'),array(0,10,15,25,20,30),array($share));
        $add(13,'함께 증가하는 관측','관계',array('A','B','C','D','E','F'),array(2,3,4,6,8,10),array($relation),null,array(array(1,2),array(2,3),array(4,4),array(6,6),array(8,8),array(9,10)));
        $add(14,'반대로 움직이는 두 특성','관계',array('A','B','C','D','E','F'),array(9,8,7,4,3,1),array($relation),null,array(array(1,9),array(2,8),array(4,7),array(6,4),array(8,3),array(10,1)));
        $add(15,'드문 점도 남겨 두기','관계',array('A','B','C','D','E','F'),array(5,3,5,4,3,9),array($relation),null,array(array(1,5),array(3,3),array(4,5),array(6,4),array(8,3),array(9,9)));
        $add(16,'비교와 분포를 함께','복합 작업판',array('A반','B반','C반','D반','E반','F반'),array(5,8,3,6,4,9),array($compare(),$hist));
        $add(17,'시간 흐름과 순위','복합 작업판',array('4일','1일','6일','2일','5일','3일'),array(7,1,11,3,9,5),array($trend(),$compare()),array(4,1,6,2,5,3));
        $add(18,'구성과 관계의 두 시선','복합 작업판',array('A','B','C','D','E','F'),array(25,20,15,10,20,10),array($share,$relation),null,array(array(1,2),array(2,4),array(4,5),array(6,5),array(8,8),array(9,9)));
        return $out;
    }
}
