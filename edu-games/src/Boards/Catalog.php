<?php
declare(strict_types=1);
namespace EduGames\Boards;

final class Catalog {
    public const VERSION = 'strategy-2026-09-v2';
    public const GAMES = array(
        'data-extraction' => \EduGames\Games\Modules\Data_Extraction\Strategy_Board::class,
        'missing-values' => \EduGames\Games\Modules\Missing_Values\Strategy_Board::class,
        'outlier-detection' => \EduGames\Games\Modules\Outlier_Detection\Strategy_Board::class,
        'data-visualization' => \EduGames\Games\Modules\Data_Visualization\Strategy_Board::class,
        'ai-classifier-lab' => \EduGames\Games\Modules\AI_Classifier_Lab\Strategy_Board::class,
        'ai-model-lab' => \EduGames\Games\Modules\AI_Model_Lab\Strategy_Board::class,
    );
    public static function game(string $id): string {
        if (!isset(self::GAMES[$id])) { throw new \InvalidArgumentException('게임을 확인하세요.'); }
        return self::GAMES[$id];
    }
    public static function version(string $id): string {
        self::game($id);
        return $id==='outlier-detection'?\EduGames\Games\Modules\Outlier_Detection\Strategy_Board::VERSION:self::VERSION;
    }
}
