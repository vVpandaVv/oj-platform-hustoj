<?php
declare(strict_types=1);

namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

/**
 * Renders one game's play surface as an oj-platform PublicUI page, reusing
 * the exact same Renderer used by the shortcode/block embedding paths so
 * markup stays in one place regardless of entry point.
 */
final class Game_Play_Page_Handler implements AddonPageHandlerV1 {
    /** @var callable */
    private $renderer;
    private string $game_id;
    private string $title;

    public function __construct(string $game_id, string $title, callable $renderer) {
        $this->game_id = $game_id;
        $this->title = $title;
        $this->renderer = $renderer;
    }

    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        if (! Capabilities::can_preview_closed() && ! Game_Visibility::is_open($this->game_id)) {
            return AddonPageResponseV1::unavailable(
                __('이 게임은 현재 공개되지 않았습니다.', 'edu-games'),
                'addon_access_denied'
            );
        }

        $body = (string) call_user_func($this->renderer, array(), false);

        return Page_Response::themed($this->title, $body);
    }
}
