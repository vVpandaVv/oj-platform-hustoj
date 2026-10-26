<?php
/**
 * Initialize an already activated OJ Platform on a fresh WordPress site.
 * Usage: wp eval-file configure-platform.php /private/config.json --user=ADMIN
 * The installer must hand the JSON to the web user with mode 0600 and remove it
 * afterwards. Credentials are passed to SecretVault, never printed or saved as
 * a plain WordPress option. No existing installation state is reset.
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
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeConnectionCommandService;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobScheduler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataSettingsService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataConfiguration;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;

if (! defined('WP_CLI') || WP_CLI !== true || ! class_exists('WP_CLI')) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "OJ_PLATFORM_INIT_REQUIRES_WP_CLI\n");
    } else {
        http_response_code(404);
    }
    exit(1);
}

// Do not let exception messages or PHP argument values expose input secrets.
ini_set('display_errors', '0');
$stage = 'input';

try {
    if (
        ! function_exists('get_current_user_id')
        || ! function_exists('current_user_can')
        || get_current_user_id() < 1
        || ! current_user_can('manage_options')
        || ! defined('OJ_PLATFORM_FILE')
        || ! defined('OJ_PLATFORM_VERSION')
        || ! class_exists(CompositionRoot::class)
    ) {
        throw new RuntimeException('Administrator and active platform required.');
    }
    $actorUserId = get_current_user_id();
    $inputArguments = isset($args) && is_array($args) ? $args : [];
    if (count($inputArguments) !== 1 || ! is_string($inputArguments[0])) {
        throw new RuntimeException('One private JSON path is required.');
    }
    $configuredPath = $inputArguments[0];
    $inputPath = realpath($configuredPath);
    if (
        $inputPath === false
        || is_link($configuredPath)
        || ! is_file($inputPath)
        || ! is_readable($inputPath)
        || filesize($inputPath) > 16384
    ) {
        throw new RuntimeException('Private JSON file unavailable.');
    }
    $wordpressRoot = realpath((string) ABSPATH);
    if (
        $wordpressRoot === false
        || $inputPath === $wordpressRoot
        || str_starts_with($inputPath, rtrim($wordpressRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Private JSON must be outside the web root.');
    }
    if (DIRECTORY_SEPARATOR === '/') {
        $mode = fileperms($inputPath);
        if ($mode === false || ($mode & 0077) !== 0) {
            throw new RuntimeException('Private JSON permissions are too broad.');
        }
        if (function_exists('posix_geteuid') && fileowner($inputPath) !== posix_geteuid()) {
            throw new RuntimeException('Private JSON must belong to the executing user.');
        }
    }
    $inputBytes = file_get_contents($inputPath);
    if (! is_string($inputBytes) || str_starts_with($inputBytes, "\xEF\xBB\xBF")) {
        throw new RuntimeException('Private JSON encoding is invalid.');
    }
    $input = json_decode($inputBytes, true, 16, JSON_THROW_ON_ERROR);
    unset($inputBytes);
    if (
        ! is_array($input)
        || ($input['schema_version'] ?? null) !== 1
        || ($input['fresh_install'] ?? null) !== true
        || ! is_array($input['judge'] ?? null)
    ) {
        throw new RuntimeException('Fresh-install input contract is invalid.');
    }
    $judge = $input['judge'];
    $host = is_string($judge['host'] ?? null) ? trim($judge['host']) : '';
    $port = filter_var($judge['port'] ?? 3306, FILTER_VALIDATE_INT);
    $username = is_string($judge['username'] ?? null) ? trim($judge['username']) : '';
    $password = is_string($judge['password'] ?? null) ? $judge['password'] : '';
    if (
        ! in_array(strtolower(trim($host, '[] ')), ['localhost', '127.0.0.1', '::1'], true)
        || $port === false
        || ($judge['database'] ?? null) !== 'jol'
        || ($judge['timestamp_contract'] ?? null) !== 'utc-v1'
        || $username === ''
        || $password === ''
        || str_contains($username . $password, "\0")
    ) {
        throw new RuntimeException('Local UTC Judge configuration is required.');
    }
    $credentials = new JudgeCredentials($username, $password);
    $candidate = new JudgeConnectionSettings(
        $host, $port, 'jol', $credentials, $credentials, false, null, true
    );
    $testData = ProblemTestDataConfiguration::from($input['test_data'] ?? []);
    unset($input, $judge, $username, $password);

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
    // Register and freeze definitions before SetupCoordinator inspects schemas.
    $kernel->boot();
    $setup = $container->get(SetupCoordinator::class);
    $commands = $container->get(JudgeConnectionCommandService::class);
    $settings = $container->get(SettingsManager::class);
    $testDataSettings = $container->get(ProblemTestDataSettingsService::class);
    if (
        ! $setup instanceof SetupCoordinator
        || ! $commands instanceof JudgeConnectionCommandService
        || ! $settings instanceof SettingsManager
        || ! $testDataSettings instanceof ProblemTestDataSettingsService
    ) {
        throw new RuntimeException('Configuration services unavailable.');
    }

    $stage = 'existing_configuration';
    $existing = $commands->current();
    if ($existing !== null) {
        foreach ([$existing->readCredentials(), $existing->writeCredentials()] as $current) {
            if (
                ! hash_equals($current->username(), $credentials->username())
                || ! hash_equals($current->password(), $credentials->password())
            ) {
                throw new RuntimeException('Existing Judge settings will not be overwritten.');
            }
        }
        if (
            strtolower($existing->host()) !== strtolower($candidate->host())
            || $existing->port() !== $candidate->port()
            || $existing->database() !== 'jol'
            || $existing->tlsEnabled()
            || ! $existing->utcStorageConfirmed()
        ) {
            throw new RuntimeException('Existing Judge settings will not be overwritten.');
        }
    }
    $storedTestData = $settings->read('problem_test_data', []);
    if (
        $storedTestData->revision() > 0
        && ProblemTestDataConfiguration::from($storedTestData->value())->toArray() !== $testData->toArray()
    ) {
        throw new RuntimeException('Existing test-data settings will not be overwritten.');
    }

    $stage = 'setup';
    $snapshot = $setup->inspect();
    if (! in_array($snapshot->mode(), [SetupMode::FreshInstall, SetupMode::Resume, SetupMode::AlreadyInstalled], true)) {
        throw new RuntimeException('Legacy migration and table collisions require manual review.');
    }
    if ($snapshot->mode() !== SetupMode::AlreadyInstalled) {
        $setup->run();
    }
    $snapshot = $setup->inspect();
    if ($snapshot->mode() !== SetupMode::AlreadyInstalled || ! $snapshot->allowsRuntime()) {
        throw new RuntimeException('Platform setup did not complete.');
    }

    $stage = 'judge_probe';
    $commands->testCandidate($candidate, $actorUserId, $commands->probeSnapshot()->revision());
    $stage = 'judge_apply';
    $commands->applyTestedCandidate($actorUserId, $commands->snapshot()->revision());

    $stage = 'test_data';
    $testDataSettings->saveRoot($testData->root(), $settings->read('problem_test_data', [])->revision());
    $testDataSettings->saveSpj(
        $testData->cCompiler(),
        $testData->cppCompiler(),
        $testData->limitExecutable(),
        $testData->sandboxExecutable(),
        $settings->read('problem_test_data', [])->revision()
    );

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

    WP_CLI::success('OJ Platform initialized; Judge connection verified; test-data settings saved.');
} catch (Throwable) {
    // Never echo the exception: database errors can contain credential metadata.
    WP_CLI::error('OJ_PLATFORM_INIT_FAILED_' . strtoupper($stage));
}
