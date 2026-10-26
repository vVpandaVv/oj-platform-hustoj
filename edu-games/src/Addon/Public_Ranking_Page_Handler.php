<?php
declare(strict_types=1);

namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Renders the group-leaderboard block (overall/team/within-team ranking)
 * as a proper Addon Public Page instead of a plain WordPress page — this
 * reuses the platform's own page shell, the same as every other addon
 * page, so it looks consistent with the rest of the site instead of
 * falling back to the theme's default page template (which also brings
 * along the theme's header/footer and the WP admin bar).
 */
final class Public_Ranking_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $body = function_exists('do_blocks')
            ? (string) do_blocks('<!-- wp:edu-games/group-leaderboard /-->')
            : '';

        // The block and shared header follow the same platform color scheme.
        $header = Page_Response::header(
            __('학습 활동', 'edu-games'),
            __('게임 순위', 'edu-games'),
            __('전체 순위, 팀 순위, 팀 내 순위를 봅니다.', 'edu-games'),
            '<a class="ojp-button ojp-button--tinted" href="' . esc_url(home_url('/oj-addons/edu-games/games')) . '">' . esc_html__('교육 게임', 'edu-games') . '</a>'
        );

        return Page_Response::themed(__('게임 순위', 'edu-games'), $header . $body);
    }
}
