<?php
declare(strict_types=1);
namespace EduGames\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

final class Console_Game_Report_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $games=array();foreach(\edu_games_registry()->all(false) as $id=>$game)if(($game['status']??'')==='playable')$games[]=array('id'=>$id,'title'=>$game['title'],'modes'=>\EduGames\Reports\Report::modes($id));
        // The report API keeps UTC (filters and "last"); the page converts
        // only the displayed last-activity time to the site time zone.
        $timezone=function_exists('wp_timezone_string')?(string)wp_timezone_string():'UTC';
        $config=array('restUrl'=>esc_url_raw(rest_url('edu-games/v1')),'nonce'=>wp_create_nonce('wp_rest'),'games'=>$games,'timezone'=>$timezone);
        $refresh='<button type="button" class="ojp-button ojp-button--tinted" data-action="refresh">'.esc_html(__('기록 새로고침','edu-games')).'</button>';
        ob_start(); ?>
        <section class="edu-game-report" data-game-report data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <?php echo Page_Response::header(__('학습 활동','edu-games'),__('게임 기록','edu-games'),__('담당 학급·그룹 학생의 게임 참여와 점수를 봅니다. 기간은 UTC 날짜로 고르고, 최근 활동은 사이트 시간대로 표시합니다.','edu-games'),$refresh); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
            <div class="ojp-filter-bar eg-report-filters">
                <div class="ojp-field eg-report-field">
                    <label for="eg-report-context">학급·그룹</label>
                    <div class="eg-report-context">
                        <select class="ojp-select" id="eg-report-context" data-field="context"></select>
                        <button type="button" class="ojp-button ojp-button--plain ojp-button--small" data-action="contexts">학급 새로고침</button>
                        <button type="button" class="ojp-button ojp-button--plain ojp-button--small" data-action="more" hidden>학급 더 보기</button>
                    </div>
                </div>
                <div class="ojp-field eg-report-field"><label for="eg-report-game">게임</label><select class="ojp-select" id="eg-report-game" data-field="game"></select></div>
                <div class="ojp-field eg-report-field"><label for="eg-report-mode">기록 종류</label><select class="ojp-select" id="eg-report-mode" data-field="mode"></select></div>
                <div class="ojp-field eg-report-field eg-report-date"><label for="eg-report-from">시작일</label><input class="ojp-input" id="eg-report-from" type="date" data-field="from"></div>
                <div class="ojp-field eg-report-field eg-report-date"><label for="eg-report-to">종료일</label><input class="ojp-input" id="eg-report-to" type="date" data-field="to"></div>
            </div>
            <p class="eg-report-status" data-field="status" role="status"></p>
            <div data-field="records"></div>
        </section>
        <?php return Page_Response::console(__('게임 기록','edu-games'),(string)ob_get_clean());
    }
}
