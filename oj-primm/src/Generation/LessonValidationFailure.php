<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

/** Quarantined provider draft, never an executable or public lesson. */
final class LessonValidationFailure extends GenerationFailure
{
    public readonly array $candidate;

    public function __construct(string $message, array|string $output)
    {
        parent::__construct($message, 'lesson_contract_invalid', false);
        $this->candidate = self::safeCandidate($output);
    }

    /** Retain only bounded lesson fields; unknown/private fields and server IDs are discarded. */
    public static function safeCandidate(array|string $output): array
    {
        try {
            if (strlen(json_encode($output, JSON_THROW_ON_ERROR)) > 262144) { return []; }
            if (is_array($output) && count($output) === 1) {
                foreach (['content', 'text'] as $wrapper) {
                    if (is_string($output[$wrapper] ?? null)) { $output = $output[$wrapper]; break; }
                }
            }
            if (is_string($output)) {
                $output = trim($output);
                if (preg_match('/\A```(?:json)?[ \t]*\r?\n([\s\S]*)\r?\n```\s*\z/D', $output, $match) === 1) { $output = $match[1]; }
                $output = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            }
            $test = ['input' => 4096, 'expected_output' => 4096];
            $stage = ['key' => 32, 'label' => 80, 'prompt' => 3000, 'questions' => [5, 1200],
                'task' => 3000, 'starter_code' => 16384, 'tests' => [8, $test], 'hints' => [4, 1000]];
            $shape = ['schema_version' => true, 'language' => 32, 'title' => 191, 'objectives' => [6, 500],
                'demo' => ['code' => 16384, 'input' => 4096, 'expected_output' => 4096, 'explanation' => 3000],
                'stages' => [5, $stage], 'support' => ['kind' => 32, 'ordering_blocks' => [60, 16384], 'template' => 16384,
                    'blanks' => [30, ['id' => 64, 'label' => 160, 'kind' => 16, 'max_bytes' => true, 'answer' => 8192]]],
                'verification' => ['status' => 32, 'note' => 2000]];
            $safe = self::project($output, $shape);
            return is_array($safe) && strlen(json_encode($safe, JSON_THROW_ON_ERROR)) <= 262144 ? $safe : [];
        } catch (\Throwable) { return []; }
    }

    private static function project(mixed $value, array|int|bool $shape): mixed
    {
        if ($shape === true) { return is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 64) ? $value : null; }
        if (is_int($shape)) { return is_string($value) && strlen($value) <= $shape && ! str_contains($value, "\0") && preg_match('//u', $value) === 1 ? $value : null; }
        if (! is_array($value)) { return null; }
        if (array_is_list($shape)) {
            if (! array_is_list($value)) { return null; }
            return array_map(static fn ($item) => self::project($item, $shape[1]), array_slice($value, 0, $shape[0]));
        }
        if (array_is_list($value)) { return null; }
        $safe = [];
        foreach ($shape as $field => $child) { if (array_key_exists($field, $value)) { $safe[$field] = self::project($value[$field], $child); } }
        return $safe;
    }
}
