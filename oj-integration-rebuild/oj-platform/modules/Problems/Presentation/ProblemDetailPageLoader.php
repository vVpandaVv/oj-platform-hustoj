<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemBookService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use Throwable;

final class ProblemDetailPageLoader
{
    public function __construct(
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemBookService $books,
        private readonly ErrorReporter $errors
    ) {
    }

    public function load(
        int $userId,
        ProblemId $problemId,
        ?ProblemAccessContext $context = null
    ): ?ProblemDetailPageState {
        $problem = $this->catalog->findVisible(
            $userId,
            $problemId,
            $context
        );
        if ($problem === null) {
            return null;
        }

        return new ProblemDetailPageState(
            $problem,
            $this->optional(
                fn () => $this->catalog->metadata($problemId),
                null
            ),
            $this->optional(
                fn (): bool => $this->access->canEdit($userId, $problemId),
                false
            ),
            $this->optional(
                fn (): bool => $this->access->canManageTestData($userId, $problemId),
                false
            ),
            $this->optional(
                fn (): bool => $this->books->hasPersonal($userId, $problemId),
                null
            )
        );
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @param T $fallback
     * @return T
     */
    private function optional(callable $operation, mixed $fallback): mixed
    {
        try {
            return $operation();
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $fallback;
        }
    }
}
