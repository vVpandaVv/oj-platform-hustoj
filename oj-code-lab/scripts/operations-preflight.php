<?php
/**
 * Read-only installed-site checks. Run after a WordPress backup:
 * wp --skip-plugins --skip-themes eval-file /path/to/operations-preflight.php
 *
 * No plugin bootstrap, migration, option update, file write or remote request is
 * performed here. SELECT/SHOW and one temporary connection-scoped advisory lock
 * are used. WordPress/WP-CLI bootstrap, MU plugins and database query logging are
 * outside this script's control; execute against a staging clone first.
 * Exit 1 means a required check failed. Exit 0 does not authorize production:
 * productionReady remains "unknown" until the separate acceptance checks pass.
 * CLI PHP settings cannot establish the settings of the web PHP process.
 */
declare(strict_types=1);

final class Oj_Code_Lab_Operations_Preflight {
    private const NOTEBOOK_BYTES = 16_000_000;
    private const DATASET_BYTES = 16_000_000;
    private const TABLES = array(
        'notebooks' => array(
            'columns' => array('id', 'user_id', 'cells', 'updated_at', 'revision', 'cell_count', 'has_error'),
            'indexes' => array('PRIMARY' => array(true, array('id')), 'user_id' => array(true, array('user_id'))),
        ),
        'datasets' => array(
            'columns' => array('id', 'label', 'icon', 'source', 'kaggle_owner', 'kaggle_slug', 'source_url', 'upload_key', 'file_name', 'sample_key', 'created_by', 'created_at', 'sort_order'),
            'indexes' => array('PRIMARY' => array(true, array('id')), 'sort_order' => array(false, array('sort_order')), 'sample_key' => array(false, array('sample_key'))),
        ),
        'dataset_cache' => array(
            'columns' => array('cache_key', 'content', 'created_at'),
            'indexes' => array('PRIMARY' => array(true, array('cache_key')), 'created_at' => array(false, array('created_at'))),
        ),
        'uploads' => array(
            'columns' => array('upload_key', 'content', 'created_at'),
            'indexes' => array('PRIMARY' => array(true, array('upload_key'))),
        ),
    );

    public static function collect_environment(): array {
        global $wp_version;
        $constant = static fn(string $name, mixed $default = null): mixed => defined($name) ? constant($name) : $default;
        return array(
            'wordpress' => (string) ($wp_version ?? ''), 'php' => PHP_VERSION,
            'sapi' => PHP_SAPI, 'now' => time(), 'pluginDirectory' => (string) $constant('WP_PLUGIN_DIR', ''),
            'codeLabBootstrapped' => defined('OJ_CODE_LAB_VERSION'),
            'platformBootstrapped' => defined('OJ_PLATFORM_VERSION'),
            'multisite' => function_exists('is_multisite') && is_multisite(),
            'networkId' => function_exists('get_current_network_id') ? get_current_network_id() : 1,
            'disableWpCron' => (bool) $constant('DISABLE_WP_CRON', false),
            'alternateWpCron' => (bool) $constant('ALTERNATE_WP_CRON', false),
            'openssl' => extension_loaded('openssl'),
            'aes256gcm' => function_exists('openssl_get_cipher_methods') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
            'mbstring' => extension_loaded('mbstring'), 'iconv' => extension_loaded('iconv'),
            'memoryLimit' => (string) ini_get('memory_limit'), 'postMaxSize' => (string) ini_get('post_max_size'),
            'uploadMaxFilesize' => (string) ini_get('upload_max_filesize'),
            'fileUploads' => (bool) ini_get('file_uploads'),
        );
    }

    /** Negative means unlimited; null means unparseable, never zero capacity. */
    public static function ini_bytes(string $value): ?int {
        $value = trim($value);
        if ('-1' === $value) { return -1; }
        if (! preg_match('/^(\d+)(?:\s*([KMG]))?$/iD', $value, $match)) { return null; }
        $multiplier = array('' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824)[strtoupper($match[2] ?? '')];
        $amount = (float) $match[1] * $multiplier;
        return $amount <= PHP_INT_MAX ? (int) $amount : null;
    }

    /** Only installed code metadata is read: PHP in plugin files is never run. */
    private static function plugin_metadata(string $directory, array $active, array $network, string $slug): array {
        $relative = $slug . '/' . $slug . '.php';
        foreach (array_merge($active, array_keys($network)) as $candidate) {
            if (is_string($candidate) && preg_match('#^[A-Za-z0-9_.-]+/' . preg_quote($slug, '#') . '\.php$#D', $candidate)) {
                $relative = $candidate; break;
            }
        }
        $file = rtrim($directory, '/\\') . '/' . $relative;
        $text = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
        $version = ''; $api = '';
        if (is_string($text)) {
            if (preg_match('/^[ \t*#]*Version:\s*([^\r\n]+)/mi', $text, $match)) { $version = trim($match[1]); }
            if ('oj-platform' === $slug && preg_match('/define\s*\(\s*[\'\"]OJ_PLATFORM_ADDON_API_VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $text, $match)) { $api = $match[1]; }
        }
        // Never return paths or arbitrary plugin-header values in the report.
        $version = preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.-]+)?$/D', $version) ? $version : '';
        $api = preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.-]+)?$/D', $api) ? $api : '';
        return array('installed' => is_string($text), 'active' => in_array($relative, $active, true) || array_key_exists($relative, $network),
            'networkActive' => array_key_exists($relative, $network), 'version' => $version, 'api' => $api);
    }

    private static function decode_option(mixed $value): mixed {
        if (! is_string($value)) { return $value; }
        if ('N;' === $value || preg_match('/^[aObisd]:/', $value)) {
            return @unserialize($value, array('allowed_classes' => false));
        }
        return $value;
    }

    /** Injectable environment/DB lets tests verify exact SQL without WordPress. */
    public static function run(object $db, array $environment): array {
        $checks = array();
        $add = static function (string $id, string $status, bool $required, string $message, array $details = array()) use (&$checks): void {
            $checks[] = array('id' => $id, 'status' => $status, 'required' => $required, 'message' => $message, 'details' => (object) $details);
        };
        $prefix = (string) ($db->prefix ?? '');
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
            $add('database.prefix', 'fail', true, '테이블 접두사를 안전하게 검사할 수 없습니다.');
            return self::report($checks, $environment);
        }
        $previous = $db->suppress_errors(true);
        try {
            $options = array();
            $names = array('active_plugins', 'home', 'siteurl', 'oj_code_lab_db_version', 'oj_code_lab_install_error', 'cron', 'oj_code_lab_kaggle_credentials', 'oj_code_lab_kaggle_username', 'oj_code_lab_kaggle_key');
            $sql = $db->prepare('SELECT option_name, option_value FROM `' . $prefix . 'options` WHERE option_name IN (' . implode(',', array_fill(0, count($names), '%s')) . ')', ...$names);
            $rows = $db->get_results($sql, 'ARRAY_A');
            $optionsRead = is_array($rows) && '' === $db->last_error;
            foreach (is_array($rows) ? $rows : array() as $row) { $options[$row['option_name']] = self::decode_option($row['option_value']); }
            $add('database.options', $optionsRead ? 'pass' : 'fail', true, $optionsRead ? '사이트 옵션을 읽었습니다.' : '사이트 옵션 조회에 실패했습니다. DB 오류 원문은 숨겼습니다.');
            $network = array();
            if (! empty($environment['multisite'])) {
                $basePrefix = (string) ($db->base_prefix ?? '');
                if (! preg_match('/^[A-Za-z0-9_]+$/D', $basePrefix)) {
                    $add('database.network_options', 'fail', true, '네트워크 옵션 테이블을 안전하게 조회할 수 없습니다.');
                } else {
                    $networkValue = $db->get_var($db->prepare('SELECT meta_value FROM `' . $basePrefix . 'sitemeta` WHERE meta_key = %s AND site_id = %d LIMIT 1', 'active_sitewide_plugins', (int) $environment['networkId']));
                    $network = self::decode_option($networkValue);
                    $network = is_array($network) ? $network : array();
                    $add('database.network_options', '' === $db->last_error ? 'pass' : 'fail', true, '네트워크 활성화 설정 조회 상태입니다.');
                }
            }
            $active = is_array($options['active_plugins'] ?? null) ? $options['active_plugins'] : array();
            $metadata = array();
            foreach (array('oj-code-lab', 'oj-platform') as $slug) {
                $metadata[$slug] = self::plugin_metadata((string) $environment['pluginDirectory'], $active, $network, $slug);
                $item = $metadata[$slug];
                $add($slug . '.active', $item['installed'] && $item['active'] ? 'pass' : 'fail', true, $slug . ' 설치 및 사이트 활성화 상태입니다.', array('installed' => $item['installed'], 'active' => $item['active']));
            }
            $add('execution.isolated', empty($environment['codeLabBootstrapped']) && empty($environment['platformBootstrapped']) ? 'pass' : 'warning', false,
                'wp --skip-plugins --skip-themes로 실행해야 사전점검 이전의 플러그인 마이그레이션·예약 작업을 피할 수 있습니다. MU 플러그인과 WordPress 부팅은 별도 확인해야 합니다.');
            $add('oj-code-lab.activation_scope', $metadata['oj-code-lab']['networkActive'] ? 'fail' : 'pass', true, 'OJ Code Lab은 사이트별 활성화만 지원합니다.');
            $versionChecks = array(
                'wordpress.version' => array((string) $environment['wordpress'], '6.6', null),
                'php.version' => array((string) $environment['php'], '8.1', null),
                'oj-platform.version' => array($metadata['oj-platform']['version'], '2.0.0-dev.30', '3.0.0'),
                'oj-platform.addon_api' => array($metadata['oj-platform']['api'], '1.0.0', '2.0.0'),
            );
            foreach ($versionChecks as $id => [$version, $minimum, $maximum]) {
                $known = '' !== $version;
                $ok = $known && version_compare($version, $minimum, '>=') && (null === $maximum || version_compare($version, $maximum, '<'));
                $add($id, ! $known ? 'unknown' : ($ok ? 'pass' : 'fail'), true, '설치 버전의 지원 범위를 확인합니다.', array('version' => $version ?: null, 'minimum' => $minimum, 'exclusiveMaximum' => $maximum));
            }
            $release = $metadata['oj-code-lab']['version'];
            $add('oj-code-lab.release', '' === $release ? 'unknown' : (str_contains($release, '-') ? 'warning' : 'pass'), false,
                '릴리스 후보 설치는 정식 릴리스 승인과 별개입니다.', array('version' => $release ?: null));
            $add('schema.version', '2' === ($options['oj_code_lab_db_version'] ?? null) ? 'pass' : 'fail', true, '필수 스키마 버전은 2입니다.');
            $add('schema.install_error', empty($options['oj_code_lab_install_error']) ? 'pass' : 'fail', true, '설치 오류 기록 유무를 확인합니다. 기록 내용은 출력하지 않습니다.');
            foreach (self::TABLES as $suffix => $definition) {
                $table = $prefix . 'oj_code_lab_' . $suffix;
                $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table))) === $table && '' === $db->last_error;
                $add('table.' . $suffix, $exists ? 'pass' : 'fail', true, '필수 테이블 존재 여부입니다.');
                if (! $exists) { continue; }
                $columnRows = $db->get_results('SHOW COLUMNS FROM `' . $table . '`', 'ARRAY_A');
                $columnNames = is_array($columnRows) ? array_column($columnRows, 'Field') : array();
                $missing = array_values(array_diff($definition['columns'], $columnNames));
                $add('columns.' . $suffix, ! $missing && '' === $db->last_error ? 'pass' : 'fail', true, '필수 컬럼 존재 여부입니다.', array('missing' => $missing));
                $indexRows = $db->get_results('SHOW INDEX FROM `' . $table . '`', 'ARRAY_A');
                $indexes = array();
                foreach (is_array($indexRows) ? $indexRows : array() as $row) {
                    $name = $row['Key_name'];
                    $indexes[$name]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
                    $indexes[$name]['unique'] = 0 === (int) $row['Non_unique'];
                    $indexes[$name]['full'] = ($indexes[$name]['full'] ?? true) && null === ($row['Sub_part'] ?? null);
                }
                $invalid = array();
                foreach ($definition['indexes'] as $name => [$unique, $columns]) {
                    $index = $indexes[$name] ?? array();
                    $actual = $index['columns'] ?? array(); ksort($actual);
                    if (array_values($actual) !== $columns || ($unique && empty($index['unique'])) || empty($index['full'])) { $invalid[] = $name; }
                }
                $add('indexes.' . $suffix, ! $invalid && '' === $db->last_error ? 'pass' : 'fail', true, '필수 인덱스의 순서·유일성·전체 컬럼 사용 여부입니다.', array('missingOrInvalid' => $invalid));
                if ('notebooks' === $suffix && ! $missing) {
                    $pending = $db->get_var('SELECT COUNT(*) FROM `' . $table . '` WHERE cell_count IS NULL OR has_error IS NULL');
                    $add('schema.summary_backfill', null !== $pending && 0 === (int) $pending && '' === $db->last_error ? 'pass' : 'warning', false,
                        '이전 노트북 요약 집계의 완료 여부입니다.', array('pendingRows' => null === $pending ? null : (int) $pending));
                }
            }
            $capabilities = $db->get_row("SELECT VERSION() AS database_version, @@SESSION.max_allowed_packet AS packet_bytes, JSON_VALID('[1]') AS json_valid, JSON_LENGTH('[1,2]') AS json_length, JSON_SEARCH('[{\"type\":\"error\"}]', 'one', 'error', NULL, '$[*].type') AS json_search", 'ARRAY_A');
            $jsonReady = is_array($capabilities) && '' === $db->last_error && 1 === (int) $capabilities['json_valid'] && 2 === (int) $capabilities['json_length'] && ! empty($capabilities['json_search']);
            $add('database.json', $jsonReady ? 'pass' : 'fail', true, '마이그레이션에 사용하는 JSON_VALID/JSON_LENGTH/JSON_SEARCH를 실제 SELECT로 검증합니다.');
            // 16 MB of input can double during SQL string escaping. Include SQL overhead.
            $requiredPacket = max(self::NOTEBOOK_BYTES, self::DATASET_BYTES) * 2 + 65536;
            $packet = is_array($capabilities) && is_numeric($capabilities['packet_bytes'] ?? null) ? (int) $capabilities['packet_bytes'] : null;
            // RC4 introduced guards on notebook, upload and cache writes. Older
            // installed builds must still satisfy the full fixed-payload requirement.
            $automaticLimits = '' !== $release && version_compare($release, '1.0.0-rc.4', '>=');
            $safePayload = null === $packet ? null : intdiv(max(0, $packet - 65536), 2);
            $packetStatus = null === $packet ? 'unknown' : ($packet >= $requiredPacket ? 'pass' : ($automaticLimits && $safePayload > 0 ? 'warning' : 'fail'));
            $add('database.max_allowed_packet', $packetStatus, true,
                $automaticLimits ? 'SQL 이스케이프 2배와 64KiB 여유를 반영해 저장 상한을 자동 조정합니다. 초과 노트북·업로드는 413, 한도 조회 실패는 503이며 캐시 쓰기는 건너뜁니다.' : '이 설치 버전은 자동 패킷 제한을 적용하지 않으므로 전체 기본 페이로드의 SQL 공간을 확보해야 합니다.',
                array('bytes' => $packet, 'recommendedBytesForFullDefaultPayload' => $requiredPacket, 'automaticLimitSupported' => $automaticLimits,
                    'effectiveDefaultNotebookBytes' => null === $safePayload ? null : ($automaticLimits ? min(self::NOTEBOOK_BYTES, $safePayload) : self::NOTEBOOK_BYTES),
                    'effectiveDefaultDatasetBytes' => null === $safePayload ? null : ($automaticLimits ? min(self::DATASET_BYTES, $safePayload) : self::DATASET_BYTES), 'sqlOverheadBytes' => 65536));
            $lock = 'ocl_preflight_' . bin2hex(random_bytes(16));
            $acquired = false; $released = false;
            try {
                $acquired = '1' === (string) $db->get_var($db->prepare('SELECT GET_LOCK(%s, 0)', $lock));
            } finally {
                if ($acquired) { $released = '1' === (string) $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock)) && '' === $db->last_error; }
            }
            $add('database.advisory_lock', $acquired && $released ? 'pass' : 'fail', true, '임의의 연결 전용 잠금을 획득한 뒤 해제했습니다. 테이블과 설정은 변경하지 않습니다.', array('acquired' => $acquired, 'released' => $released));
            $cron = is_array($options['cron'] ?? null) ? $options['cron'] : array();
            $next = null; $hourly = false;
            foreach ($cron as $timestamp => $events) {
                if (! is_numeric($timestamp) || ! is_array($events)) { continue; }
                $cleanupEvents = $events['oj_code_lab_cache_cleanup'] ?? array();
                foreach (is_array($cleanupEvents) ? $cleanupEvents : array() as $event) {
                    if (! is_array($event)) { continue; }
                    if (null === $next || (int) $timestamp < $next) { $next = (int) $timestamp; }
                    $hourly = $hourly || ('hourly' === ($event['schedule'] ?? null) && 3600 === (int) ($event['interval'] ?? 0));
                }
            }
            $age = null === $next ? null : (int) $environment['now'] - $next;
            $add('cron.cache_cleanup', null === $next || ! $hourly ? 'fail' : ($age > 7200 ? 'warning' : 'pass'), true,
                '매시간 캐시 정리 예약과 2시간 이상 지연 여부입니다. 예약 존재만으로 실행 성공을 보증하지 않습니다.', array('scheduled' => null !== $next, 'hourly' => $hourly, 'overdueSeconds' => null === $age ? null : max(0, $age)));
            $add('cron.dispatch', ! empty($environment['disableWpCron']) ? 'unknown' : 'pass', true,
                ! empty($environment['disableWpCron']) ? 'WP-Cron 자동 호출이 꺼져 있습니다. 외부 스케줄러의 실제 실행 기록이 필요합니다.' : 'WP-Cron 자동 호출이 켜져 있습니다. 실제 실행 여부는 운영 검수에서 확인해야 합니다.',
                array('disableWpCron' => (bool) $environment['disableWpCron'], 'alternateWpCron' => (bool) $environment['alternateWpCron']));
            foreach (array('home', 'siteurl') as $name) {
                $url = is_string($options[$name] ?? null) ? $options[$name] : '';
                $parts = parse_url($url);
                $valid = is_array($parts) && 'https' === strtolower((string) ($parts['scheme'] ?? '')) && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']);
                $add('https.' . $name, $valid ? 'pass' : 'fail', true, 'HTTPS URL 설정과 호스트 존재 여부입니다. URL은 출력하지 않습니다.');
            }
            $add('php.openssl', ! empty($environment['openssl']) && ! empty($environment['aes256gcm']) ? 'pass' : 'fail', true, '저장 키 보호를 위한 OpenSSL AES-256-GCM 지원이 필요합니다.');
            $add('php.mbstring', ! empty($environment['mbstring']) ? 'pass' : 'warning', false, 'CP949 변환용 mbstring 확장 상태입니다.');
            $add('php.iconv', ! empty($environment['iconv']) ? 'pass' : 'warning', false, 'CP949 변환용 iconv 확장 상태입니다.');
            $add('php.encoding_conversion', ! empty($environment['mbstring']) || ! empty($environment['iconv']) ? 'pass' : 'fail', true, '한글 CSV 변환을 위해 mbstring 또는 iconv가 필요합니다.');
            $memory = self::ini_bytes((string) $environment['memoryLimit']);
            $add('php.cli_memory', null === $memory ? 'unknown' : (-1 === $memory || $memory >= 134217728 ? 'pass' : 'warning'), false,
                '현재 CLI 프로세스 메모리 한도입니다. 기본 16MB 입출력을 위해 웹 PHP는 최소 128MiB, 운영 시 256MiB 이상을 권장합니다.', array('bytes' => $memory, 'recommendedBytes' => 268435456));
            $post = self::ini_bytes((string) $environment['postMaxSize']);
            $upload = self::ini_bytes((string) $environment['uploadMaxFilesize']);
            $postReady = null !== $post && (0 === $post || -1 === $post || $post >= self::NOTEBOOK_BYTES + 65536);
            $uploadReady = null !== $upload && $upload >= self::DATASET_BYTES && ! empty($environment['fileUploads']);
            $add('php.cli_request_limits', $postReady && $uploadReady ? 'pass' : 'warning', false,
                'CLI 설정 참고값입니다. 파일 업로드 한도는 낮춰 운영할 수 있지만 노트북·프록시 요청 크기는 실제 웹 경로에서 별도 확인해야 합니다.',
                array('postMaxBytes' => $post, 'uploadMaxBytes' => $upload, 'fileUploads' => (bool) $environment['fileUploads'], 'defaultNotebookBytes' => self::NOTEBOOK_BYTES, 'defaultDatasetBytes' => self::DATASET_BYTES));
            $credentials = $options['oj_code_lab_kaggle_credentials'] ?? null;
            $legacy = ! is_array($credentials);
            if ($legacy) { $credentials = array('username' => $options['oj_code_lab_kaggle_username'] ?? '', 'key' => $options['oj_code_lab_kaggle_key'] ?? ''); }
            $usernameSet = is_string($credentials['username'] ?? null) && '' !== $credentials['username'];
            $keySet = is_string($credentials['key'] ?? null) && '' !== $credentials['key'];
            $configured = $usernameSet && $keySet;
            $add('kaggle.credentials', $configured ? ($legacy || ! str_starts_with($credentials['key'], 'v2:') ? 'warning' : 'pass') : 'warning', false,
                '설정 존재 여부만 확인합니다. 복호화·실계정 인증은 수행하지 않으며 사용자 이름·키는 출력하지 않습니다.',
                array('recordPresent' => $configured, 'partialRecord' => $usernameSet !== $keySet, 'legacyFormat' => $configured && ($legacy || ! str_starts_with($credentials['key'], 'v2:'))));
            $add('kaggle.live_acceptance', 'unknown', false, 'Kaggle을 사용할 경우 실계정 검색·CSV 가져오기와 인증 실패 복구를 별도로 검증해야 합니다.');
        } catch (Throwable $error) {
            // Database/library exceptions may contain passwords, SQL or source URLs.
            $add('execution.exception', 'fail', true, '점검 중 예외가 발생했습니다. 비밀정보 보호를 위해 원문은 출력하지 않습니다.');
        } finally { $db->suppress_errors($previous); }
        $add('web.configuration', 'unknown', true, '웹 PHP 메모리·업로드·본문 한도, 실제 프록시 요청 한도, 적용된 데이터 상한 필터를 이 CLI 검사로 확인할 수 없습니다.');
        $add('web.browser_acceptance', 'unknown', true, '운영 HTTPS 경로의 COOP/COEP/CORP·CDN 접근, input·중단·재시작·저장 충돌·교사 권한을 실제 브라우저에서 검증해야 합니다.');
        $add('operations.backup_restore', 'unknown', true, '백업 생성과 격리 환경 복구 성공 증빙이 필요합니다. 이 도구는 백업이나 데이터 변경을 수행하지 않습니다.');
        return self::report($checks, $environment);
    }

    private static function report(array $checks, array $environment): array {
        $failed = array(); $unknown = array(); $warnings = array();
        foreach ($checks as $check) {
            if ($check['required'] && 'fail' === $check['status']) { $failed[] = $check['id']; }
            if ($check['required'] && 'unknown' === $check['status']) { $unknown[] = $check['id']; }
            if ('warning' === $check['status']) { $warnings[] = $check['id']; }
        }
        return array(
            'reportVersion' => 1, 'generatedAt' => gmdate('c', (int) ($environment['now'] ?? time())),
            'scope' => 'installed-site-read-only-cli-preflight', 'sapi' => (string) ($environment['sapi'] ?? 'unknown'),
            'preflightPassed' => ! $failed, 'productionReady' => $failed ? false : 'unknown',
            'requiredFailures' => $failed, 'requiredUnknowns' => $unknown, 'warnings' => $warnings,
            'limitations' => array('검사 자체는 SELECT/SHOW와 획득 후 해제하는 GET_LOCK만 사용합니다.', 'WordPress/WP-CLI·MU 플러그인 부팅의 부수효과는 범위 밖입니다.', '설치 메타데이터 검사는 Addon Runtime 계약의 실제 수락을 증명하지 않습니다.', '라이선스·샘플 재배포 권한 및 릴리스 승인 판단은 이 기술 검사에 포함하지 않습니다.'),
            'checks' => $checks,
        );
    }
}

if (! defined('OJ_CODE_LAB_PREFLIGHT_LIBRARY_ONLY')) {
    if ('cli' !== PHP_SAPI) { http_response_code(403); exit; }
    if (! defined('WP_CLI') || ! WP_CLI || ! isset($GLOBALS['wpdb'])) {
        fwrite(STDERR, "Run using wp --skip-plugins --skip-themes eval-file operations-preflight.php\n");
        exit(1);
    }
    $report = Oj_Code_Lab_Operations_Preflight::run($GLOBALS['wpdb'], Oj_Code_Lab_Operations_Preflight::collect_environment());
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    if (! $report['preflightPassed']) { exit(1); }
}
