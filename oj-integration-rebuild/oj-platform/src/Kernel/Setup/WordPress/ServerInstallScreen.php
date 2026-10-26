<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup\WordPress;

/** Server preparation is available even when an add-on cannot activate. */
final class ServerInstallScreen
{
    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_submenu_page('', 'OJ 서버 설치', 'OJ 서버 설치', 'install_plugins', 'oj-server-install', [self::class, 'render']);
        });
        add_action('admin_notices', static function (): void {
            if (! current_user_can('install_plugins') || ! function_exists('get_current_screen')
                || get_current_screen()?->id !== 'plugins') {
                return;
            }
            $pending = self::pending();
            if ($pending !== []) {
                echo '<div class="notice notice-warning"><p><strong>서버 준비가 필요한 플러그인이 있습니다.</strong> ';
                foreach ($pending as $item) {
                    echo '<a href="' . esc_url($item['url']) . '">' . esc_html($item['title']) . ' 설치 안내</a> ';
                }
                echo '</p></div>';
            }
        });
        foreach (['oj-ai-dashboard/oj-ai-dashboard.php', 'oj-ml-playgrounds/oj-ml-playgrounds.php'] as $pluginFile) {
            add_filter('plugin_action_links_' . $pluginFile, static function (array $links) use ($pluginFile): array {
                if (! current_user_can('install_plugins')) { return $links; }
                foreach (self::pending() as $item) {
                    if (str_starts_with($pluginFile, $item['id'] . '/')) {
                        $links[] = '<a href="' . esc_url($item['url']) . '">서버 준비 필요</a>';
                    }
                }
                return $links;
            });
        }
        add_action('admin_enqueue_scripts', static function ($hook): void {
            if ($hook === 'plugins.php') {
                wp_enqueue_style('oj-platform-plugin-list', plugins_url('resources/admin/plugin-list.css', OJ_PLATFORM_FILE), [], OJ_PLATFORM_VERSION);
                return;
            }
            if ($hook !== 'admin_page_oj-server-install') {
                return;
            }
            wp_enqueue_style('oj-server-install', plugins_url('resources/ui/css/oj-ui-system.css', OJ_PLATFORM_FILE), [], OJ_PLATFORM_VERSION);
            wp_enqueue_script('oj-server-install', plugins_url('resources/ui/js/oj-ui.js', OJ_PLATFORM_FILE), [], OJ_PLATFORM_VERSION, true);
        });
    }

    /** @return list<array{id:string,title:string,reason:string,url:string}> */
    public static function pending(): array
    {
        $pluginRoot = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : dirname(rtrim(OJ_PLATFORM_PATH, '/\\'));
        $active = function_exists('get_option') ? (array) get_option('active_plugins', []) : [];
        $items = [];
        foreach ([
            'ai' => ['oj-ai-dashboard', 'AI 활동지', 'OJ_AI_PRIVATE_STORAGE_DIR'],
            'ml' => ['oj-ml-playgrounds', 'ML 실습', 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR'],
        ] as $task => [$id, $title, $constant]) {
            if (! is_file($pluginRoot . '/' . $id . '/resources/server/install.sh')) { continue; }
            $reasons = [];
            $path = defined($constant) ? (string) constant($constant) : '';
            $resolved = $path !== '' ? realpath($path) : false;
            $webRoot = realpath(ABSPATH);
            if ($resolved === false || ! is_dir($resolved) || ! is_writable($resolved)
                || ($webRoot !== false && ($resolved === $webRoot || str_starts_with($resolved . DIRECTORY_SEPARATOR, $webRoot . DIRECTORY_SEPARATOR)))) {
                $reasons[] = '비공개 저장소 준비가 필요합니다.';
            }
            if ($task === 'ai') {
                foreach (['dom', 'fileinfo', 'intl', 'mbstring', 'mysqli', 'openssl', 'xmlwriter', 'zip'] as $extension) {
                    if (! extension_loaded($extension)) { $reasons[] = '필수 PHP 확장 준비가 필요합니다.'; break; }
                }
            } elseif (PHP_VERSION_ID < 80300) {
                $reasons[] = 'PHP 8.3 이상이 필요합니다.';
            }
            if ($reasons === []) { continue; }
            $isActive = in_array($id . '/' . $id . '.php', $active, true);
            $items[] = ['id' => $id, 'title' => $title, 'reason' => implode(' ', $reasons),
                'url' => $isActive ? home_url('/oj-console/addons/' . $id . '/settings')
                    : admin_url('admin.php?page=oj-server-install&task=' . $task)];
        }
        return $items;
    }

    public static function render(): void
    {
        if (! current_user_can('install_plugins')) {
            wp_die('서버 설치 권한이 필요합니다.');
        }
        $tasks = [
            'ai' => ['AI 활동지', '비공개 저장소와 PHP 확장을 준비합니다.', 'oj-ai-dashboard'],
            'ml' => ['ML 실습', '비공개 저장소를 준비합니다.', 'oj-ml-playgrounds'],
            'readcontents' => ['읽기자료', '서버 ZIP 가져오기 폴더를 준비합니다.', 'readcontents-library'],
            'capture' => ['그림 채점', '정답 그림 캡처 폴더를 준비합니다.', 'oj-platform'],
            'clamav' => ['첨부파일 검사', 'ClamAV 서비스와 연결 설정을 준비합니다.', 'oj-ai-dashboard'],
        ];
        $pluginRoot = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : dirname(rtrim(OJ_PLATFORM_PATH, '/\\'));
        $selected = isset($_GET['task']) && is_string($_GET['task']) ? $_GET['task'] : '';
        echo '<div class="wrap ojp-shell"><h1>서버 설치</h1><p>처음 한 번, 필요한 항목의 명령을 서버 SSH에서 실행하세요.</p>';
        echo '<p>업로드된 플러그인만 표시합니다. AI·ML은 <strong>활성화 전에</strong> 준비합니다. 실행 후 이 화면을 새로고침하세요.</p>';
        foreach ($tasks as $task => [$title, $description, $owner]) {
            $installer = $owner === 'oj-platform' ? OJ_PLATFORM_PATH . 'resources/server/install.sh' : $pluginRoot . '/' . $owner . '/resources/server/install.sh';
            if (! is_file($installer)) {
                continue;
            }
            $command = 'sudo bash ' . self::quote($installer) . ' ' . self::quote($task)
                . ' ' . self::quote(rtrim(ABSPATH, '/')) . ' ' . self::quote(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) . ' --apply';
            echo '<section class="ojp-section" id="server-' . esc_attr($task) . '"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p>';
            $constant = ['ai' => 'OJ_AI_PRIVATE_STORAGE_DIR', 'ml' => 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR'][$task] ?? null;
            if ($constant !== null) {
                $ready = defined($constant) && is_dir((string) constant($constant)) && is_writable((string) constant($constant));
                echo '<p role="status">' . ($ready ? '저장소 준비됨' : '저장소 준비 필요') . '</p>';
            }
            echo '<details' . ($selected === $task ? ' open' : '') . '><summary>설치 명령</summary><p>WordPress와 같은 Ubuntu/Debian 서버에서 실행합니다.</p>'
                . '<textarea class="ojp-textarea" id="server-command-' . esc_attr($task) . '" readonly rows="3" aria-label="' . esc_attr($title . ' 설치 명령') . '">' . esc_textarea($command) . '</textarea>'
                . '<button type="button" class="ojp-button" data-ojp-copy-target="server-command-' . esc_attr($task) . '">명령 복사</button>'
                . '<details><summary>변경 내용</summary><p>기존 설정이 다르면 중단합니다. wp-config.php 변경 전 백업을 남기며, 기존 자료의 소유권은 일괄 변경하지 않습니다.</p>'
                . '<p>ClamAV는 서비스 설치·시작까지 시간이 걸릴 수 있습니다. 필요한 PHP CLI는 자동 준비합니다. 웹 PHP 버전은 그대로 유지합니다.</p></details></details></section>';
        }
        echo '<p>실시간 서버는 교실 도구·코드 아레나 설정의 <strong>서버 설치·복구</strong>에서 진행하세요.</p></div>';
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }
}
