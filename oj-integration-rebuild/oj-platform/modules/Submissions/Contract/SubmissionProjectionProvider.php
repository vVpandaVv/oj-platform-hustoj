<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionProjectionProvider
{
    /**
     * @param list<int> $submissionIds
     * @return list<SubmissionProjection>
     */
    public function byIds(
        int $actorUserId,
        string $actorUserLogin,
        array $submissionIds
    ): array;

    /**
     * @return list<SubmissionProjection>
     */
    public function search(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array;

    /**
     * 호출 모듈이 보고서 접근 권한을 검증한 뒤 요약 정보만 조회합니다.
     *
     * @return list<SubmissionProjection>
     */
    public function report(SubmissionProjectionQuery $query): array;

    /**
     * 권한을 적용하면서 모든 일치 제출을 배치 조회합니다.
     *
     * @return list<SubmissionProjection>
     */
    public function searchAll(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array;

    /**
     * 호출 모듈이 보고서 접근 권한을 검증한 뒤 모든 일치 제출을 조회합니다.
     *
     * @return list<SubmissionProjection>
     */
    public function reportAll(SubmissionProjectionQuery $query): array;
}
