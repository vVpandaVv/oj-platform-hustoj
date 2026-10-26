<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Error;

use CodingPanda\OjPlatform\Shared\Result\Result;
use Throwable;

final class ErrorBoundary
{
    public function __construct(
        private readonly ErrorReporter $reporter,
        private readonly FailurePresenter $presenter
    ) {
    }

    /**
     * @template TValue
     *
     * @param callable(): TValue $operation
     *
     * @return Result<TValue>
     */
    public function execute(callable $operation): Result
    {
        try {
            return Result::success($operation());
        } catch (Throwable $error) {
            $message = 'OJ Platform을 시작하지 못했습니다. 시스템 기록을 확인해 주세요.';
            $this->reporter->report($error);
            $this->presenter->present([$message]);

            return Result::failure($message);
        }
    }
}
