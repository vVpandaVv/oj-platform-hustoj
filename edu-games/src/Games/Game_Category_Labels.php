<?php
declare(strict_types=1);

namespace EduGames\Games;

/**
 * Single source of truth for the game category → display label mapping,
 * shared by every hub-style listing (block, addon PublicUI page, ...).
 */
final class Game_Category_Labels {
    /**
     * @return array<string, string>
     */
    private static function labels(): array {
        return array(
            'ai' => __('인공지능', 'edu-games'),
            'algorithms' => __('알고리즘', 'edu-games'),
            'data-basics' => __('데이터 기초', 'edu-games'),
            'data-analysis' => __('데이터 분석', 'edu-games'),
            'coding-skills' => __('코딩 스킬', 'edu-games'),
            'hardware' => __('컴퓨팅 장치', 'edu-games'),
        );
    }

    public static function label(string $category): string {
        return self::labels()[$category] ?? $category;
    }
}
