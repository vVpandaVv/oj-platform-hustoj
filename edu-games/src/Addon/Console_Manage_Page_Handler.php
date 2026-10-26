<?php
declare(strict_types=1);

namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use EduGames\Games\Game_Category_Labels;

/**
 * OJ Console screen replacing the old standalone WordPress admin menu
 * (src/Admin/Menu.php). Read-only shell; all writes go through this
 * plugin's own REST API (see Games_Admin_Controller, Teams_Controller).
 */
final class Console_Manage_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $category_labels = array();
        $games = function_exists('edu_games_registry') ? \edu_games_registry()->all(false) : array();
        foreach ($games as $game) {
            $category = (string) ($game['category'] ?? '');
            if ('' !== $category) {
                $category_labels[$category] = Game_Category_Labels::label($category);
            }
        }

        $config = array(
            'restUrl' => esc_url_raw(rest_url('edu-games/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'categoryLabels' => $category_labels,
        );
        $ranking_page_url = home_url('/oj-addons/edu-games/ranking');
        $ranking_link = '<a class="ojp-button ojp-button--tinted" href="' . esc_url($ranking_page_url) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html__('게임 순위 열기', 'edu-games') . '</a>';

        ob_start();
        ?>
        <div class="edu-games-console" data-edu-games-console data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <?php
            echo Page_Response::header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
                __('학습 활동', 'edu-games'),
                __('교육 게임', 'edu-games'),
                __('학생에게 보일 게임을 고르고, 순위에 쓰는 팀을 관리합니다.', 'edu-games'),
                $ranking_link
            );
            ?>

            <section class="ojp-section eg-console-section" aria-labelledby="edu-games-visibility-title">
                <div class="ojp-section-heading">
                    <div>
                        <h2 id="edu-games-visibility-title"><?php esc_html_e('게임 공개', 'edu-games'); ?></h2>
                        <p><?php esc_html_e('공개하지 않은 게임은 학생 화면에서 숨겨지고, 점수 저장과 순위 조회도 막힙니다.', 'edu-games'); ?></p>
                    </div>
                </div>
                <div data-field="games-table"></div>
                <div class="eg-console-actions">
                    <button type="button" class="ojp-button ojp-button--primary" data-click-action="save-visibility" hidden><?php esc_html_e('공개 설정 저장', 'edu-games'); ?></button>
                    <span class="ojp-form-status" id="edu-games-save-status" data-field="save-status" role="status"></span>
                </div>
            </section>

            <section class="ojp-section eg-console-section" aria-labelledby="edu-games-teams-title">
                <div class="ojp-section-heading">
                    <div>
                        <h2 id="edu-games-teams-title"><?php esc_html_e('팀', 'edu-games'); ?></h2>
                        <p><?php esc_html_e('학생은 초대 코드로 팀에 들어가 팀 순위를 볼 수 있습니다.', 'edu-games'); ?></p>
                    </div>
                </div>
                <div data-field="team-list"></div>
            </section>

            <div class="ojp-notice ojp-notice--info eg-console-notice">
                <strong><?php esc_html_e('학생 메뉴의 "게임 순위"', 'edu-games'); ?></strong>
                <p><?php esc_html_e('전체 순위, 팀 순위, 팀 내 순위를 한 화면에서 볼 수 있습니다. 이 메뉴는 "교육 게임 플레이" 권한을 배정한 역할에만 보이며, 관리자에게도 자동으로 배정되지 않습니다. 역할과 권한 화면에서 확인해 주세요.', 'edu-games'); ?></p>
            </div>
        </div>
        <?php

        return Page_Response::console(__('교육 게임', 'edu-games'), (string) ob_get_clean());
    }
}
