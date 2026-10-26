<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use OjCodeArena\Support\Settings;

/** Markup helpers shared by the page handlers. */
final class Page_Config {
    /** @return array<string,mixed> */
    public static function base(): array {
        return array(
            'restUrl' => esc_url_raw(untrailingslashit(rest_url('oj-code-arena/v1'))),
            'nonce' => wp_create_nonce('wp_rest'),
        );
    }

    /** @param array<string,mixed> $config */
    public static function mount(string $attr, array $config): string {
        $notice = '';
        if (! Settings::configured()) {
            $notice = '<div class="ojp-notice ojp-notice--warning oca-setup-notice" role="alert"><strong>'
                . esc_html__('게임 서버 주소가 없습니다', 'oj-code-arena') . '</strong><p>'
                . esc_html__('Node 게임 서버를 쓰도록 설정했지만 서버 주소가 비어 있습니다. 사이트 관리자나 설정 권한을 받은 교사가 교사 메뉴 › 코드 아레나 › 설정에서 주소를 넣거나 내장 엔진으로 바꿔야 합니다.', 'oj-code-arena')
                . '</p></div>';
        }
        return $notice . '<div ' . $attr . ' data-config="' . esc_attr((string) wp_json_encode($config, JSON_UNESCAPED_UNICODE)) . '"></div>';
    }

    public static function console_url(string $suffix = ''): string {
        return home_url(Oj_Platform_Registration::CONSOLE_BASE . $suffix);
    }
}
