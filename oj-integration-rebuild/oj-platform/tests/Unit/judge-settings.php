<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Settings\SecretVaultJudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$judgeSettingsAssertions = 0;

function judgeSettingsAssert(bool $condition, string $message): void
{
    global $judgeSettingsAssertions;
    ++$judgeSettingsAssertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function judgeSettingsSame(mixed $expected, mixed $actual, string $message): void
{
    judgeSettingsAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

final class JudgeSettingsMemoryStore implements SettingStore
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function read(string $optionName, mixed $default = null): mixed
    {
        return $this->values[$optionName] ?? $default;
    }

    public function write(string $optionName, mixed $value): bool
    {
        $this->values[$optionName] = $value;

        return true;
    }
}

final class JudgeSettingsMemoryVault implements SecretVault
{
    public function __construct(
        private readonly SecretReference $reference,
        private readonly mixed $value
    ) {
    }

    public function store(mixed $value): SecretReference
    {
        unset($value);

        return $this->reference;
    }

    public function reveal(SecretReference $reference): mixed
    {
        if ($reference->value() !== $this->reference->value()) {
            throw new RuntimeException('unknown reference');
        }

        return $this->value;
    }

    public function discard(SecretReference $reference): void
    {
        unset($reference);
    }
}

$definitions = new DefinitionRegistry('judge-settings-test', SettingDefinition::class);
$definitions->register(new SettingDefinition(
    'judge_database',
    'oj_platform_database_settings',
    new ModuleId('judge_db'),
    true
));
$store = new JudgeSettingsMemoryStore();
$settings = new SettingsService($definitions, $store);
$reference = new SecretReference('secret:v2:123e4567-e89b-42d3-a456-426614174000');
$settings->save('judge_database', $reference, 0);

$singleAccountProvider = new SecretVaultJudgeConnectionSettingsProvider(
    $settings,
    new JudgeSettingsMemoryVault($reference, [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'jol',
        'read' => [
            'username' => 'judge',
            'password' => 'shared-secret',
        ],
        'write' => [
            'username' => 'judge',
            'password' => 'shared-secret',
        ],
        'tls' => [
            'enabled' => false,
            'ca_path' => '',
        ],
    ])
);
$singleAccount = $singleAccountProvider->current();
judgeSettingsAssert(! $singleAccount->utcStorageConfirmed(), '기존 설정의 시간 저장 기준을 자동 승인하면 안 됩니다.');
$connector = new \CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli\MysqliJudgeConnector();
foreach (['read', 'write'] as $operation) {
    $blocked = false;
    try {
        $connector->$operation($singleAccount);
    } catch (DomainException) {
        $blocked = true;
    }
    judgeSettingsAssert($blocked, '미확인 연결은 SQL 실행 전에 차단해야 합니다: ' . $operation);
}
judgeSettingsAssert(
    $singleAccount instanceof JudgeConnectionSettings,
    'Judge DB 비밀 설정을 연결 값 객체로 해석해야 합니다.'
);
judgeSettingsAssert(
    $singleAccount->usesSingleAccount(),
    '표준 HUSTOJ처럼 읽기와 쓰기에 같은 계정을 지정할 수 있어야 합니다.'
);
judgeSettingsSame(
    'judge',
    $singleAccount->readCredentials()->username(),
    '읽기 계정 이름을 그대로 사용해야 합니다.'
);

$remoteWithoutTlsRejected = false;
try {
    new JudgeConnectionSettings(
        'judge-db.internal',
        3306,
        'jol',
        new JudgeCredentials('judge', 'secret'),
        new JudgeCredentials('judge', 'secret'),
        false,
        null
    );
} catch (InvalidArgumentException) {
    $remoteWithoutTlsRejected = true;
}
judgeSettingsAssert(
    $remoteWithoutTlsRejected,
    '다른 서버의 Judge DB에는 TLS 없는 연결을 허용하면 안 됩니다.'
);

$remote = new JudgeConnectionSettings(
    'judge-db.internal',
    3306,
    'jol',
    new JudgeCredentials('judge_read', 'read-secret'),
    new JudgeCredentials('judge_write', 'write-secret'),
    true,
    'C:\\certs\\judge-ca.pem'
);
judgeSettingsAssert(
    ! $remote->usesSingleAccount() && $remote->tlsEnabled(),
    '분리 계정과 원격 TLS 구성을 함께 표현할 수 있어야 합니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "R-04 Judge 설정 단위 검사 통과: assertion %d개\n",
        $judgeSettingsAssertions
    )
);
