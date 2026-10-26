<?php
declare(strict_types=1);
namespace OjPrimm\Application;

final class ProblemPreparationAutomation
{
    public const LOG_OPTION = 'oj_primm_auto_prepare_log';
    public function __construct(private readonly ClassSettingsService $settings, private readonly GenerationService $generation, private readonly PlatformAccess $platform) {}
    public static function register(\Closure $factory): void
    {
        add_action('oj_platform_problem_saved', static function (mixed $event) use ($factory): void {
            if (! is_array($event)) { return; }
            try { $automation = $factory(); if ($automation instanceof self) { $automation->handle($event); } }
            catch (\Throwable) { self::log('auto_prepare_unavailable'); }
        });
        add_action('oj_platform_class_problems_saved', static function (mixed $event) use ($factory): void {
            if (! is_array($event)) { return; }
            try { $automation = $factory(); if ($automation instanceof self) { $automation->handleAssignment($event); } }
            catch (\Throwable) { self::log('auto_prepare_assignment_unavailable'); }
        });
    }
    public function handle(array $event): array
    {
        if (! is_int($event['problem_id'] ?? null) || $event['problem_id'] < 1 || ! is_int($event['actor_user_id'] ?? null)
            || $event['actor_user_id'] !== $this->platform->actorId()) { return []; }
        $this->platform->assertRole('teach'); $jobs = [];
        foreach ($this->settings->automaticContexts() as $context) {
            try {
                $jobs[] = $this->generation->start(['context_type' => $context['type'], 'context_id' => $context['id'],
                    'target_problem_id' => $event['problem_id'], 'request_key' => 'problem-save:' . $event['problem_id'] . ':' . hash('sha256', (string) ($event['changed_at'] ?? 'current'))]);
                self::log('auto_prepare_enqueued');
            } catch (\Throwable $error) {
                self::log($error instanceof \OjPrimm\Generation\GenerationFailure ? $error->failureCode : 'auto_prepare_context_unavailable');
                if (function_exists('do_action')) { do_action('oj_primm_auto_prepare_skipped', $this->platform->actorId(), $event['problem_id'], $context['type'], $context['id']); }
            }
        }
        return $jobs;
    }
    /** A class assignment retries only that class; it never broadens problem visibility. */
    public function handleAssignment(array $event): array
    {
        if (! is_int($event['class_id'] ?? null) || $event['class_id'] < 1 || ! is_int($event['actor_user_id'] ?? null)
            || $event['actor_user_id'] !== $this->platform->actorId() || ! is_array($event['problem_ids'] ?? null)
            || ! array_is_list($event['problem_ids']) || count($event['problem_ids']) > 1000) { return []; }
        foreach ($event['problem_ids'] as $problemId) { if (! is_int($problemId) || $problemId < 1) { return []; } }
        $this->platform->assertRole('teach'); $contextId = 'class:' . $event['class_id'];
        $settings = $this->settings->get('class', $contextId);
        if (! $settings['auto_prepare']) { return []; }
        $jobs = [];
        foreach (array_unique($event['problem_ids']) as $problemId) {
            try {
                $jobs[] = $this->generation->start(['context_type' => 'class', 'context_id' => $contextId, 'target_problem_id' => $problemId,
                    'request_key' => 'class-save:' . $event['class_id'] . ':' . $problemId . ':' . hash('sha256', (string) ($event['changed_at'] ?? 'current'))]);
                self::log('auto_prepare_enqueued');
            } catch (\Throwable $error) { self::log($error instanceof \OjPrimm\Generation\GenerationFailure ? $error->failureCode : 'auto_prepare_context_unavailable'); }
        }
        return $jobs;
    }
    private static function log(string $code): void
    {
        if (! function_exists('get_option') || ! function_exists('update_option')) { return; }
        $code = preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $code) === 1 ? $code : 'auto_prepare_failed';
        $log = get_option(self::LOG_OPTION, []); $log = is_array($log) ? $log : [];
        $log['last_code'] = $code; $log['last_at'] = gmdate('Y-m-d H:i:s');
        $log['counts'][$code] = (int) ($log['counts'][$code] ?? 0) + 1;
        update_option(self::LOG_OPTION, $log, false);
    }
}
