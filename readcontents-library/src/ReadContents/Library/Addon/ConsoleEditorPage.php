<?php
/**
 * Shared OJ console response for the Plate content workspace.
 *
 * @package ReadContentsLibrary\Addon
 */

declare(strict_types=1);

namespace ReadContents\Library\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use ReadContents\Library\Editor\Migration\ContentReimportService;

final class ConsoleEditorPage
{
    public static function response(string $title = '읽기자료'): AddonPageResponseV1
    {
        $max_upload_size = wp_max_upload_size();
        $script_url = add_query_arg(
            'ver',
            rawurlencode(READCONTENTS_LIBRARY_VERSION),
            READCONTENTS_LIBRARY_URL . 'assets/editor/admin.js'
        );

        $html = sprintf(
            '<div id="contents-editor-admin-root" class="rcis-editor-root" data-rest-url="%1$s" data-rest-namespace="%2$s" data-rest-nonce="%3$s" data-version="%4$s" data-max-upload-size="%5$d" data-max-upload-size-label="%6$s" data-public-base-url="%7$s" data-can-publish="%8$s" data-can-reimport-json="%10$s" data-can-import-server="%11$s"><div class="rcis-editor-loading" role="status">읽기자료 편집기를 불러오는 중입니다.</div></div><div id="contents-editor-overlay-root"></div><script type="module" src="%9$s"></script>',
            esc_attr(rest_url()),
            esc_attr('readcontents-library/v1'),
            esc_attr(wp_create_nonce('wp_rest')),
            esc_attr(READCONTENTS_LIBRARY_VERSION),
            $max_upload_size,
            esc_attr(size_format($max_upload_size)),
            esc_attr(home_url(AddonRoutes::BASE_PATH)),
            esc_attr(current_user_can(
                AddonCapabilityKeyV1::wordpress(AddonRoutes::CAPABILITY_PUBLISH)
            ) ? '1' : '0'),
            esc_url($script_url),
            ContentReimportService::hasLegacySources() ? '1' : '0',
            current_user_can('manage_options') && current_user_can('upload_files') &&
                current_user_can(AddonCapabilityKeyV1::wordpress(AddonRoutes::CAPABILITY_EDIT)) &&
                current_user_can(AddonCapabilityKeyV1::wordpress(AddonRoutes::CAPABILITY_PUBLISH)) ? '1' : '0'
        );

        if (current_user_can('install_plugins')) {
            $html = '<details class="ojp-section"><summary>서버 ZIP 가져오기 준비</summary>' . self::server_install_markup() . '</details>' . $html;
        }

        // The editor is an immersive screen (D-010 5장): the shell folds its
        // sidebar away and gives the workspace the full width.
        return PageResponse::immersive($title, $html);
    }

    private function __construct()
    {
    }

    /** The installer belongs to this add-on; the platform only supplies modal UI. */
    private static function server_install_markup(): string
    {
        $quote = static fn (string $value): string => "'" . str_replace("'", "'\"'\"'", $value) . "'";
        $installer = dirname(__DIR__, 4) . '/resources/server/install.sh';
        $id = 'readcontents-library-server-install';
        $commands = [];
        $commands['가져오기 폴더 준비'] = 'sudo bash ' . $quote($installer) . ' ' . $quote('readcontents') . ' ' . $quote(rtrim(ABSPATH, '/\\')) . ' ' . $quote(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) . ' --apply';
        $content = '';
        $fallback = '';
        $index = 0;
        foreach ($commands as $label => $command) {
            $commandId = $id . '-command-' . $index;
            $part = '<p>' . esc_html($label) . '</p><textarea class="ojp-textarea" id="' . esc_attr($commandId) . '" rows="3" readonly aria-label="' . esc_attr($label) . '">' . esc_textarea($command) . '</textarea>'
                . '<button type="button" class="ojp-button" data-ojp-copy-target="' . esc_attr($commandId) . '">명령 복사</button>';
            $content .= $index > 0 ? '<details><summary>' . esc_html($label) . '</summary>' . $part . '</details>' : $part;
            $fallback .= '<p>' . esc_html($label) . '</p><textarea class="ojp-textarea" rows="3" readonly aria-label="' . esc_attr($label) . '">' . esc_textarea($command) . '</textarea>';
            $index++;
        }
        return '<section class="ojp-section"><h2>서버 설치</h2><p>처음 사용할 때 서버 SSH에서 한 번 실행하세요.</p>'
            . '<button type="button" class="ojp-button" style="display:none" hidden data-ojp-server-open="' . esc_attr($id) . '">서버 설치·복구</button>'
            . '<details data-ojp-server-fallback="' . esc_attr($id) . '"><summary>서버 설치 명령</summary>' . $fallback . '</details>'
            . '<dialog class="ojp-server-install" id="' . esc_attr($id) . '" aria-labelledby="' . esc_attr($id . '-title') . '"><h2 id="' . esc_attr($id . '-title') . '">서버 설치·복구</h2>'
            . '<p>명령 복사 → 같은 서버의 SSH에서 실행 → 상태 확인</p>' . $content
            . '<details><summary>변경 내용</summary><p>이 플러그인의 설치기만 실행합니다. 기존 설정과 충돌하면 중단하고, 설정 파일은 변경 전에 백업합니다. PHP-FPM이 재시작될 수 있으므로 수업이 없는 시간에 실행하세요.</p></details>'
            . '<button type="button" class="ojp-button" data-ojp-server-close>닫기</button></dialog></section>';
    }
}
