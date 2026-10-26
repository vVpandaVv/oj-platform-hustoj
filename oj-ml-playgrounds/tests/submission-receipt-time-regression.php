<?php

declare(strict_types=1);

use OJML\Playgrounds\Application\SubmissionService;

require_once __DIR__ . '/bootstrap.php';

$assertions = 0;

function receiptTimeAssertSame(mixed $expected, mixed $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '\nExpected: ' . var_export($expected, true)
            . '\nActual: ' . var_export($actual, true)
        );
    }
}

$service = (new ReflectionClass(SubmissionService::class))->newInstanceWithoutConstructor();
$receiptMethod = new ReflectionMethod(SubmissionService::class, 'receipt');

$result = [
    'uuid' => '11111111-2222-4333-8444-555555555555',
    'attempt_no' => 2,
    'received_at' => '2026-09-10 10:58:43',
    'artifact' => [
        'sha256' => str_repeat('a', 64),
        'bytes' => 6154,
    ],
];

$originalTimezone = date_default_timezone_get();
try {
    foreach (['UTC', 'Asia/Seoul', 'America/Los_Angeles'] as $timezone) {
        date_default_timezone_set($timezone);
        $receipt = $receiptMethod->invoke($service, $result);
        receiptTimeAssertSame(
            '2026-09-10T10:58:43+00:00',
            $receipt['receivedAt'] ?? null,
            'Receipt timestamps must identify stored MySQL datetimes as UTC in ' . $timezone . '.'
        );
    }
} finally {
    date_default_timezone_set($originalTimezone);
}

receiptTimeAssertSame(
    '11111111-2222-4333-8444-555555555555',
    $receipt['attemptId'] ?? null,
    'Attempt identifier must remain unchanged.'
);
receiptTimeAssertSame(2, $receipt['attemptNo'] ?? null, 'Attempt number must remain unchanged.');
receiptTimeAssertSame(6154, $receipt['bytes'] ?? null, 'Artifact size must remain unchanged.');
receiptTimeAssertSame(
    str_repeat('a', 64),
    $receipt['sha256'] ?? null,
    'Artifact digest must remain unchanged.'
);
$receiptKeys = array_keys($receipt);
sort($receiptKeys);
receiptTimeAssertSame(
    ['attemptId', 'attemptNo', 'bytes', 'receivedAt', 'sha256'],
    $receiptKeys,
    'Receipt response fields must remain unchanged.'
);

foreach ([null, '', 'not-a-date'] as $invalidTime) {
    $invalidResult = $result;
    $invalidResult['received_at'] = $invalidTime;
    $invalidTimeReceipt = $receiptMethod->invoke($service, $invalidResult);
    receiptTimeAssertSame(
        true,
        array_key_exists('receivedAt', $invalidTimeReceipt),
        'The receivedAt response field must remain present.'
    );
    receiptTimeAssertSame(
        null,
        $invalidTimeReceipt['receivedAt'] ?? null,
        'A missing, empty, or invalid receipt timestamp must serialize as null.'
    );
}

echo "submission receipt time regression passed ({$assertions} assertions).\n";
