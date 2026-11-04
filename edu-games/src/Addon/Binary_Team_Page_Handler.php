<?php
declare(strict_types=1);
namespace EduGames\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\{AddonPageHandlerV1,AddonPageRequestV1,AddonPageResponseV1};
use EduGames\Blocks\Binary_Team_Renderer;
use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

final class Binary_Team_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        if(!Game_Visibility::is_open('binary-conversion')&&!Capabilities::can_preview_closed())return AddonPageResponseV1::unavailable('이진수 게임은 현재 공개되지 않았습니다.','addon_access_denied');
        return Page_Response::themed(__('이진수 아케이드 팀전','edu-games'),Binary_Team_Renderer::render());
    }
}
