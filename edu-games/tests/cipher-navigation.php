<?php
declare(strict_types=1);
define('ARCADE_TEST_LIBRARY',true);require __DIR__.'/arcade-fixture.php';
$GLOBALS['wpdb']=new Fake_DB();
use EduGames\Blocks\{Arcade_Renderer,Character_Code_Renderer,Cipher_Team_Renderer};
$checks=0;
function verify_cipher(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
foreach([
    ['solo',Arcade_Renderer::vault(),'data-edu-arcade','/oj-addons/edu-games/games/cipher-vault','개인 금고'],
    ['team',Cipher_Team_Renderer::render(),'data-binary-team','/oj-addons/edu-games/cipher-team','금고 팀전'],
    ['practice',Character_Code_Renderer::render(),'data-character-code','/oj-addons/edu-games/games/character-code','코드 연습'],
] as [$mode,$html,$selector,$path,$label]){
    verify_cipher(str_contains($html,$selector),$mode.' renders its own state owner');
    verify_cipher((bool)preg_match('~<a href="[^"]*'.preg_quote($path,'~').'" aria-current="page">'.$label.'</a>~',$html),$mode.' active tab points to correct route');
    verify_cipher(substr_count($html,'aria-current="page"')===1,$mode.' has one active mode');
    verify_cipher(str_contains($html,'>개인 금고</a>')&&str_contains($html,'>금고 팀전</a>')&&str_contains($html,'>코드 연습</a>'),$mode.' exposes distinct activities');
}
verify_cipher(str_contains(Arcade_Renderer::vault(),'암호 금고 · 개인전'),'solo title is explicit');
verify_cipher(str_contains(Cipher_Team_Renderer::render(),'&quot;gameId&quot;:&quot;cipher-vault&quot;'),'team uses the vault rules, not character practice');
verify_cipher(str_contains(Character_Code_Renderer::render(),'<h2>문자 코드 연습</h2>'),'legacy training is not mislabeled as main game');
echo $checks." distinct cipher solo/team/practice navigation checks passed\n";
