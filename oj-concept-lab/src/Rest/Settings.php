<?php
declare(strict_types=1);
namespace OjConceptLab\Rest;
use OjConceptLab\Activities\Catalog;
use OjConceptLab\Addon\Registration;
final class Settings {
    public static function register(): void {
        register_rest_route('oj-concept-lab/v1','/settings',['methods'=>'GET,POST','permission_callback'=>[Registration::class,'can_manage'],'callback'=>[self::class,'run']]);
    }
    public static function run(\WP_REST_Request $request): mixed {
        if($request->get_method()==='POST') {
            $input=$request->get_json_params();$closed=$input['closed']??null;
            if(!is_array($closed)||!array_is_list($closed)||count($closed)>count(Catalog::ITEMS)) return new \WP_Error('concept_lab_settings','공개 설정을 확인하세요.',['status'=>422]);
            foreach($closed as $id) if(!is_string($id)||!isset(Catalog::ITEMS[$id])) return new \WP_Error('concept_lab_settings','체험 이름을 확인하세요.',['status'=>422]);
            $before=get_option('oj_concept_lab_closed',[]);update_option('oj_concept_lab_closed',array_values(array_unique($closed)),false);
            do_action('oj_concept_lab_visibility_changed',get_current_user_id(),$before,$closed);
        }
        return rest_ensure_response(['activities'=>Catalog::all(true)]);
    }
}
