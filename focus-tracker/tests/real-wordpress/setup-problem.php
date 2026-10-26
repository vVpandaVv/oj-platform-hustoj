<?php
declare(strict_types=1);

/** Provision a synthetic problem through the real platform services, locally. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$fixtureCompleted = false;
register_shutdown_function(static function () use (&$fixtureCompleted): void {
    // WordPress may terminate on a DB bootstrap error with exit status 0.
    if (! $fixtureCompleted) { fwrite(STDERR, "Real WordPress problem fixture did not complete.\n"); exit(1); }
});

use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeConnectionCommandService;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditCommand;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditorService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;

function focus_real_problem_assert(bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message); }
}
function focus_real_problem_path(string $path): string { return strtolower(str_replace('\\', '/', rtrim($path, '/\\'))); }

try {
    $configPath = $argv[1] ?? dirname(__DIR__, 3) . '/_notes/tmp/focus-tracker-real-wp/config.json';
    $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
    $workspace = realpath(dirname(__DIR__, 3));
    $runRoot = realpath($workspace . '/_notes/tmp/focus-tracker-real-wp');
    $wpPath = realpath((string) ($config['wpPath'] ?? ''));
    focus_real_problem_assert($runRoot !== false && $wpPath !== false && focus_real_problem_path($wpPath) === focus_real_problem_path($runRoot . '/wordpress'), 'This fixture only supports its isolated workspace WordPress root.');
    $baseUrl = rtrim((string) ($config['baseUrl'] ?? $config['home'] ?? ''), '/');
    $url = parse_url($baseUrl);
    focus_real_problem_assert(is_array($url) && ($url['scheme'] ?? '') === 'http' && ($url['host'] ?? '') === '127.0.0.1' && (int) ($url['port'] ?? 0) === 33918, 'Refusing a non-fixture HTTP origin.');
    $_SERVER['HTTP_HOST'] = '127.0.0.1:33918';
    $_SERVER['SERVER_NAME'] = '127.0.0.1';
    $_SERVER['SERVER_PORT'] = '33918';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require $wpPath . '/wp-load.php';
    focus_real_problem_assert(wp_get_environment_type() === 'local' && rtrim((string) get_option('home'), '/') === $baseUrl, 'Refusing a non-local WordPress installation.');
    focus_real_problem_assert(defined('OJ_PLATFORM_FILE') && defined('OJ_PLATFORM_VERSION'), 'Activate the real platform before provisioning the problem.');

    $adminLogin = (string) ($config['admin']['username'] ?? $config['admin']['login'] ?? '');
    $admin = get_user_by('login', $adminLogin);
    focus_real_problem_assert($admin instanceof WP_User && user_can($admin, 'manage_options'), 'A fixture administrator is required.');
    wp_set_current_user((int) $admin->ID);
    $environment = PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3');
    $container = (new CompositionRoot($environment, new WordPressHookRegistrar(), new WordPressErrorReporter()))->build();
    $container->get(\CodingPanda\OjPlatform\Kernel\Boot\Kernel::class)->boot();

    // The real problem gateway is a JudgeDb gateway. Populate an empty jol on
    // the *new local DB instance*, never a server backup or a production DB.
    $dbHost = (string) DB_HOST;
    focus_real_problem_assert(preg_match('/^(127\.0\.0\.1|localhost):33917$/D', $dbHost) === 1 && $dbHost === (string) $config['dbHost'], 'Refusing a non-fixture database endpoint.');
    focus_real_problem_assert((string) DB_NAME === (string) $config['dbName'], 'The fixture database identity changed.');
    $connection = new mysqli('127.0.0.1', (string) DB_USER, (string) DB_PASSWORD, '', 33917);
    $connection->set_charset('utf8mb4');
    $connection->query('CREATE DATABASE IF NOT EXISTS jol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection->select_db('jol');
    $profile = require dirname(OJ_PLATFORM_FILE) . '/config/judge.php';
    foreach ($profile['required_columns'] as $table => $columns) {
        $definitions = array();
        foreach ($columns as $column => $acceptedTypes) {
            focus_real_problem_assert(preg_match('/^[a-z_]+$/D', $table . $column) === 1, 'Unexpected native judge schema identifier.');
            $type = $acceptedTypes[0];
            $definition = match ($type) {
                'int', 'bigint', 'tinyint', 'smallint' => strtoupper($type) . ' NOT NULL DEFAULT 0',
                'char', 'varchar' => "VARCHAR(255) NOT NULL DEFAULT ''",
                'text', 'mediumtext', 'longtext' => "LONGTEXT NOT NULL DEFAULT ''",
                'decimal', 'float', 'double' => 'DOUBLE NOT NULL DEFAULT 0',
                'datetime', 'timestamp' => 'DATETIME NULL DEFAULT NULL',
                default => throw new RuntimeException('Unsupported native judge column type.'),
            };
            if (in_array($table . '.' . $column, array('problem.problem_id', 'solution.solution_id', 'contest.contest_id'), true)) { $definition = 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY'; }
            elseif ($column === 'solution_id' || $table . '.' . $column === 'users.user_id') { $definition .= ' PRIMARY KEY'; }
            if ($table . '.' . $column === 'solution.valid') { $definition = strtoupper($type) . ' NOT NULL DEFAULT 1'; }
            $definitions[] = '`' . $column . '` ' . $definition;
        }
        if ($table === 'problem') { $definitions[] = '`in_date` DATETIME NULL'; $definitions[] = "`credits` LONGTEXT NOT NULL DEFAULT ''"; }
        if ($table === 'solution') { $definitions[] = '`class_id` BIGINT NOT NULL DEFAULT 0'; }
        if ($table === 'users') { $definitions[] = "`password` VARCHAR(255) NOT NULL DEFAULT ''"; $definitions[] = "`nick` VARCHAR(255) NOT NULL DEFAULT ''"; $definitions[] = "`defunct` CHAR(1) NOT NULL DEFAULT 'N'"; }
        $connection->query('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $definitions) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    $judgeCommands = $container->get(JudgeConnectionCommandService::class);
    $credentials = new JudgeCredentials((string) DB_USER, (string) DB_PASSWORD);
    $candidate = new JudgeConnectionSettings('127.0.0.1', 33917, 'jol', $credentials, $credentials, false, null, true);
    $judgeCommands->testCandidate($candidate, (int) $admin->ID, $judgeCommands->probeSnapshot()->revision());
    $judgeCommands->applyTestedCandidate((int) $admin->ID, $judgeCommands->snapshot()->revision());

    $settings = $container->get(SettingsManager::class);
    $dataRoot = $runRoot . '/judge-data';
    if (! is_dir($dataRoot)) { focus_real_problem_assert(mkdir($dataRoot, 0700, true), 'Could not create the local problem-data directory.'); }
    $snapshot = $settings->read('problem_test_data', array());
    $dataSettings = is_array($snapshot->value()) ? $snapshot->value() : array();
    $dataSettings['root'] = $dataRoot;
    $settings->save('problem_test_data', $dataSettings, $snapshot->revision());

    $outputDir = realpath((string) ($config['outputDir'] ?? ''));
    focus_real_problem_assert($outputDir !== false && str_starts_with(focus_real_problem_path($outputDir) . '/', focus_real_problem_path($runRoot) . '/'), 'The artifact directory must stay inside the fixture workspace.');
    $artifactPath = $outputDir . '/problem-fixture.json';
    $existing = is_file($artifactPath) ? json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR) : array();
    $catalog = $container->get(ProblemCatalogService::class);
    $draft = "a, b = map(int, input().split())\nanswer = a + b\nprint(answer)\n";
    if (isset($existing['problem']['id'])) {
        $problemId = new ProblemId((int) $existing['problem']['id']);
    } else {
        $count = (int) $connection->query('SELECT COUNT(*) AS n FROM problem')->fetch_assoc()['n'];
        focus_real_problem_assert($count === 0, 'Only the empty synthetic judge database may be seeded.');
        $languages = new JudgeLanguageCatalog();
        $command = new ProblemEditCommand(new ProblemDraft(
            'Focus Tracker 실제 환경 두 수의 합',
            '<p>두 정수 a와 b를 입력받아 합을 출력하세요. 이 문제는 로컬 통합 검증용입니다.</p>',
            '<p>첫 줄에 정수 a와 b가 공백으로 주어집니다.</p>',
            '<p>a와 b의 합을 출력합니다.</p>',
            "3 4\n", "7\n", '', 'Focus Tracker local acceptance', 1, 128, false,
            ProblemVisibility::Published, '', '', $draft, '', $languages->disabledMaskForEnabled(array(JudgeLanguageCatalog::PYTHON_3_ID))
        ), null, array(), CodeEntryMode::FullCode, null, 'Synthetic local integration fixture');
        $problemId = $container->get(ProblemEditorService::class)->create((int) $admin->ID, $command);
    }
    $problem = $catalog->findVisible((int) $admin->ID, $problemId);
    focus_real_problem_assert($problem !== null && $problem->visibility() === ProblemVisibility::Published && $problem->sampleInput() === "3 4\n" && $problem->sampleOutput() === "7\n", 'The native published problem did not retain its verified sample.');
    $result = array('problem' => array('id' => $problemId->value(), 'title' => $problem->title(), 'languageId' => 6,
        'problemUrl' => $baseUrl . '/problems/' . $problemId->value(), 'submitUrl' => $baseUrl . '/submit/' . $problemId->value(),
        'sampleInput' => "3 4\n", 'sampleOutput' => "7\n", 'draft' => $draft),
        'native' => array('judgeProbePassed' => true, 'createdWith' => ProblemEditorService::class, 'sourceTables' => 'jol.problem + WordPress problem metadata'));
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    focus_real_problem_assert(file_put_contents($artifactPath, $json, LOCK_EX) === strlen($json), 'Could not write the public fixture artifact.');
    $connection->close();
    $fixtureCompleted = true;
    echo json_encode(array('passed' => true, 'problemId' => $problemId->value(), 'artifact' => $artifactPath, 'judgeProbePassed' => true), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // Do not expose database credentials or a configuration dump on failure.
    fwrite(STDERR, 'Real WordPress problem fixture failed: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    exit(1);
}
