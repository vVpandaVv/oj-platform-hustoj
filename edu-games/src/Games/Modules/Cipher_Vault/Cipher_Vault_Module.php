<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Cipher_Vault;
final class Cipher_Vault_Module implements \EduGames\Games\Contracts\Game_Module {
    public const ID='cipher-vault';
    public function id(): string {return self::ID;}
    public function definition(): array {return ['id'=>self::ID,'title'=>'암호 금고','category'=>'data-basics','description'=>'24개 금고를 코드표로 열고 시간 보너스에 도전합니다. 팀전에서는 동전과 아이템으로 협력·경쟁합니다.','status'=>'playable','statusLabel'=>'플레이 가능','shortcode'=>'cipher_vault','block'=>'edu-games/cipher-vault','rules'=>['count'=>24,'ruleset'=>\EduGames\Arcade\Vault_Rules::VERSION,'codeTable'=>\EduGames\Arcade\Vault_Rules::codebook()]];}
}
