<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/** Projector board for one class game (?session=ID, resources/js/board.js). */
final class Board_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $query = $request->query();
        $config = Page_Config::base() + array(
            'session' => isset($query['session']) ? absint($query['session']) : 0,
            'joinHint' => (string) preg_replace('#^https?://#', '', home_url(Oj_Platform_Registration::PLAY_PATH)),
        );
        return Page_Response::immersive(__('코드 아레나 전광판', 'oj-code-arena'), Page_Config::mount('data-oca-board', $config));
    }
}
