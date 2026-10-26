<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextProfileProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmitSubmissionCommand;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use DomainException;

final class SubmissionContextProviderRegistry
{
    /**
     * @var list<SubmissionContextProvider>
     */
    private array $providers = [];

    public function register(SubmissionContextProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    public function blocklyProfile(
        int $contestId,
        ?int $classId,
        ?int $groupId
    ): ?string {
        if ($contestId === 0 && $classId === null && $groupId === null) {
            return null;
        }
        $matched = array_values(array_filter(
            $this->providers,
            static fn (SubmissionContextProvider $provider): bool =>
                $provider instanceof SubmissionContextProfileProvider
                && $provider->supportsContext(
                    $contestId,
                    $classId,
                    $groupId
                )
        ));
        if (count($matched) !== 1) {
            throw new DomainException(
                '수업·대회·그룹 Blockly 설정을 확인할 수 없습니다.'
            );
        }
        $provider = $matched[0];

        return $provider instanceof SubmissionContextProfileProvider
            ? $provider->blocklyProfile($contestId, $classId, $groupId)
            : null;
    }
    public function validate(
        SubmitSubmissionCommand $command
    ): SubmissionContext {
        if (
            $command->contestId() === 0
            && $command->classId() === null
            && $command->groupId() === null
        ) {
            return new SubmissionContext();
        }
        $matched = array_values(array_filter(
            $this->providers,
            static fn (SubmissionContextProvider $provider): bool => $provider->supports(
                $command
            )
        ));
        if (count($matched) !== 1) {
            throw new DomainException(
                '수업·대회·그룹 제출 문맥을 검증할 수 없습니다.'
            );
        }

        return $matched[0]->validate($command);
    }
}
