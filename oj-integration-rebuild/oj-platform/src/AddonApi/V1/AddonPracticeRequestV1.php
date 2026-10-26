<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
use InvalidArgumentException;

final class AddonPracticeRequestV1
{
    public function __construct(private readonly int $actorUserId,private readonly AddonPracticeMaterialV1 $material,
        private readonly string $source,private readonly int $testIndex,private readonly string $idempotencyKey)
    {
        if(!isset($material->tests()[$testIndex])){throw new InvalidArgumentException('Invalid practice test index.');}
        new AddonCodeExecutionRequestV1($actorUserId,$material->language(),$source,$material->tests()[$testIndex]['input'],$idempotencyKey);
    }
    public function actorUserId():int{return $this->actorUserId;}
    public function material():AddonPracticeMaterialV1{return $this->material;}
    public function source():string{return $this->source;}
    public function testIndex():int{return $this->testIndex;}
    public function idempotencyKey():string{return $this->idempotencyKey;}
}
