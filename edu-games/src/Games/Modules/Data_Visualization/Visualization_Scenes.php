<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Visualization;
use EduGames\Play\Hands_Board as Base;

final class Visualization_Scenes extends Base {
    public const NAMES=array('bar'=>'막대그래프','line'=>'꺾은선그래프','pie'=>'원그래프','histogram'=>'히스토그램','scatter'=>'산점도');
    public static function populate(array $s): array {
        $s['scene']=$s['kind'];$s['rows']=array();
        if($s['scene']==='line'){
            $s['mission']='수위 급상승 경보';$s['objective']='한 시간 사이 수위가 가장 크게 오른 지역과 구간에 경보를 배치하세요. 가장 높은 수위와 가장 큰 상승폭은 다릅니다.';
            $s['capacity']='경보기 1개';$s['contextNote']='가상 관측 기록 · 시간 간격 1시간 · 수위 단위 cm';
            foreach(array('동쪽','중앙','서쪽') as $i=>$region){
                $value=self::number($s,20,65);
                for($t=0;$t<5;$t++){
                    if($t>0)$value=max(3,min(99,$value+self::number($s,-18,22)));
                    $s['rows'][]=array('region'=>$region,'time'=>sprintf('%02d시',8+$t),'value'=>$value);
                }
            }
            if(max(array_column(self::choices($s),'value'))<=0)$s['rows'][1]['value']=$s['rows'][0]['value']+5;
        }elseif($s['scene']==='pie'){
            $s['supply']=self::number($s,4,6)*3;$first=self::number($s,1,$s['supply']-2);$second=self::number($s,1,$s['supply']-$first-1);
            $weights=self::shuffle($s,array($first,$second,$s['supply']-$first-$second));$factor=self::number($s,2,5);
            $s['mission']='세 지역 공평 보급';$s['objective']='보급품 '.$s['supply'].'개를 모두 배분해 세 지역의 요청 대비 지원율을 같게 만드세요. 요청이 많은 지역에는 더 많이 필요합니다.';
            $s['capacity']='보급품 '.$s['supply'].'개';$s['contextNote']='가상 보급 요청 · 요청 1건에 보급품 1개 · 세 지역의 지원 비율을 동일하게';
            foreach(array('동쪽','중앙','서쪽') as $i=>$region)$s['rows'][]=array('region'=>$region,'value'=>$weights[$i]*$factor);
        }elseif($s['scene']==='histogram'){
            $s['window']=self::number($s,2,3);$s['binStart']=self::number($s,5,7)*10;$s['binWidth']=10;$s['binCount']=self::number($s,5,7);
            $s['mission']='구명조끼 적재 범위';$s['objective']='연속된 '.$s['window'].'개 사이즈 구간을 적재해 가장 많은 사람에게 맞는 조끼를 준비하세요.';
            $s['capacity']='연속 구간 '.$s['window'].'개';$s['contextNote']='교육용 가상 사이즈 · 하한 이상, 상한 미만 · 실제 제품 규격이 아닙니다.';
            $counts=array();for($i=0;$i<$s['binCount'];$i++)$counts[]=self::number($s,1,18);
            $totals=array();for($i=0;$i<=$s['binCount']-$s['window'];$i++)$totals[]=array_sum(array_slice($counts,$i,$s['window']));
            if(count(array_unique($totals))===1)$counts[0]++;
            foreach($counts as $i=>$count)for($j=0;$j<$count;$j++)$s['rows'][]=array('value'=>$s['binStart']+$i*10+self::number($s,0,9));
            $s['rows']=self::shuffle($s,$s['rows']);
        }elseif($s['scene']==='scatter'){
            $xs=array_slice(self::shuffle($s,range(20,110)),0,8);$ys=array_slice(self::shuffle($s,range(10,90)),0,8);
            for($i=0;$i<8;$i++)$s['rows'][]=array('id'=>'relay-'.$i,'name'=>'중계기 '.($i+1),'x'=>$xs[$i],'y'=>$ys[$i]);
            sort($xs);sort($ys);$s['scatterGoal']=self::number($s,0,1)?'distance':'power';
            $s['budget']=$ys[self::number($s,2,5)];$s['minDistance']=$xs[self::number($s,2,5)];
            $s['mission']=$s['scatterGoal']==='distance'?'전력 한도 안에서 넓게 연결':'목적지까지 최소 전력 연결';
            $s['objective']=$s['scatterGoal']==='distance'?'소비 전력이 '.$s['budget'].'W 이하인 중계기 중 통신 거리가 가장 긴 한 대를 선택하세요.':$s['minDistance'].'m 떨어진 목적지에 도달하는 중계기 중 소비 전력이 가장 적은 한 대를 선택하세요.';
            $s['capacity']=$s['scatterGoal']==='distance'?'전력 '.$s['budget'].'W 이하':'거리 '.$s['minDistance'].'m 이상';$s['contextNote']='가상 장치의 관측 성능 · 가로: 통신 거리 m · 세로: 소비 전력 W';
            $s['rows']=self::shuffle($s,$s['rows']);
        }else throw new \InvalidArgumentException('시각화 장면을 확인하세요.');
        return $s;
    }
    public static function bins(array $s): array {
        $bins=array();for($i=0;$i<$s['binCount'];$i++)$bins[]=array('low'=>$s['binStart']+$i*$s['binWidth'],'high'=>$s['binStart']+($i+1)*$s['binWidth'],'count'=>0);
        foreach($s['rows'] as $r)foreach($bins as &$bin)if($r['value']>=$bin['low']&&$r['value']<$bin['high']){$bin['count']++;break;}unset($bin);
        return $bins;
    }
    public static function choices(array $s): array {
        $choices=array();
        if($s['scene']==='line'){
            foreach(array('동쪽','중앙','서쪽') as $region){
                $rows=array_values(array_filter($s['rows'],fn($r)=>$r['region']===$region));
                for($i=1;$i<count($rows);$i++){
                    $a=$rows[$i-1];$b=$rows[$i];
                    $choices[]=array('id'=>$region.'|'.$b['time'],'name'=>$region.' '.$a['time'].'~'.$b['time'],'value'=>$b['value']-$a['value'],'unit'=>'cm 상승','before'=>$a['value'],'after'=>$b['value'],'region'=>$region,'index'=>$i);
                }
            }
        }elseif($s['scene']==='histogram'){
            $bins=self::bins($s);
            for($i=0;$i<=count($bins)-$s['window'];$i++){
                $parts=array_column(array_slice($bins,$i,$s['window']),'count');
                $choices[]=array('id'=>'size-'.$i,'name'=>$bins[$i]['low'].'cm 이상 '.$bins[$i+$s['window']-1]['high'].'cm 미만','value'=>array_sum($parts),'unit'=>'명 포함','index'=>$i,'parts'=>$parts);
            }
        }elseif($s['scene']==='scatter'){
            foreach($s['rows'] as $row)$choices[]=array('id'=>$row['id'],'name'=>$row['name'],'value'=>$s['scatterGoal']==='distance'?$row['x']:-$row['y'],'unit'=>$s['scatterGoal']==='distance'?'m':'W','distance'=>$row['x'],'power'=>$row['y'],'eligible'=>$s['scatterGoal']==='distance'?$row['y']<=$s['budget']:$row['x']>=$s['minDistance']);
        }
        return $choices;
    }
    public static function judge(array $s,array $move): array {
        if($s['scene']==='pie'){
            $allocation=$move['allocation']??null;
            if(!is_array($allocation)||!array_is_list($allocation)||count($allocation)!==3)throw new \InvalidArgumentException('세 지역에 나눌 보급품 수를 확인하세요.');
            foreach($allocation as $n)self::integer($n,0,$s['supply']);
            if(array_sum($allocation)!==$s['supply'])throw new \InvalidArgumentException('보급품 '.$s['supply'].'개를 모두 배분하세요.');
            $total=array_sum(array_column($s['rows'],'value'));$target=array_map(fn($r)=>(int)round($s['supply']*$r['value']/$total),$s['rows']);$details=array();
            foreach($s['rows'] as $i=>$row)$details[]=array('name'=>$row['region'],'request'=>$row['value'],'ratio'=>round(100*$row['value']/$total,1),'actual'=>$allocation[$i],'target'=>$target[$i]);
            return array('correct'=>$allocation===$target,'allocation'=>$allocation,'target'=>$target,'details'=>$details,'explanation'=>'요청의 원과 배분의 원에서 같은 지역의 조각 비율이 같아야 합니다. 물품 수를 똑같이 나누는 것과 요청 대비 지원율을 같게 만드는 것은 다릅니다.');
        }
        $choices=self::choices($s);$id=self::choice($move['choice']??null,array_column($choices,'id'));$selected=$choices[array_search($id,array_column($choices,'id'),true)];
        $eligible=array_values(array_filter($choices,fn($c)=>$c['eligible']??true));usort($eligible,fn($a,$b)=>$b['value']<=>$a['value']);$best=$eligible[0];
        $explanations=array('line'=>'상승폭은 나중 수위에서 이전 수위를 뺀 값입니다. 모든 구간이 한 시간이므로 상승폭이 가장 큰 구간의 선이 가장 가파릅니다.','histogram'=>'연속된 '.($s['window']??2).'개 막대의 빈도를 합해야 지원 인원을 알 수 있습니다. 같은 최대 인원을 지원하는 범위는 모두 성공입니다.','scatter'=>($s['scatterGoal']??'distance')==='distance'?'전력 한도선 이하의 점 중 가장 오른쪽 점이 최선입니다. 장치 번호나 전체 점들의 관계만으로는 정할 수 없습니다.':'목적지 거리선 이상의 점 중 가장 아래쪽 점이 최선입니다. 필요한 거리를 만족하면서 전력을 가장 적게 씁니다.');
        return array('correct'=>($selected['eligible']??true)&&$selected['value']===$best['value'],'selected'=>$selected,'best'=>$best,'explanation'=>$explanations[$s['scene']]);
    }
}
