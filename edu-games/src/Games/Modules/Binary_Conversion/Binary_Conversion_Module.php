<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Binary_Conversion;

use EduGames\Games\Contracts\Game_Module;

final class Binary_Conversion_Module implements Game_Module {
    public const ID = 'binary-conversion';
    public const SHORTCODE = 'binary_arcade';
    public const BLOCK = 'edu-games/binary-arcade';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('이진수 변환 아케이드', 'edu-games'),
            'category' => 'data-basics',
            'description' => __('십진수와 이진수를 빠르게 변환하며 비트 자리값을 익히는 실전 게임입니다.', 'edu-games'),
            'status' => 'playable',
            'statusLabel' => __('플레이 가능', 'edu-games'),
            'block' => self::BLOCK,
            'shortcode' => self::SHORTCODE,
            'rules' => self::rules(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array {
        return array(
            'bits' => array(
                'minimum' => 4,
                'maximum' => 8,
                'default' => 8,
            ),
            'modes' => array(
                'dec-to-bin' => array(
                    'label' => __('10진수 → 2진수', 'edu-games'),
                    'shortLabel' => '10→2',
                ),
                'bin-to-dec' => array(
                    'label' => __('2진수 → 10진수', 'edu-games'),
                    'shortLabel' => '2→10',
                ),
            ),
            'levels' => array(
                array('lines' => 6, 'spawnMs' => 6200, 'binChance' => 0.18),
                array('lines' => 7, 'spawnMs' => 5600, 'binChance' => 0.22),
                array('lines' => 8, 'spawnMs' => 5000, 'binChance' => 0.26),
                array('lines' => 9, 'spawnMs' => 4400, 'binChance' => 0.30),
                array('lines' => 10, 'spawnMs' => 3900, 'binChance' => 0.35),
                array('lines' => 11, 'spawnMs' => 3400, 'binChance' => 0.40),
                array('lines' => 12, 'spawnMs' => 3000, 'binChance' => 0.45),
                array('lines' => 13, 'spawnMs' => 2640, 'binChance' => 0.50),
            ),
            'scoring' => array(
                'basePerBit' => 10,
                'levelBonus' => 10,
                'streakStep' => 3,
                'streakBonusPerStep' => 5,
                'minimumProblemScore' => 40,
            ),
        );
    }
}
