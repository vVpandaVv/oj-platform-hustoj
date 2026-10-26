<?php

declare(strict_types=1);

use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Inspection\InspectionResult;
use OJML\Playgrounds\Inspection\MlpxInspector;

require_once __DIR__ . '/bootstrap.php';

function archiveAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

if (in_array('--fake-zip', $argv, true)) {
    archiveAssert(!class_exists('ZipArchive', false), 'Fault injection must use php -n without native ZipArchive.');
    // Test-only native boundary replacement. Runtime code receives exactly the same
    // bool|int outcomes documented by ZipArchive::open, plus a path-bearing exception.
    class ZipArchive
    {
        public const RDONLY = 16;
        public const CHECKCONS = 4;
        public const ER_SEEK = 4;
        public const ER_READ = 5;
        public const ER_CRC = 7;
        public const ER_NOENT = 9;
        public const ER_OPEN = 11;
        public const ER_TMPOPEN = 12;
        public const ER_MEMORY = 14;
        public const ER_EOF = 17;
        public const ER_NOZIP = 19;
        public const ER_INCONS = 21;
        public static int|bool|Throwable $result = false;
        public static int $lastFlags = 0;

        public function open(string $path, int $flags = 0): int|bool
        {
            self::$lastFlags = $flags;
            if (self::$result instanceof Throwable) { throw self::$result; }
            return self::$result;
        }
    }

    $fixture = tmpfile();
    archiveAssert(is_resource($fixture), 'Fault-injection fixture must open.');
    fwrite($fixture, 'synthetic fixture; no student data');
    fflush($fixture);
    $path = stream_get_meta_data($fixture)['uri'];
    $inspector = new MlpxInspector();
    $cases = [
        [ZipArchive::ER_SEEK, true],
        [ZipArchive::ER_READ, true],
        [ZipArchive::ER_NOENT, true],
        [ZipArchive::ER_OPEN, true],
        [ZipArchive::ER_TMPOPEN, true],
        [ZipArchive::ER_MEMORY, true],
        [0, true],
        [false, true],
        [999999, true],
        [new RuntimeException('PRIVATE-PATH-SENTINEL /secret/student.mlpx'), true],
        [ZipArchive::ER_CRC, false],
        [ZipArchive::ER_EOF, false],
        [ZipArchive::ER_NOZIP, false],
        [ZipArchive::ER_INCONS, false],
    ];
    try {
        foreach ($cases as [$nativeResult, $systemFailure]) {
            ZipArchive::$result = $nativeResult;
            $expectedCode = $systemFailure ? 'OJ_ML_ARCHIVE_OPEN_FAILED' : 'OJ_ML_MLPX_NOT_ZIP';
            $result = $inspector->inspect($path);
            archiveAssert(
                $result->status() === ($systemFailure ? InspectionResult::SYSTEM_PENDING : InspectionResult::REJECTED),
                'Native ZIP failure must be classified correctly.'
            );
            archiveAssert(($result->findings()[0]['code'] ?? '') === $expectedCode, 'Correct safe error code required.');
            archiveAssert(
                ($result->findings()[0]['severity'] ?? '') === ($systemFailure ? 'system' : 'error'),
                'System failure must not become a student failure.'
            );
            archiveAssert(
                $result->firstFailureCode() === ($systemFailure ? null : $expectedCode),
                'Server failure must not create a student failure code.'
            );
            archiveAssert(ZipArchive::$lastFlags === 20, 'Both read-only and consistency flags must remain enabled.');
            $payload = json_encode($result->findings(), JSON_THROW_ON_ERROR);
            archiveAssert(!str_contains($payload, 'PRIVATE-PATH-SENTINEL') && !str_contains($payload, $path), 'Inspection must not expose paths.');
            try {
                $inspector->readOnlyView($path);
                throw new RuntimeException('Read-only preview must not accept a failed open.');
            } catch (Problem $problem) {
                archiveAssert($problem->problemCode() === $expectedCode, 'Preview must use the same classification.');
                archiveAssert($problem->httpStatus() === ($systemFailure ? 503 : 422), 'Preview needs a retryable system HTTP status.');
                archiveAssert($problem->params() === [], 'Native path/status text must not leak in preview metadata.');
                archiveAssert(!str_contains($problem->getMessage(), 'PRIVATE-PATH-SENTINEL'), 'Preview must not expose native exceptions.');
            }
        }
    } finally {
        fclose($fixture);
    }
    echo "Archive open injected failures passed.\n";
    exit;
}

archiveAssert(class_exists(ZipArchive::class), 'Native ZIP extension is required; this regression cannot skip archive tests.');
// Generated with fflate 0.8.3 Zip + AsyncZipDeflate: data-descriptor/streaming ZIP.
// This is synthetic metadata only, not a student's submission.
$streamingZip = base64_decode(
    'UEsDBBQACAAIAAarJ10AAAAAAAAAAAAAAAANAAAAbWFuaWZlc3QuanNvbquuBQBQSwcIQ7+mowQAAAACAAAAUEsDBBQACAAIAAarJ10AAAAAAAAAAAAAAAANAAAAc2V0dGluZ3MuanNvbquuBQBQSwcIQ7+mowQAAAACAAAAUEsDBBQACAAIAAarJ10AAAAAAAAAAAAAAAAJAAAAcnVucy5qc29uq64FAFBLBwhDv6ajBAAAAAIAAABQSwMEFAAIAAgABqsnXQAAAAAAAAAAAAAAABcAAABwb3J0Zm9saW8vZG9jdW1lbnQuanNvbquuBQBQSwcIQ7+mowQAAAACAAAAUEsBAhQAFAAIAAgABqsnXUO/pqMEAAAAAgAAAA0AAAAAAAAAAAAAAAAAAAAAAG1hbmlmZXN0Lmpzb25QSwECFAAUAAgACAAGqyddQ7+mowQAAAACAAAADQAAAAAAAAAAAAAAAAA/AAAAc2V0dGluZ3MuanNvblBLAQIUABQACAAIAAarJ11Dv6ajBAAAAAIAAAAJAAAAAAAAAAAAAAAAAH4AAABydW5zLmpzb25QSwECFAAUAAgACAAGqyddQ7+mowQAAAACAAAAFwAAAAAAAAAAAAAAAAC5AAAAcG9ydGZvbGlvL2RvY3VtZW50Lmpzb25QSwUGAAAAAAQABADyAAAAAgEAAAAA',
    true
);
archiveAssert(is_string($streamingZip), 'Streaming fixture must decode.');
$fixture = tmpfile();
archiveAssert(is_resource($fixture), 'Native fixture must open.');
$path = stream_get_meta_data($fixture)['uri'];
try {
    fwrite($fixture, $streamingZip);
    fflush($fixture);
    $inspector = new MlpxInspector();
    $result = $inspector->inspect($path);
    archiveAssert($result->firstFailureCode() === 'OJ_ML_MLPX_MANIFEST_FIELD_MISSING', 'Streaming ZIP must reach metadata validation, not fail ZIP opening.');
    $before = hash_file('sha256', $path);
    try {
        $inspector->readOnlyView($path);
        throw new RuntimeException('Synthetic metadata should not be a complete MLPX document.');
    } catch (Problem $problem) {
        archiveAssert($problem->problemCode() === 'OJ_ML_MLPX_MANIFEST_FIELD_MISSING', 'Preview must also open the streaming archive.');
    }
    archiveAssert(hash_file('sha256', $path) === $before, 'Read-only inspection must not change archive bytes.');

    // Change only the local filename while retaining the central directory name.
    // RDONLY accepts this; CHECKCONS must reject it.
    fseek($fixture, 30);
    fwrite($fixture, 'x');
    fflush($fixture);
    $result = $inspector->inspect($path);
    archiveAssert($result->firstFailureCode() === 'OJ_ML_MLPX_NOT_ZIP', 'Inconsistent local/central headers must remain rejected.');

    ftruncate($fixture, 0);
    rewind($fixture);
    fwrite($fixture, 'not a ZIP');
    fflush($fixture);
    $result = $inspector->inspect($path);
    archiveAssert($result->status() === InspectionResult::REJECTED, 'A non-ZIP file must still be rejected.');
    archiveAssert($result->firstFailureCode() === 'OJ_ML_MLPX_NOT_ZIP', 'Non-ZIP error compatibility must remain.');
} finally {
    fclose($fixture);
}

$process = proc_open([PHP_BINARY, '-n', basename(__FILE__), '--fake-zip'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
archiveAssert(is_resource($process), 'Fault-injection PHP subprocess must start.');
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
archiveAssert($exit === 0 && str_contains($output, 'Archive open injected failures passed.'), 'Fault-injection regressions failed: ' . $output . $error);
echo "Archive open classification and native streaming ZIP regressions passed.\n";
