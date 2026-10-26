<?php
declare(strict_types=1);

// Minimal valid actions, not an assertion that a solution script measures learning or fun.
function hands_move(string $game,array $s): array {
    switch($game){
        case 'outlier-detection':return array('type'=>'scan','ids'=>$s['truth']);
        case 'missing-values':return $s['phase']==='review'?array('type'=>'next'):array('type'=>'restore','ids'=>EduGames\Games\Modules\Missing_Values\Hands_Board::source_ids($s),'method'=>$s['family']);
        case 'data-extraction':return array('type'=>'ship')+$s['order'];
        case 'data-structure-lab':return array('type'=>'push','lane'=>'queue');
        case 'ai-classifier-lab':return array('type'=>'classify','line'=>array(64,26));
        case 'ai-model-lab':return array('type'=>'label','id'=>0,'label'=>$s['classes'][0]);
        case 'data-visualization':
            if($s['phase']==='review')return array('type'=>'next');
            if($s['scene']==='pie')return array('type'=>'dispatch','allocation'=>array($s['supply'],0,0));
            if($s['scene']!=='bar')return array('type'=>'dispatch','choice'=>EduGames\Games\Modules\Data_Visualization\Visualization_Scenes::choices($s)[0]['id']);
            $bars=EduGames\Games\Modules\Data_Visualization\Hands_Board::bars($s,$s['kind']);
            return array('type'=>'dispatch','view'=>$s['kind'],'bar'=>$bars[0]['id']);
    }
    throw new RuntimeException('Unknown game');
}
