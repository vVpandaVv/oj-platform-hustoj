<?php
declare(strict_types=1);
namespace EduGames\Blocks;
use EduGames\Play\Catalog;

final class Play_Renderer extends Abstract_Game_Renderer {
    public static function reports(): string {
        $html='';
        foreach(Catalog::TITLES as $game=>$title)if(\EduGames\Support\Capabilities::can_play()&&\EduGames\Games\Game_Visibility::is_open($game))$html.='<details class="edu-learning-supplement"><summary>'.esc_html($title).' 기록</summary>'.self::render($game,false,true).'</details>';
        return $html;
    }
    public static function render(string $game,bool $is_block=false,bool $report=false): string {
        wp_enqueue_style('edu-games-play',plugins_url('assets/play/style.css',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/play/style.css'));
        wp_enqueue_style('edu-games-hands',plugins_url('assets/play/hands.css',EDU_GAMES_FILE),array('edu-games-play'),(string)filemtime(EDU_GAMES_PATH.'assets/play/hands.css'));
        wp_enqueue_script('edu-games-chart',plugins_url('assets/vendor/chartjs/chart.umd.min.js',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/vendor/chartjs/chart.umd.min.js'),true);
        wp_enqueue_script('edu-games-missing-tools',plugins_url('assets/play/missing.js',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/play/missing.js'),true);
        wp_enqueue_script('edu-games-rescue-scenes',plugins_url('assets/play/rescue-scenes.js',EDU_GAMES_FILE),array('edu-games-chart'),(string)filemtime(EDU_GAMES_PATH.'assets/play/rescue-scenes.js'),true);
        wp_enqueue_script('edu-games-rescue-tools',plugins_url('assets/play/rescue.js',EDU_GAMES_FILE),array('edu-games-chart','edu-games-rescue-scenes'),(string)filemtime(EDU_GAMES_PATH.'assets/play/rescue.js'),true);
        wp_enqueue_script('edu-games-play-views',plugins_url('assets/play/hands.js',EDU_GAMES_FILE),array('edu-games-chart','edu-games-missing-tools','edu-games-rescue-tools'),(string)filemtime(EDU_GAMES_PATH.'assets/play/hands.js'),true);
        wp_enqueue_script('edu-games-play',plugins_url('assets/play/core.js',EDU_GAMES_FILE),array('edu-games-play-views','edu-games-rank-pager'),(string)filemtime(EDU_GAMES_PATH.'assets/play/core.js'),true);
        $class=Catalog::game($game);$previews=array();foreach($class::levels() as $id=>$d)$previews[$id]=$class::view($class::create($id));
        $config=array('hubUrl'=>home_url('/oj-addons/edu-games/games'),'restUrl'=>esc_url_raw(rest_url('edu-games/v1/play/'.$game)),'game'=>$game,'nonce'=>wp_create_nonce('wp_rest'),'userId'=>get_current_user_id(),'version'=>Catalog::version($game),'previews'=>$previews,'reportOnly'=>$report);
        ob_start(); ?>
        <section <?php echo self::wrapper_attributes($is_block,'edu-play'); ?> data-play data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <header class="ep-heading"><h2><?php echo esc_html(Catalog::TITLES[$game]); ?></h2><span data-role="challenge"><?php echo esc_html(reset($previews)['title']); ?></span></header>
            <details class="ep-settings" data-role="settings" <?php echo $report?'open':''; ?>><summary>도전 설정</summary>
            <div class="ep-toolbar"><label <?php echo count($previews)<2?'hidden':''; ?>>도전 <select data-role="level"><?php foreach($previews as $id=>$view): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($view['title']); ?></option><?php endforeach; ?></select></label>
            <?php if(!$report): ?><button type="button" data-command="new">새 도전</button><?php endif; ?>
            </div></details>
            <?php if(!$report): ?><div class="ep-recovery"><button type="button" data-command="resume" hidden>이어 하기</button><button type="button" data-command="retry" hidden>저장 재시도</button></div><?php endif; ?>
            <p data-role="message" role="status" aria-live="polite"></p>
            <?php if(!$report): ?><div data-role="board"></div><?php endif; ?>
            <details data-role="records" <?php echo $report?'open':''; ?>><summary>내 기록과 상황별 순위</summary><button type="button" data-command="records">기록 갱신</button><div data-role="leaders"></div><button type="button" data-command="archive">이전 규칙 기록</button><div data-role="archive"></div></details>
        </section>
        <?php return (string)ob_get_clean();
    }
}
