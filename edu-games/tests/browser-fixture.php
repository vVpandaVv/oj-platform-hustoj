<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$GLOBALS['wpdb']=new Fake_DB();
function esc_attr($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_html($value){return esc_attr($value);}
function esc_url_raw($value){return $value;}
function esc_html_e($value,$domain=''){echo esc_html($value);}
function esc_attr_e($value,$domain=''){echo esc_attr($value);}
function esc_attr__($value,$domain=''){return esc_attr($value);}
function esc_url($value){return esc_attr($value);}
function rest_url($path){return '/'.$path;}
function wp_create_nonce($action){return 'fixture-nonce';}
function wp_enqueue_style(...$args){}
function wp_enqueue_script(...$args){}
function wp_get_current_user(){return (object)array('display_name'=>'검증 계정');}
function get_block_wrapper_attributes($args){return 'class="'.esc_attr($args['class']).'"';}
use EduGames\Learning\Run;
use EduGames\Learning\Catalog;
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$game=$input['game'];$action=$input['action'];
if($action==='progress'){
    try{echo json_encode(EduGames\Progress\Validator::entries($game,$input['payload']),JSON_THROW_ON_ERROR);}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage());exit(1);}exit;
}
if($game==='console-report'&&$action==='html'){
    spl_autoload_register(static function($class){$prefix='CodingPanda\\OjPlatform\\';if(str_starts_with($class,$prefix)){$file=dirname(EDU_GAMES_PATH).'/oj-integration-rebuild/oj-platform/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file;}});
    $request=new CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1('addon.edu_games.console.report',7,[],[],'ko_KR','test-report');
    echo (new EduGames\Addon\Console_Game_Report_Page_Handler())->handle($request)->body();exit;
}
if($game==='warehouse'){
    if($action==='html'){echo EduGames\Blocks\Warehouse_Renderer::render();exit;}
    if($action==='catalog'){echo json_encode(array('levels'=>EduGames\Games\Modules\Data_Structure_Lab\Warehouse_Board::levels(),'solutions'=>require EDU_GAMES_PATH.'src/Games/Modules/Data_Structure_Lab/warehouse-solutions.php'));exit;}
    $board=EduGames\Games\Modules\Data_Structure_Lab\Warehouse_Board::class;
    $state=$action==='start'?$board::create($input['level']):$input['state'];
    if($action==='update'){$state=$board::apply($state,$input['move']);}
    echo json_encode(array('state'=>$state,'view'=>$board::view($state)),JSON_UNESCAPED_UNICODE);exit;
}
if($action==='html'){
    if(!empty($input['learning'])){echo EduGames\Blocks\Learning_Renderer::render_game($game,$game);exit;}
    if(!empty($input['play'])){echo EduGames\Blocks\Play_Renderer::render($game);exit;}
    if(in_array($game,array('game-leaderboard','group-leaderboard'),true)){define('ABSPATH',EDU_GAMES_PATH);require EDU_GAMES_PATH.'blocks/'.$game.'/render.php';exit;}
    if($game==='strategy-report'){echo EduGames\Blocks\Strategy_Renderer::reports();exit;}
    if(!empty($input['primary'])){
        $primary=array('data-extraction'=>'Data_Extraction','missing-values'=>'Missing_Values','outlier-detection'=>'Outlier_Detection','data-visualization'=>'Data_Visualization','ai-classifier-lab'=>'AI_Classifier_Lab','ai-model-lab'=>'AI_Model_Lab');
        if(isset($primary[$game])){echo EduGames\Blocks\Strategy_Renderer::render($game,$game);exit;}
    }
    if($game==='learning-report'){echo EduGames\Blocks\Learning_Renderer::report();exit;}
    $map=array('image-data'=>'Image_Data','binary-arcade'=>'Binary_Arcade','character-code'=>'Character_Code','device-matching'=>'Device_Matching','code-typing'=>'Code_Typing','keyword-rain'=>'Keyword_Rain');
    if(isset($map[$game])){$class='EduGames\\Blocks\\'.$map[$game].'_Renderer';echo $class::render();}
    else{echo EduGames\Blocks\Learning_Renderer::render_game($game,$game);}
    exit;
}
if($action==='start'){
    $state=Run::create($game,$input['level']??'all');
    if(isset($input['fixtureId'])){$state['ids']=array($input['fixtureId']);}
}else{
    $state=$input['state'];
    if($action==='update'){$state=Run::apply($state,$game,$input['mode'],$input['payload']);}
}
echo json_encode(array('state'=>$state,'view'=>Run::view($state,$game,$input['mode'])),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
