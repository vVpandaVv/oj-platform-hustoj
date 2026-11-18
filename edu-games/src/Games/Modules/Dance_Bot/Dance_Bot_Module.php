<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Dance_Bot;
final class Dance_Bot_Module implements \EduGames\Games\Contracts\Game_Module {
    public const ID='dance-bot';
    public function id(): string {return self::ID;}
    public function definition(): array {return ['id'=>self::ID,'title'=>'댄스봇','category'=>'algorithms','description'=>'명령과 반복으로 로봇의 안무를 재현합니다. 정확한 실행·명령 절약·속도로 기록에 도전합니다.','status'=>'playable','statusLabel'=>'플레이 가능','shortcode'=>'dance_bot','block'=>'edu-games/dance-bot','rules'=>['ruleset'=>\EduGames\Arcade\Dance_Rules::VERSION,'moves'=>\EduGames\Arcade\Dance_Rules::MOVES]];}
}
