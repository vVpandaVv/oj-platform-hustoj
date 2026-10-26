<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OjCodeArena\Database\Problem_Repository;
use OjCodeArena\Support\Settings;

/** Learner page: class games, free rooms, the game itself (resources/js/play.js). */
final class Play_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = Page_Config::base() + array(
            'free' => Settings::free_enabled(),
            'tags' => (new Problem_Repository())->tags(),
        );
        return Page_Response::immersive(__('코드 아레나', 'oj-code-arena'), Page_Config::mount('data-oca-play', $config));
    }
}
