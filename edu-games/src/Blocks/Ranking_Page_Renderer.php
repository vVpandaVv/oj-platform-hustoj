<?php
declare(strict_types=1);
namespace EduGames\Blocks;
use EduGames\Support\Capabilities;
use EduGames\Games\{Game_Visibility,Game_Category_Labels};

final class Ranking_Page_Renderer {
    private static function levels(string $class): array {
        $rows=array();foreach($class::levels() as $id=>$level)$rows[]=array('id'=>$id,'title'=>$level['title']);return $rows;
    }
    public static function games(): array {
        $registry=function_exists('edu_games_registry')?edu_games_registry()->all(false):array();$games=array();
        foreach($registry as $id=>$game){
            if(($game['status']??'')!=='playable'||(!Game_Visibility::is_open($id)&&!Capabilities::can_preview_closed()))continue;
            $variants=array();$play=isset(\EduGames\Play\Catalog::GAMES[$id]);$canRun=Capabilities::can_play()&&Game_Visibility::is_open($id);
            if($play&&$canRun){$variants[]=array('id'=>'play','title'=>'현재 게임 · 오늘의 도전','api'=>'/play/'.$id.'/summary','archive'=>'/play/'.$id.'/archive','levels'=>self::levels(\EduGames\Play\Catalog::game($id)));}
            $variants[]=array('id'=>'arcade','title'=>$play?'이전 아케이드':'개인 최고 기록','levels'=>array());
            if(isset(\EduGames\Boards\Catalog::GAMES[$id])&&$canRun)$variants[]=array('id'=>'strategy','title'=>'이전 보드 기록','api'=>'/strategy/'.$id.'/summary','archive'=>'/strategy/'.$id.'/archive','levels'=>self::levels(\EduGames\Boards\Catalog::game($id)));
            if($id==='data-structure-lab'&&$canRun)$variants[]=array('id'=>'warehouse','title'=>'이전 물류 보드','api'=>'/warehouse/summary','levels'=>self::levels(\EduGames\Games\Modules\Data_Structure_Lab\Warehouse_Board::class));
            if(isset(\EduGames\Learning\Catalog::PROVIDERS[$id])&&$canRun)$variants[]=array('id'=>'assessment','title'=>'개념 평가 기록','api'=>'/learning/'.$id.'/summary','levels'=>array_map(static fn($key,$label)=>array('id'=>$key,'title'=>$label),array('all','easy','normal','hard'),array('혼합','기초','보통','심화')));
            $category=(string)($game['category']??'data-basics');
            $games[]=array('id'=>$id,'title'=>$play?\EduGames\Play\Catalog::TITLES[$id]:$game['title'],'category'=>Game_Category_Labels::label($category),'categoryId'=>$category,
                'icon'=>match($category){'hardware'=>'memory-stick','ai'=>'lightbulb','algorithms'=>'list','data-analysis'=>'chart-no-axes-column',default=>'database'},
                'tone'=>match($category){'hardware'=>'coral','ai'=>'amber','algorithms'=>'mint',default=>'blue'},'variants'=>$variants,
                'url'=>home_url('/oj-addons/edu-games/games/'.$id));
        }
        return $games;
    }
    public static function render(): string {
        Rank_Pager_Renderer::assets();
        wp_enqueue_style('edu-games-ranking-page',plugins_url('assets/rankings/page.css',EDU_GAMES_FILE),array('edu-games-rank-pager'),EDU_GAMES_VERSION);
        wp_enqueue_script('edu-games-ranking-page',plugins_url('assets/rankings/page.js',EDU_GAMES_FILE),array('edu-games-rank-pager'),EDU_GAMES_VERSION,true);
        $config=array('restUrl'=>esc_url_raw(rest_url('edu-games/v1')),'nonce'=>wp_create_nonce('wp_rest'),'games'=>self::games(),
            'userId'=>get_current_user_id(),'userName'=>(string)wp_get_current_user()->display_name,'isLoggedIn'=>is_user_logged_in(),
            'canView'=>Capabilities::can_play()||Capabilities::can_manage(),'canManage'=>Capabilities::can_manage(),
            'icons'=>plugins_url('assets/shell/icons.svg',EDU_GAMES_FILE),'hubUrl'=>home_url('/oj-addons/edu-games/games'),
            'manageUrl'=>home_url('/oj-console/addons/edu-games/manage'));
        return '<section class="edu-rankings" data-rankings-page data-config="'.esc_attr(wp_json_encode($config)).'">'
            .'<header class="er-page-heading"><div><p>교육 게임</p><h1>게임 순위</h1></div><a class="er-back" href="'.esc_url($config['hubUrl']).'">게임 허브 <svg aria-hidden="true"><use href="'.esc_url($config['icons']).'#arrow-right"></use></svg></a></header>'
            .'<div data-er-app></div></section>';
    }
}
