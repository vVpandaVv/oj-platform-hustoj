<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OjCodeArena\Database\Problem_Repository;

/** Teacher console: create and run class games (resources/js/teacher.js). */
final class Teacher_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = Page_Config::base() + array(
            'tags' => (new Problem_Repository())->tags(),
            'boardUrl' => Page_Config::console_url('/board'),
        );
        return Page_Response::make(__('수업 게임', 'oj-code-arena'), Page_Config::mount('data-oca-teacher', $config));
    }
}
