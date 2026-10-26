<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserImportCandidate;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use DomainException;
use Throwable;

final class UserBulkImportService
{
    public function __construct(
        private readonly UserImportDraftService $drafts,
        private readonly JobDispatcher $jobs,
        private readonly Authorization $permissions,
        private readonly ErrorReporter $errors
    ) {
    }

    public function enqueue(
        string $draftReference,
        int $actorUserId
    ): JobEnqueueResult {
        if (! $this->permissions->can($actorUserId, 'oj_create_users')) {
            throw new DomainException('사용자 일괄 등록 권한이 없습니다.');
        }

        $candidates = $this->drafts->load($draftReference, $actorUserId);
        $targets = array_map(
            fn (UserImportCandidate $candidate): JobTarget => new JobTarget(
                'wordpress_user',
                $candidate->userLogin(),
                $candidate->payload()
            ),
            $candidates
        );
        $result = $this->jobs->enqueue(
            'user_csv_import',
            'selected',
            $actorUserId,
            $targets,
            [
                'columns_version' => '1',
                'passwords_stored' => false,
            ],
            ['candidate_count' => count($candidates)],
            'auth.user_csv_import'
        );

        try {
            $this->drafts->discard($draftReference, $actorUserId);
        } catch (Throwable $error) {
            $this->errors->report($error);
        }

        return $result;
    }
}
