<?php
declare(strict_types=1);

namespace TeachingTools\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Support\Capabilities;
use TeachingTools\Support\Realtime_Env;
use TeachingTools\Support\Realtime_Settings;
use TeachingTools\Support\Realtime_Versions;
use TeachingTools\Addon\Seating\Runtime_Bridge;

final class Settings_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $title = __('교실 도구 설정', 'teaching-tools');
        if (! Capabilities::can_manage_settings()) {
            $body = '<div class="htt-console"><div class="ojp-empty-state"><div><strong>'
                . esc_html__('설정 권한이 필요합니다', 'teaching-tools') . '</strong><p>'
                . esc_html__('사이트 관리자나 교실 도구 설정 권한을 받은 교사만 실시간 서버를 설정할 수 있습니다.', 'teaching-tools')
                . '</p></div></div></div>';

            return Ui::page($title, $body, 'standard', true);
        }

        Realtime_Settings::ensure_secret();
        $settings = Realtime_Settings::all();
        $versions = Realtime_Versions::bundled();
        $setup_code = Realtime_Env::install_code(Realtime_Env::build());
        $installer = str_replace("'", "'\"'\"'", TEACHING_TOOLS_PATH . 'realtime-server/install.sh');
        $command = "sudo bash '" . $installer . "'";
        $update_command = $command . ' --update';
        $saved = isset($request->query()['saved']);
        $retention_days = max(1, min(365, (int) get_option('teaching_tools_retention_days', 30)));
        $active_session_hours = max(1, min(168, (int) get_option('teaching_tools_active_session_hours', 12)));
        $privacy_path = Runtime_Bridge::integrations()?->privacyManagementPath() ?? '/oj-console/agreements';
        $config = array(
            'restUrl' => untrailingslashit(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'checkOnOpen' => $settings['enabled'],
        );

        ob_start();
        ?>
        <div class="htt-console htt-realtime-settings" data-htt-host data-htt-realtime-settings data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <header class="ojp-page-header htt-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('교실 도구', 'teaching-tools'); ?></p>
                    <h1><?php esc_html_e('실시간 전송', 'teaching-tools'); ?></h1>
                    <p><?php esc_html_e('판서 좌표를 WebSocket으로 즉시 전송하고, 연결이 끊기면 기존 방식으로 자동 전환합니다.', 'teaching-tools'); ?></p>
                </div>
            </header>

            <?php if ($saved) : ?>
                <div class="ojp-notice ojp-notice--success" role="status"><strong><?php esc_html_e('설정을 저장했습니다.', 'teaching-tools'); ?></strong></div>
            <?php endif; ?>

            <section class="htt-section" aria-labelledby="htt-version-title">
                <h2 id="htt-version-title"><?php esc_html_e('설치 버전', 'teaching-tools'); ?></h2>
                <dl class="htt-realtime-versions">
                    <dt>티칭툴 플러그인</dt><dd><?php echo esc_html($versions['plugin']); ?></dd>
                    <dt>서버의 티칭툴 모듈</dt><dd><span data-htt-version="module">확인 전</span> · 동봉 버전 <?php echo esc_html($versions['module']); ?></dd>
                    <dt>공통 실시간 서버</dt><dd><span data-htt-version="core">확인 전</span> · 동봉 버전 <?php echo esc_html($versions['core']); ?></dd>
                </dl>
                <p>업데이트 여부는 현재 플러그인에 동봉된 서버 코드와 비교합니다. 연결 실패 시에는 최신 여부를 확인할 수 없습니다.</p>
                <button type="button" class="ojp-button" data-htt-realtime-status>버전·연결 다시 확인</button>
                <div data-htt-realtime-result role="status"></div>
                <p data-htt-update-needed hidden>서버 업데이트가 필요합니다. 아래 <a href="#htt-update-command">기존 설정 유지 업데이트 명령</a>을 서버 SSH에서 실행한 뒤 다시 확인하세요.</p>
            </section>

            <form class="ojp-form-stack" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="teaching_tools_realtime_save">
                <?php wp_nonce_field('teaching_tools_realtime_save'); ?>

                <fieldset class="ojp-fieldset">
                    <legend><?php esc_html_e('실시간 연결', 'teaching-tools'); ?></legend>
                    <label class="ojp-toggle-row">
                        <input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?>>
                        <span><?php esc_html_e('WebSocket 실시간 전송 사용', 'teaching-tools'); ?></span>
                    </label>
                    <div class="ojp-field">
                        <label for="htt-realtime-url"><?php esc_html_e('브라우저 접속 주소', 'teaching-tools'); ?></label>
                        <input class="ojp-input" id="htt-realtime-url" name="public_url" type="text" value="<?php echo esc_attr($settings['public_url']); ?>" placeholder="wss://oj.school.kr/classroom-live">
                        <p class="ojp-field-help"><?php esc_html_e('HTTPS 사이트에서는 wss:// 주소를 사용합니다. 기본값을 그대로 사용해도 됩니다.', 'teaching-tools'); ?></p>
                    </div>
                    <label class="ojp-toggle-row">
                        <input type="checkbox" name="new_secret" value="1">
                        <span><?php esc_html_e('저장할 때 새 비밀키 만들기 (서버도 다시 설치해야 합니다)', 'teaching-tools'); ?></span>
                    </label>
                </fieldset>

                <fieldset class="ojp-fieldset">
                    <legend><?php esc_html_e('자료 보존', 'teaching-tools'); ?></legend>
                    <div class="ojp-field">
                        <label for="htt-active-hours"><?php esc_html_e('진행 중 세션 만료 시간', 'teaching-tools'); ?></label>
                        <input class="ojp-input" id="htt-active-hours" name="active_session_hours" type="number" min="1" max="168" value="<?php echo esc_attr((string) $active_session_hours); ?>">
                        <p class="ojp-field-help"><?php esc_html_e('시간 단위이며 새 세션부터 적용됩니다.', 'teaching-tools'); ?></p>
                    </div>
                    <div class="ojp-field">
                        <label for="htt-retention-days"><?php esc_html_e('종료 자료 보존 기간', 'teaching-tools'); ?></label>
                        <input class="ojp-input" id="htt-retention-days" name="retention_days" type="number" min="1" max="365" value="<?php echo esc_attr((string) $retention_days); ?>">
                        <p class="ojp-field-help"><?php esc_html_e('일 단위이며 만료된 세션·응답·판서를 순차 삭제합니다.', 'teaching-tools'); ?></p>
                    </div>
                </fieldset>

                <div>
                    <button type="submit" class="ojp-button ojp-button--primary"><?php esc_html_e('설정 저장', 'teaching-tools'); ?></button>
                </div>
            </form>

            <section class="htt-section">
                <h2><?php esc_html_e('개인정보 안내', 'teaching-tools'); ?></h2>
                <p><?php esc_html_e('개인정보 처리방침 문구와 동의 문서는 이 플러그인에서 별도로 만들지 않습니다. 관리자는 OJ Platform의 통합 동의 문서 화면에서 교실 도구의 처리 항목과 보존기간을 검토해 사이트 문서에 반영하세요.', 'teaching-tools'); ?></p>
                <a class="ojp-button ojp-button--plain" href="<?php echo esc_url(home_url($privacy_path)); ?>"><?php esc_html_e('플랫폼 동의 문서 관리', 'teaching-tools'); ?></a>
            </section>

            <section class="htt-section">
                <h2>서버 설치</h2>
                <p>처음 실시간 전송을 사용한다면 설정 저장 후 서버를 준비하세요.</p>
                <button type="button" class="ojp-button" style="display:none" hidden data-ojp-server-open="htt-server-install">서버 설치·복구</button>
                <details data-ojp-server-fallback="htt-server-install"><summary>서버 설치 명령 (OJ Platform 업데이트 후 설치 창 사용 가능)</summary><p>같은 서버의 SSH에서 명령을 한 번 실행한 뒤, 요청할 때 아래 설정 코드를 붙여 넣습니다.</p><textarea class="ojp-textarea" readonly rows="2" aria-label="서버 설치 명령"><?php echo esc_textarea($command ?? '주소를 먼저 저장하세요.'); ?></textarea><textarea class="ojp-textarea" readonly rows="4" aria-label="서버 설정 코드"><?php echo esc_textarea($setup_code); ?></textarea></details>
                        <dialog class="ojp-server-install" id="htt-server-install" aria-labelledby="htt-install-title">
                    <h2 id="htt-install-title">교실 도구 서버 설치</h2>
                    <p>명령 복사 → 같은 서버의 SSH에서 실행 → 숨김 입력에 설정 코드 붙여넣기 → 연결 확인</p>
                    <textarea class="ojp-textarea htt-setup-command" id="htt-install-command" readonly rows="4" aria-label="교실 도구 설치 명령" data-htt-select><?php echo esc_textarea($command); ?></textarea>
                    <button type="button" class="ojp-button" data-ojp-copy-target="htt-install-command">명령 복사</button>
                    <textarea class="ojp-textarea htt-setup-command" id="htt-install-code" readonly rows="4" aria-label="교실 도구 설정 코드" data-htt-select><?php echo esc_textarea($setup_code); ?></textarea>
                    <button type="button" class="ojp-button" data-ojp-copy-target="htt-install-code">설정 코드 복사</button>
                    <p>설정 코드는 관리자만 사용하며 공유하지 마세요. 설치 명령이나 셸 기록에는 비밀키가 포함되지 않습니다.</p>
                    <p>공통 서버가 재시작됩니다. 수업·경기가 없는 시간에 실행하세요.</p>
                    <details><summary>자세히 보기</summary><p>Ubuntu/Debian용입니다. 공통 서버 설치와 교실 도구 등록을 진행하며, 아레나 등록은 유지합니다. 주소·비밀키를 변경하면 저장 후 새 명령을 실행하세요.</p></details>
                    <button type="button" class="ojp-button" data-ojp-server-close>닫고 연결 확인</button>
                </dialog>
            </section>

            <section class="htt-section">
                <h2><?php esc_html_e('기존 설정 유지 업데이트', 'teaching-tools'); ?></h2>
                <p><?php esc_html_e('새 플러그인 ZIP을 업로드한 뒤 서버 SSH에서 아래 명령을 한 번 실행하세요. 설정 코드를 다시 입력하지 않고 기존 주소·비밀키·환경값과 다른 모듈 등록을 유지합니다.', 'teaching-tools'); ?></p>
                <textarea class="ojp-textarea htt-setup-command" id="htt-update-command" readonly rows="2" aria-label="<?php echo esc_attr__('실시간 서버 업데이트 명령', 'teaching-tools'); ?>" data-htt-select><?php echo esc_textarea($update_command); ?></textarea>
                <button type="button" class="ojp-button" data-ojp-copy-target="htt-update-command"><?php esc_html_e('업데이트 명령 복사', 'teaching-tools'); ?></button>
                <p><?php esc_html_e('nginx·서비스 설정은 변경하지 않습니다. 백업 후 서버를 재시작하고 인증 확인에 실패하면 복구합니다. 진행 중 수업·경기는 중단되므로 사용하지 않는 시간에 실행하세요.', 'teaching-tools'); ?></p>
            </section>

        </div>
        <?php

        return Ui::page($title, (string) ob_get_clean(), 'standard', true);
    }
}
