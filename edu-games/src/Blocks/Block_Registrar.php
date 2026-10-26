<?php
declare(strict_types=1);

namespace EduGames\Blocks;

final class Block_Registrar {
    public static function register(): void {
        $blocks = array(
            'ai-classifier-lab',
            'ai-model-lab',
            'binary-arcade',
            'character-code',
            'code-typing',
            'data-extraction',
            'data-visualization',
            'data-structure-lab',
            'device-matching',
            'game-hub',
            'game-leaderboard',
            'group-leaderboard',
            'image-data',
            'keyword-rain',
            'missing-values',
            'outlier-detection',
            'player-stats',
        );

        foreach ($blocks as $block) {
            $path = EDU_GAMES_PATH . 'blocks/' . $block;

            if (is_dir($path)) {
                register_block_type($path);
            }
        }
    }
}
