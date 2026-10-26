<?php
declare(strict_types=1);

namespace TeachingTools\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

final class Console_Hub_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $tools = array(
            array(
                'icon' => 'timer',
                'tone' => 'red',
                'title' => __('타이머', 'teaching-tools'),
                'description' => __('수업 활동 시간을 재고 화면에 크게 표시합니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/timer'),
                'ready' => true,
            ),
            array(
                'icon' => 'seating',
                'tone' => 'blue',
                'title' => __('자리배치', 'teaching-tools'),
                'description' => __('학급 명단으로 자리를 무작위 배치합니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/seating'),
                'ready' => true,
            ),
            array(
                'icon' => 'picker',
                'tone' => 'amber',
                'title' => __('뽑기', 'teaching-tools'),
                'description' => __('명단에서 한 명 또는 여러 명을 무작위로 뽑습니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/picker'),
                'ready' => true,
            ),
            array(
                'icon' => 'ladder',
                'tone' => 'green',
                'title' => __('사다리타기', 'teaching-tools'),
                'description' => __('학생이 각자 기기로 참여하는 사다리타기 게임입니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/ladder'),
                'ready' => true,
            ),
            array(
                'icon' => 'whiteboard',
                'tone' => 'graphite',
                'title' => __('판서', 'teaching-tools'),
                'description' => __('수업용 디지털 판서 캔버스입니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/whiteboard'),
                'ready' => true,
            ),
            array(
                'icon' => 'quickboard',
                'tone' => 'green',
                'title' => __('학생 미니 판서', 'teaching-tools'),
                'description' => __('학생이 각자 그린 답을 썸네일로 모아 확인합니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/quickboard'),
                'ready' => true,
            ),
            array(
                'icon' => 'quiz',
                'tone' => 'teal',
                'title' => __('빠른 응답', 'teaching-tools'),
                'description' => __('객관식, 척도, 한줄 응답, 워드클라우드와 익명 질문을 받습니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/quiz'),
                'ready' => true,
            ),
            array(
                'icon' => 'grouping',
                'tone' => 'blue',
                'title' => __('조 짜기', 'teaching-tools'),
                'description' => __('제출 기록을 활용해 실력이 균형 잡힌 팀을 짭니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/grouping'),
                'ready' => true,
            ),
            array(
                'icon' => 'timeattack',
                'tone' => 'orange',
                'title' => __('문제 풀이 타임어택', 'teaching-tools'),
                'description' => __('정해진 시간 안에 특정 문제를 풀고 실시간으로 결과를 확인합니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/timeattack'),
                'ready' => true,
            ),
            array(
                'icon' => 'signal',
                'tone' => 'teal',
                'title' => __('수업 신호', 'teaching-tools'),
                'description' => __('학생 상태를 확인하고 다음 활동을 바로 보냅니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/classroom'),
                'ready' => true,
            ),
            array(
                'icon' => 'operations',
                'tone' => 'amber',
                'title' => __('수업 운영 보드', 'teaching-tools'),
                'description' => __('수업 순서, 활동 모드, 신호등, 타이머와 소음을 관리합니다.', 'teaching-tools'),
                'url' => home_url('/oj-console/addons/teaching-tools/operations'),
                'ready' => true,
            ),
        );

        ob_start();
        ?>
        <div class="htt-console-hub" data-htt-host>
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('수업', 'teaching-tools'); ?></p>
                    <h1><?php esc_html_e('교실 도구', 'teaching-tools'); ?></h1>
                    <p><?php esc_html_e('수업 중 바로 쓰는 도구 모음입니다. 쓸 도구를 고르세요.', 'teaching-tools'); ?></p>
                </div>
            </header>
            <ul class="htt-app-grid" role="list">
                <?php foreach ($tools as $tool) : ?>
                    <li>
                        <?php if ($tool['ready']) : ?>
                            <a class="htt-app-tile" href="<?php echo esc_url((string) $tool['url']); ?>">
                        <?php else : ?>
                            <div class="htt-app-tile is-closed">
                        <?php endif; ?>
                                <span class="htt-app-tile__icon htt-tone--<?php echo esc_attr((string) $tool['tone']); ?>" aria-hidden="true"><?php echo Ui::icon((string) $tool['icon'], 30); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-app-tile__name"><?php echo esc_html((string) $tool['title']); ?></span>
                                <span class="htt-app-tile__description"><?php echo esc_html((string) $tool['description']); ?></span>
                                <?php if (! $tool['ready']) : ?>
                                    <span class="ojp-status-badge ojp-status-badge--neutral"><?php esc_html_e('준비 중', 'teaching-tools'); ?></span>
                                <?php endif; ?>
                        <?php if ($tool['ready']) : ?>
                            </a>
                        <?php else : ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php

        return Ui::page(__('교실 도구', 'teaching-tools'), (string) ob_get_clean(), 'standard', true);
    }
}
