<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourcePayload;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;
use DomainException;
use InvalidArgumentException;

final class SubmissionSourceModeRegistry
{
    /**
     * @var array<string, SubmissionSourceMode>
     */
    private array $modes = [];

    /**
     * @param list<SubmissionSourceMode> $modes
     */
    public function __construct(array $modes = [])
    {
        foreach ($modes as $mode) {
            $this->register($mode);
        }
    }

    public function register(SubmissionSourceMode $mode): void
    {
        $key = $mode->key();
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException('제출 소스 모드 키가 올바르지 않습니다.');
        }
        if (isset($this->modes[$key])) {
            throw new InvalidArgumentException('제출 소스 모드가 중복되었습니다: ' . $key);
        }
        $this->modes[$key] = $mode;
    }

    public function has(string $key): bool
    {
        return isset($this->modes[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->modes);
        sort($keys);

        return $keys;
    }

    public function build(
        string $key,
        SubmissionSourceRequest $request
    ): SubmissionSourcePayload {
        $mode = $this->modes[$key] ?? null;
        if (! $mode instanceof SubmissionSourceMode) {
            throw new DomainException('선택한 코드 작성 방식을 사용할 수 없습니다.');
        }

        return $mode->build($request);
    }
}
