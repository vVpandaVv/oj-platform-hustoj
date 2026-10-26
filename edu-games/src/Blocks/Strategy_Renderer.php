<?php
declare(strict_types=1);
namespace EduGames\Blocks;
use EduGames\Boards\Catalog;

final class Strategy_Renderer extends Abstract_Game_Renderer {
    public static function render(string $game,string $title,bool $is_block=false,bool $report=false): string {
        wp_enqueue_script('edu-games-chart',plugins_url('assets/vendor/chartjs/chart.umd.min.js',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/vendor/chartjs/chart.umd.min.js'),true);
        wp_enqueue_script('edu-games-strategy-views',plugins_url('assets/strategy/board-views.js',EDU_GAMES_FILE),array('edu-games-chart'),(string)filemtime(EDU_GAMES_PATH.'assets/strategy/board-views.js'),true);
        foreach(array('style.css','view.js') as $file){$url=plugins_url('assets/strategy/'.$file,EDU_GAMES_FILE);$version=(string)filemtime(EDU_GAMES_PATH.'assets/strategy/'.$file);if($file==='style.css')wp_enqueue_style('edu-games-strategy',$url,array(),$version);else wp_enqueue_script('edu-games-strategy',$url,array('edu-games-strategy-views','edu-games-rank-pager'),$version,true);}
        $class=Catalog::game($game);$previews=array();foreach($class::levels() as $id=>$d)$previews[$id]=$class::view($class::create($id));
        $config=array('restUrl'=>esc_url_raw(rest_url('edu-games/v1/strategy/'.$game)),'game'=>$game,'nonce'=>wp_create_nonce('wp_rest'),'userId'=>get_current_user_id(),'version'=>Catalog::version($game),'previews'=>$previews,'reportOnly'=>$report);
        ob_start(); ?>
        <section <?php echo self::wrapper_attributes($is_block,'edu-strategy'); ?> data-strategy data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <header class="es-header"><div><p>전략 보드 · 시즌 <?php echo $game==='outlier-detection'?'3':'2'; ?></p><h2><?php echo esc_html($title); ?></h2></div><strong data-role="collection">보드 <?php echo count($previews); ?> · 별 <?php echo count($previews)*3; ?>개</strong></header>
            <div class="es-toolbar"><label>보드<select data-role="level"><?php foreach($previews as $id=>$view): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($view['title']); ?></option><?php endforeach; ?></select></label>
            <?php if(!$report): ?><button type="button" data-command="start">도전 시작</button><button type="button" data-command="resume" hidden>이어 하기</button><?php endif; ?>
            <button type="button" data-command="refresh">기록 갱신</button><button type="button" data-command="retry" hidden>저장 재시도</button></div>
            <p data-role="message" class="es-message" role="status" aria-live="polite"></p>
            <?php if(!$report): ?><div data-role="board"></div><?php endif; ?>
            <details class="es-campaign"><summary>전체 보드 · <?php echo count($previews); ?>개</summary><nav class="es-map" data-role="map" aria-label="보드 진행"></nav></details>
            <details class="es-records" <?php echo $report?'open':''; ?>><summary>내 기록과 순위</summary><p data-role="personal"></p><div data-role="leaders"></div><button type="button" data-command="archive">이전 시즌 기록</button><div data-role="archive"></div></details>
        </section>
        <?php return (string)ob_get_clean();
    }
    public static function reports(): string {
        $html='';$titles=array('data-extraction'=>'주문 처리소','missing-values'=>'관측망 복구','outlier-detection'=>'관측소 조사','data-visualization'=>'차트 타일 공방','ai-classifier-lab'=>'분류 구역 설계','ai-model-lab'=>'모델 운영 연구소');
        foreach($titles as $id=>$title){if(\EduGames\Support\Capabilities::can_play()&&\EduGames\Games\Game_Visibility::is_open($id))$html.='<details class="edu-learning-supplement"><summary>'.esc_html($title).' 개인 보드 기록</summary>'.self::render($id,$title,false,true).'</details>';}
        return $html;
    }
}
