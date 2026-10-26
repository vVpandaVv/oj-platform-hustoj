<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraft;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlApplyResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlApplyRow;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlDocument;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlImportPlan;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlPreview;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlPreviewRow;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class ProblemXmlPreviewService
{
    private const MAX_XML_BYTES = 20971520;
    private const IMPORT_DRAFT_TYPE = 'problem_xml_import';
    private const RESULT_DRAFT_TYPE = 'problem_xml_result';
    private const TARGET_TYPE = 'problem_xml';
    private const DRAFT_TTL_SECONDS = 3600;

    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemTopicDictionary $topics,
        private readonly ProblemNumberChangeLock $numberChangeLock,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemXmlCodec $codec,
        private readonly ProblemXmlMaterializer $materializer,
        private readonly FormDraftStore $drafts
    ) {
    }

    /**
     * @return list<ProblemTopic>
     */
    public function selectableTopics(int $actorUserId): array
    {
        $this->assertAccess($actorUserId);

        return array_values(array_filter(
            $this->topics->all(),
            static fn (ProblemTopic $topic): bool =>
                ! $topic->isCategory() && $topic->isActive()
        ));
    }

    public function previewUpload(
        int $actorUserId,
        string $clientName,
        string $temporaryPath,
        int $declaredSize,
        string $fallbackTopicKey,
        bool $allowOverwrite,
        bool $includeMetadata,
        bool $includeTestData
    ): ProblemXmlPreview {
        if (
            strtolower(pathinfo($clientName, PATHINFO_EXTENSION)) !== 'xml'
            || $declaredSize < 1
            || $declaredSize > self::MAX_XML_BYTES
            || ! is_file($temporaryPath)
            || is_link($temporaryPath)
            || ! is_readable($temporaryPath)
        ) {
            throw new DomainException('20MB 이하의 XML 파일을 선택해 주세요.');
        }
        $actualSize = filesize($temporaryPath);
        if (
            $actualSize === false
            || $actualSize !== $declaredSize
            || $actualSize < 1
            || $actualSize > self::MAX_XML_BYTES
        ) {
            throw new DomainException('업로드한 XML 파일 크기를 확인하지 못했습니다.');
        }
        $xml = file_get_contents($temporaryPath);
        if (! is_string($xml)) {
            throw new DomainException('업로드한 XML 파일을 읽지 못했습니다.');
        }

        return $this->createPreview(
            $actorUserId,
            $xml,
            $fallbackTopicKey,
            $allowOverwrite,
            $includeMetadata,
            $includeTestData
        );
    }

    public function createPreview(
        int $actorUserId,
        string $xml,
        string $fallbackTopicKey,
        bool $allowOverwrite,
        bool $includeMetadata,
        bool $includeTestData
    ): ProblemXmlPreview {
        $this->assertCanApply($actorUserId);
        $fallbackTopicKey = trim($fallbackTopicKey);
        $this->assertFallbackTopic($fallbackTopicKey);
        $document = $this->codec->decode($xml);
        [$rows, $baselines] = $this->previewRows(
            $document,
            $fallbackTopicKey,
            $allowOverwrite,
            $includeMetadata,
            $includeTestData,
            null
        );
        $xmlHash = hash('sha256', $xml);
        $reference = $this->drafts->store(
            self::IMPORT_DRAFT_TYPE,
            $actorUserId,
            self::TARGET_TYPE,
            $xmlHash,
            [
                'version' => 1,
                'xml' => $xml,
                'xml_hash' => $xmlHash,
                'fallback_topic_key' => $fallbackTopicKey,
                'allow_overwrite' => $allowOverwrite,
                'include_metadata' => $includeMetadata,
                'include_test_data' => $includeTestData,
                'baselines' => $baselines,
            ],
            self::DRAFT_TTL_SECONDS
        );

        return new ProblemXmlPreview(
            $reference->value(),
            $rows,
            $document->issues(),
            $fallbackTopicKey,
            $allowOverwrite,
            $includeMetadata,
            $includeTestData
        );
    }

    public function preview(
        int $actorUserId,
        string $reference
    ): ProblemXmlPreview {
        return $this->plan($actorUserId, $reference)->preview();
    }

    public function plan(
        int $actorUserId,
        string $reference
    ): ProblemXmlImportPlan {
        $this->assertAccess($actorUserId);
        $draft = $this->loadDraft(
            $reference,
            $actorUserId,
            self::IMPORT_DRAFT_TYPE
        );
        $payload = $this->importPayload($draft);
        $document = $this->codec->decode($payload['xml']);
        [$rows] = $this->previewRows(
            $document,
            $payload['fallback_topic_key'],
            $payload['allow_overwrite'],
            $payload['include_metadata'],
            $payload['include_test_data'],
            $payload['baselines']
        );
        $preview = new ProblemXmlPreview(
            $reference,
            $rows,
            $document->issues(),
            $payload['fallback_topic_key'],
            $payload['allow_overwrite'],
            $payload['include_metadata'],
            $payload['include_test_data']
        );

        return new ProblemXmlImportPlan(
            $preview,
            $document,
            $payload['baselines']
        );
    }

    /**
     * @param list<ProblemXmlApplyRow> $rows
     */
    public function complete(
        int $actorUserId,
        string $importReference,
        array $rows
    ): ProblemXmlApplyResult {
        $this->assertAccess($actorUserId);
        $resultReference = $this->storeResult($actorUserId, $rows);
        try {
            $this->drafts->discard(
                new FormDraftReference($importReference),
                $actorUserId
            );
        } catch (Throwable) {
        }

        return new ProblemXmlApplyResult($resultReference, $rows);
    }

    public function result(
        int $actorUserId,
        string $reference
    ): ProblemXmlApplyResult {
        $this->assertAccess($actorUserId);
        $draft = $this->loadDraft(
            $reference,
            $actorUserId,
            self::RESULT_DRAFT_TYPE
        );
        $payload = $draft->payload();
        if (
            (int) ($payload['version'] ?? 0) !== 1
            || ! is_array($payload['rows'] ?? null)
        ) {
            throw new DomainException('문제 XML 적용 결과 형식이 올바르지 않습니다.');
        }
        $rows = [];
        foreach ($payload['rows'] as $row) {
            if (! is_array($row)) {
                throw new DomainException('문제 XML 적용 결과 행이 올바르지 않습니다.');
            }
            $problemId = isset($row['problem_id'])
                ? new ProblemId((int) $row['problem_id'])
                : null;
            $rows[] = new ProblemXmlApplyRow(
                (int) ($row['index'] ?? -1),
                $problemId,
                is_string($row['title'] ?? null) ? $row['title'] : '',
                is_string($row['status'] ?? null) ? $row['status'] : '',
                is_string($row['message'] ?? null) ? $row['message'] : ''
            );
        }

        return new ProblemXmlApplyResult($reference, $rows);
    }

    public function assertCanApply(int $actorUserId): void
    {
        $this->assertAccess($actorUserId);
        if ($this->numberChangeLock->hasActiveChange()) {
            throw new DomainException(
                '문제 번호 변경 중에는 XML 가져오기를 실행할 수 없습니다.'
            );
        }
    }

    /**
     * @param array<int|string, mixed>|null $expectedBaselines
     * @return array{
     *     0:list<ProblemXmlPreviewRow>,
     *     1:array<int,array{operation:string,state_hash:string,content_hash:string}>
     * }
     */
    private function previewRows(
        ProblemXmlDocument $document,
        string $fallbackTopicKey,
        bool $allowOverwrite,
        bool $includeMetadata,
        bool $includeTestData,
        ?array $expectedBaselines
    ): array {
        $requestedIdCounts = [];
        foreach ($document->items() as $item) {
            $requested = $item->requestedProblemId();
            if ($requested !== null) {
                $requestedIdCounts[$requested->value()]
                    = ($requestedIdCounts[$requested->value()] ?? 0) + 1;
            }
        }

        $rows = [];
        $baselines = [];
        foreach ($document->items() as $item) {
            $problemId = $item->requestedProblemId();
            $existing = $problemId === null
                ? null
                : $this->problems->find($problemId);
            $operation = $existing === null
                ? ProblemXmlPreviewRow::CREATE
                : ProblemXmlPreviewRow::UPDATE;
            $stateHash = $problemId === null
                ? 'missing'
                : ($this->materializer->stateHash(
                    $problemId,
                    $includeTestData
                ) ?? 'missing');
            $contentHash = $item->contentHash();
            $message = '';
            try {
                $this->materializer->validateImport(
                    $item,
                    $fallbackTopicKey,
                    $includeMetadata
                );
                if (
                    $problemId !== null
                    && ($requestedIdCounts[$problemId->value()] ?? 0) > 1
                ) {
                    throw new DomainException(
                        'XML 안에서 같은 문제 번호가 중복되었습니다.'
                    );
                }
                if (
                    $problemId !== null
                    && $this->numberChangeLock->isLocked($problemId)
                ) {
                    throw new DomainException(
                        '문제 번호 변경 중인 항목은 가져올 수 없습니다.'
                    );
                }
                if ($existing !== null && ! $allowOverwrite) {
                    throw new DomainException(
                        '같은 문제 번호가 있습니다. 덮어쓰기 허용 여부를 확인해 주세요.'
                    );
                }
                if ($expectedBaselines !== null) {
                    $expected = $expectedBaselines[$item->index()] ?? null;
                    if (
                        ! is_array($expected)
                        || ($expected['operation'] ?? null) !== $operation
                        || ($expected['state_hash'] ?? null) !== $stateHash
                        || ($expected['content_hash'] ?? null) !== $contentHash
                    ) {
                        throw new DomainException(
                            '미리보기 이후 현재 문제가 변경되었습니다. 다시 분석해 주세요.'
                        );
                    }
                }
            } catch (DomainException | InvalidArgumentException $error) {
                $message = $error->getMessage();
            }
            $baselines[$item->index()] = [
                'operation' => $operation,
                'state_hash' => $stateHash,
                'content_hash' => $contentHash,
            ];
            $rows[] = new ProblemXmlPreviewRow(
                $item->index(),
                $problemId,
                $item->draft()->title(),
                $operation,
                $message !== '',
                $message,
                count(array_filter(
                    array_keys($item->testFiles()),
                    static fn (string $name): bool => str_ends_with($name, '.in')
                )),
                $contentHash
            );
        }

        return [$rows, $baselines];
    }

    /**
     * @return array{
     *     xml:string,
     *     fallback_topic_key:string,
     *     allow_overwrite:bool,
     *     include_metadata:bool,
     *     include_test_data:bool,
     *     baselines:array<int|string,mixed>
     * }
     */
    private function importPayload(FormDraft $draft): array
    {
        $payload = $draft->payload();
        $xml = is_string($payload['xml'] ?? null) ? $payload['xml'] : '';
        $fallback = is_string($payload['fallback_topic_key'] ?? null)
            ? trim($payload['fallback_topic_key'])
            : '';
        if (
            (int) ($payload['version'] ?? 0) !== 1
            || $xml === ''
            || hash('sha256', $xml) !== ($payload['xml_hash'] ?? null)
            || $draft->targetKey() !== ($payload['xml_hash'] ?? null)
            || ! is_bool($payload['allow_overwrite'] ?? null)
            || ! is_bool($payload['include_metadata'] ?? null)
            || ! is_bool($payload['include_test_data'] ?? null)
            || ! is_array($payload['baselines'] ?? null)
        ) {
            throw new DomainException('문제 XML 미리보기 내용이 올바르지 않습니다.');
        }
        $this->assertFallbackTopic($fallback);

        return [
            'xml' => $xml,
            'fallback_topic_key' => $fallback,
            'allow_overwrite' => $payload['allow_overwrite'],
            'include_metadata' => $payload['include_metadata'],
            'include_test_data' => $payload['include_test_data'],
            'baselines' => $payload['baselines'],
        ];
    }

    private function loadDraft(
        string $reference,
        int $actorUserId,
        string $expectedType
    ): FormDraft {
        try {
            $draft = $this->drafts->find(
                new FormDraftReference($reference),
                $actorUserId
            );
        } catch (InvalidArgumentException $error) {
            throw new DomainException(
                '문제 XML 작업 참조가 올바르지 않습니다.',
                0,
                $error
            );
        }
        if (
            $draft === null
            || $draft->draftType() !== $expectedType
            || $draft->targetType() !== self::TARGET_TYPE
        ) {
            throw new DomainException(
                '문제 XML 작업이 만료되었거나 접근할 수 없습니다.'
            );
        }

        return $draft;
    }

    /**
     * @param list<ProblemXmlApplyRow> $rows
     */
    private function storeResult(int $actorUserId, array $rows): ?string
    {
        $payloadRows = array_map(
            static fn (ProblemXmlApplyRow $row): array => [
                'index' => $row->index(),
                'problem_id' => $row->problemId()?->value(),
                'title' => $row->title(),
                'status' => $row->status(),
                'message' => $row->message(),
            ],
            $rows
        );
        try {
            return $this->drafts->store(
                self::RESULT_DRAFT_TYPE,
                $actorUserId,
                self::TARGET_TYPE,
                hash('sha256', random_bytes(32)),
                ['version' => 1, 'rows' => $payloadRows],
                self::DRAFT_TTL_SECONDS
            )->value();
        } catch (Throwable) {
            return null;
        }
    }

    private function assertAccess(int $actorUserId): void
    {
        if (! $this->access->canManageXml($actorUserId)) {
            throw new DomainException('문제 XML을 관리할 권한이 없습니다.');
        }
    }

    private function assertFallbackTopic(string $fallbackTopicKey): void
    {
        if ($fallbackTopicKey === '') {
            return;
        }

        $this->topics->assertSelectableDetails([$fallbackTopicKey]);
    }
}
