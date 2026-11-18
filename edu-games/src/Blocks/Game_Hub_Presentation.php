<?php
declare(strict_types=1);
namespace EduGames\Blocks;

final class Game_Hub_Presentation {
    private const CARDS = [
        'binary-conversion'=>['binary','blue','비트로 숫자 맞히기'],
        'image-data'=>['image','coral','16색 만들기·복원'],
        'ai-model-lab'=>['bot','amber','손글씨 가르치기'],
        'code-typing'=>['code-xml','mint','Python · C 타이핑'],
        'device-matching'=>['copy','coral','사진·이름 짝 맞추기'],
        'keyword-rain'=>['cloud-rain','blue','키워드 빠르게 입력'],
        'cipher-vault'=>['vault','amber','암호 풀고 금고 열기'],
        'dance-bot'=>['music-2','mint','동작 묶고 반복하기'],
    ];
    public static function assets(): void {
        $file='resources/css/public-hub.css';
        wp_enqueue_style('edu-games-public-hub',plugins_url($file,EDU_GAMES_FILE),[],(string)filemtime(EDU_GAMES_PATH.$file));
    }
    public static function icon(string $name): string {
        return '<svg class="eg-hub-icon" aria-hidden="true" focusable="false"><use href="'.esc_url(plugins_url('assets/hub/icons.svg',EDU_GAMES_FILE)).'#'.esc_attr($name).'"></use></svg>';
    }
    public static function tone(string $id): string {return self::CARDS[$id][1]??'blue';}
    public static function card(array $game,bool $playable,string $state): string {
        $id=(string)($game['id']??'');[$icon,,$summary]=self::CARDS[$id]??['puzzle','blue',(string)($game['description']??'')];
        $title=(string)($game['title']??$id);
        $label=match($id){'binary-conversion'=>'이진수 아케이드','image-data'=>'픽셀 공방','ai-model-lab'=>'손글씨 로봇',default=>$title};
        return '<div class="eg-hub-card-top"><span class="eg-hub-art">'.self::icon($icon).'</span>'
            .(!$playable?'<span class="eg-hub-state">'.esc_html($state).'</span>':'<span class="eg-hub-open" title="게임 열기">'.self::icon('arrow-up-right').'</span>').'</div>'
            .'<div class="eg-hub-card-copy"><h3 title="'.esc_attr($title).'">'.esc_html($label).'</h3><p>'.esc_html($summary).'</p></div>';
    }
}
