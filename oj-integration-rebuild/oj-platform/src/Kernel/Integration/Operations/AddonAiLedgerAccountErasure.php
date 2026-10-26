<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AddonAiRequestLedger;

final class AddonAiLedgerAccountErasure implements AccountErasureParticipant
{
    public function __construct(private readonly AddonAiRequestLedger $ledger){}
    public function moduleKey():string{return 'addon_ai_requests';}
    public function blockers(int $userId):array{try{$this->ledger->hasActor($userId);return [];}catch(\Throwable){return [new AccountErasureBlocker('settings','ai_ledger_unavailable','AI 요청 원장 연결',1)];}}
    public function anonymize(int $userId,string $currentUserLogin,string $anonymousUserLogin):void{$this->ledger->eraseActor($userId);}
    public function verify(int $userId,string $anonymousUserLogin):bool{return !$this->ledger->hasActor($userId);}
}
