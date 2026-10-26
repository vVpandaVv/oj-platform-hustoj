<?php
declare(strict_types=1);
namespace EduGames\Play;
final class Catalog {
    public const VERSION='hands-2026-09-v1';
    public const GAMES=array(
        'outlier-detection'=>\EduGames\Games\Modules\Outlier_Detection\Hands_Board::class,
        'missing-values'=>\EduGames\Games\Modules\Missing_Values\Hands_Board::class,
        'data-visualization'=>\EduGames\Games\Modules\Data_Visualization\Hands_Board::class,
        'data-extraction'=>\EduGames\Games\Modules\Data_Extraction\Hands_Board::class,
        'data-structure-lab'=>\EduGames\Games\Modules\Data_Structure_Lab\Hands_Board::class,
        'ai-classifier-lab'=>\EduGames\Games\Modules\AI_Classifier_Lab\Hands_Board::class,
        'ai-model-lab'=>\EduGames\Games\Modules\AI_Model_Lab\Hands_Board::class
    );
    public const TITLES=array('outlier-detection'=>'데이터 스캐너','missing-values'=>'끊어진 데이터 잇기','data-visualization'=>'그래프 출동 구조대','data-extraction'=>'조건 관문 출고전','data-structure-lab'=>'스택·큐 물류 레이스','ai-classifier-lab'=>'선 긋는 분류 방어전','ai-model-lab'=>'손글씨 로봇 코치');
    public static function game(string $game): string {
        if(!isset(self::GAMES[$game]))throw new \InvalidArgumentException('게임을 확인하세요.');
        return self::GAMES[$game];
    }
    public static function base_version(string $game): string {$class=self::game($game);return defined($class.'::CONTENT_VERSION')?constant($class.'::CONTENT_VERSION'):self::VERSION;}
    public static function version(string $game): string {return self::base_version($game).'-'.\EduGames\Support\Clock::today();}
}
