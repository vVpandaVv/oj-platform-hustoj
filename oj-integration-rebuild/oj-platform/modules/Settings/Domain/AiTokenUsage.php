<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

final class AiTokenUsage
{
    /** @return array<string, int|string|null> */
    public static function parse(string $provider, array $response): array
    {
        $usage = match ($provider) {
            'ollama' => $response,
            'gemini' => is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [],
            default => is_array($response['usage'] ?? null) ? $response['usage'] : [],
        };
        [$inputKey, $outputKey, $totalKey] = match ($provider) {
            'ollama' => ['prompt_eval_count', 'eval_count', ''],
            'gemini' => ['promptTokenCount', 'candidatesTokenCount', 'totalTokenCount'],
            default => ['prompt_tokens', 'completion_tokens', 'total_tokens'],
        };
        $input = self::count($usage[$inputKey] ?? null);
        $output = self::count($usage[$outputKey] ?? null);
        $total = self::count($usage[$totalKey] ?? null);
        $totalSource = $total === null ? 'unavailable' : 'provider';
        // Gemini's total can include thought/tool tokens absent from candidate output.
        if ($total === null && $provider !== 'gemini' && $input !== null && $output !== null) {
            $total = $input + $output;
            $totalSource = 'sum';
        }
        $cached = self::count(match ($provider) {
            'ollama' => $usage['prompt_eval_cached_count'] ?? null,
            'gemini' => $usage['cachedContentTokenCount'] ?? null,
            default => $usage['prompt_tokens_details']['cached_tokens'] ?? null,
        });
        $reasoning = self::count($provider === 'gemini'
            ? ($usage['thoughtsTokenCount'] ?? null)
            : ($usage['completion_tokens_details']['reasoning_tokens'] ?? null));
        return [
            'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $total,
            'cached_input_tokens' => $cached, 'reasoning_tokens' => $reasoning,
            'total_source' => $totalSource,
            'measurement' => $input !== null && $output !== null && $total !== null ? 'complete'
                : (count(array_filter([$input, $output, $total, $cached, $reasoning], static fn ($v): bool => $v !== null)) > 0 ? 'partial' : 'unavailable'),
        ];
    }

    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 1000000000000 ? $value : null;
    }
}
