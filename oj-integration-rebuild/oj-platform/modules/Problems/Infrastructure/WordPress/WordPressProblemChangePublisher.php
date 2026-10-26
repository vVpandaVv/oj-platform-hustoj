<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemChangePublisher;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
final class WordPressProblemChangePublisher implements ProblemChangePublisher
{
    public function __construct(private readonly ErrorReporter $errors){}
    public function saved(int $problemId,int $actorUserId,string $change):void
    {
        if(!function_exists('do_action')){return;}
        try{do_action('oj_platform_problem_saved',['problem_id'=>$problemId,'actor_user_id'=>$actorUserId,
            'change'=>$change,'changed_at'=>gmdate('Y-m-d H:i:s')]);}
        catch(\Throwable $error){$this->errors->report($error);}
    }
}
