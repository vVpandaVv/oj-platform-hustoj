<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\PlatformDb\Infrastructure\WordPress\WordPressPlatformConnection;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$GLOBALS['oj_platform_test_dbdelta_error'] = '';

function dbDelta(string $createStatement): array
{
    unset($createStatement);
    if ($GLOBALS['oj_platform_test_dbdelta_error'] !== '') {
        $GLOBALS['wpdb']->last_error = $GLOBALS['oj_platform_test_dbdelta_error'];
    }

    return [];
}

final class PlatformSyncStubDatabase
{
    public string $last_error = '';
    public string $dbname = 'wordpress';

    public function query(string $statement): int
    {
        unset($statement);

        return 1;
    }

    public function get_results(string $statement, mixed $output = null): array
    {
        unset($statement, $output);

        return [];
    }
}

$assertions = 0;

function platformSyncAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$GLOBALS['wpdb'] = new PlatformSyncStubDatabase();
$connection = WordPressPlatformConnection::fromGlobals();

$GLOBALS['oj_platform_test_dbdelta_error'] = '';
$threw = false;
try {
    $connection->synchronize('CREATE TABLE `messages` (`id` BIGINT UNSIGNED)');
} catch (RuntimeException) {
    $threw = true;
}
platformSyncAssert(! $threw, '동기화 오류가 없으면 예외를 던지면 안 됩니다.');

$GLOBALS['oj_platform_test_dbdelta_error'] = "Duplicate key name 'ix_messages_sender'";
$threw = false;
try {
    $connection->synchronize('CREATE TABLE `messages` (`id` BIGINT UNSIGNED)');
} catch (RuntimeException) {
    $threw = true;
}
platformSyncAssert(! $threw, 'dbDelta가 이미 있는 색인을 다시 추가하려는 충돌은 무시해야 합니다.');

$GLOBALS['oj_platform_test_dbdelta_error'] = "Duplicate column name 'ip_address'";
$threw = false;
try {
    $connection->synchronize('CREATE TABLE `messages` (`id` BIGINT UNSIGNED)');
} catch (RuntimeException) {
    $threw = true;
}
platformSyncAssert(! $threw, 'dbDelta가 이미 있는 컬럼을 다시 추가하려는 충돌은 무시해야 합니다.');

$GLOBALS['oj_platform_test_dbdelta_error'] = "Data too long for column 'topic_dictionary_version'";
$threw = false;
$thrownMessage = '';
try {
    $connection->synchronize('CREATE TABLE `messages` (`id` BIGINT UNSIGNED)');
} catch (RuntimeException $error) {
    $threw = true;
    $thrownMessage = $error->getMessage();
}
platformSyncAssert($threw, '진짜 스키마 오류는 예외로 전달되어야 합니다.');
platformSyncAssert(
    str_contains($thrownMessage, '플랫폼 스키마를 동기화하지 못했습니다')
        && str_contains($thrownMessage, 'Data too long'),
    '진짜 스키마 오류 메시지에는 원인이 포함되어야 합니다.'
);

$GLOBALS['oj_platform_test_dbdelta_error'] = "Duplicate entry 'a-b' for key 'uq_message_reports_reporter'";
$threw = false;
try {
    $connection->synchronize('CREATE TABLE `message_reports` (`id` BIGINT UNSIGNED)');
} catch (RuntimeException) {
    $threw = true;
}
platformSyncAssert($threw, '데이터 자체의 고유 제약 위반(Duplicate entry)은 무시하면 안 됩니다.');

fwrite(STDOUT, sprintf("R-10 플랫폼 스키마 동기화 단위 검사 통과: assertion %d개\n", $assertions));
