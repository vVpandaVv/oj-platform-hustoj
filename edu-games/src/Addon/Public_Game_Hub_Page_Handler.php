<?php
declare(strict_types=1);
namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\{AddonPageHandlerV1,AddonPageRequestV1,AddonPageResponseV1};
use EduGames\Blocks\Game_Hub_Presentation as Cards;
use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

final class Public_Game_Hub_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $all_games=function_exists('edu_games_registry')?\edu_games_registry()->catalog(false):[];
        $preview=Capabilities::can_preview_closed();
        $games=$preview?$all_games:array_filter($all_games,static fn($game)=>($game['status']??'planned')!=='playable'||Game_Visibility::is_open((string)$game['id']));
        $teams=[];
        foreach(['binary-conversion'=>['binary-team','binary','이진수'],'device-matching'=>['device-team','copy','장치 카드'],'cipher-vault'=>['cipher-team','vault','암호 금고']] as $id=>$item)if(Game_Visibility::is_open($id)||$preview)$teams[]=$item;
        ob_start();
        ?>
        <div class="edu-games-addon-hub">
            <header class="eg-hub-heading">
                <div><h1>교육 게임</h1><span class="eg-hub-count"><?php echo count($games); ?>개</span></div>
                <nav class="eg-hub-links" aria-label="게임 기록과 체험">
                    <a href="<?php echo esc_url(home_url('/oj-addons/edu-games/ranking')); ?>"><?php echo Cards::icon('trophy'); ?>게임 순위</a>
                    <?php if(defined('OJ_CONCEPT_LAB_FILE')): ?><a href="<?php echo esc_url(home_url('/oj-addons/oj-concept-lab/activities')); ?>"><?php echo Cards::icon('book-open'); ?>개념 체험</a><?php endif; ?>
                </nav>
            </header>
            <?php if($teams): ?>
            <nav class="eg-hub-teams" aria-label="실시간 팀전">
                <span><?php echo Cards::icon('users'); ?>팀전</span>
                <div class="eg-hub-links"><?php foreach($teams as [$slug,$icon,$label]): ?><a href="<?php echo esc_url(home_url('/oj-addons/edu-games/'.$slug)); ?>"><?php echo Cards::icon($icon); ?><?php echo esc_html($label); ?></a><?php endforeach; ?></div>
            </nav>
            <?php endif; ?>
            <?php echo \EduGames\Blocks\Typing_Entry_Renderer::hub(); ?>
            <?php if(!$games): ?>
                <div class="ojp-empty-state"><div><strong>아직 열린 게임이 없습니다.</strong><p>선생님이 게임을 공개하면 여기에 나타납니다.</p></div></div>
            <?php else: ?>
                <div class="eg-hub-grid">
                    <?php foreach($games as $game):
                        $id=(string)$game['id'];$built=($game['status']??'planned')==='playable';$open=Game_Visibility::is_open($id);
                        $playable=$built&&($open||$preview);$state=$built?($open?'플레이 가능':'비공개'):(string)($game['statusLabel']??'준비 중');
                        ?>
                        <?php if($playable): ?>
                            <a class="eg-hub-card<?php echo $open?'':' is-closed'; ?>" data-tone="<?php echo esc_attr(Cards::tone($id)); ?>" href="<?php echo esc_url(home_url('/oj-addons/edu-games/games/'.$id)); ?>">
                        <?php else: ?><article class="eg-hub-card is-closed" data-tone="<?php echo esc_attr(Cards::tone($id)); ?>"><?php endif; ?>
                            <?php echo Cards::card($game,$playable,$state); ?>
                        <?php if($playable): ?></a><?php else: ?></article><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php return Page_Response::themed(__('교육 게임','edu-games'),(string)ob_get_clean());
    }
}
