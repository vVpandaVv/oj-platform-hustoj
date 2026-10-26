<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemArchiveManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;

final class ProblemTestcaseApplyJobHandler implements JobHandler
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemArchiveManager $archives,
        private readonly ProblemFileManager $files,
        private readonly ProblemAiDraftService $drafts,
        private readonly ProblemAccessPolicy $access
    ) {
    }

    public function jobType(): string
    {
        return 'problem_testcase_apply';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        $payload = $item->payload();
        if (! is_array($payload)) {
            return JobHandlerResult::failed(
                'problem.invalid_job_payload',
                '테스트데이터 작업 입력이 올바르지 않습니다.'
            );
        }
        $problemIdValue = $this->positiveInt($payload['problem_id'] ?? null);
        if ($problemIdValue < 1) {
            return JobHandlerResult::failed(
                'problem.invalid_job_payload',
                '테스트데이터 작업 대상이 올바르지 않습니다.'
            );
        }
        $problemId = new ProblemId($problemIdValue);
        if (
            $this->problems->find($problemId) === null
            || ! $this->access->canManageTestData(
                $item->claim()->createdByUserId(),
                $problemId
            )
        ) {
            return JobHandlerResult::needsAttention(
                'problem.test_data_permission_changed',
                '현재 권한으로 테스트데이터 작업을 실행할 수 없습니다.'
            );
        }
        try {
            $operation = $this->string($payload['operation'] ?? null);
            if ($operation === 'archive_create') {
                $result = $this->archives->create(
                    $problemId,
                    $this->string($payload['archive_name'] ?? null),
                    $this->stringMap($payload['file_hashes'] ?? null)
                );

                return JobHandlerResult::succeeded(
                    'problem.archive_created',
                    '선택한 테스트 파일을 ZIP으로 만들었습니다.',
                    [
                        'file_count' => $result->fileCount(),
                        'total_bytes' => $result->totalBytes(),
                        'archive_sha256' => $result->resultSha256(),
                    ]
                );
            }
            if ($operation === 'archive_extract') {
                $result = $this->archives->extract(
                    $problemId,
                    $this->string($payload['archive_name'] ?? null),
                    $this->string($payload['archive_sha256'] ?? null),
                    $this->stringMap($payload['overwrite_hashes'] ?? null)
                );

                return JobHandlerResult::succeeded(
                    'problem.archive_extracted',
                    '검증한 ZIP 항목을 문제 폴더에 적용했습니다.',
                    [
                        'file_count' => $result->fileCount(),
                        'total_bytes' => $result->totalBytes(),
                        'result_sha256' => $result->resultSha256(),
                    ]
                );
            }
            if ($operation === 'ai_draft_apply') {
                $reference = $this->string($payload['draft_reference'] ?? null);
                $selectedFiles = $this->stringList(
                    $payload['selected_files'] ?? null
                );
                $expectedHashes = $this->nullableHashMap(
                    $payload['expected_hashes'] ?? null
                );
                if (array_keys($expectedHashes) !== $selectedFiles) {
                    throw new DomainException(
                        'AI 테스트데이터 파일과 원본 해시가 일치하지 않습니다.'
                    );
                }
                $draft = $this->drafts->testcases(
                    $reference,
                    $item->claim()->createdByUserId(),
                    $problemId
                );
                if ($draft === null) {
                    throw new DomainException('AI 테스트케이스 초안을 찾을 수 없습니다.');
                }
                $available = [];
                foreach ($draft['files'] as $file) {
                    $available[$file['name']] = $file['contents'];
                }
                $contents = [];
                foreach ($selectedFiles as $fileName) {
                    if (! array_key_exists($fileName, $available)) {
                        throw new DomainException(
                            'AI 초안에서 적용할 테스트 파일을 찾을 수 없습니다.'
                        );
                    }
                    $contents[$fileName] = $available[$fileName];
                }
                $resultHashes = $this->files->replaceMany(
                    $problemId,
                    $contents,
                    $expectedHashes
                );
                $draftDiscarded = true;
                try {
                    $this->drafts->discard(
                        $reference,
                        $item->claim()->createdByUserId()
                    );
                } catch (\Throwable) {
                    $draftDiscarded = false;
                }

                return JobHandlerResult::succeeded(
                    'problem.testcase_draft_applied',
                    '검토한 AI 테스트케이스를 문제 폴더에 적용했습니다.',
                    [
                        'problem_id' => $problemId->value(),
                        'file_count' => count($resultHashes),
                        'result_sha256' => hash(
                            'sha256',
                            implode('', $resultHashes)
                        ),
                        'draft_discarded' => $draftDiscarded,
                    ]
                );
            }

            return JobHandlerResult::failed(
                'problem.unsupported_testcase_apply',
                '지원하지 않는 테스트데이터 적용 작업입니다.'
            );
        } catch (DomainException $error) {
            return JobHandlerResult::needsAttention(
                'problem.test_data_changed',
                $error->getMessage()
            );
        }
    }

    private function positiveInt(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException('테스트데이터 작업 문자열 입력이 올바르지 않습니다.');
        }

        return trim($value);
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('테스트데이터 파일 해시 입력이 올바르지 않습니다.');
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (
                ! is_string($key)
                || ! is_string($item)
                || preg_match('/^[a-f0-9]{64}$/', $item) !== 1
            ) {
                throw new DomainException('테스트데이터 파일 해시 입력이 올바르지 않습니다.');
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException('테스트데이터 파일 목록이 올바르지 않습니다.');
        }
        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new DomainException('테스트데이터 파일 목록이 올바르지 않습니다.');
            }
            $items[] = trim($item);
        }
        if (
            $items === []
            || count($items) > 40
            || count(array_unique($items)) !== count($items)
        ) {
            throw new DomainException('테스트데이터 파일 목록이 올바르지 않습니다.');
        }

        return $items;
    }

    /**
     * @return array<string, string|null>
     */
    private function nullableHashMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('테스트데이터 원본 해시 입력이 올바르지 않습니다.');
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (
                ! is_string($key)
                || (
                    $item !== null
                    && (
                        ! is_string($item)
                        || preg_match('/^[a-f0-9]{64}$/', $item) !== 1
                    )
                )
            ) {
                throw new DomainException('테스트데이터 원본 해시 입력이 올바르지 않습니다.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
