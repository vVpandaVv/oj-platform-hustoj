<?php
declare(strict_types=1);
namespace EduGames\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\{AddonPageHandlerV1,AddonPageRequestV1,AddonPageResponseV1};
final class Cipher_Team_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        if(!\EduGames\Games\Game_Visibility::is_open('cipher-vault')&&!\EduGames\Support\Capabilities::can_preview_closed())return AddonPageResponseV1::unavailable('현재 공개되지 않은 게임입니다.','addon_access_denied');
        return Page_Response::themed('암호 금고 팀전',\EduGames\Blocks\Cipher_Team_Renderer::render());
    }
}
