<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AddonAiRequestLedger;
use DomainException;
use RuntimeException;
use Throwable;

/** Durable at-most-once dispatch. Lost provider outcomes require a deliberate new request key. */
final class PlatformAddonAiRequestLedger implements AddonAiRequestLedger
{
    public function __construct(private readonly ApplicationDatabase $db) {}
    public function execute(string $addonId, int $actor, string $requestKey, string $inputHash, \Closure $operation): array
    {
        $hash = hash('sha256', $addonId . ':' . $actor . ':' . $requestKey);
        $token = bin2hex(random_bytes(24)); $now = gmdate('Y-m-d H:i:s');
        $locked=(int)$this->db->value('SELECT GET_LOCK(%s,0)',['oj_platform_addon_ai_ledger'])===1;
        if(!$locked){throw new \CodingPanda\OjPlatform\Modules\Settings\Contract\AiRequestPending('AI 요청 원장을 갱신 중입니다. 잠시 후 다시 시도해 주세요.');}
        try {
            $this->db->beginTransaction();
            $row = $this->db->row('SELECT * FROM addon_ai_requests WHERE request_hash=%s FOR UPDATE', [$hash]);
            if ($row !== null) {
                if (! hash_equals((string)$row['input_hash'], $inputHash)) { throw new DomainException('같은 AI 요청 키의 입력·모델 설정이 변경되었습니다. 새 생성 요청이 필요합니다.'); }
                if ($row['status'] === 'succeeded') {
                    $cached = json_decode((string)$row['result_json'], true, 64, JSON_THROW_ON_ERROR);
                    if (! is_array($cached)) { throw new RuntimeException('AI 완료 기록을 읽지 못했습니다.'); }
                    $this->db->commit(); return $cached;
                }
                if ($row['status'] === 'inflight' && (string)$row['lease_expires_at'] > $now) {
                    throw new \CodingPanda\OjPlatform\Modules\Settings\Contract\AiRequestPending('같은 AI 요청을 처리 중입니다. 잠시 후 상태를 다시 확인해 주세요.');
                }
                $this->db->update('addon_ai_requests', ['status'=>'uncertain','updated_at'=>$now], ['request_hash'=>$hash]);
                $this->db->commit();
                throw new \CodingPanda\OjPlatform\Modules\Settings\Contract\AiRequestOutcomeUncertain('이전 AI 호출의 완료 여부가 불명확해 중복 호출을 차단했습니다. 사용량을 확인한 뒤 명시적으로 새 생성을 시작해 주세요.');
            }
            $count = $this->db->value('SELECT COUNT(*) FROM addon_ai_requests');
            if (! is_numeric($count) || (int)$count >= 5000) { throw new DomainException('AI 요청 원장의 보관 한도에 도달했습니다. 운영자가 보관 정리를 확인해 주세요.'); }
            $bytes=$this->db->value("SELECT COALESCE(SUM(OCTET_LENGTH(result_json)+CASE WHEN status='inflight' THEN 1048576 ELSE 0 END),0) FROM addon_ai_requests");
            if(!is_numeric($bytes)||(int)$bytes+1048576>67108864){throw new DomainException('AI 결과 보관 용량에 도달했습니다. 보관 정리 후 다시 시도해 주세요.');}
            if ($this->db->insert('addon_ai_requests', ['request_hash'=>$hash,'addon_id'=>$addonId,'actor_user_id'=>$actor,
                'input_hash'=>$inputHash,'status'=>'inflight','lease_token'=>$token,'lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+300),
                'result_json'=>'','created_at'=>$now,'updated_at'=>$now]) < 1) { throw new RuntimeException('AI 요청 원장을 준비하지 못했습니다.'); }
            $this->db->commit();
        } catch (Throwable $error) { try { $this->db->rollBack(); } catch (Throwable) {} throw $error; }
        finally {try{$this->db->value('SELECT RELEASE_LOCK(%s)',['oj_platform_addon_ai_ledger']);}catch(Throwable){}}
        try {
            $result = $operation();
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (! is_array($result) || strlen($json)>1048576) { throw new RuntimeException('AI 결과의 보관 크기를 초과했습니다.'); }
            $updated = $this->db->update('addon_ai_requests', ['status'=>'succeeded','result_json'=>$json,'lease_token'=>'',
                'updated_at'=>gmdate('Y-m-d H:i:s')], ['request_hash'=>$hash,'status'=>'inflight','lease_token'=>$token]);
            if ($updated!==1) { throw new DomainException('AI 요청의 저장 권한이 변경되었습니다. 결과를 배포하지 않았습니다.'); }
            return $result;
        } catch (Throwable $error) {
            try { $this->db->update('addon_ai_requests', ['status'=>'uncertain','lease_token'=>'','updated_at'=>gmdate('Y-m-d H:i:s')],
                ['request_hash'=>$hash,'status'=>'inflight','lease_token'=>$token]); } catch (Throwable) {}
            throw $error;
        }
    }
    public function cleanup(): array
    {
        $rows=$this->db->rows('SELECT request_hash FROM addon_ai_requests WHERE updated_at<%s AND status<>%s ORDER BY updated_at LIMIT 200',
            [gmdate('Y-m-d H:i:s',time()-7*86400),'inflight']);
        $deleted=0; foreach($rows as $row){$deleted+=$this->db->delete('addon_ai_requests',['request_hash'=>$row['request_hash']]);}
        $stale=$this->db->rows('SELECT request_hash FROM addon_ai_requests WHERE status=%s AND lease_expires_at<%s LIMIT 200',['inflight',gmdate('Y-m-d H:i:s')]);
        foreach($stale as $row){$this->db->update('addon_ai_requests',['status'=>'uncertain','updated_at'=>gmdate('Y-m-d H:i:s')],['request_hash'=>$row['request_hash'],'status'=>'inflight']);}
        return ['deleted'=>$deleted,'uncertain'=>count($stale)];
    }
    public function eraseActor(int $actor): void
    {
        // Removing an active row fences its late provider response; it cannot recreate the row.
        $this->db->delete('addon_ai_requests',['actor_user_id'=>$actor]);
    }
    public function hasActor(int $actor): bool
    {
        $count=$this->db->value('SELECT COUNT(*) FROM addon_ai_requests WHERE actor_user_id=%d',[$actor]);
        if(!is_numeric($count)){throw new RuntimeException('AI 요청 원장의 개인정보 보관 상태를 확인하지 못했습니다.');}
        return (int)$count>0;
    }
}
