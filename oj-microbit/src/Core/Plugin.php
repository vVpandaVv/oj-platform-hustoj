<?php
declare(strict_types=1);
namespace OjMicrobit\Core;

use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use OjMicrobit\Addon\Registration;
use OjMicrobit\Application\PracticeService;
use OjMicrobit\Application\LessonService;
use OjMicrobit\Storage\Installer;
use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Storage\WpdbLessonRepository;
use OjMicrobit\Support\HttpRunner;
use OjMicrobit\Support\LearnerCapabilities;
use OjMicrobit\Support\PlatformAccess;
use OjMicrobit\Support\Privacy;
use OjMicrobit\Support\Settings;

final class Plugin
{
    private static ?AddonRuntimeContextV1 $runtime = null;
    public static function boot(): void
    {
        Registration::register(); Settings::register(); LearnerCapabilities::register(); Privacy::register();
        add_action('plugins_loaded', [Installer::class, 'maybeInstall'], 12);
        add_action('init', [self::class, 'schedule']);
        add_action('oj_microbit_cleanup', [self::class, 'cleanup']);
        add_action('oj_microbit_cleanup_continue', [self::class, 'cleanup']);
    }
    public static function connect(AddonRuntimeContextV1 $runtime): void { self::$runtime = $runtime; }
    public static function runtime(): ?AddonRuntimeContextV1 { return self::$runtime; }
    public static function repository(): WpdbRepository { global $wpdb; return new WpdbRepository($wpdb); }
    public static function service(): PracticeService { return new PracticeService(self::repository(), new PlatformAccess(), new HttpRunner()); }
    public static function lessons(): LessonService
    { global $wpdb; $access = new PlatformAccess(); return new LessonService(new WpdbLessonRepository($wpdb), self::repository(), $access, $access, new HttpRunner()); }
    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) { wp_die('OJ Microbit은 사이트별로 활성화해 주세요.'); }
        if (PHP_VERSION_ID < 80300 || !defined('OJ_PLATFORM_VERSION') || version_compare((string) OJ_PLATFORM_VERSION, '2.5.9', '<')
            || !defined('OJ_PLATFORM_ADDON_API_VERSION') || version_compare((string) OJ_PLATFORM_ADDON_API_VERSION, '1.9.0', '<')
            || !interface_exists(AddonRuntimeContextV1::class)) { wp_die('PHP 8.3, OJ Platform 2.5.9 이상과 Addon API 1.9이 필요합니다.'); }
        if (!Installer::install()) { wp_die('마이크로비트 기록 테이블을 만들지 못했습니다. 데이터베이스 권한을 확인해 주세요.'); }
        self::schedule();
    }
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('oj_microbit_cleanup');
        wp_clear_scheduled_hook('oj_microbit_cleanup_continue');
        if (LearnerCapabilities::removeOwnedGrant()) { delete_option('oj_microbit_cap_cleanup_error'); }
        else { update_option('oj_microbit_cap_cleanup_error', gmdate('c'), false); }
    }
    public static function schedule(): void
    {
        if (!wp_next_scheduled('oj_microbit_cleanup')) { wp_schedule_event(time() + 3600, 'daily', 'oj_microbit_cleanup'); }
    }
    public static function cleanup(): void
    {
        try {
            $days = (int) Settings::get()['retentionDays'];
            $repository = self::repository();
            // Each callback has fixed row budgets. Even with retention disabled,
            // stale quota keys and old summary conversions still make progress.
            $more = $repository->purge($days);
            $more = $repository->backfillAttemptSummaries() || $more;
            $more = Privacy::resumePendingErasure() || $more;
            if ($more) { self::continueCleanup(); }
            delete_option('oj_microbit_cleanup_error');
        } catch (\Throwable) {
            update_option('oj_microbit_cleanup_error', gmdate('c'), false);
            try { self::continueCleanup(); } catch (\Throwable) { /* The error remains visible in site health. */ }
        }
    }
    private static function continueCleanup(): void
    {
        if (!wp_next_scheduled('oj_microbit_cleanup_continue')
            && wp_schedule_single_event(time() + 60, 'oj_microbit_cleanup_continue') !== true) {
            throw new \RuntimeException('Microbit cleanup scheduling failed.');
        }
    }
}
