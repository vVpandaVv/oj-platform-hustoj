<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

use DomainException;

/** Small, deterministic OLS engine. No student data is sent to an AI or external service. */
final class PilotRegression
{
    public static function fit(array $x, array $y, ?array $clusters=null): array
    {
        $n=count($y); $k=count($x[0]??[]);
        if ($k===0 || $n<=$k) {throw new DomainException('분석 인원에 비해 추정할 조건이 많습니다. 자료를 더 수집하세요.');}
        $xx=array_fill(0,$k,array_fill(0,$k,0.0)); $xy=array_fill(0,$k,0.0);
        foreach ($x as $r=>$v) {for($i=0;$i<$k;++$i){$xy[$i]+=$v[$i]*$y[$r];for($j=0;$j<$k;++$j){$xx[$i][$j]+=$v[$i]*$v[$j];}}}
        $inverse=self::inverse($xx); $beta=self::multiplyVector($inverse,$xy);
        $meat=array_fill(0,$k,array_fill(0,$k,0.0)); $scores=[]; $sse=0.0;
        foreach ($x as $r=>$v) {
            $e=$y[$r]-self::dot($v,$beta); $sse+=$e*$e;
            if ($clusters===null) {
                $h=self::dot($v,self::multiplyVector($inverse,$v));
                if (1-$h<1e-9) {throw new DomainException('일부 학생의 조건이 유일하여 안정적인 신뢰구간을 계산할 수 없습니다.');}
                self::outerAdd($meat,$v,($e/(1-$h))**2);
            } else {
                $key=$clusters[$r]; $scores[$key]??=array_fill(0,$k,0.0);
                for($i=0;$i<$k;++$i){$scores[$key][$i]+=$v[$i]*$e;}
            }
        }
        if ($sse<1e-12) {throw new DomainException('점수의 잔차 변동이 없어 불확실성을 추정할 수 없습니다.');}
        $df=$n-$k; $factor=1.0;
        if ($clusters!==null) {
            $g=count($scores);
            if ($g<6) {throw new DomainException('학급 보정 신뢰구간은 이 도구에서 최소 6개 학급이 필요합니다. 학급 수가 충분하다는 보장은 아니며, 현재는 기술통계만 제공합니다.');}
            foreach($scores as $v){self::outerAdd($meat,$v,1.0);}
            $factor=$g/($g-1)*($n-1)/($n-$k); $df=$g-1;
        }
        $cov=array_fill(0,$k,array_fill(0,$k,0.0));
        for($i=0;$i<$k;++$i){for($j=0;$j<$k;++$j){for($a=0;$a<$k;++$a){for($b=0;$b<$k;++$b){$cov[$i][$j]+=$inverse[$i][$a]*$meat[$a][$b]*$inverse[$b][$j]*$factor;}}}}
        return ['beta'=>$beta,'covariance'=>$cov,'df'=>$df,'n'=>$n,'parameters'=>$k,'critical'=>self::critical($df),
            'method'=>$clusters===null?'OLS-HC3':'OLS-CR1','clusters'=>$clusters===null?null:count($scores)];
    }

    public static function contrast(array $fit,array $weights):array
    {
        $estimate=self::dot($weights,$fit['beta']);
        $variance=self::dot($weights,self::multiplyVector($fit['covariance'],$weights));
        if (!is_finite($variance) || $variance<=1e-14) {throw new DomainException('이 비교의 변동을 추정할 수 없어 신뢰구간을 보류합니다.');}
        $se=sqrt($variance); $margin=$fit['critical']*$se;
        return ['estimate'=>$estimate,'se'=>$se,'lower'=>$estimate-$margin,'upper'=>$estimate+$margin,'df'=>$fit['df']];
    }

    private static function inverse(array $matrix):array
    {
        $n=count($matrix); $scale=max(array_map(static fn($r)=>max(array_map('abs',$r)),$matrix));
        foreach($matrix as$i=>&$row){$identity=array_fill(0,$n,0.0);$identity[$i]=1.0;$row=array_merge($row,$identity);}unset($row);
        for($i=0;$i<$n;++$i){
            $pivot=$i;for($r=$i+1;$r<$n;++$r){if(abs($matrix[$r][$i])>abs($matrix[$pivot][$i])){$pivot=$r;}}
            if(abs($matrix[$pivot][$i])<max(1,$scale)*1e-11){throw new DomainException('사전 점수·수준·집단을 구분하여 추정할 수 없습니다. 집단별 수준 분포와 점수 변동을 확인하세요.');}
            [$matrix[$i],$matrix[$pivot]]=[$matrix[$pivot],$matrix[$i]];$v=$matrix[$i][$i];
            for($c=0;$c<2*$n;++$c){$matrix[$i][$c]/=$v;}
            for($r=0;$r<$n;++$r){if($r===$i){continue;}$v=$matrix[$r][$i];for($c=0;$c<2*$n;++$c){$matrix[$r][$c]-=$v*$matrix[$i][$c];}}
        }
        return array_map(static fn($r)=>array_slice($r,$n),$matrix);
    }
    private static function multiplyVector(array $a,array $v):array {return array_map(static fn($r)=>self::dot($r,$v),$a);}
    private static function dot(array $a,array $b):float {$sum=0.0;foreach($a as$i=>$v){$sum+=$v*$b[$i];}return $sum;}
    private static function outerAdd(array &$a,array $v,float $factor):void {foreach($v as$i=>$x){foreach($v as$j=>$y){$a[$i][$j]+=$factor*$x*$y;}}}

    // Two-sided 95% Student-t quantile via the regularized incomplete beta function.
    public static function critical(int $df):float
    {
        if($df<1){throw new DomainException('신뢰구간 계산에 필요한 자유도가 없습니다.');}
        $lo=0.0;$hi=128.0;
        for($i=0;$i<70;++$i){$t=($lo+$hi)/2;$tail=self::beta($df/($df+$t*$t),$df/2,0.5);
            if($tail>0.05){$lo=$t;}else{$hi=$t;}}
        return ($lo+$hi)/2;
    }
    private static function beta(float $x,float $a,float $b):float
    {
        if($x<=0){return 0;}if($x>=1){return 1;}
        $bt=exp(self::logGamma($a+$b)-self::logGamma($a)-self::logGamma($b)+$a*log($x)+$b*log1p(-$x));
        return $x<($a+1)/($a+$b+2)?$bt*self::fraction($x,$a,$b)/$a:1-$bt*self::fraction(1-$x,$b,$a)/$b;
    }
    private static function fraction(float $x,float $a,float $b):float
    {
        $floor=static fn(float $v):float=>abs($v)<1e-30?($v<0?-1e-30:1e-30):$v;
        $qab=$a+$b;$qap=$a+1;$qam=$a-1;$c=1.0;$d=1/$floor(1-$qab*$x/$qap);$h=$d;
        for($m=1;$m<=200;++$m){$m2=2*$m;$aa=$m*($b-$m)*$x/(($qam+$m2)*($a+$m2));
            $d=1/$floor(1+$aa*$d);$c=$floor(1+$aa/$c);$h*=$d*$c;
            $aa=-($a+$m)*($qab+$m)*$x/(($a+$m2)*($qap+$m2));$d=1/$floor(1+$aa*$d);$c=$floor(1+$aa/$c);$delta=$d*$c;$h*=$delta;
            if(abs($delta-1)<3e-14){return $h;}}
        throw new DomainException('신뢰구간 수치 계산이 수렴하지 않았습니다.');
    }
    private static function logGamma(float $z):float
    {
        $coeff=[676.5203681218851,-1259.1392167224028,771.32342877765313,-176.61502916214059,12.507343278686905,-0.13857109526572012,9.984369578019572e-6,1.5056327351493116e-7];
        if($z<0.5){return log(M_PI)-log(sin(M_PI*$z))-self::logGamma(1-$z);}
        --$z;$x=0.99999999999980993;foreach($coeff as$i=>$c){$x+=$c/($z+$i+1);}$t=$z+7.5;
        return 0.5*log(2*M_PI)+($z+0.5)*log($t)-$t+log($x);
    }
}
