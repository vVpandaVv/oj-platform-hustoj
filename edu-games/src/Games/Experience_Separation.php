<?php
declare(strict_types=1);
namespace EduGames\Games;
/** Legacy IDs remain registered for historical reports, never for new scored play. */
final class Experience_Separation {
    public const MOVED=['outlier-detection','missing-values','data-visualization','data-extraction','data-structure-lab','ai-classifier-lab'];
    public static function moved(string $id): bool {return in_array($id,self::MOVED,true);}
    public static function catalog(array $all): array {return array_filter($all,static fn($d)=>!self::moved((string)$d['id'])&&$d['id']!=='character-code');}
    public static function notice(string $id): string {
        $ready=defined('OJ_CONCEPT_LAB_FILE');
        return '<section class="edu-games-experience-moved"><h2>개념 체험으로 이동한 활동입니다.</h2><p>기존 게임 기록은 보존됩니다.</p>'.($ready?'<a class="ojp-button ojp-button--primary" href="'.esc_url(home_url('/oj-addons/oj-concept-lab/activities/'.$id)).'">개념 체험 열기</a>':'<p>정보 개념 체험(OJ Concept Lab) 플러그인을 설치·활성화하면 참여할 수 있습니다.</p>').'</section>';
    }
}
