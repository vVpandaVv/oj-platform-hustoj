<?php
declare(strict_types=1);

namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/** A single game-selected ranking page inside the platform shell. */
final class Public_Ranking_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        return Page_Response::themed(__('게임 순위', 'edu-games'), \EduGames\Blocks\Ranking_Page_Renderer::render());
    }
}
