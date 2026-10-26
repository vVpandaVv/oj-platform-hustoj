<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Classes\Infrastructure\WordPress;
use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassProblemChangePublisher;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
final class WordPressClassProblemChangePublisher implements ClassProblemChangePublisher
{
    public function __construct(private readonly ErrorReporter $errors){}
    public function saved(int $classId,int $actorUserId,array $problemIds):void
    {
        if(!function_exists('do_action')){return;}
        try{do_action('oj_platform_class_problems_saved',['class_id'=>$classId,'actor_user_id'=>$actorUserId,
            'problem_ids'=>array_values(array_unique($problemIds)),'changed_at'=>gmdate('Y-m-d H:i:s')]);}
        catch(\Throwable $error){$this->errors->report($error);}
    }
}
