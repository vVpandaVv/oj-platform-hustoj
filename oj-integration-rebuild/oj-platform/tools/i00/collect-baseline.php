<?php

declare(strict_types=1);

/**
 * I-00 read-only baseline collector.
 *
 * Usage:
 * php collect-baseline.php [output-directory] [baseline-commit] [judge-schema.sql]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI에서만 실행할 수 있습니다.\n");
    exit(1);
}

define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);

$pluginRoot = realpath(dirname(__DIR__, 2));
$repositoryRoot = $pluginRoot !== false ? realpath(dirname($pluginRoot)) : false;

if ($pluginRoot === false || $repositoryRoot === false) {
    fwrite(STDERR, "플러그인 또는 저장소 경로를 확인할 수 없습니다.\n");
    exit(1);
}

$outputRoot = $argv[1] ?? ($pluginRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'i-00');
$baselineCommit = $argv[2] ?? command_output(
    'git -C ' . escapeshellarg($repositoryRoot) . ' rev-parse HEAD'
);
$judgeSchemaPath = $argv[3] ?? '';
$baselineCommit = trim($baselineCommit);

if (preg_match('/^[0-9a-f]{40}$/', $baselineCommit) !== 1) {
    fwrite(STDERR, "유효한 기준 커밋이 필요합니다.\n");
    exit(1);
}
if ($judgeSchemaPath === '' || ! is_file($judgeSchemaPath)) {
    fwrite(STDERR, "Judge 기준 스키마 SQL 경로를 세 번째 인수로 지정해야 합니다.\n");
    exit(1);
}

ensure_directory($outputRoot);

$trackedFiles = tracked_file_manifest($repositoryRoot, $pluginRoot, $baselineCommit);
$phpFiles = array_values(array_filter(
    $trackedFiles,
    static fn (array $file): bool => str_ends_with(strtolower($file['path']), '.php')
));
$phpSources = load_php_sources($repositoryRoot, $phpFiles);
$modules = require $pluginRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'modules.php';
$features = require $pluginRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'features.php';
$permissions = require $pluginRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'capabilities.php';
$types = php_type_manifest($phpSources);
$routes = route_manifest($phpSources, $modules);
$actions = action_manifest($phpSources);
$hooks = hook_manifest($phpSources);
$menus = menu_manifest($pluginRoot);
$options = option_manifest($phpSources);
$tables = table_manifest($pluginRoot);
$judgeSchema = judge_schema_manifest($judgeSchemaPath);
$compatibilityRoutes = array_values(array_filter(
    $routes,
    static fn (array $route): bool => $route['file'] === 'modules/AccessRouter/Compatibility_Route_Provider.php'
));
$requiredJudgeTables = array_values(array_filter(
    $tables['judge'],
    static fn (array $table): bool => $table['required_by_current_code']
));

$summary = array(
    'schema_version' => 1,
    'generated_at' => gmdate('c'),
    'baseline_commit' => $baselineCommit,
    'branch' => trim(command_output('git -C ' . escapeshellarg($repositoryRoot) . ' branch --show-current')),
    'collector' => 'oj-platform/tools/i00/collect-baseline.php',
    'read_only' => true,
    'counts' => array(
        'tracked_files' => count($trackedFiles),
        'php_files' => count($phpFiles),
        'php_types' => count($types),
        'modules' => count($modules),
        'features' => count($features),
        'capabilities' => count($permissions['capabilities'] ?? array()),
        'roles' => count($permissions['roles'] ?? array()),
        'presets' => count($permissions['presets'] ?? array()),
        'routes' => count($routes),
        'direct_routes' => count($routes) - count($compatibilityRoutes),
        'compatibility_routes' => count($compatibilityRoutes),
        'unique_route_names' => count(array_unique(array_column($routes, 'name'))),
        'unique_route_paths' => count(array_unique(array_column($routes, 'path'))),
        'actions' => count($actions),
        'unique_action_names' => count(array_unique(array_column($actions, 'action'))),
        'console_menu_items' => count($menus['console']),
        'public_menu_items' => count($menus['public_defaults']),
        'options' => count($options),
        'platform_tables' => count($tables['platform']),
        'judge_tables' => count($tables['judge']),
        'judge_tables_required_by_current_code' => count($requiredJudgeTables),
        'judge_schema_tables' => count($judgeSchema['tables'] ?? array()),
        'literal_hooks' => count($hooks),
    ),
    'environment' => array(
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'php_extensions' => get_loaded_extensions(),
        'operating_system' => PHP_OS_FAMILY,
    ),
    'invariants' => array(
        'working_tree_commit_matches_baseline' => trim(command_output(
            'git -C ' . escapeshellarg($repositoryRoot) . ' rev-parse HEAD'
        )) === $baselineCommit,
        'route_names_unique' => count($routes) === count(array_unique(array_column($routes, 'name'))),
        'route_paths_unique' => count($routes) === count(array_unique(array_column($routes, 'path'))),
        'action_names_unique' => count($actions) === count(array_unique(array_column($actions, 'action'))),
        'console_menu_keys_unique' => count($menus['console']) === count(array_unique(array_column($menus['console'], 'key'))),
    ),
    'sources' => array(
        'git_tree' => $baselineCommit,
        'judge_schema' => $judgeSchema['source'] ?? null,
    ),
);

$artifacts = array(
    'summary.json' => $summary,
    'files.json' => $trackedFiles,
    'php-types.json' => $types,
    'modules.json' => $modules,
    'features.json' => $features,
    'permissions.json' => $permissions,
    'routes.json' => $routes,
    'actions.json' => $actions,
    'hooks.json' => $hooks,
    'menus.json' => $menus,
    'options.json' => $options,
    'tables.json' => $tables,
    'judge-schema-reference.json' => $judgeSchema,
);

foreach ($artifacts as $filename => $data) {
    write_json($outputRoot . DIRECTORY_SEPARATOR . $filename, $data);
}

fwrite(
    STDOUT,
    sprintf(
        "I-00 기준선 수집 완료: 파일 %d개, 경로 %d개, 액션 %d개, 관리 메뉴 %d개, 플랫폼 테이블 %d개\n",
        $summary['counts']['tracked_files'],
        $summary['counts']['routes'],
        $summary['counts']['actions'],
        $summary['counts']['console_menu_items'],
        $summary['counts']['platform_tables']
    )
);

/**
 * @return list<array<string, mixed>>
 */
function tracked_file_manifest(string $repositoryRoot, string $pluginRoot, string $commit): array
{
    $output = command_output(
        'git -C ' . escapeshellarg($repositoryRoot)
        . ' ls-tree -r -l ' . escapeshellarg($commit)
        . ' -- oj-platform'
    );
    $files = array();

    foreach (preg_split('/\R/', trim($output)) ?: array() as $line) {
        if (preg_match('/^(\d{6})\s+blob\s+([0-9a-f]+)\s+(\d+|-)\t(.+)$/', $line, $match) !== 1) {
            continue;
        }

        $repositoryPath = str_replace('/', DIRECTORY_SEPARATOR, $match[4]);
        $absolutePath = $repositoryRoot . DIRECTORY_SEPARATOR . $repositoryPath;
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($pluginRoot) + 1));
        $isText = preg_match('/\.(?:css|html?|js|json|md|php|txt|xml|ya?ml)$/i', $absolutePath) === 1;

        $files[] = array(
            'path' => $relativePath,
            'mode' => $match[1],
            'git_blob' => $match[2],
            'bytes' => $match[3] === '-' ? null : (int) $match[3],
            'sha256' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            'lines' => $isText && is_file($absolutePath)
                ? line_count((string) file_get_contents($absolutePath))
                : null,
        );
    }

    usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

    return $files;
}

/**
 * @param list<array<string, mixed>> $phpFiles
 * @return array<string, string>
 */
function load_php_sources(string $repositoryRoot, array $phpFiles): array
{
    $sources = array();

    foreach ($phpFiles as $file) {
        $relativePath = 'oj-platform/' . $file['path'];
        $absolutePath = $repositoryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (is_file($absolutePath)) {
            $sources[$relativePath] = (string) file_get_contents($absolutePath);
        }
    }

    return $sources;
}

/**
 * @param array<string, string> $sources
 * @return list<array<string, string>>
 */
function php_type_manifest(array $sources): array
{
    $types = array();

    foreach ($sources as $path => $source) {
        $tokens = token_get_all($source);
        $namespace = '';
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = read_token_name($tokens, $index + 1);
                continue;
            }

            $typeTokens = array(T_CLASS, T_INTERFACE, T_TRAIT);
            if (defined('T_ENUM')) {
                $typeTokens[] = T_ENUM;
            }

            if (! in_array($token[0], $typeTokens, true)) {
                continue;
            }

            if ($token[0] === T_CLASS && previous_significant_token($tokens, $index) === T_NEW) {
                continue;
            }

            $name = read_next_string_token($tokens, $index + 1);

            if ($name === '') {
                continue;
            }

            $types[] = array(
                'type' => match ($token[0]) {
                    T_INTERFACE => 'interface',
                    T_TRAIT => 'trait',
                    default => defined('T_ENUM') && $token[0] === T_ENUM ? 'enum' : 'class',
                },
                'name' => $name,
                'namespace' => $namespace,
                'fully_qualified_name' => ltrim($namespace . '\\' . $name, '\\'),
                'file' => str_replace('oj-platform/', '', $path),
            );
        }
    }

    usort($types, static fn (array $left, array $right): int => $left['fully_qualified_name'] <=> $right['fully_qualified_name']);

    return $types;
}

/**
 * @param array<string, string> $sources
 * @param array<string, array<string, mixed>> $modules
 * @return list<array<string, mixed>>
 */
function route_manifest(array $sources, array $modules): array
{
    $directoryOwners = array();

    foreach ($modules as $key => $definition) {
        $class = (string) ($definition['class'] ?? '');

        if (preg_match('/\\\\Modules\\\\([^\\\\]+)\\\\Module$/', $class, $match) === 1) {
            $directoryOwners[$match[1]] = $key;
        }
    }

    $routes = array();
    $callPattern = "/\\\$this->(?:public_route|console_route|console_redirect|route|redirect)\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'/";
    $arrayPattern = "/'name'\\s*=>\\s*'([^']+)'[\\s\\S]{0,600}?'path'\\s*=>\\s*'([^']+)'/";

    foreach ($sources as $path => $source) {
        if (! str_starts_with($path, 'oj-platform/modules/')) {
            continue;
        }

        $owner = module_owner_from_path($path, $directoryOwners);

        foreach (array($callPattern, $arrayPattern) as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $name = (string) ($match[1][0] ?? '');
                $routePath = (string) ($match[2][0] ?? '');
                $offset = (int) ($match[0][1] ?? 0);

                $routes[] = array(
                    'name' => $name,
                    'path' => $routePath,
                    'area' => str_starts_with($routePath, '/oj-console') ? 'console' : 'public',
                    'owner' => $owner,
                    'file' => str_replace('oj-platform/', '', $path),
                    'line' => source_line($source, $offset),
                );
            }
        }
    }

    usort($routes, static function (array $left, array $right): int {
        return array($left['path'], $left['name'], $left['file'])
            <=> array($right['path'], $right['name'], $right['file']);
    });

    return $routes;
}

/**
 * @param array<string, string> $sources
 * @return list<array<string, mixed>>
 */
function action_manifest(array $sources): array
{
    $actions = array();
    $pattern = "/->(admin_post|ajax)\\s*\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'/";

    foreach ($sources as $path => $source) {
        if (! str_starts_with($path, 'oj-platform/modules/')) {
            continue;
        }

        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $actions[] = array(
                'transport' => $match[1][0] === 'ajax' ? 'ajax' : 'admin_post',
                'owner' => (string) $match[2][0],
                'action' => (string) $match[3][0],
                'file' => str_replace('oj-platform/', '', $path),
                'line' => source_line($source, (int) $match[0][1]),
            );
        }
    }

    usort($actions, static fn (array $left, array $right): int => $left['action'] <=> $right['action']);

    return $actions;
}

/**
 * @param array<string, string> $sources
 * @return list<array<string, mixed>>
 */
function hook_manifest(array $sources): array
{
    $hooks = array();
    $pattern = "/\\b(add_action|add_filter|register_activation_hook|register_deactivation_hook)\\s*\\(\\s*'([^']+)'/";

    foreach ($sources as $path => $source) {
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $hooks[] = array(
                'registration' => (string) $match[1][0],
                'hook' => (string) $match[2][0],
                'file' => str_replace('oj-platform/', '', $path),
                'line' => source_line($source, (int) $match[0][1]),
            );
        }
    }

    usort($hooks, static fn (array $left, array $right): int => array($left['hook'], $left['file']) <=> array($right['hook'], $right['file']));

    return $hooks;
}

/**
 * @return array{console: list<array<string, mixed>>, public_defaults: list<array<string, mixed>>}
 */
function menu_manifest(string $pluginRoot): array
{
    require_once $pluginRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Config.php';
    require_once $pluginRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Registry' . DIRECTORY_SEPARATOR . 'Registry.php';
    require_once $pluginRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Registry' . DIRECTORY_SEPARATOR . 'Route_Registry.php';
    require_once $pluginRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'I18n.php';
    require_once $pluginRoot . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'Settings' . DIRECTORY_SEPARATOR . 'Repository' . DIRECTORY_SEPARATOR . 'Settings_Repository.php';
    require_once $pluginRoot . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'AdminConsole' . DIRECTORY_SEPARATOR . 'Navigation' . DIRECTORY_SEPARATOR . 'Console_Menu_Provider.php';

    $settingsDefaults = require $pluginRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'settings.php';
    $modules = require $pluginRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'modules.php';
    $features = require $pluginRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'features.php';
    $permissions = require $pluginRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'capabilities.php';
    $config = new \CodingPanda\OjPlatform\Core\Config(
        array(),
        $settingsDefaults,
        $modules,
        $features,
        $permissions
    );
    $settings = new \CodingPanda\OjPlatform\Modules\Settings\Repository\Settings_Repository($config);
    $public = $settings->defaults(
        \CodingPanda\OjPlatform\Modules\Settings\Repository\Settings_Repository::OPTION_PUBLIC_MENU
    );
    $console = (new \CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\Console_Menu_Provider())->items();

    return array(
        'console' => array_values($console),
        'public_defaults' => array_values(is_array($public['items'] ?? null) ? $public['items'] : array()),
    );
}

/**
 * @param array<string, string> $sources
 * @return list<array<string, mixed>>
 */
function option_manifest(array $sources): array
{
    $options = array();
    $constantPattern = "/\\b(?:public|protected|private)?\\s*const\\s+(OPTION[A-Z0-9_]*)\\s*=\\s*'([^']+)'/";
    $functionPattern = "/\\b(get_option|update_option|add_option|delete_option)\\s*\\(\\s*'([^']+)'/";

    foreach ($sources as $path => $source) {
        preg_match_all($constantPattern, $source, $constantMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($constantMatches as $match) {
            add_option_record(
                $options,
                (string) $match[2][0],
                'constant:' . (string) $match[1][0],
                $path,
                $source,
                (int) $match[0][1]
            );
        }

        preg_match_all($functionPattern, $source, $functionMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($functionMatches as $match) {
            add_option_record(
                $options,
                (string) $match[2][0],
                (string) $match[1][0],
                $path,
                $source,
                (int) $match[0][1]
            );
        }
    }

    ksort($options);

    return array_values($options);
}

/**
 * @return array<string, mixed>
 */
function table_manifest(string $pluginRoot): array
{
    require_once $pluginRoot . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'PlatformDb' . DIRECTORY_SEPARATOR . 'Mapping' . DIRECTORY_SEPARATOR . 'Platform_Table_Map.php';
    require_once $pluginRoot . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'JudgeDb' . DIRECTORY_SEPARATOR . 'Mapping' . DIRECTORY_SEPARATOR . 'Judge_Table_Map.php';

    $platformReflection = new ReflectionClass(\CodingPanda\OjPlatform\Modules\PlatformDb\Mapping\Platform_Table_Map::class);
    $judgeReflection = new ReflectionClass(\CodingPanda\OjPlatform\Modules\JudgeDb\Mapping\Judge_Table_Map::class);
    $platformTables = reflection_constant($platformReflection, 'TABLES');
    $unprefixedTables = reflection_constant($platformReflection, 'UNPREFIXED_TABLES');
    $judgeTables = reflection_constant($judgeReflection, 'TABLES');
    $requiredJudgeTables = array_flip(reflection_constant($judgeReflection, 'REQUIRED_TABLES'));
    $schemaSource = (string) file_get_contents(
        $pluginRoot . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'PlatformDb' . DIRECTORY_SEPARATOR . 'Schema' . DIRECTORY_SEPARATOR . 'Platform_Schema_Installer.php'
    );
    $schemaByLogical = platform_schema_columns($schemaSource);
    $platform = array();

    foreach ($platformTables as $logical => $physical) {
        $unprefixed = isset($unprefixedTables[$logical]);
        $platform[] = array(
            'logical' => $logical,
            'physical_template' => $unprefixed ? $physical : '{$wpdb->prefix}' . $physical,
            'unprefixed' => $unprefixed,
            'columns' => $schemaByLogical[$logical]['columns'] ?? array(),
            'indexes' => $schemaByLogical[$logical]['indexes'] ?? array(),
        );
    }

    $judge = array();
    foreach ($judgeTables as $logical => $physical) {
        $judge[] = array(
            'logical' => $logical,
            'physical_template' => '{$judge_table_prefix}' . $physical,
            'required_by_current_code' => isset($requiredJudgeTables[$logical]),
        );
    }

    return array(
        'platform' => $platform,
        'judge' => $judge,
    );
}

/**
 * @return array<string, mixed>
 */
function judge_schema_manifest(string $path): array
{
    if ($path === '' || ! is_file($path)) {
        return array(
            'available' => false,
            'source' => null,
            'sha256' => null,
            'tables' => array(),
        );
    }

    $source = (string) file_get_contents($path);
    $tables = array();
    preg_match_all(
        '/CREATE TABLE\s+`([^`]+)`\s*\((.*?)\)\s*ENGINE=/si',
        $source,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $columns = array();
        $indexes = array();

        foreach (preg_split('/\R/', $match[2]) ?: array() as $line) {
            $line = trim(rtrim($line, ','));

            if (preg_match('/^`([^`]+)`\s+(.+)$/', $line, $columnMatch) === 1) {
                $definition = $columnMatch[2];
                $commentPosition = stripos($definition, ' COMMENT ');
                if ($commentPosition !== false) {
                    $definition = substr($definition, 0, $commentPosition);
                }
                $columns[$columnMatch[1]] = trim($definition);
            } elseif ($line !== '') {
                $indexes[] = $line;
            }
        }

        $tables[] = array(
            'name' => $match[1],
            'columns' => $columns,
            'indexes' => $indexes,
        );
    }

    return array(
        'available' => true,
        'source' => basename($path),
        'bytes' => filesize($path),
        'sha256' => hash_file('sha256', $path),
        'tables' => $tables,
    );
}

/**
 * @return array<string, array{columns: array<string, string>, indexes: list<string>}>
 */
function platform_schema_columns(string $source): array
{
    $schemas = array();
    preg_match_all(
        '/\$tables\[\'([^\']+)\'\]\s*=>\s*"CREATE TABLE\s+\{\$tables\[\'[^\']+\'\]\}\s*\((.*?)\)\s+\{\$charset\};"/s',
        $source,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $columns = array();
        $indexes = array();

        foreach (preg_split('/\R/', $match[2]) ?: array() as $line) {
            $line = trim(rtrim($line, ','));

            if ($line === '') {
                continue;
            }

            if (preg_match('/^([a-zA-Z0-9_]+)\s+(.+)$/', $line, $columnMatch) === 1
                && ! in_array(strtoupper($columnMatch[1]), array('PRIMARY', 'UNIQUE', 'KEY', 'FULLTEXT', 'CONSTRAINT'), true)
            ) {
                $columns[$columnMatch[1]] = $columnMatch[2];
            } else {
                $indexes[] = $line;
            }
        }

        $schemas[$match[1]] = array(
            'columns' => $columns,
            'indexes' => $indexes,
        );
    }

    return $schemas;
}

/**
 * @param array<string, array<string, mixed>> $options
 */
function add_option_record(
    array &$options,
    string $name,
    string $access,
    string $path,
    string $source,
    int $offset
): void {
    if (! str_starts_with($name, 'oj_')) {
        return;
    }

    if (! isset($options[$name])) {
        $options[$name] = array(
            'name' => $name,
            'references' => array(),
        );
    }

    $reference = array(
        'access' => $access,
        'file' => str_replace('oj-platform/', '', $path),
        'line' => source_line($source, $offset),
    );

    if (! in_array($reference, $options[$name]['references'], true)) {
        $options[$name]['references'][] = $reference;
    }
}

/**
 * @param array<string, string> $directoryOwners
 */
function module_owner_from_path(string $path, array $directoryOwners): string
{
    if (preg_match('#^oj-platform/modules/([^/]+)/#', $path, $match) !== 1) {
        return '';
    }

    return (string) ($directoryOwners[$match[1]] ?? strtolower($match[1]));
}

/**
 * @return array<mixed>
 */
function reflection_constant(ReflectionClass $reflection, string $name): array
{
    $constant = $reflection->getReflectionConstant($name);

    if (! $constant instanceof ReflectionClassConstant || ! is_array($constant->getValue())) {
        return array();
    }

    return $constant->getValue();
}

/**
 * @param list<mixed> $tokens
 */
function read_token_name(array $tokens, int $start): string
{
    $name = '';
    $allowed = array(T_STRING, T_NS_SEPARATOR);

    if (defined('T_NAME_QUALIFIED')) {
        $allowed[] = T_NAME_QUALIFIED;
    }

    for ($index = $start, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];

        if (is_string($token) && ($token === ';' || $token === '{')) {
            break;
        }

        if (is_array($token) && in_array($token[0], $allowed, true)) {
            $name .= $token[1];
        }
    }

    return trim($name, '\\');
}

/**
 * @param list<mixed> $tokens
 */
function read_next_string_token(array $tokens, int $start): string
{
    for ($index = $start, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];

        if (is_array($token) && $token[0] === T_STRING) {
            return $token[1];
        }

        if (is_string($token) && in_array($token, array('{', '(', ';'), true)) {
            break;
        }
    }

    return '';
}

/**
 * @param list<mixed> $tokens
 */
function previous_significant_token(array $tokens, int $index): ?int
{
    for (--$index; $index >= 0; --$index) {
        $token = $tokens[$index];

        if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }

        return is_array($token) ? $token[0] : null;
    }

    return null;
}

function source_line(string $source, int $offset): int
{
    return substr_count(substr($source, 0, max(0, $offset)), "\n") + 1;
}

function line_count(string $contents): int
{
    if ($contents === '') {
        return 0;
    }

    return substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
}

function ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (! mkdir($path, 0775, true) && ! is_dir($path)) {
        throw new RuntimeException('디렉터리를 만들 수 없습니다: ' . $path);
    }
}

/**
 * @param mixed $data
 */
function write_json(string $path, mixed $data): void
{
    assert_valid_utf8($data);
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('파일을 기록할 수 없습니다: ' . $path);
    }
}

/**
 * @param mixed $value
 */
function assert_valid_utf8(mixed $value, string $location = '$'): void
{
    if (is_string($value) && preg_match('//u', $value) !== 1) {
        throw new RuntimeException('UTF-8이 아닌 문자열을 발견했습니다: ' . $location);
    }

    if (! is_array($value)) {
        return;
    }

    foreach ($value as $key => $item) {
        assert_valid_utf8($item, $location . '[' . (string) $key . ']');
    }
}

function command_output(string $command): string
{
    $lines = array();
    $exitCode = 0;
    exec($command . ' 2>&1', $lines, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "명령 실행 실패: {$command}\n" . implode(PHP_EOL, $lines)
        );
    }

    return implode(PHP_EOL, $lines);
}
