<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\AI_Model_Lab;
use EduGames\Boards\Rules as R;

final class Strategy_Board {
    private const TARGET_F1=85;
    private const TARGET_RECALL=75;
    public static function bank(): array {static $bank=null;if($bank===null)$bank=require __DIR__.'/strategy-experiments.php';return $bank;}
    public static function levels(): array {
        $out=array();foreach(self::bank()['missions'] as $id=>$m){$out[$id]=array('title'=>$m['title'],'chapter'=>$m['chapter'],'goal'=>'처음 보는 자료에서도 A와 B를 골고루 찾아내는 모델을 만드세요. 합성 자료로 진행하는 실험입니다.','budget'=>36,'referenceCost'=>$m['referenceCost']);}return $out;
    }
    public static function create(string $id): array {return R::begin($id,self::levels())+array('batches'=>array(0,0,0),'trained'=>null,'validated'=>false,'rounds'=>0,'log'=>array());}
    private static function meets(array $metrics): bool {return $metrics['macroF1']>=self::TARGET_F1&&min($metrics['recall'])>=self::TARGET_RECALL;}
    public static function apply(array $s,array $m): array {
        R::guard($s);$d=self::levels()[$s['level']];$mission=self::bank()['missions'][$s['level']];$type=R::choice($m,'type',array('collect','review','train','validate','finish'));
        if($type==='collect'||$type==='review'){
            $i=R::index($m,'batch',3);$b=$mission['batches'][$i];
            if($type==='collect'&&$s['batches'][$i]!==0)throw new \InvalidArgumentException('이미 확보한 자료 묶음입니다.');
            if($type==='review'&&$s['batches'][$i]!==1)throw new \InvalidArgumentException('수집한 미검수 자료에만 검수권을 쓸 수 있습니다.');
            R::charge($s,$type==='collect'?$b['cost']:$b['reviewCost']);$s['batches'][$i]=$type==='collect'?1:2;$s['trained']=null;$s['validated']=false;$s['message']=$type==='collect'?'자료를 확보했습니다. 일부 라벨에는 오류가 섞여 있습니다.':'라벨을 대조하여 수정했습니다. 변경된 자료로 다시 훈련해야 합니다.';
        }elseif($type==='train'){
            $model=R::choice($m,'model',array('tree','knn','linear'));if(!array_filter($s['batches']))throw new \InvalidArgumentException('먼저 자료를 수집하세요.');if($s['rounds']>=3)throw new \InvalidArgumentException('훈련 기회 3회를 모두 사용했습니다.');
            R::charge($s,array('tree'=>5,'knn'=>6,'linear'=>8)[$model]);$key=implode('',$s['batches']).':'.$model;$s['trained']=$key;$s['validated']=false;$s['rounds']++;$s['log'][]=array('round'=>$s['rounds'],'model'=>$model,'training'=>$mission['experiments'][$key]['training']['accuracy'],'batches'=>$s['batches']);$s['message']='훈련 완료. 훈련 정확도만으로 배포 품질을 판단할 수는 없습니다.';
        }elseif($type==='validate'){
            if($s['trained']===null||$s['validated'])throw new \InvalidArgumentException('새로 훈련한 모델을 검증하세요.');R::charge($s,2);$s['validated']=true;$s['log'][count($s['log'])-1]['validation']=$mission['experiments'][$s['trained']]['validation']['macroF1'];$s['message']='처음 보는 자료를 분류했습니다. A와 B 중 무엇을 놓쳤는지 결과가 나왔습니다.';
        }else{
            R::charge($s,1);$experiment=$s['trained']!==null?$mission['experiments'][$s['trained']]:null;$won=$experiment!==null&&$s['validated']&&self::meets($experiment['validation'])&&self::meets($experiment['test']);
            $s['evaluation']=$experiment?array('검증 매크로 F1'=>$s['validated']?$experiment['validation']['macroF1']:0,'최종 매크로 F1'=>$experiment['test']['macroF1'],'최종 A 재현율'=>$experiment['test']['recall'][0],'최종 B 재현율'=>$experiment['test']['recall'][1]):array('훈련 결과'=>'없음');
            R::finish($s,$won,$d,$won?'배포 임무 완료. 성능 기준을 지키면서 비용을 줄여 보세요.':'배포 실패. 검증 여부와 두 범주의 성능을 확인하세요.');
        }
        return R::after($s);
    }
    public static function view(array $s): array {
        $d=self::levels()[$s['level']];$bank=self::bank();$m=$bank['missions'][$s['level']];$batches=array();foreach($m['batches'] as $i=>$b){$labels=$s['batches'][$i]===2?$b['verifiedLabels']:$b['labels'];$batches[]=array('id'=>$i,'name'=>$b['name'],'cost'=>$b['cost'],'reviewCost'=>$b['reviewCost'],'status'=>$s['batches'][$i],'count'=>count($labels),'counts'=>array(count(array_filter($labels,static fn($v)=>$v===0)),count(array_filter($labels,static fn($v)=>$v===1))));}
        $e=$s['trained']!==null?$m['experiments'][$s['trained']]:null;
        return R::view($s,$d)+array('batches'=>$batches,'rounds'=>$s['rounds'],'log'=>$s['log'],'training'=>$e?$e['training']:null,'validation'=>$e&&$s['validated']?$e['validation']:null,'singleClass'=>$e?(bool)$e['singleClass']:false,'trained'=>$s['trained']!==null,'validated'=>$s['validated'],'context'=>$m['context'],'targets'=>array('macroF1'=>self::TARGET_F1,'recall'=>self::TARGET_RECALL),'engine'=>$bank['engine'].' '.$bank['engineVersion']);
    }
    public static function solution(string $id): array {[$status,$model]=explode(':',self::bank()['missions'][$id]['solution']);$out=array();foreach(str_split($status) as $i=>$value){if((int)$value>0)$out[]=array('type'=>'collect','batch'=>$i);if((int)$value===2)$out[]=array('type'=>'review','batch'=>$i);}$out[]=array('type'=>'train','model'=>$model);$out[]=array('type'=>'validate');$out[]=array('type'=>'finish');return $out;}
}
