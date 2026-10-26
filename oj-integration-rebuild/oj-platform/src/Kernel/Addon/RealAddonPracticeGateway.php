<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeMaterialV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionAvailabilityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine;

/** Every supplied material must come from the addon's server-side active activity, never request JSON. */
final class RealAddonPracticeGateway implements AddonPracticeGatewayV1
{
    public function __construct(private readonly string $addonId,private readonly CodeExecutionEngine $engine,
        private readonly WordPressUserGateway $users,private readonly AddonContextGatewayV1 $contexts,
        private readonly AddonCorrelationIdFactory $correlations,private readonly \Closure $key,private readonly ?\Closure $languages=null,
        private readonly ?\Closure $identityKey=null){}
    public function availability(AddonPracticeMaterialV1 $material):AddonCodeExecutionAvailabilityV1
    {return $this->runner($material)->availability();}
    public function submit(AddonPracticeRequestV1 $request):AddonCodeExecutionResultV1
    {
        $material=$request->material();
        return $this->runner($material)->submit(new AddonCodeExecutionRequestV1($request->actorUserId(),$material->language(),
            $request->source(),$material->tests()[$request->testIndex()]['input'],$request->idempotencyKey()));
    }
    public function poll(AddonPracticeQueryV1 $query):AddonCodeExecutionResultV1
    {return $this->runner($query->material())->poll(new AddonCodeExecutionQueryV1($query->actorUserId(),$query->ticket()));}
    private function runner(AddonPracticeMaterialV1 $material):BoundCodeExecutionRunner
    {
        $hash=hash('sha256',json_encode([$material->activityKey(),$material->revision(),$material->contextType(),
            $material->contextId(),$material->language(),$material->tests()],JSON_THROW_ON_ERROR));
        return new BoundCodeExecutionRunner($this->addonId.':practice:'.$hash,$this->engine,$this->correlations,$this->key,
            function(int $actor)use($material):bool{
                $current=$this->users->currentUser()?->userId()??0; $actor=$actor===0?$current:$actor;
                if($actor<1||$actor!==$current){return false;}
                $decision=$this->contexts->authorize(new AddonContextAccessRequestV1($actor,$material->contextType(),$material->contextId(),'participate'));
                return $decision->isAllowed()&&$decision->snapshot()?->lifecycleState()==='active';
            },$this->languages,$this->identityKey);
    }
}
