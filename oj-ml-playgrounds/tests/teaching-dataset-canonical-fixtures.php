<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use OJML\Playgrounds\Domain\TeachingDataset;
$fixtures = [
    "이름,결과\r\n첫째,1\r\n둘째,0\r\n",
    "\xEF\xBB\xBFfeature,target\n1,yes\n2,no",
    "text,label\n\"한글, 쉼표\",yes\n\"He said \"\"hi\"\"\",no\n",
    "text,label\r\n\"첫 줄\r\n둘째 줄\",a\r\n\"line\nbreak\",b\r\n",
    "name,value\n\n  , \n가,3\n\xC2\xA0,\xE3\x80\x80\n나,4",
    "x,y\n\"\",1\n2,\"\"",
    "x,y\r1,2\r3,4",
    "x,y\n😀,1\né,2",
    "value,label\n-1,a\n+1,b\n-1.5e-3,c\n+.5,d\n'=1+1,literal",
];
for ($i = 0; $i < 80; $i++) {
    $cells = ['한글', '  앞뒤  ', 'comma,value', 'quote"value', "line\nline", "cr\rcr", '', '0', '-7.5', 'a/b', '😀'];
    $rows = [['feature', 'target']];
    for ($j = 0; $j < 6; $j++) { $rows[] = [$cells[($i + $j) % count($cells)], (string) ($i * 6 + $j)]; }
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(static fn (string $cell): string => strpbrk($cell, ",\"\r\n") === false ? $cell : '"' . str_replace('"', '""', $cell) . '"', $row));
    }
    $fixtures[] = implode($i % 2 === 0 ? "\r\n" : "\n", $lines) . "\n";
}
$result = [];
foreach ($fixtures as $index => $input) {
    $csv = TeachingDataset::normalize($input);
    if (TeachingDataset::normalize($csv['content']) !== $csv) { throw new RuntimeException('Canonical idempotence failed: ' . $index); }
    $result[] = ['index' => $index, 'base64' => base64_encode($csv['content']), 'sha256' => $csv['sha256'], 'rowCount' => $csv['rowCount'], 'columnCount' => $csv['columnCount']];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
