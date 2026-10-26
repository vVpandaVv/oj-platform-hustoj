<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
use InvalidArgumentException;

/** Server-owned material. An addon must reconstruct this from its authorized active activity on EVERY call. */
final class AddonPracticeMaterialV1
{
    public function __construct(private readonly string $activityKey,private readonly string $revision,
        private readonly string $contextType,private readonly string $contextId,private readonly string $language,
        private readonly array $tests)
    {
        Internal\ValueValidator::safeToken($activityKey,'activityKey',160);
        Internal\ValueValidator::safeToken($revision,'revision',160);
        Internal\ValueValidator::oneOf($contextType,['class','group'],'contextType');
        if(preg_match('/^'. $contextType . ':[1-9][0-9]{0,17}$/D',$contextId)!==1){throw new InvalidArgumentException('Invalid practice context.');}
        Internal\ValueValidator::oneOf($language,['python','c','cpp','java'],'language');
        if(!array_is_list($tests)||count($tests)<1||count($tests)>8){throw new InvalidArgumentException('Invalid practice cases.');}
        foreach($tests as $test){
            if(!is_array($test)||count($test)!==2||!is_string($test['input']??null)||!is_string($test['expected_output']??null)){throw new InvalidArgumentException('Invalid practice case.');}
            foreach(['input','expected_output'] as $key){Internal\ValueValidator::text($test[$key],$key,4096,true,true);if(str_contains($test[$key],"\0")){throw new InvalidArgumentException('Invalid practice text.');}}
        }
    }
    public function activityKey():string{return $this->activityKey;}
    public function revision():string{return $this->revision;}
    public function contextType():string{return $this->contextType;}
    public function contextId():string{return $this->contextId;}
    public function language():string{return $this->language;}
    public function tests():array{return $this->tests;}
}
