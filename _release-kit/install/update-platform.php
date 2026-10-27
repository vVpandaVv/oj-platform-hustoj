<?php
/**
 * Check or upgrade an existing OJ Platform without changing site settings.
 * Usage: wp eval-file update-platform.php check|apply --use-include
 */
declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator;
use CodingPanda\OjPlatform\Kernel\Setup\SetupMode;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRewriteManager;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobScheduler;

if (! defined('WP_CLI') || WP_CLI !== true || ! class_exists('WP_CLI')) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "OJ_PLATFORM_UPDATE_REQUIRES_WP_CLI\n");
    } else {
        http_response_code(404);
    }
    exit(1);
}

// Database and framework exceptions may contain private configuration values.
ini_set('display_errors', '0');
$stage = 'input';

try {
    $inputArguments = isset($args) && is_array($args) ? $args : [];
    if (
        count($inputArguments) !== 1
        || ! is_string($inputArguments[0])
        || ! in_array($inputArguments[0], ['check', 'apply'], true)
    ) {
        throw new RuntimeException('Choose check or apply.');
    }
    $operation = $inputArguments[0];

    $stage = 'platform';
    if (
        ! defined('ABSPATH')
        || ! defined('OJ_PLATFORM_FILE')
        || ! defined('OJ_PLATFORM_VERSION')
        || ! defined('OJ_PLATFORM_MIN_WP_VERSION')
        || ! defined('OJ_PLATFORM_MIN_PHP_VERSION')
        || ! class_exists(CompositionRoot::class)
    ) {
        throw new RuntimeException('An active platform is required.');
    }
    if (! function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (! is_plugin_active('oj-platform/oj-platform.php')) {
        throw new RuntimeException('The platform must be active.');
    }

    $stage = 'container';
    $environment = PluginEnvironment::fromPluginFile(
        OJ_PLATFORM_FILE,
        OJ_PLATFORM_VERSION,
        OJ_PLATFORM_MIN_WP_VERSION,
        OJ_PLATFORM_MIN_PHP_VERSION
    );
    $composition = new CompositionRoot(
        $environment,
        new WordPressHookRegistrar(),
        new WordPressErrorReporter()
    );
    $container = $composition->build();
    $kernel = $container->get(Kernel::class);
    if (! $kernel instanceof Kernel) {
        throw new RuntimeException('Kernel service unavailable.');
    }
    $kernel->boot();
    $setup = $container->get(SetupCoordinator::class);
    if (! $setup instanceof SetupCoordinator) {
        throw new RuntimeException('Setup service unavailable.');
    }

    $stage = 'inspection';
    $snapshot = $setup->inspect();
    if (
        $snapshot->installationId() === null
        || ! in_array($snapshot->mode(), [SetupMode::AlreadyInstalled, SetupMode::Resume], true)
        || ! $snapshot->isRuntimeReady()
    ) {
        throw new RuntimeException('A ready existing installation is required.');
    }
    if ($operation === 'check') {
        WP_CLI::success('OJ Platform is ready for an update.');
        return;
    }

    $stage = 'schema';
    if ($snapshot->mode() === SetupMode::Resume) {
        $setup->run();
    }
    $snapshot = $setup->inspect();
    if (
        $snapshot->installationId() === null
        || $snapshot->mode() !== SetupMode::AlreadyInstalled
        || ! $snapshot->allowsRuntime()
        || ! $snapshot->isRuntimeReady()
    ) {
        throw new RuntimeException('The platform schema upgrade did not complete.');
    }

    $stage = 'routes';
    $rewrites = $container->get(WordPressRewriteManager::class);
    if (! $rewrites instanceof WordPressRewriteManager) {
        throw new RuntimeException('Rewrite service unavailable.');
    }
    $rewrites->synchronizeNow();

    $stage = 'jobs';
    $scheduler = $container->get(WordPressJobScheduler::class);
    if (! $scheduler instanceof WordPressJobScheduler) {
        throw new RuntimeException('Scheduler service unavailable.');
    }
    $scheduler->ensureScheduled();

    WP_CLI::success('OJ Platform update verified; schema, routes and scheduled tasks are ready.');
} catch (Throwable) {
    WP_CLI::error('OJ_PLATFORM_UPDATE_FAILED_' . strtoupper($stage));
}
