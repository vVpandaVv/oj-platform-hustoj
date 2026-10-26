<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemArchiveManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjCompilerProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjSandboxProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDataFile;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;

final class ProblemTestDataJobService
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemFileManager $files,
        private readonly ProblemArchiveManager $archives,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemAiDraftService $drafts,
        private readonly JobDispatcher $jobs,
        private readonly ProblemNumberChangeLock $numberChangeLock,
        private readonly SpjCompilerProfileProvider $compilerProfiles,
        private readonly SpjSandboxProfileProvider $sandboxProfiles
    ) {
    }

    /**
     * @param array<string, string> $expectedHashesByFile
     */
    public function enqueueArchiveCreate(
        int $actorUserId,
        ProblemId $problemId,
        string $archiveName,
        array $expectedHashesByFile
    ): JobEnqueueResult {
        $this->assertTestDataAccess($actorUserId, $problemId);
        $archiveName = trim($archiveName);
        $selected = $this->validatedSelection(
            $problemId,
            $expectedHashesByFile
        );

        return $this->jobs->enqueue(
            'problem_testcase_apply',
            'archive_create',
            $actorUserId,
            [
                new JobTarget(
                    'problem_test_data',
                    $problemId->value() . ':archive:' . $archiveName,
                    [
                        'operation' => 'archive_create',
                        'problem_id' => $problemId->value(),
                        'archive_name' => $archiveName,
                        'file_hashes' => $selected,
                    ],
                    hash('sha256', implode('', $selected))
                ),
            ],
            [
                'operation' => 'archive_create',
                'archive_name' => $archiveName,
                'file_count' => count($selected),
            ],
            [
                'problem_id' => $problemId->value(),
                'file_count' => count($selected),
            ],
            'problems.test_data',
            '1',
            '1'
        );
    }

    public function enqueueArchiveExtract(
        int $actorUserId,
        ProblemId $problemId,
        string $archiveName,
        string $expectedArchiveSha256,
        bool $overwriteCollisions
    ): JobEnqueueResult {
        $this->assertTestDataAccess($actorUserId, $problemId);
        $inspection = $this->archives->inspect(
            $problemId,
            trim($archiveName),
            $expectedArchiveSha256
        );
        $overwriteHashes = $overwriteCollisions
            ? $inspection->collisionHashes()
            : [];

        return $this->jobs->enqueue(
            'problem_testcase_apply',
            'archive_extract',
            $actorUserId,
            [
                new JobTarget(
                    'problem_test_data',
                    $problemId->value() . ':extract:' . $inspection->archiveName(),
                    [
                        'operation' => 'archive_extract',
                        'problem_id' => $problemId->value(),
                        'archive_name' => $inspection->archiveName(),
                        'archive_sha256' => $inspection->archiveSha256(),
                        'overwrite_hashes' => $overwriteHashes,
                    ],
                    $inspection->archiveSha256()
                ),
            ],
            [
                'operation' => 'archive_extract',
                'archive_name' => $inspection->archiveName(),
                'file_count' => $inspection->entryCount(),
                'overwrite_count' => count($overwriteHashes),
            ],
            [
                'problem_id' => $problemId->value(),
                'file_count' => $inspection->entryCount(),
            ],
            'problems.test_data',
            '1',
            '1'
        );
    }

    public function enqueueSpjCompile(
        int $actorUserId,
        ProblemId $problemId,
        string $sourceName
    ): JobEnqueueResult {
        $problem = $this->problems->find($problemId);
        if (
            $problem === null
            || ! $problem->specialJudge()
            || ! $this->access->canManageTestData($actorUserId, $problemId)
        ) {
            throw new DomainException('이 문제의 SPJ를 컴파일할 수 없습니다.');
        }
        if ($this->numberChangeLock->isLocked($problemId)) {
            throw new DomainException(
                '문제 번호 변경 중에는 SPJ 컴파일을 등록할 수 없습니다.'
            );
        }
        if (! in_array($sourceName, ['spj.c', 'spj.cc', 'spj.cpp'], true)) {
            throw new DomainException('SPJ 소스 파일을 선택해 주세요.');
        }
        $this->sandboxProfiles->current();
        $this->compilerProfiles->forSource($sourceName);
        $files = $this->indexFiles($problemId);
        $source = $files[$sourceName] ?? null;
        if (! $source instanceof ProblemDataFile) {
            throw new DomainException('선택한 SPJ 소스 파일을 찾을 수 없습니다.');
        }

        return $this->jobs->enqueue(
            'problem_spj_compile',
            'single',
            $actorUserId,
            [
                new JobTarget(
                    'problem_spj',
                    (string) $problemId->value(),
                    [
                        'problem_id' => $problemId->value(),
                        'source_name' => $sourceName,
                        'source_sha256' => $source->sha256(),
                        'binary_sha256' => ($files['spj'] ?? null)?->sha256(),
                        'log_sha256' => ($files['spj-build.log'] ?? null)?->sha256(),
                    ],
                    $source->sha256(),
                    $source->modifiedAt()
                ),
            ],
            [
                'source_name' => $sourceName,
                'source_sha256' => $source->sha256(),
            ],
            ['problem_id' => $problemId->value()],
            'problems.test_data',
            '1',
            '1'
        );
    }

    /**
     * @param list<string> $selectedFileNames
     */
    public function enqueueAiDraftApply(
        int $actorUserId,
        ProblemId $problemId,
        string $draftReference,
        array $selectedFileNames,
        bool $overwriteCollisions
    ): JobEnqueueResult {
        $this->assertTestDataAccess($actorUserId, $problemId);
        $draft = $this->drafts->testcases(
            $draftReference,
            $actorUserId,
            $problemId
        );
        if ($draft === null) {
            throw new DomainException('적용할 AI 테스트케이스 초안을 선택해 주세요.');
        }
        $selectedFileNames = array_values(array_unique(array_map(
            'trim',
            $selectedFileNames
        )));
        if ($selectedFileNames === [] || count($selectedFileNames) > 40) {
            throw new DomainException('적용할 AI 테스트 파일을 1개부터 40개까지 선택해 주세요.');
        }
        $draftFiles = [];
        foreach ($draft['files'] as $file) {
            $draftFiles[$file['name']] = $file;
        }
        $currentFiles = $this->indexFiles($problemId);
        $expectedHashes = [];
        $collisionCount = 0;
        foreach ($selectedFileNames as $fileName) {
            if (! isset($draftFiles[$fileName])) {
                throw new DomainException('AI 초안에 없는 테스트 파일이 선택되었습니다.');
            }
            $current = $currentFiles[$fileName] ?? null;
            if ($current instanceof ProblemDataFile) {
                ++$collisionCount;
                if (! $overwriteCollisions) {
                    throw new DomainException(
                        '같은 이름의 파일이 있습니다. 덮어쓰기를 확인해 주세요.'
                    );
                }
                $expectedHashes[$fileName] = $current->sha256();
            } else {
                $expectedHashes[$fileName] = null;
            }
        }
        ksort($expectedHashes, SORT_NATURAL);
        $selectedFileNames = array_keys($expectedHashes);
        $inputHash = hash(
            'sha256',
            $draft['reference'] . "\n" . implode(
                "\n",
                array_map(
                    static fn (string $fileName): string => $fileName . ':'
                        . ($expectedHashes[$fileName] ?? 'new'),
                    $selectedFileNames
                )
            )
        );

        return $this->jobs->enqueue(
            'problem_testcase_apply',
            'ai_draft_apply',
            $actorUserId,
            [new JobTarget(
                'problem_test_data',
                $problemId->value() . ':' . $draft['reference'],
                [
                    'operation' => 'ai_draft_apply',
                    'problem_id' => $problemId->value(),
                    'draft_reference' => $draft['reference'],
                    'selected_files' => $selectedFileNames,
                    'expected_hashes' => $expectedHashes,
                ],
                $inputHash
            )],
            [
                'operation' => 'ai_draft_apply',
                'file_count' => count($selectedFileNames),
                'overwrite_count' => $collisionCount,
            ],
            [
                'problem_id' => $problemId->value(),
                'file_count' => count($selectedFileNames),
            ],
            'problems.test_data',
            '1',
            '1'
        );
    }

    private function assertTestDataAccess(
        int $actorUserId,
        ProblemId $problemId
    ): void {
        if (
            $this->problems->find($problemId) === null
            || ! $this->access->canManageTestData($actorUserId, $problemId)
        ) {
            throw new DomainException('이 문제의 테스트 데이터를 관리할 수 없습니다.');
        }
        if ($this->numberChangeLock->isLocked($problemId)) {
            throw new DomainException(
                '문제 번호 변경 중에는 테스트데이터 작업을 등록할 수 없습니다.'
            );
        }
    }

    /**
     * @param array<string, string> $selection
     *
     * @return array<string, string>
     */
    private function validatedSelection(
        ProblemId $problemId,
        array $selection
    ): array {
        if ($selection === [] || count($selection) > 200) {
            throw new DomainException('ZIP으로 묶을 파일은 1개부터 200개까지 선택해 주세요.');
        }
        $files = $this->indexFiles($problemId);
        $validated = [];
        foreach ($selection as $fileName => $expectedHash) {
            $file = $files[$fileName] ?? null;
            if (
                ! $file instanceof ProblemDataFile
                || ! is_string($expectedHash)
                || ! hash_equals($file->sha256(), $expectedHash)
                || ! $file->isEditable()
            ) {
                throw new DomainException(
                    'ZIP 대상 파일이 변경되었거나 허용되지 않는 파일을 포함합니다.'
                );
            }
            $validated[$fileName] = $expectedHash;
        }
        ksort($validated, SORT_NATURAL);

        return $validated;
    }

    /**
     * @return array<string, ProblemDataFile>
     */
    private function indexFiles(ProblemId $problemId): array
    {
        $indexed = [];
        foreach ($this->files->files($problemId) as $file) {
            $indexed[$file->name()] = $file;
        }

        return $indexed;
    }
}
