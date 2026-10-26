<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionAvailabilityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionResultV1;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine;

/** Staff-only facade; learner practice has its own separately authorized gateway. */
final class RealAddonCodeExecutionGateway implements AddonCodeExecutionGatewayV1
{
    private readonly BoundCodeExecutionRunner $runner;
    public function __construct(string $addonId,CodeExecutionEngine $engine,WordPressUserGateway $users,Authorization $permissions,
        AddonCorrelationIdFactory $correlations,\Closure $signingKey,array $staffCapabilities=[],?\Closure $languages=null,?\Closure $identityKey=null)
    {
        $this->runner=new BoundCodeExecutionRunner($addonId,$engine,$correlations,$signingKey,
            static function(int $actor)use($users,$permissions,$staffCapabilities):bool{
                $current=$users->currentUser()?->userId()??0;
                $actor=$actor===0?$current:$actor;
                return $actor>0&&$current===$actor&&($permissions->isSuperAdmin($actor)||$permissions->canAny($actor,
                    array_merge(['oj_manage_problems','oj_manage_classes','oj_manage_groups','oj_manage_own_groups'],$staffCapabilities)));
            },$languages,$identityKey);
    }
    public function availability():AddonCodeExecutionAvailabilityV1{return $this->runner->availability();}
    public function submit(AddonCodeExecutionRequestV1 $request):AddonCodeExecutionResultV1{return $this->runner->submit($request);}
    public function poll(AddonCodeExecutionQueryV1 $query):AddonCodeExecutionResultV1{return $this->runner->poll($query);}
}
