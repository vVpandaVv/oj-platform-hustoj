<?php
declare(strict_types=1);
namespace EduGames\Blocks;

use EduGames\Games\Modules\Data_Structure_Lab\Warehouse_Board;

final class Warehouse_Renderer extends Abstract_Game_Renderer {
    public static function render(bool $is_block=false, bool $report_only=false): string {
        wp_enqueue_style('edu-games-warehouse',plugins_url('assets/warehouse/style.css',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/warehouse/style.css'));
        wp_enqueue_script('edu-games-warehouse',plugins_url('assets/warehouse/view.js',EDU_GAMES_FILE),array('edu-games-rank-pager'),(string)filemtime(EDU_GAMES_PATH.'assets/warehouse/view.js'),true);
        $previews=array();if(!$report_only)foreach(Warehouse_Board::levels() as $id=>$d)$previews[$id]=Warehouse_Board::view(Warehouse_Board::create($id));
        $config=array('restUrl'=>esc_url_raw(rest_url('edu-games/v1/warehouse')),'nonce'=>wp_create_nonce('wp_rest'),'version'=>Warehouse_Board::VERSION,'userId'=>get_current_user_id(),'reportOnly'=>$report_only,'previews'=>$previews);
        ob_start(); ?>
        <section <?php echo self::wrapper_attributes($is_block,'edu-warehouse'); ?> data-warehouse data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <header class="ew-header"><img src="<?php echo esc_url(plugins_url('assets/warehouse/crate.svg',EDU_GAMES_FILE)); ?>" alt="" width="48" height="48"><div><h2>자료구조 물류 기지</h2><p data-role="collection">구역 12 · 수집할 별 36</p></div><strong class="ew-season">시즌 1</strong></header>
            <div class="ew-toolbar"><label>구역<select data-role="level"><?php foreach(Warehouse_Board::levels() as $id=>$level): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($level['number'].'. '.$level['title']); ?></option><?php endforeach; ?></select></label>
            <?php if(!$report_only): ?><button type="button" data-command="start">도전 시작</button><button type="button" data-command="resume" hidden>이어 하기</button><?php endif; ?>
            <button type="button" data-command="refresh">기록 새로고침</button></div>
            <div class="ew-message" data-role="message" role="status" aria-live="polite"></div>
            <button type="button" data-command="retry" hidden>같은 요청 다시 보내기</button>
            <div data-role="board"></div>
            <nav class="ew-map" data-role="map" aria-label="물류 구역"></nav>
            <section class="ew-records"><h3>구역 최고 기록</h3><div data-role="personal"></div><div data-role="leaders"></div></section>
        </section>
        <?php return (string)ob_get_clean();
    }
    public static function report(): string {
        if (!\EduGames\Support\Capabilities::can_play()||!\EduGames\Games\Game_Visibility::is_open(Warehouse_Board::GAME)) { return ''; }
        return self::render(false,true);
    }
}
