<?php
declare(strict_types=1);
define('ARCADE_TEST_LIBRARY',true);require __DIR__.'/arcade-fixture.php';
function add_action($hook,$callback,...$args){$GLOBALS['embed_actions'][$hook][]=$callback;}
function add_filter(...$args){}
function add_shortcode($name,$callback){$GLOBALS['embed_shortcodes'][$name]=$callback;}
function register_block_type($name,$settings=[]){$GLOBALS['embed_blocks'][$name]=$settings;}
EduGames\Core\Plugin::boot();$checks=0;
foreach($GLOBALS['embed_actions']['init'] as $fn)if($fn instanceof Closure)$fn();
foreach(['cipher_vault','dance_bot','cipher_team','oj_cipher_vault','oj_dance_bot','oj_cipher_team'] as $tag){$html=$GLOBALS['embed_shortcodes'][$tag]([],null,$tag);if(!str_contains($html,'data-edu-arcade')&&!str_contains($html,'data-binary-team'))throw new RuntimeException($tag);$checks++;}
foreach(['cipher-vault','dance-bot','cipher-team'] as $block){$html=$GLOBALS['embed_blocks']['edu-games/'.$block]['render_callback']([],'',new stdClass());if(!str_contains($html,'data-edu-arcade')&&!str_contains($html,'data-binary-team'))throw new RuntimeException($block);$checks++;}
$GLOBALS['options']['edu_games_closed_games']=['cipher-vault'];
if(!is_string(EduGames\Frontend\Game_Access_Guard::pre_do_shortcode_tag(false,'cipher_team',[],[]))||!is_string(EduGames\Frontend\Game_Access_Guard::pre_render_block(null,['blockName'=>'edu-games/cipher-team'])))throw new RuntimeException('closed team embeds');$checks+=2;
echo "$checks WordPress shortcode/block callback and visibility checks passed\n";
