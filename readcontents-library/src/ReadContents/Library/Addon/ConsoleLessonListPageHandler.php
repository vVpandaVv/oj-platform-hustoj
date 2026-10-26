<?php

declare(strict_types=1);

namespace ReadContents\Library\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

final class ConsoleLessonListPageHandler implements AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        unset($request);

        return ConsoleEditorPage::response('읽기자료');
    }
}
