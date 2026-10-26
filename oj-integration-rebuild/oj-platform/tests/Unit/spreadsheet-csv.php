<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Shared\Export\SpreadsheetCsv;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
Autoloader::register(dirname(__DIR__, 2));
$values = ['=1+1', '+SUM(A1)', '-2+3', '@name', " \t=1", "\r=1", "\tname", "　=1", '일반 이름', "쉼표,따옴표\"줄\n바꿈", 'C:\\path\\'];
$stream = fopen('php://temp', 'w+b');
fwrite($stream, SpreadsheetCsv::encode([$values, [42, -2, 0.5, null]]));
rewind($stream);
$row = fgetcsv($stream, 0, ',', '"', '');
foreach ($values as $index => $value) {
    $expected = $index < 8 ? "'" . $value : $value;
    if ($row[$index] !== $expected) {
        throw new RuntimeException('CSV round trip or formula protection failed: ' . $index);
    }
}
if (fgetcsv($stream, 0, ',', '"', '') !== ['42', '-2', '0.5', '']) {
    throw new RuntimeException('Numeric CSV values changed.');
}
fclose($stream);
$download = HttpResponse::download('report.csv', 'text/csv', 'body');
if ($download->headers()['X-Content-Type-Options'] !== 'nosniff'
    || $download->headers()['Cache-Control'] !== 'private, no-store') {
    throw new RuntimeException('Download response protection missing.');
}
echo "CSV formula protection and round-trip tests passed.\n";
