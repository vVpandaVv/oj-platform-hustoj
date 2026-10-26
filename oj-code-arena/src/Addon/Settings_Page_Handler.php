<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OjCodeArena\Admin\Settings_Page;
use OjCodeArena\Support\Capabilities;
use OjCodeArena\Support\Server_Env;
use OjCodeArena\Support\Settings;

/**
 * 교사 메뉴 › 코드 아레나 › 설정: the site settings (engine, free play,
 * record keeping, Node game server). Site admins and educators with the
 * "코드 아레나 설정" capability (Capabilities::can_manage_settings) may
 * open it. Saves through the admin-post handler (Settings_Page::save) with
 * its nonce and the same check, so option names and sanitising stay in one
 * place. The status check and load test run in resources/js/settings.js
 * and resources/js/admin-loadtest.js.
 */
final class Settings_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $title = __('코드 아레나 설정', 'oj-code-arena');
        if (! Capabilities::can_manage_settings()) {
            $body = self::header('')
                . '<div class="ojp-empty-state"><div><strong>' . esc_html__('설정 권한이 필요합니다', 'oj-code-arena') . '</strong>'
                . '<p>' . esc_html__('게임 엔진과 기록 보관 기간은 사이트 관리자나 [코드 아레나 설정] 권한을 받은 교사가 정합니다. 수업 게임은 지금 설정으로 그대로 열 수 있습니다.', 'oj-code-arena') . '</p>'
                . '<a class="ojp-button ojp-button--small" href="' . esc_url(Page_Config::console_url()) . '">' . esc_html__('수업 게임으로', 'oj-code-arena') . '</a></div></div>';

            return Page_Response::make($title, '<div class="oca-console">' . $body . '</div>', 'standard', true, 403);
        }

        Settings::ensure_secret();
        $s = Settings::all();
        $node = Settings::uses_node();
        $env = Settings_Page::env();
        $command = 'node src/setup.js ' . Server_Env::setup_code($env);
        $install_command = Server_Env::install_command($s['public_url'], $s['api_url'], home_url(), $env, OJ_CODE_ARENA_PATH);
        $install_code = Server_Env::install_code($env);
        $download = wp_nonce_url(admin_url('admin-post.php?action=oj_code_arena_env'), 'oj_code_arena_env');
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $saved = isset($request->query()['saved']);
        $config = array(
            'restUrl' => untrailingslashit(rest_url('oj-code-arena/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'checkOnOpen' => $node,
        );

        ob_start();
        ?>
        <div class="oca-console oca-settings" data-oca-settings-page data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo self::header(__('게임 엔진, 자유 대전, 경기 기록 보관 기간을 정합니다.', 'oj-code-arena')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <?php if ($saved) : ?>
                <div class="ojp-notice ojp-notice--success" role="status" data-ojp-flash><strong><?php esc_html_e('설정을 저장했습니다', 'oj-code-arena'); ?></strong></div>
            <?php endif; ?>

            <section class="oca-card-box">
                <h2><?php esc_html_e('바로 시작하기', 'oj-code-arena'); ?></h2>
                <ol class="oca-steps">
                    <li><?php esc_html_e('선생님은 코드 아레나 › 수업 게임에서 반이나 그룹을 골라 수업 게임을 만듭니다.', 'oj-code-arena'); ?>
                        <a href="<?php echo esc_url(Page_Config::console_url()); ?>"><?php esc_html_e('열기', 'oj-code-arena'); ?></a></li>
                    <li><?php esc_html_e('학생은 메뉴의 코드 아레나에서 수업 게임에 들어와 팀을 고릅니다.', 'oj-code-arena'); ?>
                        <a href="<?php echo esc_url(home_url(Oj_Platform_Registration::PLAY_PATH)); ?>"><?php esc_html_e('열기', 'oj-code-arena'); ?></a></li>
                    <li><?php esc_html_e('선생님이 [경기 시작]을 누르면 시작합니다. 프로젝터에는 [전광판 열기]를 띄웁니다.', 'oj-code-arena'); ?></li>
                </ol>
                <p class="ojp-field-help"><?php esc_html_e('문제는 코드 아레나 › 문제은행에서 편집하거나 추가합니다.', 'oj-code-arena'); ?></p>
            </section>

            <form class="ojp-form-stack oca-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="oj_code_arena_save">
                <?php wp_nonce_field('oj_code_arena_save'); ?>

                <fieldset class="ojp-fieldset">
                    <legend><?php esc_html_e('게임 엔진', 'oj-code-arena'); ?></legend>
                    <label class="ojp-toggle-row oca-choice">
                        <input type="radio" name="engine" value="builtin" <?php checked(! $node); ?>>
                        <span><strong><?php esc_html_e('내장 (추천, 설치할 것 없음)', 'oj-code-arena'); ?></strong>
                            <span class="ojp-field-help"><?php esc_html_e('이 사이트 안에서 게임이 돌아갑니다. 한 반(30명 안팎) 수업에 알맞습니다. 화면 반응이 0.3~0.8초쯤 늦을 수 있습니다.', 'oj-code-arena'); ?></span></span>
                    </label>
                    <label class="ojp-toggle-row oca-choice">
                        <input type="radio" name="engine" value="node" <?php checked($node); ?>>
                        <span><strong><?php esc_html_e('공통 실시간 서버 (WebSocket)', 'oj-code-arena'); ?></strong>
                            <span class="ojp-field-help"><?php esc_html_e('공통 서버에 아레나 모듈을 등록합니다. 아레나만 단독으로 설치할 수 있으며, 티칭툴이 이미 사용 중이면 같은 서버를 함께 이용합니다.', 'oj-code-arena'); ?></span></span>
                    </label>
                </fieldset>

                <fieldset class="ojp-fieldset">
                    <legend><?php esc_html_e('자유 대전', 'oj-code-arena'); ?></legend>
                    <label class="ojp-toggle-row">
                        <input type="checkbox" name="free_enabled" value="1" <?php checked($s['free_enabled']); ?>>
                        <span><?php esc_html_e('학생끼리 방을 만들어 연습하는 자유 대전 허용', 'oj-code-arena'); ?></span>
                    </label>
                </fieldset>

                <div class="ojp-field">
                    <label for="oca-keep"><?php esc_html_e('경기 기록 보관 (일)', 'oj-code-arena'); ?></label>
                    <input class="ojp-input oca-input-short" id="oca-keep" name="keep_days" type="number" min="0" max="3650" value="<?php echo esc_attr((string) $s['keep_days']); ?>">
                    <p class="ojp-field-help"><?php esc_html_e('0이면 계속 보관합니다. 정한 날보다 오래된 경기 기록은 매시간 정리할 때 지워집니다. 학생 한 명의 기록은 WordPress [도구 › 개인 데이터 지우기]로도 지울 수 있습니다.', 'oj-code-arena'); ?></p>
                </div>

                <details class="oca-card-box oca-advanced" <?php echo $node ? 'open' : ''; ?>>
                    <summary><?php esc_html_e('공통 실시간 서버 (내장 엔진을 쓰면 필요 없습니다)', 'oj-code-arena'); ?></summary>
                    <div class="ojp-form-stack">
                        <div class="ojp-field">
                            <label for="oca-public"><?php esc_html_e('게임 서버 주소 (브라우저용)', 'oj-code-arena'); ?></label>
                            <input class="ojp-input oca-mono" id="oca-public" name="public_url" type="text" value="<?php echo esc_attr($s['public_url']); ?>" placeholder="<?php echo esc_attr('wss://' . $host . '/arena'); ?>">
                            <p class="ojp-field-help"><?php esc_html_e('사이트가 https이면 wss:// 주소여야 합니다. 원클릭 설치는 같은 도메인의 /arena를 공통 서버(127.0.0.1:3210)에 연결합니다.', 'oj-code-arena'); ?></p>
                        </div>
                        <div class="ojp-field">
                            <label for="oca-api"><?php esc_html_e('게임 서버 내부 주소 (선택)', 'oj-code-arena'); ?></label>
                            <input class="ojp-input oca-mono" id="oca-api" name="api_url" type="text" value="<?php echo esc_attr($s['api_url']); ?>" placeholder="http://127.0.0.1:3210/arena">
                            <p class="ojp-field-help"><?php esc_html_e('게임 서버가 WordPress와 같은 컴퓨터에 있으면 이 주소를 넣습니다. 비우면 위 주소를 https://로 바꿔 씁니다.', 'oj-code-arena'); ?></p>
                        </div>
                        <div class="ojp-field">
                            <span class="ojp-label"><?php esc_html_e('비밀키', 'oj-code-arena'); ?></span>
                            <code class="oca-mono"><?php echo esc_html(substr($s['secret'], 0, 6) . '…' . substr($s['secret'], -4)); ?></code>
                            <label class="ojp-toggle-row">
                                <input type="checkbox" name="new_secret" value="1">
                                <span><?php esc_html_e('저장할 때 새 비밀키 만들기 (게임 서버 설정 파일도 다시 만들어야 합니다)', 'oj-code-arena'); ?></span>
                            </label>
                        </div>

                        <p>처음 WebSocket을 사용한다면 주소를 저장한 뒤 서버를 준비하세요.</p>
                        <button type="button" class="ojp-button" style="display:none" hidden data-ojp-server-open="oca-server-install">서버 설치·복구</button>
                        <details data-ojp-server-fallback="oca-server-install"><summary>서버 설치 명령 (OJ Platform 업데이트 후 설치 창 사용 가능)</summary><p>같은 서버의 SSH에서 명령을 실행한 뒤 요청할 때 설정 코드를 붙여 넣습니다.</p><textarea class="ojp-textarea" readonly rows="2" aria-label="서버 설치 명령"><?php echo esc_textarea($install_command ?? '주소를 먼저 저장하세요.'); ?></textarea><textarea class="ojp-textarea" readonly rows="4" aria-label="서버 설정 코드"><?php echo esc_textarea($install_code); ?></textarea></details>
                        <dialog class="ojp-server-install" id="oca-server-install" aria-labelledby="oca-install-title">
                            <h2 id="oca-install-title">아레나 서버 설치</h2>
                            <p>주소 저장 → 명령 복사 → SSH 실행 → WebSocket 엔진 선택·상태 확인</p>
                        <?php if (null !== $install_command) : ?>
                            <div class="ojp-field">
                                <label for="oca-install-command"><?php esc_html_e('서버에서 실행할 설치·업데이트 명령', 'oj-code-arena'); ?></label>
                                <textarea class="ojp-textarea oca-mono" id="oca-install-command" readonly rows="4" data-oca-select><?php echo esc_textarea($install_command); ?></textarea>
                            </div>
                            <button type="button" class="ojp-button" data-ojp-copy-target="oca-install-command">명령 복사</button>
                            <div class="ojp-field">
                                <label for="oca-install-code"><?php esc_html_e('설치 프로그램이 요청할 설정 코드', 'oj-code-arena'); ?></label>
                                <textarea class="ojp-textarea oca-mono" id="oca-install-code" readonly rows="4" data-oca-select><?php echo esc_textarea($install_code); ?></textarea>
                            </div>
                            <button type="button" class="ojp-button" data-ojp-copy-target="oca-install-code">설정 코드 복사</button>
                        <?php else : ?>
                            <div class="ojp-notice ojp-notice--warning"><p><?php esc_html_e('같은 사이트의 wss://사이트/arena 주소를 저장하면 설치 명령이 표시됩니다. 내부 주소는 비우거나 http://127.0.0.1:3210/arena로 설정하세요. HTTP 사이트는 ws://를 사용합니다. 다른 서버나 직접 포트 연결은 아래 수동 설치를 사용합니다.', 'oj-code-arena'); ?></p></div>
                        <?php endif; ?>
                            <p>설정 코드는 관리자만 사용하며 공유하지 마세요. 설치 명령과 셸 기록에는 비밀키가 포함되지 않습니다.</p>
                            <p>공통 서버가 재시작되어 진행 중 경기가 종료됩니다. 수업·경기가 없는 시간에 실행하세요.</p>
                            <details><summary>자세히 보기</summary><p>WordPress와 같은 Ubuntu/Debian 서버에서 실행합니다. 교실 도구 등록은 유지합니다. 주소·비밀키 변경 후에는 저장하고 새 명령을 실행하세요. 다른 nginx 사이트 파일은 명령 끝에 해당 경로를 붙입니다.</p></details>
                            <button type="button" class="ojp-button" data-ojp-server-close>닫고 상태 확인</button>
                        </dialog>

                        <details>
                        <summary><?php esc_html_e('기존 독립 서버용 수동 설정(.env)', 'oj-code-arena'); ?></summary>
                        <?php if ('' === $s['public_url']) : ?>
                            <div class="ojp-notice ojp-notice--warning"><p><?php esc_html_e('먼저 게임 서버 주소를 저장합니다. 포트와 경로를 그 주소에서 계산합니다.', 'oj-code-arena'); ?></p></div>
                        <?php endif; ?>
                        <div class="ojp-field">
                            <label for="oca-command"><?php esc_html_e('방법 1. 게임 서버의 game-server 폴더에서 아래 한 줄을 실행합니다. 이미 있던 파일은 .env.bak으로 남습니다.', 'oj-code-arena'); ?></label>
                            <textarea class="ojp-textarea oca-mono" id="oca-command" readonly rows="3" data-oca-select><?php echo esc_textarea($command); ?></textarea>
                        </div>
                        <div class="ojp-field">
                            <span class="ojp-label"><?php esc_html_e('방법 2. 설정 파일을 받아 game-server 폴더에 그대로 넣습니다.', 'oj-code-arena'); ?></span>
                            <div><a class="ojp-button ojp-button--small" href="<?php echo esc_url($download); ?>"><?php esc_html_e('oj-code-arena.env 다운로드', 'oj-code-arena'); ?></a></div>
                        </div>
                        <details>
                            <summary><?php esc_html_e('방법 3. 내용 보기', 'oj-code-arena'); ?></summary>
                            <textarea class="ojp-textarea oca-mono" readonly rows="8" aria-label="<?php esc_attr_e('설정 파일 내용', 'oj-code-arena'); ?>" data-oca-select><?php echo esc_textarea(Server_Env::text($env)); ?></textarea>
                        </details>
                        <p class="ojp-field-help"><?php esc_html_e('설정 파일에는 비밀키가 들어 있습니다. 다른 사람과 공유하지 마세요.', 'oj-code-arena'); ?></p>
                        </details>
                    </div>
                </details>

                <div class="oca-form-actions">
                    <button type="submit" class="ojp-button ojp-button--primary"><?php esc_html_e('저장', 'oj-code-arena'); ?></button>
                </div>
            </form>

            <section class="oca-card-box">
                <h2><?php esc_html_e('상태 확인', 'oj-code-arena'); ?></h2>
                <div class="oca-row">
                    <button type="button" class="ojp-button" data-oca-status><?php echo esc_html($node ? __('게임 서버에 연결해 보기', 'oj-code-arena') : __('게임 엔진 상태 보기', 'oj-code-arena')); ?></button>
                </div>
                <div data-oca-status-result role="status"></div>
            </section>

            <?php if (! $node) : ?>
                <section class="oca-card-box">
                    <h2><?php esc_html_e('부하 시험', 'oj-code-arena'); ?></h2>
                    <p class="ojp-field-help"><?php esc_html_e('가상 학생이 실제 학생처럼 이 사이트에서 게임을 하고 응답 시간을 잽니다. 수업이 없는 시간에 하세요. 끝나면 시험 게임과 기록은 지워집니다.', 'oj-code-arena'); ?></p>
                    <div id="oca-loadtest" data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
                        <div class="oca-row oca-loadtest-fields">
                            <div class="ojp-field">
                                <label for="oca-lt-count"><?php esc_html_e('가상 학생 (명)', 'oj-code-arena'); ?></label>
                                <input class="ojp-input oca-input-short" id="oca-lt-count" type="number" data-lt-count min="1" max="60" value="30">
                            </div>
                            <div class="ojp-field">
                                <label for="oca-lt-minutes"><?php esc_html_e('시간 (분)', 'oj-code-arena'); ?></label>
                                <input class="ojp-input oca-input-short" id="oca-lt-minutes" type="number" data-lt-minutes min="1" max="5" value="1">
                            </div>
                            <button type="button" class="ojp-button" data-lt-start><?php esc_html_e('부하 시험 시작', 'oj-code-arena'); ?></button>
                        </div>
                        <div data-lt-out></div>
                    </div>
                </section>
            <?php endif; ?>
        </div>
        <?php

        return Page_Response::make($title, (string) ob_get_clean());
    }

    private static function header(string $description): string {
        return '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">' . esc_html__('코드 아레나', 'oj-code-arena') . '</p>'
            . '<h1>' . esc_html__('설정', 'oj-code-arena') . '</h1>'
            . ('' !== $description ? '<p>' . esc_html($description) . '</p>' : '')
            . '</div></header>';
    }
}
