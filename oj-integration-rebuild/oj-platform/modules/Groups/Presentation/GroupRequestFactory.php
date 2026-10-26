<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand;

final class GroupRequestFactory
{
    public function saveCommand(HttpRequest $request): GroupSaveCommand
    {
        return new GroupSaveCommand(
            $request->bodyInt('group_id'),
            $request->bodyString('name'),
            $request->bodyString('slug'),
            $request->bodyString('description'),
            $request->bodyString('visibility', 'private'),
            $request->bodyString('approval_mode', 'manual'),
            $request->bodyBool('enabled'),
            $request->bodyString('blockly_profile_key'),
            $request->bodyString('evaluation_mode', 'normal'),
            $request->bodyString('join_code')
        );
    }
}
