<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Inspection;

use JsonException;
use OJML\Playgrounds\Domain\Problem;
use Throwable;

final class MlpxInspector
{
    public const VERSION = '5';

    private const REQUIRED_JSON = [
        'manifest.json',
        'settings.json',
        'runs.json',
        'portfolio/document.json',
    ];

    private const TASK_TYPE_LABELS = [
        'classification' => '분류',
        'regression' => '회귀',
        'clustering' => '군집화',
    ];

    private const ALGORITHM_LABELS = [
        'decision_tree' => '의사결정나무',
        'random_forest' => '랜덤 포레스트',
        'naive_bayes' => '나이브 베이즈',
        'knn' => 'K-최근접 이웃',
        'logistic_regression' => '로지스틱 회귀',
        'linear_regression' => '선형 회귀',
        'svm' => '서포트 벡터 머신',
        'k_means' => 'K-평균 군집화',
    ];

    private const HARD_MAX_ENTRIES = 20000;
    private const HARD_MAX_TOTAL_BYTES = 524288000;
    private const HARD_MAX_ENTRY_BYTES = 262144000;
    private const HARD_MAX_RATIO = 200.0;
    private const HARD_MAX_JSON_BYTES = 4194304;
    private const PREVIEW_MAX_JSON_TOTAL_BYTES = 4194304;
    private const HARD_MAX_DATASET_RECORDS = 200000;
    private const HARD_MAX_DATASET_COLUMNS = 5000;
    private const HARD_MAX_SELECTED_FEATURES = 2000;
    private const HARD_MAX_CSV_RECORD_BYTES = 16777216;
    private const MAX_NAME_BYTES = 512;
    private const JSON_DEPTH = 64;

    /** @param array<string,mixed> $policy */
    public function inspect(string $path, array $policy = []): InspectionResult
    {
        if (!class_exists(\ZipArchive::class)) {
            return InspectionResult::systemPending([
                $this->finding(
                    'system',
                    'OJ_ML_ZIP_EXTENSION_UNAVAILABLE',
                    '서버의 ZIP 검사 기능이 준비되지 않아 제출을 보류했습니다.'
                ),
            ]);
        }

        if (!is_file($path) || !is_readable($path)) {
            return InspectionResult::systemPending([
                $this->finding('system', 'OJ_ML_ARTIFACT_UNREADABLE', '저장된 제출 파일을 읽을 수 없습니다.'),
            ]);
        }

        try {
            return $this->inspectArchive($path, $this->limits($policy), $policy);
        } catch (Problem $problem) {
            if ($problem->httpStatus() >= 500) {
                return InspectionResult::systemPending([
                    $this->finding('system', $problem->problemCode(), $problem->getMessage()),
                ]);
            }
            return InspectionResult::rejected([
                $this->finding('error', $problem->problemCode(), $problem->getMessage(), $problem->params()),
            ]);
        } catch (Throwable $throwable) {
            return InspectionResult::systemPending([
                $this->finding('system', 'OJ_ML_INSPECTION_FAILED', '제출 파일 검사 중 서버 오류가 발생했습니다.'),
            ]);
        }
    }

    /** @return array{zipExtension:bool,evaluator:string,evaluatorVersion:string} */
    public function health(): array
    {
        return [
            'zipExtension' => class_exists(\ZipArchive::class),
            'evaluator' => 'mlpx-preflight',
            'evaluatorVersion' => self::VERSION,
        ];
    }

    /**
     * Read metadata and bounded CSV aggregate statistics for the teacher preview.
     * The archive is never extracted. Models, images, embeddings and attachments
     * are never opened or executed; raw CSV cells never enter the response.
     *
     * @return array<string,mixed>
     */
    public function readOnlyView(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new Problem(
                'OJ_ML_ZIP_EXTENSION_UNAVAILABLE',
                '서버의 ZIP 검사 기능이 준비되지 않아 바로보기를 열 수 없습니다.',
                503
            );
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new Problem('OJ_ML_ARTIFACT_UNREADABLE', '저장된 제출 파일을 읽을 수 없습니다.', 500);
        }

        $zip = $this->openArchive($path);

        try {
            $limits = [
                'entries' => self::HARD_MAX_ENTRIES,
                'total' => self::HARD_MAX_TOTAL_BYTES,
                'entry' => self::HARD_MAX_ENTRY_BYTES,
                'ratio' => self::HARD_MAX_RATIO,
                'json' => self::HARD_MAX_JSON_BYTES,
            ];
            [$entries] = $this->preflight($zip, $limits);
            $jsonBytes = 0;
            foreach (self::REQUIRED_JSON as $required) {
                if (!isset($entries[$required]) || $entries[$required]['directory']) {
                    throw new Problem('OJ_ML_MLPX_REQUIRED_ENTRY_MISSING', '필수 MLPX 항목이 없습니다.', 422, [
                        'entry' => $required,
                    ]);
                }
                $jsonBytes += $entries[$required]['size'];
            }
            if ($jsonBytes > self::PREVIEW_MAX_JSON_TOTAL_BYTES) {
                throw new Problem(
                    'OJ_ML_PREVIEW_JSON_TOO_LARGE',
                    '바로보기 메타데이터가 안전 한도를 초과합니다.',
                    422
                );
            }

            $manifest = $this->readJsonObject($zip, 'manifest.json', $limits['json']);
            $settings = $this->readJsonObject($zip, 'settings.json', $limits['json']);
            $runs = $this->readJsonObject($zip, 'runs.json', $limits['json']);
            $portfolio = $this->readJsonObject($zip, 'portfolio/document.json', $limits['json']);
            $this->validateManifest($manifest);
            if ((int) $manifest['formatVersion'] !== 2) {
                throw new Problem(
                    'OJ_ML_PREVIEW_FORMAT_UNSUPPORTED',
                    '이 MLPX 형식은 현재 안전 바로보기에서 지원하지 않습니다.',
                    422
                );
            }

            $view = (new MlpxReadOnlyProjector())->project($manifest, $settings, $runs, $portfolio);
            $view['datasetProfiles'] = (new DatasetProfiler())->profile($zip, $entries, $manifest, $settings, $runs);
            // Preserve the existing preview transport ceiling after adding the statistics.
            if (strlen((string) json_encode($view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 262144) {
                $view['datasetProfiles'] = [
                    'schemaVersion' => 1,
                    'source' => 'server_archive_csv',
                    'status' => 'unavailable',
                    'reasons' => ['response_limit'],
                    'datasets' => [],
                    'bindings' => [],
                ];
                $view['notices'][] = '응답 크기 제한으로 데이터 통계 요약을 생략했습니다. 교사 확인이 필요합니다.';
                // The metadata projector can fill its own ceiling. Remove only final
                // experiment entries if the small unavailable descriptor needs room.
                while ($view['experiments'] !== []
                    && strlen((string) json_encode($view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 262144) {
                    array_pop($view['experiments']);
                }
            }
            return $view;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array{entries:int,total:int,entry:int,ratio:float,json:int} $limits
     * @param array<string,mixed> $policy
     */
    private function inspectArchive(string $path, array $limits, array $policy): InspectionResult
    {
        $zip = $this->openArchive($path);

        try {
            [$entries, $totalBytes] = $this->preflight($zip, $limits);
            foreach (self::REQUIRED_JSON as $required) {
                if (!isset($entries[$required]) || $entries[$required]['directory']) {
                    throw new Problem('OJ_ML_MLPX_REQUIRED_ENTRY_MISSING', '필수 MLPX 항목이 없습니다.', 422, [
                        'entry' => $required,
                    ]);
                }
            }

            $manifest = $this->readJsonObject($zip, 'manifest.json', $limits['json']);
            $settings = $this->readJsonObject($zip, 'settings.json', $limits['json']);
            $runs = $this->readJsonObject($zip, 'runs.json', $limits['json']);
            $portfolio = $this->readJsonObject($zip, 'portfolio/document.json', $limits['json']);
            $this->validateManifest($manifest);

            $findings = [];
            $hashFacts = $this->inspectHashes($zip, $entries, $limits, $policy, $findings);
            $algorithms = $this->collectAlgorithms($runs);
            $tabularFacts = $this->inspectTabularDataset(
                $zip,
                $entries,
                $manifest,
                $settings,
                $runs,
                $policy,
                $findings
            );
            $facts = [
                'formatVersion' => (int) $manifest['formatVersion'],
                'appVersion' => (string) $manifest['appVersion'],
                'projectId' => (string) $manifest['projectId'],
                'projectName' => (string) $manifest['name'],
                'projectCreatedAt' => (string) $manifest['createdAt'],
                'projectUpdatedAt' => (string) $manifest['updatedAt'],
                'taskType' => isset($manifest['taskType']) ? (string) $manifest['taskType'] : null,
                'dataType' => (string) $manifest['dataType'],
                'entryCount' => count($entries),
                'totalUncompressedBytes' => $totalBytes,
                'runCount' => $this->countRuns($runs),
                'algorithms' => $algorithms,
                'integrity' => $hashFacts,
                'tabular' => $tabularFacts,
                'metadataPresent' => [
                    'settings' => $settings !== [],
                    'portfolio' => $portfolio !== [],
                ],
            ];
            $this->enforcePolicy($manifest, $algorithms, $hashFacts, $policy, $findings);
            $missingAnswers = \OJML\Playgrounds\Domain\PortfolioRequirements::missing($portfolio, $policy['portfolioRequirements'] ?? []);
            if ($missingAnswers !== []) {
                $findings[] = $this->finding('error', 'OJ_ML_POLICY_PORTFOLIO_REQUIRED', '필수 포트폴리오 문항이 없거나 답변 길이가 부족합니다. 교사 양식을 적용하고 필수 답변을 작성하세요.', ['sectionIds' => $missingAnswers]);
            }

            foreach ($findings as $finding) {
                if (($finding['severity'] ?? '') === 'error') {
                    return InspectionResult::rejected($findings, $facts);
                }
            }

            return InspectionResult::accepted($findings, $facts);
        } finally {
            $zip->close();
        }
    }

    /** Open without weakening consistency checks or blaming students for server I/O failures. */
    private function openArchive(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        $flags = defined('ZipArchive::RDONLY') ? \ZipArchive::RDONLY : 0;
        if (defined('ZipArchive::CHECKCONS')) {
            $flags |= \ZipArchive::CHECKCONS;
        }
        try {
            // Native warnings can contain a private filesystem path. The result below
            // is the only diagnostic exposed to callers; never emit the native message.
            $opened = @$zip->open($path, $flags);
        } catch (Throwable $throwable) {
            $opened = false;
        }
        if ($opened === true) {
            return $zip;
        }

        // Positive evidence of an invalid archive only. An I/O, memory, unsupported
        // library, or unknown error must remain retryable instead of forcing a new submission.
        foreach (['ER_NOZIP', 'ER_INCONS', 'ER_CRC', 'ER_EOF'] as $name) {
            $constant = 'ZipArchive::' . $name;
            if (defined($constant) && $opened === constant($constant)) {
                throw new Problem(
                    'OJ_ML_MLPX_NOT_ZIP',
                    '제출 파일이 MLPX ZIP 형식이 아니거나 손상되어 내용을 열 수 없습니다. 원래 실습에서 다시 내보낸 파일인지 확인해 주세요.',
                    422
                );
            }
        }
        throw new Problem(
            'OJ_ML_ARCHIVE_OPEN_FAILED',
            '서버에서 제출 파일을 열지 못해 검사를 보류했습니다. 다시 제출하지 말고 잠시 후 확인하거나 운영자에게 문의해 주세요.',
            503
        );
    }

    /**
     * @param \ZipArchive $zip
     * @param array{entries:int,total:int,entry:int,ratio:float,json:int} $limits
     * @return array{0:array<string,array{index:int,size:int,compressed:int,directory:bool}>,1:int}
     */
    private function preflight(\ZipArchive $zip, array $limits): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > $limits['entries']) {
            throw new Problem('OJ_ML_MLPX_ENTRY_LIMIT', 'MLPX 항목 수가 허용 범위를 벗어났습니다.', 422, [
                'maxEntries' => $limits['entries'],
            ]);
        }

        $entries = [];
        $canonicalNames = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, 0);
            if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                throw new Problem('OJ_ML_MLPX_DIRECTORY_CORRUPT', 'MLPX 디렉터리 정보를 읽을 수 없습니다.', 422);
            }
            $name = (string) $stat['name'];
            $directory = str_ends_with($name, '/');
            $this->validateEntryName($name, $directory);
            $canonical = $this->canonicalName($name);
            if (isset($canonicalNames[$canonical])) {
                throw new Problem('OJ_ML_MLPX_DUPLICATE_ENTRY', '중복되거나 충돌하는 MLPX 항목이 있습니다.', 422, [
                    'entry' => $name,
                ]);
            }
            $canonicalNames[$canonical] = true;

            $size = max(0, (int) ($stat['size'] ?? 0));
            $compressed = max(0, (int) ($stat['comp_size'] ?? 0));
            if ($size > $limits['entry']) {
                throw new Problem('OJ_ML_MLPX_ENTRY_TOO_LARGE', 'MLPX 내부 항목이 너무 큽니다.', 422, [
                    'entry' => $name,
                    'maxBytes' => $limits['entry'],
                ]);
            }
            if ($size > 0 && ($compressed === 0 || ($size / max(1, $compressed)) > $limits['ratio'])) {
                throw new Problem('OJ_ML_MLPX_COMPRESSION_RATIO', '비정상적으로 높은 ZIP 압축률이 감지되었습니다.', 422, [
                    'entry' => $name,
                    'maxRatio' => $limits['ratio'],
                ]);
            }
            if ($size > $limits['total'] - $total) {
                throw new Problem('OJ_ML_MLPX_UNCOMPRESSED_LIMIT', 'MLPX 압축 해제 예상 크기가 제한을 초과합니다.', 422, [
                    'maxBytes' => $limits['total'],
                ]);
            }
            $total += $size;
            $this->rejectEncryptedOrSpecialEntry($zip, $index, $name, $directory);
            $entries[$name] = [
                'index' => $index,
                'size' => $size,
                'compressed' => $compressed,
                'directory' => $directory,
            ];
        }

        return [$entries, $total];
    }

    private function validateEntryName(string $name, bool $directory): void
    {
        if ($name === '' || strlen($name) > self::MAX_NAME_BYTES || str_contains($name, "\0")) {
            throw new Problem('OJ_ML_MLPX_ENTRY_NAME_INVALID', 'MLPX 내부 경로가 올바르지 않습니다.', 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || str_contains($name, '\\')) {
            throw new Problem('OJ_ML_MLPX_ENTRY_NAME_INVALID', 'MLPX 내부 경로가 올바르지 않습니다.', 422, ['entry' => $name]);
        }
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1 || str_contains($name, '//')) {
            throw new Problem('OJ_ML_MLPX_PATH_TRAVERSAL', '안전하지 않은 MLPX 내부 경로가 있습니다.', 422, ['entry' => $name]);
        }
        $trimmed = $directory ? rtrim($name, '/') : $name;
        if ($trimmed === '') {
            throw new Problem('OJ_ML_MLPX_ENTRY_NAME_INVALID', 'MLPX 내부 경로가 올바르지 않습니다.', 422);
        }
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new Problem('OJ_ML_MLPX_PATH_TRAVERSAL', '안전하지 않은 MLPX 내부 경로가 있습니다.', 422, ['entry' => $name]);
            }
        }
    }

    private function canonicalName(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $name = $normalized;
            }
        }
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private function rejectEncryptedOrSpecialEntry(\ZipArchive $zip, int $index, string $name, bool $directory): void
    {
        if (method_exists($zip, 'getEncryptionName')) {
            $encryption = $zip->getEncryptionName($name);
            if (is_string($encryption) && $encryption !== '' && strtolower($encryption) !== 'none') {
                throw new Problem('OJ_ML_MLPX_ENCRYPTED_ENTRY', '암호화된 MLPX 항목은 허용되지 않습니다.', 422, ['entry' => $name]);
            }
        }

        $operatingSystem = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return;
        }
        if ($operatingSystem !== \ZipArchive::OPSYS_UNIX) {
            return;
        }
        $mode = ($attributes >> 16) & 0xFFFF;
        $type = $mode & 0170000;
        if ($type !== 0 && $type !== 0100000 && !($directory && $type === 0040000)) {
            throw new Problem('OJ_ML_MLPX_SPECIAL_ENTRY', '심볼릭 링크나 특수 파일은 허용되지 않습니다.', 422, ['entry' => $name]);
        }
    }

    /** @return array<string,mixed> */
    private function readJsonObject(\ZipArchive $zip, string $name, int $maxBytes): array
    {
        $json = $this->readEntry($zip, $name, $maxBytes);
        try {
            $object = json_decode($json, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Problem('OJ_ML_MLPX_JSON_INVALID', 'MLPX JSON 메타데이터가 올바르지 않습니다.', 422, ['entry' => $name]);
        }
        if (!$object instanceof \stdClass) {
            throw new Problem('OJ_ML_MLPX_JSON_OBJECT_REQUIRED', 'MLPX 메타데이터는 JSON 객체여야 합니다.', 422, ['entry' => $name]);
        }
        $encoded = json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decoded = json_decode($encoded, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function readEntry(\ZipArchive $zip, string $name, int $maxBytes): string
    {
        $stream = $zip->getStream($name);
        if (!is_resource($stream)) {
            throw new Problem('OJ_ML_MLPX_ENTRY_UNREADABLE', 'MLPX 내부 항목을 읽을 수 없습니다.', 422, ['entry' => $name]);
        }
        $contents = '';
        try {
            while (!feof($stream)) {
                $remaining = $maxBytes + 1 - strlen($contents);
                if ($remaining <= 0) {
                    throw new Problem('OJ_ML_MLPX_JSON_TOO_LARGE', 'MLPX JSON 메타데이터가 너무 큽니다.', 422, ['entry' => $name]);
                }
                $chunk = fread($stream, min(65536, $remaining));
                if ($chunk === false) {
                    throw new Problem('OJ_ML_MLPX_ENTRY_UNREADABLE', 'MLPX 내부 항목을 읽을 수 없습니다.', 422, ['entry' => $name]);
                }
                $contents .= $chunk;
            }
        } finally {
            fclose($stream);
        }
        if (strlen($contents) > $maxBytes) {
            throw new Problem('OJ_ML_MLPX_JSON_TOO_LARGE', 'MLPX JSON 메타데이터가 너무 큽니다.', 422, ['entry' => $name]);
        }
        return $contents;
    }

    /** @param array<string,mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        foreach (['formatVersion', 'appVersion', 'projectId', 'name', 'createdAt', 'updatedAt', 'dataType'] as $field) {
            if (!array_key_exists($field, $manifest)) {
                throw new Problem('OJ_ML_MLPX_MANIFEST_FIELD_MISSING', 'manifest.json 필수 필드가 없습니다.', 422, ['field' => $field]);
            }
        }
        if (!is_int($manifest['formatVersion']) || $manifest['formatVersion'] < 1) {
            throw new Problem('OJ_ML_MLPX_FORMAT_VERSION_INVALID', 'MLPX 형식 버전이 올바르지 않습니다.', 422);
        }
        foreach (['appVersion', 'projectId', 'name', 'createdAt', 'updatedAt', 'dataType'] as $field) {
            if (!is_string($manifest[$field]) || trim($manifest[$field]) === '') {
                throw new Problem('OJ_ML_MLPX_MANIFEST_FIELD_INVALID', 'manifest.json 필드 형식이 올바르지 않습니다.', 422, ['field' => $field]);
            }
        }
        if (strlen($manifest['appVersion']) > 100 || strlen($manifest['name']) > 255) {
            throw new Problem('OJ_ML_MLPX_MANIFEST_FIELD_INVALID', 'manifest.json 문자열 필드가 너무 깁니다.', 422);
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $manifest['projectId']) !== 1) {
            throw new Problem('OJ_ML_MLPX_PROJECT_ID_INVALID', 'MLPX 프로젝트 ID가 올바르지 않습니다.', 422);
        }
        if (!in_array($manifest['dataType'], ['tabular', 'image'], true)) {
            throw new Problem('OJ_ML_MLPX_DATA_TYPE_INVALID', '지원하지 않는 데이터 유형입니다.', 422);
        }
        if (isset($manifest['taskType']) && (!is_string($manifest['taskType']) || !in_array($manifest['taskType'], ['classification', 'regression', 'clustering'], true))) {
            throw new Problem('OJ_ML_MLPX_TASK_TYPE_INVALID', '지원하지 않는 과제 유형입니다.', 422);
        }
        if (isset($manifest['kind']) && $manifest['kind'] !== 'machineLearning') {
            throw new Problem('OJ_ML_MLPX_KIND_INVALID', '지원하지 않는 MLPX 프로젝트 종류입니다.', 422);
        }
    }

    /**
     * @param array<string,array{index:int,size:int,compressed:int,directory:bool}> $entries
     * @param array{entries:int,total:int,entry:int,ratio:float,json:int} $limits
     * @param array<string,mixed> $policy
     * @param list<array<string,mixed>> $findings
     * @return array{present:bool,verified:bool,contentHash:?string,entryHashes:array<string,string>}
     */
    private function inspectHashes(\ZipArchive $zip, array $entries, array $limits, array $policy, array &$findings): array
    {
        $empty = ['present' => false, 'verified' => false, 'contentHash' => null, 'entryHashes' => []];
        if (!isset($entries['hashes.json'])) {
            if (($policy['requireIntegrityManifest'] ?? false) === true) {
                $findings[] = $this->finding('error', 'OJ_ML_MLPX_HASHES_REQUIRED', 'hashes.json 무결성 목록이 필요합니다.');
            } else {
                $findings[] = $this->finding('warning', 'OJ_ML_MLPX_HASHES_MISSING', 'hashes.json이 없어 내부 파일 해시를 교차 검증하지 않았습니다.');
            }
            return $empty;
        }

        $hashes = $this->readJsonObject($zip, 'hashes.json', $limits['json']);
        if (($hashes['algorithm'] ?? null) !== 'sha256' || !is_array($hashes['entries'] ?? null)) {
            $findings[] = $this->finding('error', 'OJ_ML_MLPX_HASHES_INVALID', 'hashes.json 형식이 올바르지 않습니다.');
            return ['present' => true, 'verified' => false, 'contentHash' => null, 'entryHashes' => []];
        }

        $expectedTargets = [];
        foreach ($entries as $entry => $metadata) {
            if ($entry !== 'hashes.json' && !$metadata['directory']) {
                $expectedTargets[$entry] = true;
            }
        }
        $declaredTargets = [];
        foreach (array_keys($hashes['entries']) as $entry) {
            if (is_string($entry)) {
                $declaredTargets[$entry] = true;
            }
        }
        $missingTargets = array_keys(array_diff_key($expectedTargets, $declaredTargets));
        $extraTargets = array_keys(array_diff_key($declaredTargets, $expectedTargets));
        $hashesComplete = $missingTargets === [] && $extraTargets === [];
        if (!$hashesComplete) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_HASHES_INCOMPLETE',
                'hashes.json이 MLPX 내부 파일 전체를 정확히 포함하지 않습니다.',
                [
                    'missingCount' => count($missingTargets),
                    'missing' => array_slice($missingTargets, 0, 20),
                    'extraCount' => count($extraTargets),
                    'extra' => array_slice($extraTargets, 0, 20),
                ]
            );
        }

        $verified = [];
        foreach ($hashes['entries'] as $entry => $expected) {
            if (!is_string($entry) || !is_string($expected) || preg_match('/^[a-f0-9]{64}$/i', $expected) !== 1) {
                $findings[] = $this->finding('error', 'OJ_ML_MLPX_HASH_ENTRY_INVALID', 'hashes.json의 해시 항목이 올바르지 않습니다.');
                continue;
            }
            if ($entry === 'hashes.json' || !isset($entries[$entry]) || $entries[$entry]['directory']) {
                $findings[] = $this->finding('error', 'OJ_ML_MLPX_HASH_TARGET_INVALID', '해시 대상 파일을 찾을 수 없습니다.', ['entry' => $entry]);
                continue;
            }
            $actual = $this->hashEntry($zip, $entry, min($limits['entry'], $entries[$entry]['size'] + 1));
            if (!hash_equals(strtolower($expected), $actual)) {
                $findings[] = $this->finding('error', 'OJ_ML_MLPX_HASH_MISMATCH', 'MLPX 내부 파일 해시가 일치하지 않습니다.', ['entry' => $entry]);
                continue;
            }
            $verified[$entry] = $actual;
        }
        ksort($verified, SORT_STRING);
        $canonical = '';
        foreach ($verified as $entry => $hash) {
            $canonical .= $entry . "\n" . $hash . "\n";
        }
        $computedContentHash = hash('sha256', $canonical);
        $declaredContentHash = is_string($hashes['contentHash'] ?? null) ? strtolower($hashes['contentHash']) : null;
        $contentHashVerified = $declaredContentHash !== null
            && preg_match('/^[a-f0-9]{64}$/', $declaredContentHash) === 1
            && hash_equals($declaredContentHash, $computedContentHash);
        if (!$contentHashVerified) {
            $findings[] = $this->finding('error', 'OJ_ML_MLPX_CONTENT_HASH_MISMATCH', 'MLPX 콘텐츠 해시가 일치하지 않습니다.');
        }
        return [
            'present' => true,
            'verified' => $hashesComplete && $contentHashVerified && count($verified) === count($expectedTargets),
            'contentHash' => $computedContentHash,
            'entryHashes' => $verified,
        ];
    }

    private function hashEntry(\ZipArchive $zip, string $entry, int $maxBytes): string
    {
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            throw new Problem('OJ_ML_MLPX_ENTRY_UNREADABLE', 'MLPX 내부 항목을 읽을 수 없습니다.', 422, ['entry' => $entry]);
        }
        $context = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false) {
                    throw new Problem('OJ_ML_MLPX_ENTRY_UNREADABLE', 'MLPX 내부 항목을 읽을 수 없습니다.', 422, ['entry' => $entry]);
                }
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    throw new Problem('OJ_ML_MLPX_ENTRY_TOO_LARGE', 'MLPX 내부 항목이 너무 큽니다.', 422, ['entry' => $entry]);
                }
                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }
        return hash_final($context);
    }

    /**
     * @param array<string,array{index:int,size:int,compressed:int,directory:bool}> $entries
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $runs
     * @param array<string,mixed> $policy
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function inspectTabularDataset(
        \ZipArchive $zip,
        array $entries,
        array $manifest,
        array $settings,
        array $runs,
        array $policy,
        array &$findings
    ): array {
        if (($manifest['dataType'] ?? null) !== 'tabular') {
            return ['applicable' => false];
        }

        $facts = [
            'applicable' => true,
            'datasetPresent' => false,
            'datasetPath' => null,
            'hasHeader' => null,
            'records' => 0,
            'rows' => 0,
            'columns' => 0,
            'currentFeatureCount' => null,
            'experimentFeatureCounts' => [],
            'maxSelectedFeatureCount' => 0,
            'policyMaxRows' => $this->optionalPolicyLimit($policy, 'maxRows', self::HARD_MAX_DATASET_RECORDS),
            'policyMaxFeatures' => $this->optionalPolicyLimit($policy, 'maxFeatures', self::HARD_MAX_SELECTED_FEATURES),
        ];

        $data = $settings['data'] ?? null;
        if (!is_array($data)) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_FEATURES_INVALID',
                'settings.json의 표 데이터 설정이 올바르지 않습니다.',
                ['path' => 'settings.data']
            );
            return $facts;
        }

        $featureSources = [];
        $currentFeatures = $this->featureSelection(
            $data['features'] ?? null,
            'settings.data.features',
            $findings
        );
        if ($currentFeatures !== null) {
            $featureSources[] = ['path' => 'settings.data.features', 'features' => $currentFeatures];
            $facts['currentFeatureCount'] = count($currentFeatures);
        }

        $experiments = $runs['experiments'] ?? null;
        if (!is_array($experiments) || !array_is_list($experiments)) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_FEATURES_INVALID',
                'runs.json의 실험 목록이 올바르지 않습니다.',
                ['path' => 'runs.experiments']
            );
        } else {
            foreach ($experiments as $experimentIndex => $experiment) {
                $featurePath = 'runs.experiments.' . $experimentIndex . '.settings.data.features';
                $snapshotFeatures = is_array($experiment)
                    ? ($experiment['settings']['data']['features'] ?? null)
                    : null;
                $features = $this->featureSelection($snapshotFeatures, $featurePath, $findings);
                $facts['experimentFeatureCounts'][] = [
                    'experimentIndex' => $experimentIndex,
                    'count' => $features === null ? null : count($features),
                ];
                if ($features !== null) {
                    $featureSources[] = ['path' => $featurePath, 'features' => $features];
                }
            }
        }

        $validCounts = array_map(
            static fn (array $source): int => count($source['features']),
            $featureSources
        );
        $facts['maxSelectedFeatureCount'] = $validCounts === [] ? 0 : max($validCounts);
        $this->enforceFeaturePolicy($featureSources, $facts['policyMaxFeatures'], $findings);

        if (!array_key_exists('dataset', $data) || $data['dataset'] === null) {
            $this->validateFeaturesAgainstColumns($featureSources, [], $findings);
            return $facts;
        }

        $dataset = $data['dataset'];
        if (!is_array($dataset)
            || !is_string($dataset['path'] ?? null)
            || ($dataset['path'] ?? '') === ''
            || !is_bool($dataset['hasHeader'] ?? null)
        ) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_DATASET_REFERENCE_INVALID',
                'settings.json의 데이터셋 참조가 올바르지 않습니다.',
                ['path' => 'settings.data.dataset']
            );
            return $facts;
        }

        $datasetPath = $dataset['path'];
        $hasHeader = $dataset['hasHeader'];
        $facts['datasetPresent'] = true;
        $facts['datasetPath'] = $datasetPath;
        $facts['hasHeader'] = $hasHeader;

        if (!str_starts_with($datasetPath, 'dataset/')
            || strlen($datasetPath) <= strlen('dataset/')
            || str_ends_with($datasetPath, '/')
            || str_contains($datasetPath, "\0")
            || str_contains($datasetPath, '\\')
            || in_array('..', explode('/', $datasetPath), true)
        ) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_DATASET_REFERENCE_INVALID',
                '데이터셋 참조 경로가 올바르지 않습니다.',
                ['path' => $datasetPath]
            );
            return $facts;
        }
        if (($dataset['encoding'] ?? null) !== 'utf-8') {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_DATASET_ENCODING_INVALID',
                '정본 데이터셋 인코딩은 UTF-8이어야 합니다.',
                ['path' => $datasetPath]
            );
        }
        if (!isset($entries[$datasetPath]) || $entries[$datasetPath]['directory']) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_DATASET_ENTRY_MISSING',
                'settings.json이 가리키는 데이터셋 파일이 없습니다.',
                ['path' => $datasetPath]
            );
            return $facts;
        }

        try {
            $csv = $this->inspectCanonicalCsv($zip, $datasetPath, $hasHeader);
        } catch (Problem $problem) {
            $findings[] = $this->finding(
                'error',
                $problem->problemCode(),
                $problem->getMessage(),
                $problem->params()
            );
            return $facts;
        }

        $facts['records'] = $csv['records'];
        $facts['rows'] = $csv['rows'];
        $facts['columns'] = $csv['columns'];
        $policyMaxRows = $facts['policyMaxRows'];
        if (is_int($policyMaxRows) && $csv['rows'] > $policyMaxRows) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_POLICY_MAX_ROWS',
                '데이터 행 수가 과제의 허용 한도를 초과합니다.',
                ['actualRows' => $csv['rows'], 'maxRows' => $policyMaxRows]
            );
        }
        $this->validateFeaturesAgainstColumns($featureSources, $csv['columnNames'], $findings);

        return $facts;
    }

    /**
     * @param list<array{path:string,features:list<string>}> $featureSources
     * @param list<string> $columnNames
     * @param list<array<string,mixed>> $findings
     */
    private function validateFeaturesAgainstColumns(
        array $featureSources,
        array $columnNames,
        array &$findings
    ): void {
        $known = array_fill_keys($columnNames, true);
        foreach ($featureSources as $source) {
            $missing = [];
            foreach ($source['features'] as $feature) {
                if (!isset($known[$feature])) {
                    $missing[$feature] = true;
                }
            }
            if ($missing === []) {
                continue;
            }
            $names = array_keys($missing);
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_FEATURE_NOT_FOUND',
                '선택 특성이 실제 데이터셋 열과 일치하지 않습니다.',
                [
                    'path' => $source['path'],
                    'missingCount' => count($names),
                    'missing' => array_slice($names, 0, 20),
                ]
            );
        }
    }

    /**
     * @param mixed $value
     * @param list<array<string,mixed>> $findings
     * @return list<string>|null
     */
    private function featureSelection(mixed $value, string $path, array &$findings): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_FEATURES_INVALID',
                '선택 특성 목록이 올바르지 않습니다.',
                ['path' => $path]
            );
            return null;
        }

        $features = [];
        foreach ($value as $feature) {
            if (!is_string($feature) || $feature === '' || preg_match('//u', $feature) !== 1) {
                $findings[] = $this->finding(
                    'error',
                    'OJ_ML_MLPX_FEATURES_INVALID',
                    '선택 특성 이름이 올바르지 않습니다.',
                    ['path' => $path]
                );
                return null;
            }
            $features[] = $feature;
        }

        $seen = [];
        $duplicates = [];
        foreach ($features as $feature) {
            if (isset($seen[$feature])) {
                $duplicates[$feature] = true;
            }
            $seen[$feature] = true;
        }
        if ($duplicates !== []) {
            $names = array_keys($duplicates);
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_FEATURE_DUPLICATE',
                '선택 특성 목록에 중복된 열이 있습니다.',
                [
                    'path' => $path,
                    'duplicateCount' => count($names),
                    'duplicates' => array_slice($names, 0, 20),
                ]
            );
        }
        if (count($features) > self::HARD_MAX_SELECTED_FEATURES) {
            $findings[] = $this->finding(
                'error',
                'OJ_ML_MLPX_FEATURE_LIMIT',
                '선택 특성 수가 안전 한도를 초과합니다.',
                [
                    'path' => $path,
                    'actualFeatures' => count($features),
                    'maxFeatures' => self::HARD_MAX_SELECTED_FEATURES,
                ]
            );
        }

        return $features;
    }

    /**
     * @param list<array{path:string,features:list<string>}> $featureSources
     * @param list<array<string,mixed>> $findings
     */
    private function enforceFeaturePolicy(array $featureSources, ?int $maxFeatures, array &$findings): void
    {
        if ($maxFeatures === null) {
            return;
        }
        foreach ($featureSources as $source) {
            $actual = count($source['features']);
            if ($actual <= $maxFeatures) {
                continue;
            }
            $findings[] = $this->finding(
                'error',
                'OJ_ML_POLICY_MAX_FEATURES',
                '선택 특성 수가 과제의 허용 한도를 초과합니다.',
                ['path' => $source['path'], 'actualFeatures' => $actual, 'maxFeatures' => $maxFeatures]
            );
        }
    }

    /**
     * @return array{records:int,rows:int,columns:int,columnNames:list<string>}
     */
    private function inspectCanonicalCsv(\ZipArchive $zip, string $path, bool $hasHeader): array
    {
        $stream = $zip->getStream($path);
        if (!is_resource($stream)) {
            throw new Problem(
                'OJ_ML_MLPX_ENTRY_UNREADABLE',
                'MLPX 내부 데이터셋을 읽을 수 없습니다.',
                422,
                ['entry' => $path]
            );
        }

        try {
            return $this->inspectCanonicalCsvStream($stream, $path, $hasHeader);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Finds RFC 4180 record boundaries before parsing so an attacker cannot make
     * fgetcsv allocate an entire oversized quoted record.
     *
     * @param resource $stream
     * @return array{records:int,rows:int,columns:int,columnNames:list<string>}
     */
    private function inspectCanonicalCsvStream($stream, string $path, bool $hasHeader): array
    {
        $bom = '';
        while (strlen($bom) < 3 && !feof($stream)) {
            $chunk = fread($stream, 3 - strlen($bom));
            if ($chunk === false || ($chunk === '' && !feof($stream))) {
                throw new Problem(
                    'OJ_ML_MLPX_ENTRY_UNREADABLE',
                    'MLPX 내부 데이터셋을 읽을 수 없습니다.',
                    422,
                    ['entry' => $path]
                );
            }
            $bom .= $chunk;
        }
        if ($bom !== "\xEF\xBB\xBF") {
            throw new Problem(
                'OJ_ML_MLPX_DATASET_ENCODING_INVALID',
                '정본 데이터셋에 UTF-8 BOM이 없습니다.',
                422,
                ['entry' => $path]
            );
        }

        $records = 0;
        $width = null;
        $header = [];
        $consume = function (string $rawRecord) use (
            &$records,
            &$width,
            &$header,
            $path,
            $hasHeader
        ): void {
            $records++;
            if ($records > self::HARD_MAX_DATASET_RECORDS) {
                throw new Problem(
                    'OJ_ML_MLPX_DATASET_ROW_LIMIT',
                    '데이터셋 레코드 수가 안전 한도를 초과합니다.',
                    422,
                    [
                        'actualRecords' => $records,
                        'maxRecords' => self::HARD_MAX_DATASET_RECORDS,
                    ]
                );
            }

            // The upstream canonical serializer uses RFC 4180 doubled quotes only.
            // An empty escape disables PHP's proprietary backslash-quote handling.
            $parsed = str_getcsv($rawRecord, ',', '"', '');
            $cells = [];
            foreach ($parsed as $cell) {
                $value = $cell === null ? '' : $cell;
                if (preg_match('//u', $value) !== 1) {
                    throw new Problem(
                        'OJ_ML_MLPX_DATASET_ENCODING_INVALID',
                        '정본 데이터셋에 올바르지 않은 UTF-8 문자열이 있습니다.',
                        422,
                        ['entry' => $path, 'record' => $records]
                    );
                }
                $cells[] = $value;
            }
            if ($this->emptyCsvRecord($cells)) {
                throw new Problem(
                    'OJ_ML_MLPX_DATASET_EMPTY_RECORD',
                    '정본 데이터셋에 빈 레코드가 있습니다.',
                    422,
                    ['record' => $records]
                );
            }

            $columns = count($cells);
            if ($columns > self::HARD_MAX_DATASET_COLUMNS) {
                throw new Problem(
                    'OJ_ML_MLPX_DATASET_COLUMN_LIMIT',
                    '데이터셋 열 수가 안전 한도를 초과합니다.',
                    422,
                    ['actualColumns' => $columns, 'maxColumns' => self::HARD_MAX_DATASET_COLUMNS]
                );
            }
            if ($width === null) {
                $width = $columns;
                if ($hasHeader) {
                    $header = $cells;
                }
            } elseif ($columns !== $width) {
                throw new Problem(
                    'OJ_ML_MLPX_DATASET_ROW_WIDTH',
                    '정본 데이터셋의 레코드별 열 수가 일치하지 않습니다.',
                    422,
                    ['record' => $records, 'expectedColumns' => $width, 'actualColumns' => $columns]
                );
            }
        };

        $record = '';
        $recordBytes = 0;
        $inQuotes = false;
        $quotePending = false;
        $fieldStart = true;
        $afterQuotedField = false;
        $skipLineFeed = false;

        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || ($chunk === '' && !feof($stream))) {
                throw new Problem(
                    'OJ_ML_MLPX_ENTRY_UNREADABLE',
                    'MLPX 내부 데이터셋을 읽을 수 없습니다.',
                    422,
                    ['entry' => $path]
                );
            }
            if ($chunk === '') {
                break;
            }

            $segmentStart = 0;
            $length = strlen($chunk);
            for ($index = 0; $index < $length; $index++) {
                $character = $chunk[$index];

                if ($skipLineFeed) {
                    $skipLineFeed = false;
                    if ($character === "\n") {
                        $segmentStart = $index + 1;
                        continue;
                    }
                }

                if ($quotePending) {
                    if ($character === '"') {
                        $quotePending = false;
                        $recordBytes++;
                        if ($recordBytes > self::HARD_MAX_CSV_RECORD_BYTES) {
                            throw $this->oversizedCsvRecord($records + 1);
                        }
                        continue;
                    }
                    $quotePending = false;
                    $inQuotes = false;
                    $afterQuotedField = true;
                }

                if ($inQuotes) {
                    if ($character === '"') {
                        $quotePending = true;
                    }
                    $recordBytes++;
                    if ($recordBytes > self::HARD_MAX_CSV_RECORD_BYTES) {
                        throw $this->oversizedCsvRecord($records + 1);
                    }
                    continue;
                }

                if ($character === "\r" || $character === "\n") {
                    if ($index > $segmentStart) {
                        $record .= substr($chunk, $segmentStart, $index - $segmentStart);
                    }
                    $consume($record);
                    $record = '';
                    $recordBytes = 0;
                    $fieldStart = true;
                    $afterQuotedField = false;
                    $segmentStart = $index + 1;
                    $skipLineFeed = $character === "\r";
                    continue;
                }

                if ($afterQuotedField) {
                    if ($character !== ',') {
                        throw new Problem(
                            'OJ_ML_MLPX_DATASET_CSV_INVALID',
                            '정본 데이터셋의 CSV 따옴표 형식이 올바르지 않습니다.',
                            422,
                            ['record' => $records + 1]
                        );
                    }
                    $afterQuotedField = false;
                    $fieldStart = true;
                    $recordBytes++;
                    if ($recordBytes > self::HARD_MAX_CSV_RECORD_BYTES) {
                        throw $this->oversizedCsvRecord($records + 1);
                    }
                    continue;
                }

                if ($character === ',') {
                    $fieldStart = true;
                } elseif ($character === '"') {
                    if (!$fieldStart) {
                        throw new Problem(
                            'OJ_ML_MLPX_DATASET_CSV_INVALID',
                            '정본 데이터셋의 CSV 따옴표 형식이 올바르지 않습니다.',
                            422,
                            ['record' => $records + 1]
                        );
                    }
                    $inQuotes = true;
                    $fieldStart = false;
                } else {
                    $fieldStart = false;
                }

                $recordBytes++;
                if ($recordBytes > self::HARD_MAX_CSV_RECORD_BYTES) {
                    throw $this->oversizedCsvRecord($records + 1);
                }
            }

            if ($segmentStart < $length) {
                $record .= substr($chunk, $segmentStart);
            }
        }

        if ($quotePending) {
            $quotePending = false;
            $inQuotes = false;
            $afterQuotedField = true;
        }
        if ($inQuotes) {
            throw new Problem(
                'OJ_ML_MLPX_DATASET_CSV_INVALID',
                '정본 데이터셋의 CSV 따옴표가 닫히지 않았습니다.',
                422,
                ['record' => $records + 1]
            );
        }
        if ($recordBytes > 0) {
            $consume($record);
        }

        $columns = $width ?? 0;
        $rows = $records - ($hasHeader ? 1 : 0);
        if ($records === 0 || $columns === 0 || $rows < 1) {
            throw new Problem(
                'OJ_ML_MLPX_DATASET_EMPTY',
                '데이터셋에 실제 데이터 행이 없습니다.',
                422,
                ['entry' => $path, 'hasHeader' => $hasHeader]
            );
        }

        return [
            'records' => $records,
            'rows' => $rows,
            'columns' => $columns,
            'columnNames' => $this->columnNames($hasHeader ? $header : [], $columns),
        ];
    }

    private function oversizedCsvRecord(int $record): Problem
    {
        return new Problem(
            'OJ_ML_MLPX_DATASET_RECORD_TOO_LARGE',
            '데이터셋의 단일 레코드가 너무 큽니다.',
            422,
            ['record' => $record, 'maxRawBytes' => self::HARD_MAX_CSV_RECORD_BYTES]
        );
    }

    /** @param list<string> $cells */
    private function emptyCsvRecord(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($this->trimColumnName($cell) !== '') {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $header @return list<string> */
    private function columnNames(array $header, int $width): array
    {
        $names = [];
        $taken = [];
        for ($index = 0; $index < $width; $index++) {
            $letter = $this->spreadsheetName($index);
            $raw = $this->trimColumnName($header[$index] ?? '');
            $labeled = $raw === '' || isset($taken[$raw])
                ? ($raw === '' ? '' : $raw . ' ') . '(' . $letter . ')'
                : $raw;
            $name = $labeled;
            for ($suffix = 2; isset($taken[$name]); $suffix++) {
                $name = $labeled . ' (' . $suffix . ')';
            }
            $names[] = $name;
            $taken[$name] = true;
        }
        return $names;
    }

    private function spreadsheetName(int $index): string
    {
        $name = '';
        for ($number = $index; $number >= 0; $number = intdiv($number, 26) - 1) {
            $name = chr(65 + ($number % 26)) . $name;
        }
        return $name;
    }

    private function trimColumnName(string $value): string
    {
        $trimmed = preg_replace('/^[\\p{Z}\\s\\x{FEFF}]+|[\\p{Z}\\s\\x{FEFF}]+$/u', '', $value);
        return is_string($trimmed) ? $trimmed : trim($value);
    }

    /** @param array<string,mixed> $policy */
    private function optionalPolicyLimit(array $policy, string $key, int $hardMaximum): ?int
    {
        if (!array_key_exists($key, $policy)) {
            return null;
        }
        return $this->boundedInt($policy[$key], 1, $hardMaximum);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param list<string> $algorithms
     * @param array{present:bool,verified:bool,contentHash:?string,entryHashes:array<string,string>} $hashFacts
     * @param array<string,mixed> $policy
     * @param list<array<string,mixed>> $findings
     */
    private function enforcePolicy(array $manifest, array $algorithms, array $hashFacts, array $policy, array &$findings): void
    {
        $allowedFormats = $this->stringOrIntegerList($policy['allowedFormatVersions'] ?? [2], true);
        if ($allowedFormats !== [] && !in_array((int) $manifest['formatVersion'], $allowedFormats, true)) {
            $findings[] = $this->finding('error', 'OJ_ML_POLICY_FORMAT_VERSION', '허용되지 않은 MLPX 형식 버전입니다.');
        }
        $allowedData = $this->stringOrIntegerList($policy['allowedDataTypes'] ?? ['tabular']);
        if (!in_array((string) $manifest['dataType'], $allowedData, true)) {
            $findings[] = $this->finding('error', 'OJ_ML_POLICY_DATA_TYPE', '이 과제에서 허용하지 않는 데이터 유형입니다.');
        }
        $allowedTasks = $this->stringOrIntegerList($policy['allowedTaskTypes'] ?? []);
        if ($allowedTasks !== [] && !in_array((string) ($manifest['taskType'] ?? ''), $allowedTasks, true)) {
            $taskType = $manifest['taskType'] ?? null;
            $actualLabel = $taskType === null
                ? '미선택'
                : (self::TASK_TYPE_LABELS[$taskType] ?? '알 수 없는 유형');
            $allowedLabels = $this->policyLabels($allowedTasks, self::TASK_TYPE_LABELS, '기타 지정 유형');
            $findings[] = $this->finding(
                'error',
                'OJ_ML_POLICY_TASK_TYPE',
                '이 과제에서 허용하지 않는 학습 과제 유형입니다. 제출 유형: ' . $actualLabel
                    . '. 허용 유형: ' . $allowedLabels . '. 과제 조건과 실습의 학습 유형을 확인하세요.'
            );
        }
        $allowedAlgorithms = $this->stringOrIntegerList($policy['allowedAlgorithms'] ?? []);
        if ($allowedAlgorithms !== []) {
            $allowedLabels = $this->policyLabels($allowedAlgorithms, self::ALGORITHM_LABELS, '기타 지정 알고리즘');
            foreach ($algorithms as $algorithm) {
                if (!in_array($algorithm, $allowedAlgorithms, true)) {
                    $actualLabel = self::ALGORITHM_LABELS[$algorithm] ?? '알 수 없는 알고리즘';
                    $findings[] = $this->finding(
                        'error',
                        'OJ_ML_POLICY_ALGORITHM',
                        '허용되지 않은 알고리즘이 포함되어 있습니다. 제출 실행 기록: ' . $actualLabel
                            . '. 허용 알고리즘: ' . $allowedLabels
                            . '. 과거 실험과 실패·취소 실행도 검사합니다. 현재 모델 선택을 바꾸어도 이전 실행 기록은 남습니다.',
                        ['algorithm' => $algorithm]
                    );
                }
            }
        }
        $requiredDatasetHash = strtolower((string) ($policy['requiredDatasetSha256'] ?? ''));
        if ($requiredDatasetHash !== '') {
            $datasetPath = isset($policy['requiredDatasetPath']) ? (string) $policy['requiredDatasetPath'] : null;
            $datasetHashes = array_filter(
                $hashFacts['entryHashes'],
                static fn (string $hash, string $path): bool => str_starts_with($path, 'dataset/'),
                ARRAY_FILTER_USE_BOTH
            );
            $actual = $datasetPath !== null ? ($hashFacts['entryHashes'][$datasetPath] ?? null) : (count($datasetHashes) === 1 ? reset($datasetHashes) : null);
            if (!is_string($actual) || !hash_equals($requiredDatasetHash, $actual)) {
                $findings[] = $this->finding('error', 'OJ_ML_POLICY_DATASET_HASH', '지정된 실습 데이터셋과 제출 데이터셋이 일치하지 않습니다.');
            }
        }
    }

    /**
     * Only fixed labels enter public messages; uploaded IDs or legacy policy values
     * may contain private text. Deduplication bounds the list to the known labels
     * and one fallback without changing exact policy membership checks.
     *
     * @param list<string> $ids
     * @param array<string,string> $labels
     */
    private function policyLabels(array $ids, array $labels, string $fallback): string
    {
        $safe = [];
        foreach ($ids as $id) {
            $safe[$labels[$id] ?? $fallback] = true;
        }
        return implode(', ', array_keys($safe));
    }

    /** @param array<string,mixed> $runs @return list<array<string,mixed>> */
    private function actualRuns(array $runs): array
    {
        $actual = [];
        $experiments = $runs['experiments'] ?? null;
        if (!is_array($experiments)) {
            return $actual;
        }
        foreach ($experiments as $experiment) {
            if (!is_array($experiment) || !is_array($experiment['runs'] ?? null)) {
                continue;
            }
            foreach ($experiment['runs'] as $run) {
                if (is_array($run)) {
                    $actual[] = $run;
                }
            }
        }
        return $actual;
    }

    /** @param array<string,mixed> $runs @return list<string> */
    private function collectAlgorithms(array $runs): array
    {
        $found = [];
        foreach ($this->actualRuns($runs) as $run) {
            $algorithm = $run['algorithm'] ?? null;
            if (is_string($algorithm) && $algorithm !== '') {
                $found[$algorithm] = true;
            }
        }
        $algorithms = array_keys($found);
        sort($algorithms, SORT_STRING);
        return $algorithms;
    }

    /** @param array<string,mixed> $runs */
    private function countRuns(array $runs): int
    {
        return count($this->actualRuns($runs));
    }

    /** @param mixed $value @return list<mixed> */
    private function stringOrIntegerList(mixed $value, bool $integers = false): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if ($integers && is_numeric($item)) {
                $result[] = (int) $item;
            } elseif (!$integers && is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }
        return array_values(array_unique($result, SORT_REGULAR));
    }

    /** @param array<string,mixed> $policy @return array{entries:int,total:int,entry:int,ratio:float,json:int} */
    private function limits(array $policy): array
    {
        return [
            'entries' => $this->boundedInt($policy['maxEntries'] ?? 10000, 1, self::HARD_MAX_ENTRIES),
            'total' => $this->boundedInt($policy['maxTotalUncompressedBytes'] ?? 262144000, 1, self::HARD_MAX_TOTAL_BYTES),
            'entry' => $this->boundedInt($policy['maxEntryUncompressedBytes'] ?? 104857600, 1, self::HARD_MAX_ENTRY_BYTES),
            'ratio' => (float) $this->boundedInt($policy['maxCompressionRatio'] ?? 100, 1, (int) self::HARD_MAX_RATIO),
            'json' => $this->boundedInt($policy['maxJsonBytes'] ?? 2097152, 1, self::HARD_MAX_JSON_BYTES),
        ];
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum): int
    {
        $number = is_numeric($value) ? (int) $value : $minimum;
        return max($minimum, min($maximum, $number));
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function finding(string $severity, string $code, string $message, array $context = []): array
    {
        return array_filter([
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'context' => $context === [] ? null : $context,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
