<?php
declare(strict_types=1);
namespace EduGames\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\{AddonPageHandlerV1,AddonPageRequestV1,AddonPageResponseV1};
use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;
final class Device_Team_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        if(!Game_Visibility::is_open('device-matching')&&!Capabilities::can_preview_closed())return AddonPageResponseV1::unavailable('장치 카드 게임은 현재 공개되지 않았습니다.','addon_access_denied');
        return Page_Response::themed(__('장치 카드 매칭 팀전','edu-games'),\EduGames\Blocks\Device_Team_Renderer::render());
    }
}
