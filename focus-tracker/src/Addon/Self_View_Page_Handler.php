<?php
declare(strict_types=1);

namespace FocusTracker\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Phase 2 (docs/DESIGN.md §10): lets a student see their own most recent
 * activity score — the same numbers their teacher sees, nothing about
 * classmates. Transparency about what's being measured was one of the
 * explicit design goals (docs/DESIGN.md §8).
 */
final class Self_View_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('focus-tracker/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        );

        ob_start();
        ?>
        <div class="ftk-self-view" data-ftk-self-view data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('수업', 'focus-tracker'); ?></p>
                    <h1><?php esc_html_e('내 집중도 요약', 'focus-tracker'); ?></h1>
                    <p><?php esc_html_e('최근 활동의 화면 활성·복귀·입력과 제출 기록입니다. 읽기·생각하기·학습의 질이나 실제 집중을 직접 측정하지 않습니다.', 'focus-tracker'); ?></p>
                </div>
            </header>
            <div data-field="summary" aria-live="polite">
                <p class="ojp-muted"><?php esc_html_e('불러오는 중…', 'focus-tracker'); ?></p>
            </div>
            <section class="ftk-statistics" data-ftk-statistics data-mode="self" data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>" aria-label="<?php esc_attr_e('내 주간·월간 누적 기록', 'focus-tracker'); ?>">
                <p class="ojp-muted"><?php esc_html_e('누적 기록을 불러오는 중…', 'focus-tracker'); ?></p>
            </section>
        </div>
        <?php

        return Page_Response::make(__('내 집중도 요약', 'focus-tracker'), (string) ob_get_clean());
    }
}
