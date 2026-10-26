<?php
declare(strict_types=1);

namespace OjCodeArena\Integration;

use EduGames\Games\Contracts\Game_Module;

/**
 * The Code Arena card in the Edu Games hub. Only loaded by
 * Edu_Games::add_card(), after checking that Edu Games' interface exists.
 */
final class Edu_Games_Card implements Game_Module {
    public function id(): string {
        return Edu_Games::GAME_ID;
    }

    /** @return array<string, mixed> */
    public function definition(): array {
        return array(
            'id' => Edu_Games::GAME_ID,
            'title' => __('코드 아레나', 'oj-code-arena'),
            'category' => 'coding-skills',
            'description' => __('팀원과 한 판을 나눠 블록을 차지하고 코드를 정확히 입력해 판을 모두 푸는 실시간 팀 코딩 게임입니다. 선생님이 연 수업 게임이나 자유 대전에 들어가요.', 'oj-code-arena'),
            'status' => 'playable',
            'statusLabel' => __('팀 대전', 'oj-code-arena'),
            'block' => '',
            'shortcode' => '',
            // No scoring rules: Edu Games' score API rejects this game, so
            // nothing can be posted to its leaderboards under this id.
            'rules' => array('scoring' => array()),
            'external' => true,
            'url' => home_url(\OjCodeArena\Addon\Oj_Platform_Registration::PLAY_PATH),
        );
    }
}
