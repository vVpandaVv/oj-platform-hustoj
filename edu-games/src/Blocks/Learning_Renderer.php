<?php
declare(strict_types=1);
namespace EduGames\Blocks;

use EduGames\Learning\Catalog;

final class Learning_Renderer extends Abstract_Game_Renderer {
    public static function supplement(string $game, string $title): string {
        return '<details class="edu-learning-supplement"><summary>'.esc_html($title).'</summary>'.self::render_game($game,$title).'</details>';
    }
    public static function report(): string {
        if(!\EduGames\Support\Capabilities::can_play()){return '';}
        $games=array_intersect_key(edu_games_registry()->all(),Catalog::PROVIDERS);
        $games=array_filter($games,static fn(array $g): bool=>\EduGames\Games\Game_Visibility::is_open($g['id']));
        if(!$games){return '';}
        self::assets();
        $config=array('reportOnly'=>true,'gameId'=>array_key_first($games),'restUrl'=>esc_url_raw(rest_url('edu-games/v1')),'nonce'=>wp_create_nonce('wp_rest'),'version'=>Catalog::VERSION,'userId'=>get_current_user_id());
        ob_start(); ?>
        <section class="platform-page edu-learning-block" data-learning data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <header class="edu-learn-header"><h2>개념 실습 기록·평가 순위</h2><span class="edu-learn-version">2026.09 · 이전 아케이드 점수와 별도</span></header>
            <div class="edu-learn-toolbar"><label>게임<select data-role="report-game"><?php foreach($games as $id=>$game): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($game['title']); ?></option><?php endforeach; ?></select></label>
            <label>난이도<select data-role="level"><option value="all">혼합</option><option value="easy">기초</option><option value="normal">보통</option><option value="hard">심화</option></select></label>
            <button type="button" data-command="history">기록 조회</button></div><div data-role="work"></div><div data-role="message" role="status"></div><div data-role="history"></div>
        </section>
        <?php return (string)ob_get_clean();
    }
    private static function assets(): void {
        Rank_Pager_Renderer::assets();
        wp_enqueue_style('edu-games-learning',plugins_url('assets/learning/style.css',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/learning/style.css'));
        wp_enqueue_script('edu-games-chart',plugins_url('assets/vendor/chartjs/chart.umd.min.js',EDU_GAMES_FILE),array(),'4.5.1',true);
        wp_enqueue_script('edu-games-learning',plugins_url('assets/learning/view.js',EDU_GAMES_FILE),array('edu-games-chart','edu-games-rank-pager'),(string)filemtime(EDU_GAMES_PATH.'assets/learning/view.js'),true);
    }
    public static function render_game(string $game,string $title,bool $is_block=false): string {
        self::assets();
        $counts=array_count_values(array_column(Catalog::questions($game),'level'));
        $config=array('gameId'=>$game,'restUrl'=>esc_url_raw(rest_url('edu-games/v1')),'nonce'=>wp_create_nonce('wp_rest'),'version'=>Catalog::VERSION,'counts'=>$counts,'userId'=>get_current_user_id());
        $wrapper=self::wrapper_attributes($is_block,'edu-learning-block');
        ob_start(); ?>
        <section <?php echo $wrapper; ?> data-learning data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <header class="edu-learn-header"><h2><?php echo esc_html($title); ?></h2><span class="edu-learn-version">개념 실습 · 2026.09</span></header>
            <div class="edu-learn-toolbar">
                <label>활동 <select data-role="mode"><option value="guided">안내 연습</option><option value="practice" selected>독립 연습</option><option value="assessment">평가</option></select></label>
                <label><?php echo $game==='ai-model-lab'?'주제':'난이도'; ?> <select data-role="level"><option value="all">혼합</option><?php foreach($game==='ai-model-lab'?array('easy'=>'라벨링·품질 비교','normal'=>'훈련 데이터','hard'=>'평가 지표'):array('easy'=>'기초','normal'=>'보통','hard'=>'심화') as $key=>$label): if(($counts[$key]??0)>0): ?><option value="<?php echo esc_attr($key); ?>" <?php echo $game==='ai-model-lab'&&$key==='easy'?'selected':''; ?>><?php echo esc_html($label); ?></option><?php endif; endforeach; ?></select></label>
                <button type="button" data-command="start">새 세트 시작</button>
                <button type="button" data-command="resume" hidden>이어 하기</button>
                <button type="button" data-command="history">내 기록·평가 순위</button>
            </div>
            <div class="edu-learn-status" data-role="status" aria-live="polite">준비</div>
            <div data-role="work"></div>
            <div class="edu-learn-message" data-role="message" role="status" aria-live="polite"></div>
            <div data-role="history" hidden></div>
        </section>
        <?php return (string)ob_get_clean();
    }
}
