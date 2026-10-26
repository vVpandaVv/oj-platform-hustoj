<?php
declare(strict_types=1);
// Frozen rule selection for the archived 1.1.0 gameplay regression tests.
final class Legacy_Play_Catalog {
    public const GAMES=array(
        'outlier-detection'=>EduGames\Games\Modules\Outlier_Detection\Play_Board::class,
        'missing-values'=>EduGames\Games\Modules\Missing_Values\Play_Board::class,
        'data-visualization'=>EduGames\Games\Modules\Data_Visualization\Play_Board::class,
        'data-extraction'=>EduGames\Games\Modules\Data_Extraction\Play_Board::class,
        'data-structure-lab'=>EduGames\Games\Modules\Data_Structure_Lab\Play_Board::class,
        'ai-classifier-lab'=>EduGames\Games\Modules\AI_Classifier_Lab\Play_Board::class,
        'ai-model-lab'=>EduGames\Games\Modules\AI_Model_Lab\Play_Board::class
    );
    public static function game(string $game): string {return self::GAMES[$game];}
}
