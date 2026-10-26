<?php
declare(strict_types=1);

namespace EduGames\Learning;

use EduGames\Games\Modules;

final class Catalog {
    public const VERSION = 'concept-2026-09-v1';
    public const PROVIDERS = array(
        'data-extraction' => Modules\Data_Extraction\Learning_Content::class,
        'missing-values' => Modules\Missing_Values\Learning_Content::class,
        'outlier-detection' => Modules\Outlier_Detection\Learning_Content::class,
        'data-visualization' => Modules\Data_Visualization\Learning_Content::class,
        'data-structure-lab' => Modules\Data_Structure_Lab\Learning_Content::class,
        'ai-classifier-lab' => Modules\AI_Classifier_Lab\Learning_Content::class,
        'ai-model-lab' => Modules\AI_Model_Lab\Learning_Content::class,
        'keyword-rain' => Modules\Keyword_Rain\Learning_Content::class,
        'code-typing' => Modules\Code_Typing\Learning_Content::class,
        'device-matching' => Modules\Device_Matching\Learning_Content::class,
    );

    public static function questions(string $game, string $level = 'all'): array {
        $provider = self::PROVIDERS[$game] ?? null;
        if (null === $provider) { return array(); }
        $questions = $provider::questions();
        return array_values(array_filter($questions, static fn (array $q): bool => 'all' === $level || $q['level'] === $level));
    }

    public static function find(string $game, string $id): ?array {
        foreach (self::questions($game) as $question) {
            if ($question['id'] === $id) { return $question; }
        }
        return null;
    }

    public static function grade(string $game,array $question,array $answer): array {
        $provider=self::PROVIDERS[$game];
        return method_exists($provider,'grade')?$provider::grade($question,$answer):Question::grade($question,$answer);
    }
}
