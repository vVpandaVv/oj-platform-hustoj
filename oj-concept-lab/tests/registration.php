<?php
declare(strict_types=1);
// The first registration runs without loading or defining Edu Games.
define('OJ_CONCEPT_LAB_PATH',dirname(__DIR__).'/');define('OJ_CONCEPT_LAB_FILE',OJ_CONCEPT_LAB_PATH.'oj-concept-lab.php');define('OJ_CONCEPT_LAB_VERSION','1.0.0');
require OJ_CONCEPT_LAB_PATH.'src/Core/Autoloader.php';OjConceptLab\Core\Autoloader::register();
function __($v,$domain=''){return $v;}
function get_option($key,$default=false){return $GLOBALS['test_options'][$key]??$default;}
function home_url($path=''){return $path;}
function plugins_url($path='',$file=''){return '/plugins/'.basename(dirname($file)).'/'.$path;}
function current_user_can($cap){return in_array($cap,$GLOBALS['test_caps']??[],true);}
function is_user_logged_in(){return true;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
$source=dirname(__DIR__,2).'/oj-integration-rebuild/oj-platform/src';
spl_autoload_register(static function($class)use($source){$prefix='CodingPanda\\OjPlatform\\';if(str_starts_with($class,$prefix)){$file=$source.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file;}});
use CodingPanda\OjPlatform\Kernel\Addon as K;
use OjConceptLab\Addon\Registration as L;
$checks=0;
function lab_check(bool $v,string $m):void{global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
function lab_validate(array $registrations):array{
    $errors=new class implements CodingPanda\OjPlatform\Kernel\Error\ErrorReporter{public array $items=[];public function report(Throwable $e):void{$this->items[]=$e->getMessage();}};
    $gateway=new K\AddonRegistrationGateway('1.0.0',$errors);foreach($registrations as $callback)$callback($gateway);lab_check(!$errors->items,'registration: '.implode(',',$errors->items));
    $core=new class implements K\CoreContributionSnapshotProvider{public function snapshot():K\CoreContributionSnapshot{return new K\CoreContributionSnapshot([],[],[],['learning_support'],['learning_support'],[],[],[]);}};
    $ledger=new class implements K\AddonNamespaceLedger{private array $owners=[];public function ownerOf(string $ns):string{return $this->owners[$ns]??'';}public function bind(string $ns,string $id):void{$this->owners[$ns]=$id;}};
    $entries=(new K\AddonManifestValidator('2.0.0-dev.30','1.0.0',$core,$ledger))->validate($gateway->close());foreach($entries as $e)lab_check($e->isAccepted(),'manifest accepted: '.($e->code()??''));return $entries;
}
$entries=lab_validate([[L::class,'build']]);$m=$entries[0]->manifest();lab_check(count($m->publicPages())===12,'index and 11 independent experience routes');lab_check(count($m->consolePages())===1&&count($m->consoleMenus())===1,'single teacher management entry');
foreach($m->assetBundles() as $bundle)foreach(array_merge($bundle->styles(),$bundle->scripts()) as $file)lab_check(is_file(OJ_CONCEPT_LAB_PATH.$file),'own asset '.$file);
$count=0;foreach(OjConceptLab\Activities\Catalog::ITEMS as $id=>$item)$count+=count(OjConceptLab\Activities\Catalog::data($id));lab_check($count===156,'self-contained data bank');lab_check(!defined('EDU_GAMES_PATH'),'no Edu Games boot or runtime dependency');
$GLOBALS['test_options']['oj_concept_lab_closed']=['missing-values'];lab_check(!isset(OjConceptLab\Activities\Catalog::all()['missing-values'])&&isset(OjConceptLab\Activities\Catalog::all(true)['missing-values']),'public catalog hides closed activities');
lab_check(!L::can_learn()&&!L::can_manage(),'unprivileged shortcode denied');$GLOBALS['test_caps']=['oj_addon_'.L::LEARN];
$key=CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress(L::LEARN);$GLOBALS['test_caps']=[$key];lab_check(L::can_learn()&&!L::can_manage(),'learner access without management');
define('EDU_GAMES_PATH',dirname(__DIR__,2).'/edu-games/');define('EDU_GAMES_FILE',EDU_GAMES_PATH.'edu-games.php');define('EDU_GAMES_VERSION',(require EDU_GAMES_PATH.'plugin-package.php')['version']);require EDU_GAMES_PATH.'src/Core/Autoloader.php';EduGames\Core\Autoloader::register();
function edu_games_registry(){static $r;return $r??=new EduGames\Games\Game_Module_Registry();}
foreach([[[L::class,'build'],[EduGames\Addon\Oj_Platform_Registration::class,'build']],[[EduGames\Addon\Oj_Platform_Registration::class,'build'],[L::class,'build']]] as $order)lab_check(count(lab_validate($order))===2,'both installation orders accepted');
echo "$checks independent concept plugin registration and install-order checks passed\n";
