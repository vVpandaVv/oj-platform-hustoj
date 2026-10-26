<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
Autoloader::register(dirname(__DIR__, 2));

$errors = [];
$catalog = new JudgeResultCatalog();
$expected = [
    0 => 'pending',
    3 => 'pending',
    4 => 'ac',
    5 => 'pe',
    6 => 'wa',
    7 => 'tle',
    8 => 'mle',
    9 => 'ole',
    10 => 're',
    11 => 'ce',
    12 => '',
    13 => '',
    14 => 'manual',
];
foreach ($expected as $code => $verdict) {
    $actual = $catalog->find($code)->verdict();
    if ($actual !== $verdict) {
        $errors[] = sprintf('결과 코드 %d의 색 구분 이름이 %s가 아니라 %s입니다.', $code, $verdict, $actual);
    }
}

if ($catalog->find(6)->badgeClass() !== 'ojp-badge ojp-badge--danger ojp-verdict ojp-verdict--wa') {
    $errors[] = '틀렸습니다 배지 class가 톤과 결과별 색을 함께 가져야 합니다.';
}
if ($catalog->find(13)->badgeClass() !== 'ojp-badge ojp-badge--neutral') {
    $errors[] = '색 구분이 없는 결과는 기본 톤 class만 가져야 합니다.';
}
if ($catalog->find(4)->badgeClass('ojp-status-badge') !== 'ojp-status-badge ojp-status-badge--success ojp-verdict ojp-verdict--ac') {
    $errors[] = '상태 배지 기반 class로도 결과별 색을 붙일 수 있어야 합니다.';
}

$css = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/ui/css/oj-ui-system.css');
$v2 = strpos($css, '/* DS-10 통합 디자인 v2');
$restore = strpos($css, '/* DS-10 색 복원');
if ($v2 === false || $restore === false || $restore < $v2) {
    $errors[] = '톤·채점 결과 색 복원 규칙은 v2 배지 규칙보다 뒤에 있어야 덮어쓰이지 않습니다.';
}
$tail = $restore === false ? '' : substr($css, $restore);
foreach ([
    '.ojp-badge--success',
    '.ojp-status-badge--danger',
    '.ojp-status-badge--difficulty-1',
    '.ojp-status-badge--difficulty-5',
    '.ojp-button--success',
    '.ojp-verdict--ac',
    '.ojp-verdict--wa',
    '.ojp-verdict--pe',
    '.ojp-verdict--tle',
    '.ojp-verdict--re',
    '.ojp-verdict--ce',
    '.ojp-verdict--pending',
] as $selector) {
    if (! str_contains($tail, $selector)) {
        $errors[] = '색 복원 구간에 규칙이 없습니다: ' . $selector;
    }
}
foreach (['pe', 'limit', 're', 'ce'] as $token) {
    if (substr_count($css, '--ojp-verdict-' . $token . ':') !== 3) {
        $errors[] = '채점 결과 색 토큰은 밝은 화면과 두 어두운 화면 정의가 모두 있어야 합니다: ' . $token;
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo 'judge result colors: OK' . PHP_EOL;
