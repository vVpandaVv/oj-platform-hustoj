<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeContext;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonEditorGateway;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionCodeEditorAssets;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);
$checks = 0;
function editorCheck(bool $condition, string $message): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

$accounts = new class implements CurrentAccountProvider {
    public ?int $userId = 7;
    public bool $throws = false;
    public function current(): CurrentAccount
    {
        if ($this->throws) {
            throw new RuntimeException('private account error');
        }
        return new CurrentAccount(
            $this->userId === null ? null : new UserIdentity($this->userId, 'test', '', '테스트'),
            null
        );
    }
};
$preferences = new class implements UserSubmissionPreferenceProvider {
    public array $reads = [];
    public bool $throws = false;
    public function forUser(int $userId): UserSubmissionPreferences
    {
        $this->reads[] = $userId;
        if ($this->throws) {
            throw new RuntimeException('private preference error');
        }
        return new UserSubmissionPreferences(null, $userId === 7 ? 'ace/theme/monokai' : 'github', 'private template', 'code', $userId === 7 ? 18 : 14);
    }
};
$assets = new class implements SubmissionCodeEditorAssets {
    public bool $ready = true;
    public bool $throws = false;
    public bool $badUrl = false;
    public function isReady(): bool { return $this->ready; }
    public function urls(): array
    {
        if ($this->throws) { throw new RuntimeException('private asset error'); }
        return ['core' => $this->badUrl ? 'javascript:alert(1)' : '/assets/ace/ace.js', 'base' => '/assets/ace/'];
    }
};
$gateway = new RealAddonEditorGateway($accounts, $preferences, $assets);
$snapshot = $gateway->current();
editorCheck($snapshot->theme() === 'monokai', '현재 계정의 정규화된 제출 테마를 공유해야 합니다.');
editorCheck($snapshot->fontSize() === 18, '현재 계정의 글자 크기를 공유해야 합니다.');
editorCheck($snapshot->isAvailable() && $snapshot->coreUrl() === '/assets/ace/ace.js' && $snapshot->baseUrl() === '/assets/ace/', '제출 편집기의 기존 자산을 사용해야 합니다.');
editorCheck($preferences->reads === [7], '현재 계정만 조회해야 합니다.');
editorCheck((new ReflectionMethod(AddonEditorGatewayV1::class, 'current'))->getNumberOfParameters() === 0, '호출자가 다른 사용자 번호를 지정할 수 없어야 합니다.');
editorCheck(! method_exists($snapshot, 'userId') && ! method_exists($snapshot, 'codeTemplate') && ! method_exists($gateway, 'save'), '계정 식별자, 코드 템플릿, 쓰기 API를 노출하면 안 됩니다.');
$accounts->userId = 9;
$snapshot = $gateway->current();
editorCheck($snapshot->theme() === 'github' && $snapshot->fontSize() === 14 && $preferences->reads === [7, 9], '계정 전환 후 이전 계정 설정이 남으면 안 됩니다.');
$accounts->userId = null;
$snapshot = $gateway->current();
editorCheck($snapshot->theme() === 'chrome' && $snapshot->fontSize() === 16 && $preferences->reads === [7, 9], '비로그인 요청은 계정 조회 없이 기본 설정이어야 합니다.');
$accounts->userId = 7;
$preferences->throws = true;
$snapshot = $gateway->current();
editorCheck($snapshot->theme() === 'chrome' && $snapshot->isAvailable(), '설정 조회 오류는 기본 편집기로 복구해야 합니다.');
$preferences->throws = false;
$accounts->throws = true;
editorCheck($gateway->current()->theme() === 'chrome', '계정 조회 오류에 개인 설정이 재사용되면 안 됩니다.');
$accounts->throws = false;
$assets->ready = false;
$snapshot = $gateway->current();
editorCheck(! $snapshot->isAvailable() && $snapshot->coreUrl() === '' && $snapshot->baseUrl() === '' && $snapshot->theme() === 'monokai', '자산이 없으면 URL을 비우고 표시 설정만 제공해야 합니다.');
$assets->ready = true;
$assets->throws = true;
editorCheck(! $gateway->current()->isAvailable(), '자산 조회 예외는 편집기 대체 입력으로 복구해야 합니다.');
$assets->throws = false;
$assets->badUrl = true;
editorCheck(! $gateway->current()->isAvailable(), '잘못된 자산 URL은 제공하면 안 됩니다.');
$assets->badUrl = false;
$correlations = new AddonCorrelationIdFactory();
$factory = new UnavailableAddonRuntimeServiceFactory($correlations);
$runtime = new AddonRuntimeContext('oj-code-lab', null, '2.0.0-dev.103', '1.1.0', '', $factory->contexts('oj-code-lab', null), $factory->aiTasks('oj-code-lab', null), $correlations);
editorCheck($runtime instanceof AddonRuntimeContextV1 && $runtime instanceof AddonEditorRuntimeContextV1, '추가 인터페이스는 기존 런타임 계약과 호환되어야 합니다.');
editorCheck(! $runtime->editor()->current()->isAvailable(), '이전 생성자 인자는 사용할 수 없는 편집기로 안전하게 복구해야 합니다.');
$runtime = new AddonRuntimeContext('oj-code-lab', null, '2.0.0-dev.103', '1.1.0', '', $factory->contexts('oj-code-lab', null), $factory->aiTasks('oj-code-lab', null), $correlations, $gateway);
editorCheck($runtime->editor()->current()->theme() === 'monokai', '런타임에서 실제 편집기 게이트웨이를 사용해야 합니다.');
foreach ([
    fn () => new AddonEditorSnapshotV1('../monokai', 16, false, '', ''),
    fn () => new AddonEditorSnapshotV1('chrome', 500, false, '', ''),
    fn () => new AddonEditorSnapshotV1('chrome', 16, true, '', ''),
    fn () => new AddonEditorSnapshotV1('chrome', 16, true, 'javascript:alert(1)', '/ace/'),
    fn () => new AddonEditorSnapshotV1('chrome', 16, false, '/ace/ace.js', '/ace/'),
] as $invalid) {
    $thrown = false;
    try { $invalid(); } catch (InvalidArgumentException) { $thrown = true; }
    editorCheck($thrown, '잘못된 스냅샷 입력을 거부해야 합니다.');
}
$https = new AddonEditorSnapshotV1('github', 14, true, 'https://judge.example.test/ace/ace.js', 'https://judge.example.test/ace/');
editorCheck($https->isAvailable(), 'HTTPS 자산을 허용해야 합니다.');
fwrite(STDOUT, "Addon editor runtime: {$checks} checks passed\n");
