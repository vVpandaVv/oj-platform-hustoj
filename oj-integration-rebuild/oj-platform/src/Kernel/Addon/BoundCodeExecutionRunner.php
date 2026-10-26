<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionAvailabilityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionResultV1;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionProbe;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionIdentity;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine;
use Throwable;

/** @internal Staff execution is bound to the current actor and this accepted addon. */
final class BoundCodeExecutionRunner implements AddonCodeExecutionGatewayV1
{
    public function __construct(private readonly string $addonId, private readonly CodeExecutionEngine $engine,
        private readonly AddonCorrelationIdFactory $correlations, private readonly \Closure $signingKey,
        private readonly \Closure $authorization, private readonly ?\Closure $languages = null,
        private readonly ?\Closure $identityKey = null) {}

    public function availability(): AddonCodeExecutionAvailabilityV1
    {
        try {
            $actor = 0; // The bound authorization closure resolves its current user for availability.
            if (! $this->allowed($actor)) { return new AddonCodeExecutionAvailabilityV1(false, [], $this->denied()); }
            $this->key();
            if ($this->engine->isAvailable() && $this->languages() !== []) { return new AddonCodeExecutionAvailabilityV1(true, $this->languages()); }
        } catch (Throwable) {}
        return new AddonCodeExecutionAvailabilityV1(false, [], $this->unavailable());
    }

    public function submit(AddonCodeExecutionRequestV1 $request): AddonCodeExecutionResultV1
    {
        if (! $this->allowed($request->actorUserId())) { return $this->failure($this->denied()); }
        try {
            if (!in_array($request->language(),$this->languages(),true)) { return $this->failure($this->unavailable()); }
            $key = $this->key();
            $identityKey = $this->identityKey($key);
            $identity = CodeExecutionIdentity::actorPrefix($request->actorUserId(), $identityKey) . '_'
                . substr(hash_hmac('sha256', json_encode([$this->addonId, $request->idempotencyKey(), $request->language(),
                    $request->source(), $request->input()], JSON_THROW_ON_ERROR), $identityKey), 0, 16);
            $source = (new CodeExecutionProbe())->instrument($request->language(), $request->source(), $this->marker($identity, $identityKey));
            $language = match ($request->language()) { 'c' => 0, 'cpp' => 1, 'java' => 3, 'python' => 6 };
            $id = $this->engine->submit($identity, $language, $source, $request->input());
            $ticket = $id . '.' . $identity . '.' . $this->signature($request->actorUserId(), $id, $identity, $key);
            return $this->result($request->actorUserId(), $ticket, $id, $identity, $key);
        } catch (\CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionBusy $error) {
            return $this->failure(new AddonApiProblemV1('addon_execution_busy',$error->getMessage(),
                $this->correlations->next($this->addonId.':execution-busy'),true));
        } catch (\DomainException) {
            // An unsupported generated entry point is a repairable code failure,
            // not a successful execution. Java generation requests conventional Main.
            return $this->failure(new AddonApiProblemV1('addon_context_unavailable', '실행할 예제 코드의 진입점 또는 형식을 확인해 주세요.',
                $this->correlations->next($this->addonId . ':code-execution'), false));
        } catch (Throwable) { return $this->failure($this->unavailable()); }
    }

    public function poll(AddonCodeExecutionQueryV1 $query): AddonCodeExecutionResultV1
    {
        if (! $this->allowed($query->actorUserId())) { return $this->failure($this->denied()); }
        try {
            $key = $this->key();
            [$id, $identity, $signature] = explode('.', $query->ticket()); $id = (int) $id;
            if (! hash_equals($this->signature($query->actorUserId(), $id, $identity, $key), $signature)
                || $this->probeKey($query->actorUserId(),$identity,$key)===null) {
                return $this->failure($this->denied());
            }
            return $this->result($query->actorUserId(), $query->ticket(), $id, $identity, $key);
        } catch (Throwable) { return $this->failure($this->unavailable()); }
    }

    private function result(int $actor, string $ticket, int $id, string $identity, string $key): AddonCodeExecutionResultV1
    {
        $row = $this->engine->poll($id, $identity);
        if ($row === null) { return $this->failure($this->unavailable()); }
        if (! $this->allowed($actor)) { return $this->failure($this->denied()); }
        if (in_array($row['result'], [0, 1, 2, 3, 12, 14], true)) {
            if ($row['age_seconds'] > 300) {
                return $this->failure(new AddonApiProblemV1('addon_execution_timeout', 'OJ 검증 대기 시간이 초과되었습니다. 채점 서버 상태를 확인한 뒤 다시 시도해 주세요.',
                    $this->correlations->next($this->addonId . ':code-execution-timeout'), true));
            }
            $probe = ['verdict' => 'pending', 'actual_output' => '', 'diagnostics' => '']; $status = 'pending';
        } else {
            $probeKey=$this->probeKey($actor,$identity,$key);
            if($probeKey===null){return $this->failure($this->denied());}
            $probe = (new CodeExecutionProbe())->inspect($row['result'], $row['output'], $row['compile_error'], $this->marker($identity, $probeKey));
            $status = 'completed';
        }
        return new AddonCodeExecutionResultV1(true, ['ticket' => $ticket, 'status' => $status,
            'actual_output' => $probe['actual_output'], 'diagnostics' => $probe['diagnostics'],
            'result_code' => $row['result'], 'verdict' => $probe['verdict']], null);
    }
    private function marker(string $identity, string $key): string { return 'OJVERIFY_' . hash_hmac('sha256', 'probe:' . $identity, $key); }
    private function signature(int $actor, int $id, string $identity, string $key): string
    {
        return hash_hmac('sha256', $this->addonId . ':' . $actor . ':' . $id . ':' . $identity, $key);
    }
    private function key(): string
    {
        $key = ($this->signingKey)();
        if (! is_string($key) || strlen($key) < 32) { throw new \RuntimeException('Execution signing key unavailable.'); }
        return $key;
    }
    private function identityKey(string $fallback):string
    {
        $key=$this->identityKey!==null?($this->identityKey)():$fallback;
        if(!is_string($key)||strlen($key)<32){throw new \RuntimeException('Execution identity key unavailable.');}
        return $key;
    }
    private function probeKey(int $actor,string $identity,string $signingKey):?string
    {
        $stable=$this->identityKey($signingKey);
        if(str_starts_with($identity,CodeExecutionIdentity::actorPrefix($actor,$stable).'_')){return $stable;}
        // A correctly signed pre-2.4 ticket still works until its AUTH salt rotates.
        return str_starts_with($identity,CodeExecutionIdentity::actorPrefix($actor,$signingKey).'_')?$signingKey:null;
    }
    private function allowed(int $actor): bool { return ($this->authorization)($actor) === true; }
    private function languages(): array { return $this->languages !== null ? ($this->languages)() : ['python','c','cpp','java']; }
    private function failure(AddonApiProblemV1 $problem): AddonCodeExecutionResultV1 { return new AddonCodeExecutionResultV1(false, [], $problem); }
    private function denied(): AddonApiProblemV1
    {
        return new AddonApiProblemV1('addon_access_denied', '현재 사용자와 활동 문맥에서 이 코드 실행을 사용할 권한이 없습니다.',
            $this->correlations->next($this->addonId . ':code-execution'), false);
    }
    private function unavailable(): AddonApiProblemV1
    {
        return new AddonApiProblemV1('addon_runtime_unavailable', '기존 OJ 채점 연결과 custominput 실행 기능을 확인해 주세요.',
            $this->correlations->next($this->addonId . ':code-execution'), true);
    }
}

