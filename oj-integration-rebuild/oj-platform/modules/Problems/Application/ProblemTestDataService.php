<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemArchiveManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveInspection;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDataFile;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataInspection;
use DomainException;

final class ProblemTestDataService
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemFileManager $files,
        private readonly ProblemDataRootProvider $roots,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemArchiveManager $archives,
        private readonly ProblemNumberChangeLock $numberChangeLock
    ) {
    }

    /**
     * @return list<ProblemDataFile>
     */
    public function files(int $actorUserId, ProblemId $problemId): array
    {
        $this->assertAccess($actorUserId, $problemId);

        return $this->files->files($problemId);
    }

    public function inspection(
        int $actorUserId,
        ProblemId $problemId
    ): ProblemTestDataInspection {
        return ProblemTestDataInspection::inspect(
            $this->files($actorUserId, $problemId)
        );
    }

    public function read(
        int $actorUserId,
        ProblemId $problemId,
        string $fileName
    ): string {
        $this->assertAccess($actorUserId, $problemId);

        return $this->files->read($problemId, $fileName);
    }

    public function download(
        int $actorUserId,
        ProblemId $problemId,
        string $fileName
    ): string {
        $this->assertAccess($actorUserId, $problemId);

        return $this->files->readBinary($problemId, $fileName);
    }

    public function save(
        int $actorUserId,
        ProblemId $problemId,
        string $fileName,
        string $contents,
        ?string $expectedSha256
    ): void {
        $this->assertAccess($actorUserId, $problemId);
        $this->files->write(
            $problemId,
            trim($fileName),
            str_replace(["\r\n", "\r"], "\n", $contents),
            $expectedSha256
        );
    }

    public function upload(
        int $actorUserId,
        ProblemId $problemId,
        string $fileName,
        string $temporaryPath
    ): void {
        $this->assertAccess($actorUserId, $problemId);
        $this->files->import(
            $problemId,
            trim(basename(str_replace('\\', '/', $fileName))),
            $temporaryPath
        );
    }

    public function rename(
        int $actorUserId,
        ProblemId $problemId,
        string $fileName,
        string $newFileName,
        string $expectedSha256
    ): void {
        $this->assertAccess($actorUserId, $problemId);
        $this->files->rename(
            $problemId,
            trim($fileName),
            trim($newFileName),
            $expectedSha256
        );
    }

    public function delete(
        int $actorUserId,
        ProblemId $problemId,
        string $fileName,
        string $expectedSha256
    ): void {
        $this->assertAccess($actorUserId, $problemId);
        $this->files->delete(
            $problemId,
            trim($fileName),
            $expectedSha256
        );
    }

    /**
     * @param array<string, string> $expectedHashesByFile
     */
    public function deleteMany(
        int $actorUserId,
        ProblemId $problemId,
        array $expectedHashesByFile
    ): void {
        $this->assertAccess($actorUserId, $problemId);
        $this->files->deleteMany($problemId, $expectedHashesByFile);
    }

    public function directory(
        int $actorUserId,
        ProblemId $problemId
    ): string {
        $this->assertAccess($actorUserId, $problemId);

        return $this->roots->problemDirectory($problemId);
    }

    public function archiveInspection(
        int $actorUserId,
        ProblemId $problemId,
        string $archiveName
    ): ProblemArchiveInspection {
        $this->assertAccess($actorUserId, $problemId);

        return $this->archives->inspect($problemId, trim($archiveName));
    }

    private function assertAccess(int $actorUserId, ProblemId $problemId): void
    {
        if (
            $this->problems->find($problemId) === null
            || ! $this->access->canManageTestData($actorUserId, $problemId)
        ) {
            throw new DomainException('이 문제의 테스트 데이터를 관리할 수 없습니다.');
        }
        if ($this->numberChangeLock->isLocked($problemId)) {
            throw new DomainException(
                '문제 번호 변경 중에는 테스트 데이터를 변경할 수 없습니다.'
            );
        }
    }
}
