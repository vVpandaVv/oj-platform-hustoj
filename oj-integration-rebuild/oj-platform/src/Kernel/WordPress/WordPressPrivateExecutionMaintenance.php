<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\WordPress;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AddonAiRequestLedger;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionMaintenance;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
final class WordPressPrivateExecutionMaintenance
{
    public const HOOK='oj_platform_private_execution_cleanup';
    public function __construct(private readonly CodeExecutionMaintenance $execution,private readonly AddonAiRequestLedger $ledger,
        private readonly ErrorReporter $errors){}
    public function schedule():void
    {
        try{(new WordPressCodeExecutionIdentityKey())->key();}catch(\Throwable $error){$this->errors->report($error);}
        if(!wp_next_scheduled(self::HOOK)){wp_schedule_event(time()+300,'hourly',self::HOOK);}
    }
    public function run():void
    {
        try{$this->execution->housekeeping();}catch(\Throwable $error){$this->errors->report($error);}
        try{$this->ledger->cleanup();}catch(\Throwable $error){$this->errors->report($error);}
    }
}
