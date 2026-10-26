<?php
declare(strict_types=1);
namespace OjPrimm\Support;

final class GenerationPolicy
{
    public const OPTION = 'oj_primm_generation_policy';
    public static function defaults(): array
    {
        return ['teacher_active_limit' => 3, 'site_active_limit' => 30, 'teacher_daily_ai_limit' => 40, 'site_daily_ai_limit' => 400,
            'job_ai_limit' => 6, 'transient_retry_limit' => 4, 'paused' => false];
    }
    public static function sanitize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : []; $value = self::defaults();
        foreach (['teacher_active_limit' => [1, 20], 'site_active_limit' => [1, 500], 'teacher_daily_ai_limit' => [1, 1000],
            'site_daily_ai_limit' => [1, 10000], 'job_ai_limit' => [1, 20], 'transient_retry_limit' => [0, 8]] as $key => [$min, $max]) {
            $value[$key] = max($min, min($max, is_scalar($raw[$key] ?? null) ? (int) $raw[$key] : $value[$key]));
        }
        $value['paused'] = in_array($raw['paused'] ?? false, [true, 1, '1'], true);
        return $value;
    }
    public static function get(): array { return self::sanitize(function_exists('get_option') ? get_option(self::OPTION, []) : []); }
}
