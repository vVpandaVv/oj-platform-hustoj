<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

use InvalidArgumentException;
use OjMicrobit\Storage\Installer;

final class Settings
{
    public const OPTION = 'oj_microbit_settings';
    public static function register(): void
    {
        add_action('admin_menu', static fn () => add_options_page('OJ Microbit 설정', 'OJ Microbit', 'manage_options', 'oj-microbit', [self::class, 'render']));
        add_action('admin_init', static fn () => register_setting('oj_microbit', self::OPTION, ['type' => 'array', 'sanitize_callback' => [self::class, 'sanitize']]));
        add_filter('site_status_tests', [self::class, 'healthTests']);
    }
    public static function get(): array
    {
        $value = get_option(self::OPTION, []);
        return array_replace(['runnerUrl' => '', 'runnerToken' => '', 'retentionDays' => 365, 'deleteOnUninstall' => false], is_array($value) ? $value : []);
    }
    public static function connection(): array
    {
        $value = self::get();
        return [
            'url' => defined('OJ_MICROBIT_RUNNER_URL') ? (string) OJ_MICROBIT_RUNNER_URL : $value['runnerUrl'],
            'token' => defined('OJ_MICROBIT_RUNNER_TOKEN') ? (string) OJ_MICROBIT_RUNNER_TOKEN : $value['runnerToken'],
        ];
    }
    public static function configured(): bool
    {
        $value = self::connection();
        return $value['url'] !== '' && strlen($value['token']) >= 32;
    }
    public static function validateUrl(string $value): string
    {
        $value = rtrim(trim($value), '/');
        if ($value === '') { return ''; }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new InvalidArgumentException('실행 서비스의 HTTP 또는 HTTPS 주소를 확인해 주세요.');
        }
        // Credentials are sent only to this administrator-selected origin; redirects are disabled.
        if ($parts['scheme'] === 'http' && !in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]', '::1', 'microbit-runner'], true)) {
            throw new InvalidArgumentException('원격 실행 서비스에는 HTTPS 주소를 사용해 주세요.');
        }
        return $value;
    }
    public static function sanitize(mixed $input): array
    {
        $old = self::get();
        if (!is_array($input)) { return $old; }
        try {
            $url = self::validateUrl(is_string($input['runnerUrl'] ?? null) ? $input['runnerUrl'] : '');
            $token = is_string($input['runnerToken'] ?? null) && $input['runnerToken'] !== '' ? trim($input['runnerToken']) : $old['runnerToken'];
            if ($token !== '' && (strlen($token) < 32 || strlen($token) > 256 || preg_match('/[^\x21-\x7e]/', $token))) {
                throw new InvalidArgumentException('서비스 인증키는 공백 없는 ASCII 32~256자로 설정해 주세요.');
            }
            $value = ['runnerUrl' => $url, 'runnerToken' => $token, 'retentionDays' => max(0, min(3650, (int) ($input['retentionDays'] ?? 365))),
                'deleteOnUninstall' => in_array($input['deleteOnUninstall'] ?? false, [true, 1, '1'], true)];
            if ($value !== $old) {
                do_action('oj_microbit_audit', 'settings_changed', get_current_user_id(), ['configured' => $url !== '' && $token !== '']);
                if (function_exists('oj_log_audit_event')) { oj_log_audit_event('microbit.settings_changed', '마이크로비트 실행 서비스 설정 변경', ['actor_id' => get_current_user_id()]); }
            }
            return $value;
        } catch (InvalidArgumentException $e) { add_settings_error(self::OPTION, 'invalid', $e->getMessage()); return $old; }
    }
    private static function installationCommands(string $pluginRoot, string $wordpressRoot): array
    {
        $normalize = static function (string $path): string {
            if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) { throw new InvalidArgumentException('설치 경로를 확인해 주세요.'); }
            $resolved = realpath($path);
            $path = str_replace('\\', '/', $resolved !== false ? $resolved : $path);
            if (!preg_match('~^(?:/|[A-Za-z]:/)~', $path)) { throw new InvalidArgumentException('절대 설치 경로를 확인해 주세요.'); }
            return $path === '/' || preg_match('~^[A-Za-z]:/$~', $path) ? $path : rtrim($path, '/');
        };
        // POSIX quoting is intentional: escapeshellarg follows the PHP host OS on Windows.
        $quote = static fn (string $path): string => "'" . str_replace("'", "'\"'\"'", $path) . "'";
        $pluginRoot = $normalize($pluginRoot); $wordpressRoot = $normalize($wordpressRoot);
        $install = 'sudo bash ' . $quote(rtrim($pluginRoot, '/') . '/runner/install.sh') . ' --wp-root ' . $quote($wordpressRoot);
        return ['install' => $install, 'check' => $install . ' --check'];
    }
    public static function render(): void
    {
        if (!current_user_can('manage_options')) { wp_die('설정을 변경할 권한이 없습니다.'); }
        $value = self::get();
        echo '<div class="wrap"><h1>OJ Microbit 설정</h1><p>교사 화면: <a href="' . esc_url(home_url('/oj-console/addons/oj-microbit'))
            . '">마이크로비트 과제</a> · 학생 화면: <a href="' . esc_url(home_url('/oj-addons/oj-microbit/learn')) . '">마이크로비트 실습</a></p>';
        settings_errors(self::OPTION);
        echo '<h2>실행 서비스 설치</h2><p>센서 시뮬레이션과 자동 채점에는 WordPress와 별도로 전용 실행 서비스가 필요합니다. WordPress와 같은 Ubuntu 24.04 amd64·systemd 서버의 관리자 SSH 터미널에서 아래 명령을 한 번 실행하세요.</p>'
            . '<p id="oj-microbit-install-help">이 웹 화면은 복사할 명령만 표시합니다. 설치와 관리자 권한 명령은 서버 SSH에서 실행하며, 웹 요청으로 실행하지 않습니다. 입력칸의 내용을 전체 선택한 뒤 복사하세요.</p>';
        try {
            $commands = self::installationCommands(dirname(OJ_MICROBIT_FILE), defined('ABSPATH') ? (string) ABSPATH : '');
            echo '<p><label for="oj-microbit-install-command"><strong>설치·자동 연결 명령</strong></label></p><p><input id="oj-microbit-install-command" type="text" class="large-text code" readonly spellcheck="false" aria-describedby="oj-microbit-install-help" value="'
                . esc_attr($commands['install']) . '"></p><p class="description">의존성과 실행 서비스를 설치하고 이 WordPress에 연결합니다. 인증키는 설치 과정에서 자동으로 관리하며 명령에 입력하지 않습니다. WordPress 계정은 자동 판별하며 root 소유 파일은 www-data를 사용합니다. 실제 사이트 계정이 다르면 --wp-user 옵션으로 지정하세요.</p>'
                . '<p><label for="oj-microbit-check-command"><strong>설치 상태 진단 명령</strong></label></p><p><input id="oj-microbit-check-command" type="text" class="large-text code" readonly spellcheck="false" aria-describedby="oj-microbit-install-help" value="'
                . esc_attr($commands['check']) . '"></p><p class="description">명령 실행 뒤 이 페이지를 새로고침해 아래 실행 서비스 상태를 확인하세요. 다른 서버를 사용하거나 기존 연결을 직접 설정하려면 다음 항목을 사용합니다.</p>';
        } catch (InvalidArgumentException) { echo '<p>WordPress 설치 경로를 확인한 뒤 설치 안내서의 서버 명령을 사용해 주세요.</p>'; }
        echo '<h2>실행 서비스 연결 설정</h2>';
        echo '<form action="options.php" method="post">'; settings_fields('oj_microbit');
        echo '<table class="form-table"><tr><th><label for="oj-microbit-url">실행 서비스 주소</label></th><td><input id="oj-microbit-url" type="url" class="regular-text" name="'
            . self::OPTION . '[runnerUrl]" value="' . esc_attr($value['runnerUrl']) . '" placeholder="http://127.0.0.1:3037">'
            . '<p class="description">같은 서버의 실행 서비스 또는 HTTPS 주소를 입력합니다. 학생에게 이 주소와 인증키를 전달하지 않습니다.</p></td></tr>'
            . '<tr><th><label for="oj-microbit-token">실행 서비스 인증키</label></th><td><input id="oj-microbit-token" type="password" autocomplete="new-password" class="regular-text" name="'
            . self::OPTION . '[runnerToken]" value=""><p class="description">32자 이상. 비워 두면 저장된 키를 유지합니다. wp-config.php 상수로도 설정할 수 있습니다.</p></td></tr>'
            . '<tr><th><label for="oj-microbit-retention">학생 기록 보관 기간</label></th><td><input id="oj-microbit-retention" type="number" min="0" max="3650" name="'
            . self::OPTION . '[retentionDays]" value="' . (int) $value['retentionDays'] . '"> 일 <p class="description">기본 365일. 0은 기간 제한 없이 보관합니다. 과제는 보관 기간과 관계없이 유지합니다.</p></td></tr>'
            . '<tr><th>플러그인 삭제 시</th><td><input type="hidden" name="' . self::OPTION . '[deleteOnUninstall]" value="0"><label><input type="checkbox" name="'
            . self::OPTION . '[deleteOnUninstall]" value="1"' . checked($value['deleteOnUninstall'], true, false) . '> 과제·프로젝트·채점 기록·설정도 제거</label></td></tr></table>';
        submit_button('설정 저장'); echo '</form>';
        $status = (new HttpRunner())->status();
        echo '<h2>실행 서비스 상태</h2><p>' . esc_html($status['message']) . '</p><p>실행 버전: ' . esc_html($status['runtimeVersion'] ?? '확인되지 않음')
            . '</p><p>데이터베이스: ' . (get_option('oj_microbit_schema_version') === Installer::SCHEMA_VERSION ? '준비됨' : '설치 상태 확인 필요') . '</p></div>';
    }
    public static function healthTests(array $tests): array
    {
        $tests['direct']['oj_microbit_runner'] = ['label' => '마이크로비트 실행 서비스', 'test' => static function (): array {
            $status = (new HttpRunner())->status();
            return ['label' => '마이크로비트 실행 서비스', 'status' => ($status['ready'] ?? false) ? 'good' : 'recommended',
                'badge' => ['label' => 'OJ Microbit', 'color' => 'blue'], 'description' => '<p>' . esc_html($status['message']) . '</p>',
                'actions' => '<p><a href="' . esc_url(admin_url('options-general.php?page=oj-microbit')) . '">연결 설정</a></p>', 'test' => 'oj_microbit_runner'];
        }];
        $tests['direct']['oj_microbit_storage'] = ['label' => '마이크로비트 저장소와 정리 작업', 'test' => static function (): array {
            $ready = get_option('oj_microbit_schema_version') === Installer::SCHEMA_VERSION;
            $failed = get_option('oj_microbit_cleanup_error', false) || get_option('oj_microbit_privacy_cleanup_error', false) || get_option('oj_microbit_uninstall_error', false);
            return ['label' => '마이크로비트 저장소와 정리 작업', 'status' => $ready && !$failed ? 'good' : 'recommended',
                'badge' => ['label' => 'OJ Microbit', 'color' => 'blue'],
                'description' => '<p>' . ($ready ? ($failed ? '자료 정리·개인정보 삭제 또는 플러그인 자료 제거에 오류가 있습니다. DB 권한과 WP-Cron을 확인해 주세요.' : '필수 스키마가 설치되어 있고 최근 정리 오류가 없습니다.') : '플러그인을 활성화하고 데이터베이스 스키마 업그레이드를 확인해 주세요.') . '</p>',
                'actions' => '', 'test' => 'oj_microbit_storage'];
        }];
        return $tests;
    }
}
