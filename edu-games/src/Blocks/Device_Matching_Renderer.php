<?php
declare(strict_types=1);

namespace EduGames\Blocks;

use EduGames\Games\Modules\Device_Matching\Device_Matching_Module;

final class Device_Matching_Renderer extends Abstract_Game_Renderer {
    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = array(), bool $is_block = false): string {
        $config = self::build_config(Device_Matching_Module::ID, Device_Matching_Module::rules());
        $wrapper = self::wrapper_attributes($is_block, 'device-matching-block');

        ob_start();
        ?>
        <section <?php echo $wrapper; ?> data-device-matching data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="oj-device-shell">
                <header class="oj-device-topbar">
                    <div>
                        <p class="oj-device-kicker"><?php esc_html_e('장치 카드 매칭', 'edu-games'); ?></p>
                        <h2><?php esc_html_e('사진과 설명을 짝지어 보세요', 'edu-games'); ?></h2>
                    </div>
                    <div class="oj-device-actions">
                        <button type="button" class="oj-device-button oj-device-button-primary" data-action="start"><?php esc_html_e('도전 시작', 'edu-games'); ?></button>
                        <button type="button" class="oj-device-button oj-device-button-ghost" data-action="next-stage" hidden><?php esc_html_e('다음 단계', 'edu-games'); ?></button>
                    </div>
                </header>
                <p data-field="challenge-goal" aria-live="polite"></p>

                <div class="oj-device-stats" aria-label="<?php esc_attr_e('게임 상태', 'edu-games'); ?>">
                    <div class="oj-device-stat">
                        <span><?php esc_html_e('단계', 'edu-games'); ?></span>
                        <strong data-field="stage">-</strong>
                    </div>
                    <div class="oj-device-stat">
                        <span><?php esc_html_e('단계 별점', 'edu-games'); ?></span>
                        <strong data-field="lives">-</strong>
                    </div>
                    <div class="oj-device-stat">
                        <span><?php esc_html_e('점수', 'edu-games'); ?></span>
                        <strong data-field="score">0</strong>
                    </div>
                    <div class="oj-device-stat">
                        <span><?php esc_html_e('연속 정답', 'edu-games'); ?></span>
                        <strong data-field="streak">0</strong>
                    </div>
                    <div class="oj-device-stat">
                        <span><?php esc_html_e('내 순위', 'edu-games'); ?></span>
                        <strong data-field="rank">-</strong>
                    </div>
                </div>

                <main class="oj-device-layout">
                    <section class="oj-device-panel oj-device-play-panel">
                        <div class="oj-device-stagebar">
                            <div>
                                <span data-field="stage-meta"><?php esc_html_e('대기 중', 'edu-games'); ?></span>
                                <h3 data-field="stage-title"><?php esc_html_e('카드를 열어 같은 의미의 짝을 찾으세요', 'edu-games'); ?></h3>
                            </div>
                            <div class="oj-device-progress" data-field="progress">0 / 0</div>
                        </div>
                        <div class="oj-device-deck" data-field="deck"></div>
                        <p class="oj-device-feedback" data-field="feedback" aria-live="polite"></p>
                    </section>

                    <aside class="oj-device-side">
                        <section class="oj-device-panel oj-device-rule-card">
                            <div class="oj-device-panel-head">
                                <span><?php esc_html_e('진행 방식', 'edu-games'); ?></span>
                            </div>
                            <div class="oj-device-rule-list">
                                <div>
                                    <strong><?php esc_html_e('오답 처리', 'edu-games'); ?></strong>
                                    <span><?php esc_html_e('오답 카드는 잠시 보인 뒤 다시 뒤집히며, 단계별 실수 횟수로만 기록됩니다.', 'edu-games'); ?></span>
                                </div>
                                <div>
                                    <strong><?php esc_html_e('점수와 별점', 'edu-games'); ?></strong>
                                    <span><?php esc_html_e('실수가 많을수록 감점되고, 단계 완료 보너스와 별점이 낮아집니다.', 'edu-games'); ?></span>
                                </div>
                                <div>
                                    <strong><?php esc_html_e('종료 조건', 'edu-games'); ?></strong>
                                    <span><?php esc_html_e('6쌍에서 시작해 12쌍까지 모든 단계를 완료하면 도전이 끝납니다.', 'edu-games'); ?></span>
                                </div>
                            </div>
                        </section>

                        <section class="oj-device-panel">
                            <div class="oj-device-panel-head">
                                <span><?php esc_html_e('순위', 'edu-games'); ?></span>
                                <span data-field="rank-status"><?php esc_html_e('불러오는 중', 'edu-games'); ?></span>
                            </div>
                            <div class="oj-device-leaderboard" data-field="leaderboard"></div>
                        </section>
                    </aside>
                </main>
                <details class="oj-device-credits">
                    <summary><?php echo esc_html(sprintf(__('사진 출처 · 장치 %d종', 'edu-games'), count($config['rules']['devices']))); ?></summary>
                    <ul>
                        <?php foreach ($config['rules']['devices'] as $device): ?>
                            <li>
                                <a href="<?php echo esc_url($device['sourceUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($device['name']); ?></a>
                                · <?php echo esc_html($device['author'] . ' · ' . $device['license']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?php echo esc_url(plugins_url('assets/device-matching/ATTRIBUTION.md', EDU_GAMES_FILE)); ?>"><?php esc_html_e('사진별 이용 조건', 'edu-games'); ?></a>
                </details>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
