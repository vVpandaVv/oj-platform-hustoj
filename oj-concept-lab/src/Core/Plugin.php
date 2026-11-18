<?php
declare(strict_types=1);
namespace OjConceptLab\Core;
use OjConceptLab\Addon\Registration;
use OjConceptLab\Activities\Catalog;
use OjConceptLab\Frontend\Renderer;
final class Plugin {
    public static function boot(): void {
        Registration::register();
        add_action('init', static function (): void {
            add_shortcode('oj_concept_lab', [Renderer::class, 'shortcode']);
            register_block_type('oj-concept-lab/activity', ['api_version'=>3, 'render_callback'=>[Renderer::class,'shortcode'], 'attributes'=>['activity'=>['type'=>'string','default'=>'']]]);
        });
        add_action('rest_api_init', [\OjConceptLab\Rest\Settings::class,'register']);
        add_action('plugins_loaded', static function (): void {
            do_action('oj_concept_lab_catalog_ready', new Catalog());
        }, 20);
    }
    public static function activate(): void { add_option('oj_concept_lab_closed', [], '', false); }
}
