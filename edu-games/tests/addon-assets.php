<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$package=require EDU_GAMES_PATH.'plugin-package.php';
define('EDU_GAMES_VERSION',$package['version']);
$source=getenv('OJ_PLATFORM_SOURCE')?:dirname(__DIR__,2).'/oj-integration-rebuild/oj-platform/src';
if(!is_file($source.'/AddonApi/V1/AddonManifestBuilderV1.php')) { throw new RuntimeException('Set OJ_PLATFORM_SOURCE to the platform src directory.'); }
spl_autoload_register(static function(string $class) use($source):void {
    $prefix='CodingPanda\\OjPlatform\\';
    if(str_starts_with($class,$prefix)) {
        $file=$source.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require $file;
    }
});
use CodingPanda\OjPlatform\AddonApi\V1 as Api;
$builder=new class implements Api\AddonManifestBuilderV1 {
    public array $assets=array(); public array $pages=array();
    public function onReady(callable $callback):void {}
    public function manifest(string $namespace,string $version,string $pluginFile,string $requiresPlatform,string $requiresAddonApi,string $diagnosticLabel,string $supportReference):void {}
    public function capability(Api\AddonCapabilityDefinitionV1 $definition):void {}
    public function consolePage(Api\AddonConsolePageDefinitionV1 $definition):void {}
    public function publicPage(Api\AddonPublicPageDefinitionV1 $definition):void {$this->pages[$definition->key()]=$definition;}
    public function consoleMenu(Api\AddonConsoleMenuDefinitionV1 $definition):void {}
    public function publicMenu(Api\AddonPublicMenuDefinitionV1 $definition):void {}
    public function assetBundle(Api\AddonAssetBundleDefinitionV1 $definition):void {$this->assets[$definition->key()]=$definition;}
    public function aiTask(Api\AddonAiTaskDefinitionV1 $definition):void {}
};
EduGames\Addon\Oj_Platform_Registration::manifest($builder);
$checks=0;
function asset_check(bool $condition,string $message):void { global $checks;$checks++;if(!$condition)throw new RuntimeException($message); }
foreach($builder->assets as $bundle) {
    asset_check(count($bundle->scripts())<=8&&count($bundle->styles())<=8,'Addon API per-bundle asset limit '.$bundle->key());
    foreach(array_merge($bundle->styles(),$bundle->scripts()) as $file)asset_check(is_file(EDU_GAMES_PATH.$file),'Missing asset '.$file);
    if(in_array('assets/rankings/pager.js',$bundle->scripts(),true)) {
        asset_check($bundle->scripts()[0]==='assets/rankings/pager.js','Pager must load before ranking consumers');
        asset_check(in_array('assets/rankings/pager.css',$bundle->styles(),true),'Pager style required');
    }
}
foreach(EduGames\Learning\Catalog::PROVIDERS as $game=>$provider) {
    $page=$builder->pages['addon.edu_games.games.'.$game];$scripts=array();$styles=array();
    foreach($page->assetBundles() as $id){$scripts=array_merge($scripts,$builder->assets[$id]->scripts());$styles=array_merge($styles,$builder->assets[$id]->styles());}
    asset_check(!in_array('assets/learning/view.js',$scripts,true),'No supplementary question runtime on game '.$game);
    asset_check(!in_array('assets/learning/style.css',$styles,true),'No supplementary question style on game '.$game);
    if($game==='data-structure-lab')asset_check(in_array('assets/warehouse/view.js',$scripts,true),'Board script missing');
    if(isset(EduGames\Boards\Catalog::GAMES[$game])){
        $views=array_search('assets/strategy/board-views.js',$scripts,true);$runtime=array_search('assets/strategy/view.js',$scripts,true);
        asset_check($views!==false&&$runtime!==false&&$views<$runtime,'Strategy renderers load before runtime '.$game);
    }
}
$ranking=$builder->assets['addon.edu_games.ranking_assets']->scripts();
asset_check($ranking[0]==='assets/rankings/pager.js','Ranking pager dependency');
asset_check(in_array('assets/learning/view.js',$ranking,true)&&in_array('assets/warehouse/view.js',$ranking,true),'Ranking runtime');
asset_check(array_search('assets/strategy/board-views.js',$ranking,true)<array_search('assets/strategy/view.js',$ranking,true),'Ranking strategy dependency order');
asset_check(count($builder->pages)===15,'Existing game/hub/rank routes retained');
foreach($builder->pages as $key=>$page){
    $game=substr($key,strlen('addon.edu_games.games.'));
    $needsPlay=isset(EduGames\Play\Catalog::GAMES[$game])||$key==='addon.edu_games.ranking';
    asset_check(in_array('addon.edu_games.play_assets',$page->assetBundles(),true)===$needsPlay,'Shared runtime only on play/ranking pages '.$key);
    if(!$needsPlay)continue;
    $scripts=array();foreach($page->assetBundles() as $id)$scripts=array_merge($scripts,$builder->assets[$id]->scripts());
    asset_check(count($scripts)===count(array_unique($scripts)),'No duplicate scripts '.$key);
    $chart=array_search('assets/vendor/chartjs/chart.umd.min.js',$scripts,true);
    asset_check($chart!==false&&$chart<array_search('assets/play/missing.js',$scripts,true),'Chart.js before play views '.$key);
    asset_check(array_slice($scripts,-6)===array('assets/play/missing.js','assets/play/rescue-scenes.js','assets/play/rescue.js','assets/play/hands.js','assets/play/core.js','assets/shell/shell.js'),'Play dependencies and shared shell '.$key);
}

// Run the platform's real registration gateway and full manifest validator.
// Only the host contribution inventory and namespace persistence are doubles.
use CodingPanda\OjPlatform\Kernel\Addon as Kernel;
$errors=new class implements CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    public array $items=array();
    public function report(Throwable $error):void {$this->items[]=$error->getMessage();}
};
$gateway=new Kernel\AddonRegistrationGateway('1.0.0',$errors);
EduGames\Addon\Oj_Platform_Registration::build($gateway);
asset_check($errors->items===array(),'No registration exception: '.implode('; ',$errors->items));
$core=new class implements Kernel\CoreContributionSnapshotProvider {
    public function snapshot():Kernel\CoreContributionSnapshot {
        return new Kernel\CoreContributionSnapshot(array(),array(),array(),array('learning_support'),array('learning_support'),array(),array(),array());
    }
};
$ledger=new class implements Kernel\AddonNamespaceLedger {
    private array $owners=array();
    public function ownerOf(string $namespace):string {return $this->owners[$namespace]??'';}
    public function bind(string $namespace,string $addonId):void {$this->owners[$namespace]=$addonId;}
};
$entries=(new Kernel\AddonManifestValidator('2.0.0-dev.30','1.0.0',$core,$ledger))->validate($gateway->close());
asset_check(count($entries)===1&&$entries[0]->isAccepted(),'Manifest accepted: '.($entries[0]->code()??'no entry'));
asset_check(count($entries[0]->manifest()->publicPages())===15,'Validator preserves all public pages');
asset_check(count($entries[0]->manifest()->consolePages())===3,'Validator preserves management/report pages');
asset_check(count($entries[0]->manifest()->consoleMenus())===1,'One console menu (D-010)');
asset_check(array_map(fn($item)=>$item->route(),$entries[0]->manifest()->consoleLocalNav())===array('addon.edu_games.manage','addon.edu_games.report','addon.edu_games.ai_model_lab_report'),'Console pages reached through local navigation');

function wp_register_script($handle,$url,$dependencies,$version,$footer):void {
    $GLOBALS['addon_test_scripts'][$handle]=array('url'=>$url,'dependencies'=>$dependencies);
}
function wp_scripts():object {
    return new class {
        public function do_items(array $handles):void {$GLOBALS['addon_test_printed']=$handles;}
    };
}
$renderer=new Kernel\WordPressAddonAssetRenderer();
foreach($builder->pages as $page){
    $GLOBALS['addon_test_scripts']=array();$GLOBALS['addon_test_printed']=array();$expected=array();
    foreach($page->assetBundles() as $id)foreach($builder->assets[$id]->scripts() as $path)$expected[]=plugins_url($path,EDU_GAMES_FILE);
    $renderer->renderScripts(EDU_GAMES_FILE,array_values($builder->assets),$page->assetBundles());
    $actual=array_map(fn($id)=>$GLOBALS['addon_test_scripts'][$id]['url'],$GLOBALS['addon_test_printed']);
    asset_check($actual===$expected,'Platform asset renderer preserves bundle and script order '.$page->key());
}

// Exercise public pages and their shared shortcode/block renderers without a live site.
$GLOBALS['wpdb']=new Fake_DB();
function esc_attr($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_html($value){return esc_attr($value);}
function esc_url_raw($value){return $value;}
function esc_html_e($value,$domain=''){echo esc_html($value);}
function esc_attr_e($value,$domain=''){echo esc_attr($value);}
function esc_attr__($value,$domain=''){return esc_attr($value);}
function esc_url($value){return esc_attr($value);}
function rest_url($path){return '/'.$path;}
function wp_create_nonce($action){return 'test-nonce';}
function wp_enqueue_style($handle,...$args){$GLOBALS['render_styles'][]=$handle;}
function wp_enqueue_script($handle,...$args){$GLOBALS['render_scripts'][]=$handle;}
function wp_get_current_user(){return (object)array('display_name'=>'Test student');}
function get_block_wrapper_attributes($args){return 'class="'.esc_attr($args['class']).'"';}
$renderers=array(
    'outlier-detection'=>'Outlier_Detection', 'missing-values'=>'Missing_Values',
    'data-visualization'=>'Data_Visualization', 'data-extraction'=>'Data_Extraction',
    'data-structure-lab'=>'Data_Structure_Lab', 'ai-classifier-lab'=>'AI_Classifier_Lab',
    'ai-model-lab'=>'AI_Model_Lab', 'code-typing'=>'Code_Typing',
    'device-matching'=>'Device_Matching', 'keyword-rain'=>'Keyword_Rain',
);
foreach($renderers as $game=>$name){
    foreach(array('page','shortcode','block') as $surface){
        $GLOBALS['render_scripts']=array();$GLOBALS['render_styles']=array();
        if($surface==='page'){
            $page=$builder->pages['addon.edu_games.games.'.$game];
            $request=new Api\AddonPageRequestV1($page->key(),7,array(),array(),'ko_KR','test-render');
            $response=$page->handler()->handle($request);
            asset_check($response->supportsDarkMode(),'Game page follows screen mode '.$game);
            $html=$response->body();
        }else{
            $class='EduGames\\Blocks\\'.$name.'_Renderer';
            $html=$class::render(array(),$surface==='block');
        }
        $label=$game.' / '.$surface;
        asset_check(str_contains($html,isset(EduGames\Play\Catalog::GAMES[$game])?'data-play':'data-'.$game),'Main game retained '.$label);
        asset_check(!str_contains($html,'data-learning')&&!str_contains($html,'edu-learning-supplement'),'No appended questions '.$label);
        asset_check(!in_array('edu-games-learning',$GLOBALS['render_scripts'],true)&&!in_array('edu-games-learning',$GLOBALS['render_styles'],true),'No supplementary assets enqueued '.$label);
        asset_check(in_array('edu-games-rank-pager',$GLOBALS['render_scripts'],true),'Ranking dependency retained '.$label);
    }
}
asset_check(str_contains(EduGames\Blocks\Learning_Renderer::report(),'data-learning'),'Existing concept report retained');
asset_check(str_contains(EduGames\Blocks\Learning_Renderer::render_game('missing-values','Concept practice'),'data-learning'),'Existing question renderer retained independently');
foreach(array('addon.edu_games.games','addon.edu_games.ranking') as $key){
    $page=$builder->pages[$key];
    $request=new Api\AddonPageRequestV1($key,7,array(),array(),'ko_KR','test-scheme');
    asset_check($page->handler()->handle($request)->supportsDarkMode(),'Public page follows screen mode '.$key);
}

echo "PASS: $checks addon checks, including registration and 30 game page/shortcode/block renders; no live WordPress site.\n";
