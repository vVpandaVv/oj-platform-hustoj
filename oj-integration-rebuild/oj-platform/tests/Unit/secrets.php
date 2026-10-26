<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretRecordStore;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SecretKeyRing;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Security\AesGcmSecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Security\SecretKeyRotationService;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$secretAssertions = 0;

function secretAssert(bool $condition, string $message): void
{
    global $secretAssertions;
    ++$secretAssertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function secretSame(mixed $expected, mixed $actual, string $message): void
{
    secretAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

final class SecretMemoryStore implements SecretRecordStore
{
    /** @var array<string, array<string, mixed>> */
    public array $records = [];

    public function put(string $recordId, array $record): void
    {
        if (isset($this->records[$recordId])) {
            throw new RuntimeException('duplicate');
        }

        $this->records[$recordId] = $record;
    }

    public function find(string $recordId): ?array
    {
        return $this->records[$recordId] ?? null;
    }

    public function remove(string $recordId): void
    {
        unset($this->records[$recordId]);
    }

    public function replace(string $recordId, array $record): void
    {
        if (! isset($this->records[$recordId])) {
            throw new RuntimeException('missing');
        }

        $this->records[$recordId] = $record;
    }

    public function recordIds(): array
    {
        return array_keys($this->records);
    }
}

if (! defined('OJ_PLATFORM_SECRET_KEYS')) {
    foreach ([
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
    ] as $secretName) {
        if (! defined($secretName)) {
            define($secretName, str_repeat(strtolower($secretName) . '-', 4));
        }
    }

    $automaticKeyRing = SecretKeyRing::fromConstants();
    secretSame(
        'wordpress-v1',
        $automaticKeyRing->primaryKeyId(),
        '플러그인 전용 키가 없으면 WordPress 보안 키를 자동으로 사용해야 합니다.'
    );
    secretSame(
        32,
        strlen($automaticKeyRing->encryptionKey('wordpress-v1')),
        'WordPress 보안 키에서 256비트 암호화 키를 만들어야 합니다.'
    );
}

if (! function_exists('openssl_encrypt')) {
    fwrite(STDOUT, "R-04 비밀값 단위 검사 건너뜀: OpenSSL 없음\n");
    exit(0);
}

$store = new SecretMemoryStore();
$keyRing = new SecretKeyRing(
    ['primary-2026' => str_repeat('a', 32)],
    'primary-2026'
);
$vault = new AesGcmSecretVault($store, $keyRing);
$payload = [
    'password' => 'temporary-password',
    'connection' => ['host' => 'db.internal', 'port' => 3306],
];
$reference = $vault->store($payload);

secretAssert(
    str_starts_with($reference->value(), 'secret:v2:'),
    '비밀값 참조 버전이 올바르지 않습니다.'
);
secretSame(1, count($store->records), '암호화 레코드는 하나만 생성되어야 합니다.');
secretAssert(
    ! str_contains(
        (string) json_encode($store->records, JSON_UNESCAPED_UNICODE),
        'temporary-password'
    ),
    '암호화 저장소에 평문 비밀번호가 남았습니다.'
);
secretSame($payload, $vault->reveal($reference), '비밀값 왕복 결과가 다릅니다.');

$wrongKeyRejected = false;
try {
    (new AesGcmSecretVault(
        $store,
        new SecretKeyRing(
            ['primary-2026' => str_repeat('b', 32)],
            'primary-2026'
        )
    ))->reveal($reference);
} catch (RuntimeException) {
    $wrongKeyRejected = true;
}
secretAssert($wrongKeyRejected, '다른 키로 비밀값을 해석할 수 없어야 합니다.');

$vault->discard($reference);
secretSame(0, count($store->records), '폐기한 비밀값 레코드가 남았습니다.');
$discardedRejected = false;
try {
    $vault->reveal($reference);
} catch (RuntimeException) {
    $discardedRejected = true;
}
secretAssert($discardedRejected, '폐기한 비밀값을 다시 읽을 수 없어야 합니다.');

$rotationStore = new SecretMemoryStore();
$oldRing = new SecretKeyRing(
    ['old-2025' => str_repeat('a', 32)],
    'old-2025'
);
$oldVault = new AesGcmSecretVault($rotationStore, $oldRing);
$referenceOne = $oldVault->store(['secret' => 'one']);
$referenceTwo = $oldVault->store(['secret' => 'two']);

$transitionRing = new SecretKeyRing(
    [
        'old-2025' => str_repeat('a', 32),
        'new-2026' => str_repeat('c', 32),
    ],
    'new-2026'
);
$rotation = new SecretKeyRotationService($rotationStore, $transitionRing);
$summary = $rotation->rotate();
secretSame(2, $summary->rotatedCount(), '이전 키로 저장된 비밀값 2개는 모두 재암호화되어야 합니다.');
secretSame(0, $summary->alreadyCurrentCount(), '첫 교체 전에는 이미 최신 키인 레코드가 없어야 합니다.');
secretAssert(! $summary->hasFailures(), '정상 교체는 실패가 없어야 합니다.');

$newVault = new AesGcmSecretVault($rotationStore, $transitionRing);
secretSame(
    ['secret' => 'one'],
    $newVault->reveal($referenceOne),
    '교체 뒤에도 새 키로 기존 비밀값을 읽을 수 있어야 합니다.'
);
secretSame(
    ['secret' => 'two'],
    $newVault->reveal($referenceTwo),
    '교체 뒤에도 새 키로 두 번째 비밀값을 읽을 수 있어야 합니다.'
);
secretSame(
    'new-2026',
    $rotationStore->find(substr($referenceOne->value(), strlen('secret:v2:')))['key_id'] ?? null,
    '재암호화된 레코드는 새 기본 키 식별자를 저장해야 합니다.'
);

$secondSummary = (new SecretKeyRotationService($rotationStore, $transitionRing))->rotate();
secretSame(0, $secondSummary->rotatedCount(), '이미 최신 키인 레코드는 다시 교체하지 않아야 합니다.');
secretSame(2, $secondSummary->alreadyCurrentCount(), '이미 최신 키인 레코드 수를 보고해야 합니다.');

$rotationStore2 = new SecretMemoryStore();
$staleVault = new AesGcmSecretVault(
    $rotationStore2,
    new SecretKeyRing(['stale-2024' => str_repeat('d', 32)], 'stale-2024')
);
$staleReference = $staleVault->store(['secret' => 'stale']);
$ringWithoutOldKey = new SecretKeyRing(
    ['new-2026' => str_repeat('c', 32)],
    'new-2026'
);
$failureSummary = (new SecretKeyRotationService($rotationStore2, $ringWithoutOldKey))->rotate();
secretSame(
    [substr($staleReference->value(), strlen('secret:v2:'))],
    $failureSummary->failedRecordIds(),
    '이전 키가 이미 사라졌으면 실패 목록으로 남아야 합니다.'
);
secretSame(
    'stale-2024',
    $rotationStore2->find(substr($staleReference->value(), strlen('secret:v2:')))['key_id'] ?? null,
    '교체에 실패한 레코드는 기존 키와 암호문을 그대로 유지해야 합니다.'
);

fwrite(
    STDOUT,
    sprintf("R-04 비밀값 단위 검사 통과: assertion %d개\n", $secretAssertions)
);
