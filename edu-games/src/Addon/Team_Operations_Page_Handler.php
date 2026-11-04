<?php
declare(strict_types=1);
namespace EduGames\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\{AddonPageHandlerV1,AddonPageRequestV1,AddonPageResponseV1};
use EduGames\Support\Capabilities;
use EduGames\Games\Game_Visibility;

final class Team_Operations_Page_Handler implements AddonPageHandlerV1 {
    public function __construct(private bool $board=false){}
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        if(!Capabilities::can_manage()&&!current_user_can('manage_options'))return AddonPageResponseV1::unavailable('교사 전용 화면입니다.','addon_access_denied');
        $game=isset($_GET['game'])&&is_string($_GET['game'])&&$_GET['game']==='device-matching'?'device-matching':'binary-conversion';
        if(!Game_Visibility::is_open($game)&&!Capabilities::can_preview_closed())return AddonPageResponseV1::unavailable('현재 공개되지 않은 게임입니다.','addon_access_denied');
        $room=isset($_GET['room'])&&is_string($_GET['room'])?strtoupper($_GET['room']):'';
        return Page_Response::themed($this->board?'팀전 현황판':'실시간 팀전 운영실',\EduGames\Blocks\Binary_Team_Renderer::render_game($game,false,$this->board?'board':'teacher',$room));
    }
}
