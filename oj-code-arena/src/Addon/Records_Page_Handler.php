<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/** Round records with CSV download (resources/js/records.js). */
final class Records_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        return Page_Response::make(__('경기 기록', 'oj-code-arena'), Page_Config::mount('data-oca-records', Page_Config::base()));
    }
}
