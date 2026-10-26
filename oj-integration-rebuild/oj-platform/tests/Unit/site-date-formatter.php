<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone($GLOBALS['test_site_timezone'] ?? 'Asia/Seoul');
}

$assertions = 0;

function siteDateAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function siteDateSame(mixed $expected, mixed $actual, string $message): void
{
    siteDateAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

$formatter = new SiteDateFormatter();

siteDateSame('-', $formatter->format(null), '빈 값은 -로 표시해야 합니다.');
siteDateSame('-', $formatter->format(''), '빈 문자열은 -로 표시해야 합니다.');
siteDateSame('-', $formatter->format('   '), '공백만 있는 값은 -로 표시해야 합니다.');

siteDateSame(
    '2026-08-27 12:00',
    $formatter->format('2026-08-27 03:00:00'),
    'UTC로 저장된 값은 사이트 시간대(KST, UTC+9)로 변환해 표시해야 합니다.'
);
siteDateSame(
    '2026-08-27',
    $formatter->format('2026-08-27 10:00:00', 'Y-m-d'),
    '날짜가 바뀌지 않는 시각은 그대로 같은 날짜여야 합니다.'
);
siteDateSame(
    '2026-08-28',
    $formatter->format('2026-08-27 20:00:00', 'Y-m-d'),
    'UTC 20시는 KST에서 다음날 새벽 5시이므로 날짜가 넘어가야 합니다.'
);
siteDateSame(
    '2026-08-27 12:00',
    $formatter->format('2026-08-27T03:00:00+00:00'),
    'ISO 8601 UTC 기록도 사이트 시간대로 변환해야 합니다.'
);
siteDateSame(
    '2026-08-27T12:00',
    $formatter->format('2026-08-27T03:00:00Z', 'Y-m-d\\TH:i'),
    'datetime-local 입력값도 사이트 시간대로 변환할 수 있어야 합니다.'
);
siteDateSame(
    '2026-08-27 12:00',
    $formatter->formatTimestamp(1787799600),
    'Unix 타임스탬프는 사이트 시간대로 표시해야 합니다.'
);
siteDateSame(
    'Asia/Seoul (UTC+09:00)',
    $formatter->timezoneLabel(),
    '사이트 시간대 이름과 현재 UTC 오프셋을 표시해야 합니다.'
);

siteDateSame(
    'garbled-value',
    $formatter->format('garbled-value'),
    '형식을 알 수 없는 값은 원본을 그대로 반환해야 합니다.'
);

siteDateSame('2026-08-27T12:00:37+09:00', $formatter->export('2026-08-27 03:00:37'), 'CSV는 초와 오프셋을 보존해야 합니다.');
$GLOBALS['test_site_timezone'] = 'America/New_York';
siteDateSame('2026-01-15T07:30:59-05:00', $formatter->export('2026-01-15 12:30:59'), '겨울 기록은 당시 오프셋을 사용해야 합니다.');
siteDateSame('2026-07-15T08:30:59-04:00', $formatter->export('2026-07-15 12:30:59'), '여름 기록은 당시 오프셋을 사용해야 합니다.');
siteDateSame('America/New_York (UTC-05:00)', $formatter->timezoneLabel('2026-01-15 12:30:59'), '시간대 라벨도 기록 시각에 맞아야 합니다.');
unset($GLOBALS['test_site_timezone']);

fwrite(STDOUT, sprintf("R-10 SiteDateFormatter 단위 검사 통과: assertion %d개\n", $assertions));
