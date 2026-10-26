<?php
declare(strict_types=1);
namespace OjPrimm\Core;

use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use OjPrimm\Application\PrimmService;
use OjPrimm\Application\GenerationService;
use OjPrimm\Generation\PlatformLessonGenerator;
use OjPrimm\Storage\Installer;
use OjPrimm\Storage\WpdbRepository;
use OjPrimm\Storage\WpdbGenerationStore;
use OjPrimm\Support\LearnerCapabilities;
use OjPrimm\Support\PlatformGateway;
use OjPrimm\Support\PlatformLessonExecutor;
use OjPrimm\Support\Privacy;
use OjPrimm\Support\Settings;
use OjPrimm\Application\ActivityManagement;
use OjPrimm\Application\ClassSettingsService;
use OjPrimm\Application\PracticeService;
use OjPrimm\Storage\WpdbManagementStore;
use OjPrimm\Storage\WpdbPracticeStore;
use OjPrimm\Support\PlatformPracticeExecutor;
use OjPrimm\Application\ProblemPreparationAutomation;

final class Plugin
{
    private static ?AddonRuntimeContextV1 $runtime = null;
    private static ?PrimmService $service = null;
    private static ?GenerationService $generation = null;
    private static ?ActivityManagement $management = null;
    private static ?ClassSettingsService $curriculum = null;
    private static ?PracticeService $practice = null;

    public static function boot(): void
    {
        add_action('plugins_loaded', [Installer::class, 'maybeInstall'], 12);
        add_action('init', [self::class, 'scheduleCleanup']);
        add_action('oj_primm_retention_cleanup', [self::class, 'cleanup']);
        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action('oj_primm_generation_tick', [self::class, 'generationTick']);
        add_action('oj_primm_generation_recovery', [self::class, 'generationTick']);
        LearnerCapabilities::register();
        Privacy::register();
        Settings::register();
        ProblemPreparationAutomation::register(static fn () => new ProblemPreparationAutomation(self::curriculum(), self::generation(), new PlatformGateway(static fn () => self::$runtime)));
        if (class_exists(\OjPrimm\Addon\Registration::class)) { \OjPrimm\Addon\Registration::register(); }
    }

    public static function connect(AddonRuntimeContextV1 $runtime): void { self::$runtime = $runtime; }
    public static function runtime(): ?AddonRuntimeContextV1 { return self::$runtime; }
    public static function service(): PrimmService
    {
        if (self::$service === null) {
            global $wpdb;
            $records = self::repository();
            self::$service = new PrimmService($records, new PlatformGateway(static fn () => self::$runtime),
                new WpdbManagementStore($wpdb, $records), new WpdbPracticeStore($wpdb), new WpdbGenerationStore($wpdb));
        }
        return self::$service;
    }
    public static function repository(): WpdbRepository { global $wpdb; return new WpdbRepository($wpdb, true); }
    public static function management(): ActivityManagement
    {
        if (self::$management === null) {
            global $wpdb; $records = self::repository();
            self::$management = new ActivityManagement($records, new WpdbManagementStore($wpdb, $records), new PlatformGateway(static fn () => self::$runtime),
                new WpdbGenerationStore($wpdb));
        }
        return self::$management;
    }
    public static function curriculum(): ClassSettingsService
    {
        return self::$curriculum ??= new ClassSettingsService(new PlatformGateway(static fn () => self::$runtime));
    }
    public static function practice(): PracticeService
    {
        if (self::$practice === null) {
            global $wpdb;
            self::$practice = new PracticeService(self::repository(), new WpdbPracticeStore($wpdb), new PlatformGateway(static fn () => self::$runtime),
                new PlatformPracticeExecutor(static fn () => self::$runtime));
        }
        return self::$practice;
    }
    public static function generation(): GenerationService
    {
        if (self::$generation === null) {
            if (get_option('oj_primm_schema_version', '') !== '3') {
                throw new \DomainException('PRIMM 저장소 갱신이 완료되지 않았습니다. 관리자에서 플러그인 활성화와 데이터베이스 상태를 확인해 주세요.');
            }
            global $wpdb;
            self::$generation = new GenerationService(new WpdbGenerationStore($wpdb), self::repository(),
                new PlatformGateway(static fn () => self::$runtime), new PlatformLessonGenerator(static fn () => self::$runtime),
                new PlatformLessonExecutor(static fn () => self::$runtime), self::curriculum(),
                static fn (array $job, array $lesson, array $quality): array => self::management()->acceptGenerated($job, $lesson, $quality));
        }
        return self::$generation;
    }
    public static function cronSchedules(array $schedules): array
    {
        $schedules['oj_primm_minute'] = ['interval' => 60, 'display' => 'PRIMM 생성 작업 복구'];
        return $schedules;
    }
    public static function generationTick(): void
    {
        global $wpdb;
        $previousActor = get_current_user_id();
        try {
            $store = new WpdbGenerationStore($wpdb);
            foreach ($store->pending(3) as $pending) {
                // Each stored teacher request is reauthorized; this grants no new permission.
                wp_set_current_user((int) $pending['creator_user_id']);
                try { self::generation()->processForTeacher((int) $pending['id']); }
                catch (\DomainException) { $store->cancelPending((int) $pending['id'], '담당 교사의 권한·소속을 확인하지 못했습니다. 권한을 확인한 뒤 다시 시도해 주세요.'); }
                catch (\Throwable $error) { self::backgroundFailure($error); }
            }
        } catch (\Throwable $error) { self::backgroundFailure($error); }
        finally { wp_set_current_user($previousActor); }
    }

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) { wp_die('OJ PRIMM은 네트워크 전체 활성화를 지원하지 않습니다. 각 사이트에서 활성화해 주세요.'); }
        if (PHP_VERSION_ID < 80300 || ! defined('OJ_PLATFORM_VERSION') || version_compare((string) OJ_PLATFORM_VERSION, '2.4.3', '<')
            || ! defined('OJ_PLATFORM_ADDON_API_VERSION') || version_compare((string) OJ_PLATFORM_ADDON_API_VERSION, '1.7.0', '<')
            || ! interface_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRuntimeContextV1::class)) {
            wp_die('OJ PRIMM에는 PHP 8.3, OJ Platform 2.4.3 이상과 Addon API 1.7이 필요합니다.');
        }
        if (! Installer::install()) { wp_die('OJ PRIMM 학습 기록 테이블을 만들지 못했습니다. 데이터베이스 권한을 확인해 주세요.'); }
        self::scheduleCleanup();
    }
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('oj_primm_retention_cleanup');
        wp_clear_scheduled_hook('oj_primm_generation_tick');
        wp_clear_scheduled_hook('oj_primm_generation_recovery');
        if (! LearnerCapabilities::removeOwnedGrant()) { wp_die('PRIMM 기본 참여 권한을 정리하지 못했습니다. 데이터베이스 상태를 확인한 뒤 비활성화해 주세요.'); }
    }
    public static function scheduleCleanup(): void
    {
        if (! wp_next_scheduled('oj_primm_retention_cleanup')) { wp_schedule_event(time() + 3600, 'daily', 'oj_primm_retention_cleanup'); }
        if (! wp_next_scheduled('oj_primm_generation_recovery')) { wp_schedule_event(time() + 60, 'oj_primm_minute', 'oj_primm_generation_recovery'); }
    }
    public static function cleanup(): void
    {
        $days = Settings::retentionDays();
        if ($days > 0) { self::repository()->purgeBefore(gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)); }
    }
    private static function backgroundFailure(\Throwable $error): void
    {
        // Record a bounded safe code, never source, tickets, provider messages or learner text.
        update_option('oj_primm_last_background_error', ['code' => 'background_storage_or_runtime', 'type' => get_class($error), 'at' => gmdate('Y-m-d H:i:s')], false);
        do_action('oj_primm_background_failure', 'background_storage_or_runtime', gmdate('Y-m-d H:i:s'));
    }
}
