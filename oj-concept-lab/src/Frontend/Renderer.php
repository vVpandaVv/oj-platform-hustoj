<?php
declare(strict_types=1);
namespace OjConceptLab\Frontend;
use OjConceptLab\Activities\Catalog;
use OjConceptLab\Addon\Registration;
final class Renderer {
    public static function shortcode(array $atts=[]): string {
        if(!is_user_logged_in()) return '<p>로그인 후 개념 체험에 참여하세요.</p>';
        if(!Registration::can_learn()) return '<p>개념 체험 참여 권한이 필요합니다.</p>';
        $id=is_string($atts['activity']??null)?sanitize_key($atts['activity']):'';
        if($id!==''&&!isset(Catalog::all()[$id])&&!Registration::can_manage()) return '<p>현재 공개되지 않은 체험입니다.</p>';
        wp_enqueue_style('oj-concept-lab',plugins_url('resources/css/lab.css',OJ_CONCEPT_LAB_FILE),[],OJ_CONCEPT_LAB_VERSION);
        wp_enqueue_script('oj-concept-lab-charts',plugins_url('resources/vendor/chartjs/chart.umd.min.js',OJ_CONCEPT_LAB_FILE),[],OJ_CONCEPT_LAB_VERSION,true);
        wp_enqueue_script('oj-concept-lab',plugins_url('resources/js/lab.js',OJ_CONCEPT_LAB_FILE),['oj-concept-lab-charts'],OJ_CONCEPT_LAB_VERSION,true);
        return self::render($id);
    }
    public static function render(string $id='',bool $manage=false): string {
        if($id!==''&&!isset(Catalog::ITEMS[$id])) return '<p>체험을 찾을 수 없습니다.</p>';
        $config=['activity'=>$id,'activities'=>Catalog::all($manage||Registration::can_manage()),'data'=>$id!==''?Catalog::data($id):[],
            'manage'=>$manage,'restUrl'=>rest_url('oj-concept-lab/v1'),'nonce'=>wp_create_nonce('wp_rest'),
            'homeUrl'=>home_url('/oj-addons/oj-concept-lab/activities'),'icons'=>plugins_url('resources/icons.svg',OJ_CONCEPT_LAB_FILE),
            'hubIcons'=>plugins_url('resources/hub/icons.svg',OJ_CONCEPT_LAB_FILE),
            'storageKey'=>hash('sha256',home_url('/').':'.get_current_user_id())];
        return '<section class="oj-concept-lab" data-concept-lab data-config="'.esc_attr(wp_json_encode($config)).'"><div data-cl-app><p role="status">체험을 준비하고 있습니다.</p></div><noscript>이 체험에는 JavaScript가 필요합니다.</noscript></section>';
    }
}
