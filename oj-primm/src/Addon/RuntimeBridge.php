<?php

declare(strict_types=1);

namespace OjPrimm\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use DomainException;

/** Receives only the runtime scoped to the accepted PRIMM addon. */
final class RuntimeBridge
{
    private static ?AddonRuntimeContextV1 $runtime = null;

    public static function connect(AddonRuntimeContextV1 $runtime): void
    {
        self::$runtime = $runtime->snapshot()->status() === 'accepted' ? $runtime : null;
    }

    public static function ordering(): object
    {
        if (!self::$runtime instanceof AddonCodeOrderingRuntimeContextV1) {
            throw new DomainException('코드 순서 배열 기능을 사용할 수 없습니다. OJ Platform을 확인해 주세요.');
        }
        return self::$runtime->codeOrdering();
    }

    public static function completion(): object
    {
        if (!self::$runtime instanceof AddonCodeCompletionRuntimeContextV1) {
            throw new DomainException('코드 빈칸 완성 기능을 사용할 수 없습니다. OJ Platform을 확인해 주세요.');
        }
        return self::$runtime->codeCompletion();
    }
}
