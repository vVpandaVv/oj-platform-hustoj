<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionIdentity;

final class CodeExecutionAccountErasureParticipant implements AccountErasureParticipant
{
    public function __construct(private readonly CodeExecutionEngine $engine, private readonly \Closure $signingKey,
        private readonly ?\Closure $legacyKey = null) {}
    public function moduleKey(): string { return 'submission_code_execution'; }
    public function blockers(int $userId): array
    {
        try {
            foreach($this->prefixes($userId) as $prefix){if($this->engine->hasRecords($prefix)&&!$this->engine->isAvailable()){throw new \RuntimeException('Judge unavailable.');}}
            return [];
        } catch (\Throwable) {}
        return [new AccountErasureBlocker('submissions', 'judge_connection_required', 'OJ 예제 검증 기록 삭제를 위한 채점 연결', 1)];
    }
    public function anonymize(int $userId, string $currentUserLogin, string $anonymousUserLogin): void
    {
        foreach($this->prefixes($userId) as $prefix){$this->engine->erase($prefix);}
    }
    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        foreach($this->prefixes($userId) as $prefix){if($this->engine->hasRecords($prefix)){return false;}}
        return true;
    }
    private function prefixes(int $actor): array
    {
        $key = ($this->signingKey)();
        if (! is_string($key) || strlen($key) < 32) { throw new \RuntimeException('Execution signing key unavailable.'); }
        $prefixes=[CodeExecutionIdentity::actorPrefix($actor, $key)];
        if($this->legacyKey!==null){
            $legacy=($this->legacyKey)();
            if(!is_string($legacy)||strlen($legacy)<32){throw new \RuntimeException('Legacy execution identity key unavailable.');}
            $prefixes[]=CodeExecutionIdentity::actorPrefix($actor,$legacy);
        }
        return array_values(array_unique($prefixes));
    }
}
