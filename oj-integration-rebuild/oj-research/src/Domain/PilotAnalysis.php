<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

use DomainException;

final class PilotAnalysis
{
    public const VERSION='pilot-analysis.1';

    public static function build(array $protocol,array $rows):array
    {
        $assigned=array_values(array_filter($rows,static fn($r)=>in_array($r['arm'],['A','B'],true)));
        $complete=array_values(array_filter($assigned,static fn($r)=>$r['pretest_score']!==null&&$r['posttest_score']!==null));
        $flow=[];$balance=[];$warnings=PilotDesign::cautions($protocol);
        foreach(['A','B']as$arm){
            $all=self::arm($assigned,$arm);$paired=self::arm($complete,$arm);
            $flow[$arm]=['included'=>count($all),'pretest'=>count(array_filter($all,static fn($r)=>$r['pretest_score']!==null)),
                'posttest'=>count(array_filter($all,static fn($r)=>$r['posttest_score']!==null)), 'complete'=>count($paired),'missing'=>count($all)-count($paired)];
            $balance[$arm]=PilotReport::stats(array_column($paired,'pretest_score'));
        }
        $levels=[];
        foreach(PilotDesign::LEVELS as$level=>$label){
            $group=array_values(array_filter($complete,static fn($r)=>$r['level']===$level));$summary=[];
            foreach(['A','B']as$arm){$a=self::arm($group,$arm);$summary[$arm]=['n'=>count($a),'pre'=>PilotReport::stats(array_column($a,'pretest_score')),
                'post'=>PilotReport::stats(array_column($a,'posttest_score')),'gain'=>PilotReport::stats(array_column($a,'gain'))];}
            $levels[$level]=['label'=>$label,'summary'=>$summary];
        }
        if(count($complete)<count($assigned)){$warnings[]='완전 사례 분석: 사전·사후가 모두 있는 학생만 추정에 포함합니다. 결측·철회에 따른 편향을 검토하세요.';}
        $clusterKeys=array_values(array_unique(array_column($complete,'class_code')));
        $requested=$protocol['covariance']??'auto';
        $cluster=$protocol['allocation']==='class'||$requested==='cluster'||($requested==='auto'&&count($clusterKeys)>1);
        if($cluster&&count($clusterKeys)<20){$warnings[]='학급 수가 적어 학급 보정 신뢰구간도 불안정할 수 있습니다. 최소 계산 조건은 연구 표본 수의 충분성 기준이 아닙니다.';}
        if(!$cluster&&count($clusterKeys)>1){$warnings[]='학생 단위 HC3를 선택했습니다. 같은 학급 학생의 잔차가 독립이라는 가정을 별도로 검토하세요.';}
        $warnings[]='사전 점수와 사후 점수의 선형 관계 및 집단 간 공통 기울기를 가정합니다. 분포·잔차와 가정은 별도로 확인하세요.';
        $warnings[]='수준별 비교와 수준 간 효과 차이는 탐색 분석이며 다중 비교를 보정하지 않았습니다. 한 수준에서만 차이가 보여도 수준 간 차이가 입증되는 것은 아닙니다.';
        $result=['version'=>self::VERSION,'outcome'=>'AI 비허용 평가 권장 · 사후 성취 (0~100점)', 'flow'=>$flow,'balance'=>$balance,
            'baseline_smd'=>self::standardized($complete,'pretest_score'), 'levels'=>$levels,'warnings'=>$warnings,
            'primary'=>['status'=>'unavailable','reason'=>'사전·사후 자료를 수집하면 분석합니다.'],
            'heterogeneity'=>['status'=>'unavailable','reason'=>'각 수준의 두 집단에 분석 자료가 필요합니다.']];
        try{
            self::guard($complete,$cluster);
            [$x,$columns]=self::matrix($complete,false);
            $fit=PilotRegression::fit($x,array_column($complete,'posttest_score'),$cluster?array_column($complete,'class_code'):null);
            $weights=array_fill(0,count($columns),0.0);$weights[1]=1.0;
            $effect=PilotRegression::contrast($fit,$weights);
            $sd=self::pooled($complete,'posttest_score');
            $result['primary']=['status'=>'estimated','method'=>$fit['method'],'n'=>count($complete),'columns'=>$columns,
                'clusters'=>$fit['clusters'],'effect'=>$effect,'pooled_post_sd'=>$sd,'standardized_difference'=>$sd!==null&&$sd>0?$effect['estimate']/$sd:null];
        }catch(DomainException $e){$result['primary']['reason']=$e->getMessage();}
        if($result['primary']['status']!=='estimated'){return $result;}
        try{
            $present=array_values(array_unique(array_column($complete,'level')));sort($present);
            if(count($present)<2){throw new DomainException('두 수준 이상이 있어야 수준 간 효과 차이를 비교할 수 있습니다.');}
            foreach($present as$level){self::guard(array_values(array_filter($complete,static fn($r)=>$r['level']===$level)),$cluster);}
            [$x,$columns]=self::matrix($complete,true);
            $fit=PilotRegression::fit($x,array_column($complete,'posttest_score'),$cluster?array_column($complete,'class_code'):null);
            $effects=[];$differences=[];
            foreach($present as$level){$w=array_fill(0,count($columns),0.0);$w[1]=1.0;$index=array_search('A:'.$level,$columns,true);if($index!==false){$w[$index]=1.0;}
                $effects[$level]=PilotRegression::contrast($fit,$w);}
            // Direct interaction contrasts, instead of comparing whether separate intervals cross zero.
            foreach($present as$i=>$left){foreach(array_slice($present,$i+1)as$right){$w=array_fill(0,count($columns),0.0);
                foreach([$left=>1.0,$right=>-1.0]as$level=>$sign){$index=array_search('A:'.$level,$columns,true);if($index!==false){$w[$index]=$sign;}}
                $differences[]=['left'=>$left,'right'=>$right,'effect'=>PilotRegression::contrast($fit,$w)];}}
            $result['heterogeneity']=['status'=>'estimated','effects'=>$effects,'differences'=>$differences,'method'=>$fit['method'],'columns'=>$columns];
        }catch(DomainException $e){$result['heterogeneity']['reason']=$e->getMessage();}
        return $result;
    }

    private static function matrix(array $rows,bool $interaction):array
    {
        $levels=array_values(array_unique(array_column($rows,'level')));sort($levels);$other=array_slice($levels,1);
        $mean=array_sum(array_column($rows,'pretest_score'))/count($rows);$columns=['intercept','A','pretest_centered_div_100'];
        foreach($other as$l){$columns[]='level:'.$l;}if($interaction){foreach($other as$l){$columns[]='A:'.$l;}}
        $matrix=[];
        foreach($rows as$r){$a=$r['arm']==='A'?1.0:0.0;$x=[1.0,$a,($r['pretest_score']-$mean)/100];
            foreach($other as$l){$x[]=$r['level']===$l?1.0:0.0;}if($interaction){foreach($other as$l){$x[]=$r['level']===$l?$a:0.0;}}$matrix[]=$x;}
        return [$matrix,$columns];
    }
    private static function guard(array $rows,bool $cluster):void
    {
        foreach(['A','B']as$arm){$selected=self::arm($rows,$arm);
            if(count($selected)<3){throw new DomainException('비교할 각 집단에 사전·사후가 모두 있는 학생이 최소 3명 필요합니다. 이는 수치 계산의 최소 조건이며 표본 수 계획을 대신하지 않습니다.');}
            if($cluster&&count(array_unique(array_column($selected,'class_code')))<2){throw new DomainException('각 집단에 최소 2개 학급이 필요합니다. 한 집단이 한 학급뿐이면 학급과 처치를 구분하기 어렵습니다.');}}
        if($cluster&&in_array('',array_column($rows,'class_code'),true)){throw new DomainException('학급 식별 자료가 누락되어 학급 보정을 계산할 수 없습니다.');}
    }
    private static function arm(array $rows,string $arm):array {return array_values(array_filter($rows,static fn($r)=>$r['arm']===$arm));}
    private static function pooled(array $rows,string $key):?float
    {
        $a=PilotReport::stats(array_column(self::arm($rows,'A'),$key));$b=PilotReport::stats(array_column(self::arm($rows,'B'),$key));
        if($a['sd']===null||$b['sd']===null){return null;}return sqrt((($a['n']-1)*$a['sd']**2+($b['n']-1)*$b['sd']**2)/($a['n']+$b['n']-2));
    }
    private static function standardized(array $rows,string $key):?float
    {
        $sd=self::pooled($rows,$key);if($sd===null||$sd<=0){return null;}
        return (PilotReport::stats(array_column(self::arm($rows,'A'),$key))['mean']-PilotReport::stats(array_column(self::arm($rows,'B'),$key))['mean'])/$sd;
    }
}
