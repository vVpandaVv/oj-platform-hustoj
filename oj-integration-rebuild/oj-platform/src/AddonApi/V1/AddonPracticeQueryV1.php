<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
final class AddonPracticeQueryV1
{
    public function __construct(private readonly int $actorUserId,private readonly AddonPracticeMaterialV1 $material,private readonly string $ticket)
    {new AddonCodeExecutionQueryV1($actorUserId,$ticket);}
    public function actorUserId():int{return $this->actorUserId;}
    public function material():AddonPracticeMaterialV1{return $this->material;}
    public function ticket():string{return $this->ticket;}
}
