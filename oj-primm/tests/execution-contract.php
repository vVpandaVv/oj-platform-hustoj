<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
OjPrimm\Core\Autoloader::register();
require_once dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform/src/Kernel/Autoloader.php';
CodingPanda\OjPlatform\Kernel\Autoloader::register(dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform');

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use OjPrimm\Support\PlatformLessonExecutor;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    ++$checks; if (! $condition) { throw new RuntimeException($label); }
};
$denies = static function (callable $work, string $label) use ($assert): void {
    try { $work(); } catch (DomainException) { $assert(true, $label); return; } $assert(false, $label);
};
$gateway = new class implements Api\AddonCodeExecutionGatewayV1 {
    public bool $enabled = true;
    public bool $fail = false;
    public ?Api\AddonCodeExecutionRequestV1 $request = null;
    public ?Api\AddonCodeExecutionQueryV1 $query = null;
    public string $ticket;
    public function __construct() { $this->ticket = '321.ojv' . str_repeat('a', 12) . '_' . str_repeat('b', 16) . '.' . str_repeat('c', 64); }
    public function problem(): Api\AddonApiProblemV1 { return new Api\AddonApiProblemV1('addon_runtime_unavailable', 'OJ 연결을 확인해 주세요.', 'exec-test-1', true); }
    public function availability(): Api\AddonCodeExecutionAvailabilityV1 {
        return new Api\AddonCodeExecutionAvailabilityV1($this->enabled, $this->enabled ? ['python', 'cpp'] : [], $this->enabled ? null : $this->problem());
    }
    public function submit(Api\AddonCodeExecutionRequestV1 $request): Api\AddonCodeExecutionResultV1 {
        $this->request = $request;
        if ($this->fail) { return new Api\AddonCodeExecutionResultV1(false, [], $this->problem()); }
        return new Api\AddonCodeExecutionResultV1(true, ['ticket' => $this->ticket, 'status' => 'pending', 'verdict' => 'pending',
            'result_code' => 0, 'actual_output' => '', 'diagnostics' => ''], null);
    }
    public function poll(Api\AddonCodeExecutionQueryV1 $query): Api\AddonCodeExecutionResultV1 {
        $this->query = $query;
        if ($this->fail) { return new Api\AddonCodeExecutionResultV1(false, [], $this->problem()); }
        return new Api\AddonCodeExecutionResultV1(true, ['ticket' => $this->ticket, 'status' => 'completed', 'verdict' => 'executed',
            'result_code' => 13, 'actual_output' => "7\n", 'diagnostics' => ''], null);
    }
};
$runtime = new class($gateway) implements Api\AddonCodeExecutionRuntimeContextV1 {
    public string $status = 'accepted';
    public function __construct(private Api\AddonCodeExecutionGatewayV1 $gateway) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 { return new Api\AddonRuntimeSnapshotV1('oj-primm', '0.3.0', '>=2.3.25 <3.0.0', '2.3.25', '^1.5', '1.5.0', $this->status, '', 'catalog', [], 'execution-test'); }
    public function contexts(): Api\AddonContextGatewayV1 { throw new LogicException('Unused'); }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('Unused'); }
    public function codeExecution(): Api\AddonCodeExecutionGatewayV1 { return $this->gateway; }
};
$executor = new PlatformLessonExecutor(static fn () => $runtime);
$available = $executor->availability();
$assert($available['available'] && $available['languages'] === ['python', 'cpp'], '플랫폼 실행 가능 언어를 그대로 사용');
$gateway->enabled = false;
$assert(! $executor->availability()['available'] && str_contains($executor->availability()['message'], 'OJ 연결'), '채점기 설정 오류를 안전하게 안내');
$gateway->enabled = true;
$source = "print(int(input()) + 1)\n";
$key = str_repeat('long-run-key:', 30);
$pending = $executor->execute(10, 'python', $source, "6\n", $key, null);
$assert($pending['status'] === 'pending' && $pending['ticket'] === $gateway->ticket, '개인 OJ 실행 대기 ticket 보존');
$assert($gateway->request?->actorUserId() === 10 && $gateway->request->language() === 'python'
    && $gateway->request->source() === $source && $gateway->request->input() === "6\n", '공개 typed API에 생성 코드와 해당 입력만 전달');
$assert(strlen($gateway->request->idempotencyKey()) <= 160 && $gateway->request->idempotencyKey() === 'primm-verify:' . hash('sha256', $key), '긴 작업 키는 안정적인 공개 API 토큰으로 축약');
$done = $executor->execute(10, 'python', $source, "6\n", $key, $pending['ticket']);
$assert($gateway->query?->actorUserId() === 10 && $gateway->query->ticket() === $pending['ticket'], '재조회는 원래 교사와 저장한 ticket에 바인딩');
$assert($done['status'] === 'completed' && $done['verdict'] === 'executed' && $done['actual_output'] === "7\n", '실행 완료만 전달하고 출력 일치 판정은 verifier 책임');
$gateway->fail = true;
$denies(fn () => $executor->execute(10, 'python', $source, '', $key, null), '채점 submit 실패는 수정용 코드 오류로 위조하지 않음');
$denies(fn () => $executor->execute(10, 'python', $source, '', $key, $pending['ticket']), '채점 poll 실패도 성공 출력으로 위조하지 않음');
$missing = new PlatformLessonExecutor(static fn () => null);
$assert(! $missing->availability()['available'], '공개 실행 API 없는 플랫폼 안전 미준비');
$denies(fn () => $missing->execute(10, 'python', $source, '', $key, null), '승인 runtime 없는 실행 차단');
echo 'PRIMM execution adapter checks passed: ' . $checks . " (public platform DTOs, fixture gateway)\n";
