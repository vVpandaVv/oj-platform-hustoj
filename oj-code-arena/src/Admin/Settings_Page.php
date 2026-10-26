<?php
declare(strict_types=1);

namespace OjCodeArena\Admin;

use OjCodeArena\Addon\Oj_Platform_Registration;
use OjCodeArena\Support\Capabilities;
use OjCodeArena\Support\Server_Env;
use OjCodeArena\Support\Settings;

/**
 * Settings handlers behind the OJ console page 교사 메뉴 › 코드 아레나 ›
 * 설정 (Addon\Settings_Page_Handler). There is no wp-admin settings screen:
 * the plugin-row link and the activation notice go to the console page.
 * The admin-post handlers below (save, .env download) allow site admins
 * and educators with the "코드 아레나 설정" capability, and keep their nonces.
 */
final class Settings_Page {
    public const WELCOME = 'oj_code_arena_welcome';

    public static function register(): void {
        add_action('admin_post_oj_code_arena_save', array(__CLASS__, 'save'));
        add_action('admin_post_oj_code_arena_env', array(__CLASS__, 'download_env'));
        add_action('admin_notices', array(__CLASS__, 'welcome'));
        add_filter('plugin_action_links_' . plugin_basename(OJ_CODE_ARENA_FILE), array(__CLASS__, 'action_links'));
    }

    /** @param array<string,string> $links */
    public static function action_links(array $links): array {
        array_unshift($links, '<a href="' . esc_url(self::console_url()) . '">' . esc_html__('설정', 'oj-code-arena') . '</a>');
        return $links;
    }

    /** The settings page inside the OJ console. */
    public static function console_url(array $args = array()): string {
        return add_query_arg($args, home_url(Oj_Platform_Registration::SETTINGS_PATH));
    }

    /** One-time "ready to use" notice after activation. */
    public static function welcome(): void {
        if (! current_user_can('manage_options') || ! get_option(self::WELCOME)) {
            return;
        }
        delete_option(self::WELCOME);
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong><?php esc_html_e('코드 아레나를 바로 쓸 수 있어요!', 'oj-code-arena'); ?></strong>
                <?php esc_html_e('따로 설치할 서버가 없어요. 기본 문제 230여 개와 학생·교사 권한도 준비해 두었어요.', 'oj-code-arena'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(home_url(Oj_Platform_Registration::CONSOLE_BASE)); ?>"><?php esc_html_e('교사: 수업 게임 만들기', 'oj-code-arena'); ?></a>
                <a class="button" href="<?php echo esc_url(home_url(Oj_Platform_Registration::PLAY_PATH)); ?>"><?php esc_html_e('학생 화면 보기', 'oj-code-arena'); ?></a>
                <a class="button" href="<?php echo esc_url(self::console_url()); ?>"><?php esc_html_e('설정', 'oj-code-arena'); ?></a>
            </p>
        </div>
        <?php
    }

    public static function save(): void {
        if (! Capabilities::can_manage_settings()) {
            wp_die(esc_html__('코드 아레나 설정 권한이 없습니다.', 'oj-code-arena'), '', array('response' => 403));
        }
        check_admin_referer('oj_code_arena_save');
        $values = array(
            'engine' => isset($_POST['engine']) && Settings::ENGINE_NODE === $_POST['engine'] ? Settings::ENGINE_NODE : Settings::ENGINE_BUILTIN,
            'public_url' => isset($_POST['public_url']) ? sanitize_text_field(wp_unslash($_POST['public_url'])) : '',
            'api_url' => isset($_POST['api_url']) ? sanitize_text_field(wp_unslash($_POST['api_url'])) : '',
            'free_enabled' => ! empty($_POST['free_enabled']),
            'keep_days' => isset($_POST['keep_days']) ? absint($_POST['keep_days']) : 0,
        );
        if (! empty($_POST['new_secret'])) {
            $values['secret'] = Settings::new_secret();
        }
        Settings::save($values);
        wp_safe_redirect(self::console_url(array('saved' => '1')));
        exit;
    }

    /** @return array<string,string> */
    public static function env(): array {
        $s = Settings::all();
        return Server_Env::build($s['secret'], $s['public_url'], $s['api_url'], untrailingslashit(rest_url('oj-code-arena/v1')), home_url());
    }

    // Sends the ready-made .env file. It is saved as oj-code-arena.env, which
    // the game server also reads, so it needs no renaming.
    public static function download_env(): void {
        if (! Capabilities::can_manage_settings()) {
            wp_die(esc_html__('코드 아레나 설정 권한이 없습니다.', 'oj-code-arena'), '', array('response' => 403));
        }
        check_admin_referer('oj_code_arena_env');
        Settings::ensure_secret();
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="oj-code-arena.env"');
        echo Server_Env::text(self::env()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}
