<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Application;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResult;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;

final class JudgeResultCatalog implements JudgeResults
{
    /**
     * @var array<int, array{string, string, string, bool}>
     */
    private const DEFINITIONS = [
        0 => ['OJ_WT0', '채점 대기', 'info', true],
        1 => ['OJ_WT1', '재채점 대기', 'info', true],
        2 => ['OJ_CI', '컴파일 중', 'info', true],
        3 => ['OJ_RI', '채점 중', 'info', true],
        4 => ['OJ_AC', '맞았습니다', 'success', false],
        5 => ['OJ_PE', '출력 형식 오류', 'warning', false],
        6 => ['OJ_WA', '틀렸습니다', 'danger', false],
        7 => ['OJ_TL', '시간 초과', 'danger', false],
        8 => ['OJ_ML', '메모리 초과', 'danger', false],
        9 => ['OJ_OL', '출력 초과', 'danger', false],
        10 => ['OJ_RE', '런타임 오류', 'danger', false],
        11 => ['OJ_CE', '컴파일 오류', 'danger', false],
        12 => ['OJ_CO', '컴파일 완료', 'neutral', false],
        13 => ['OJ_TR', '테스트 실행 완료', 'neutral', false],
        14 => ['OJ_MC', '수동 확인 대기', 'warning', true],
        15 => ['SUBMITTING', '제출 중', 'info', true],
        16 => ['REMOTE_PENDING', '원격 제출 중', 'info', true],
        17 => ['REMOTE_JUDGING', '원격 채점 중', 'info', true],
    ];

    public function find(int $code): JudgeResult
    {
        $definition = self::DEFINITIONS[$code] ?? null;
        if ($definition === null) {
            return new JudgeResult(
                $code,
                'UNKNOWN',
                '알 수 없는 채점 결과 (' . $code . ')',
                'neutral',
                false
            );
        }

        return new JudgeResult(
            $code,
            $definition[0],
            $definition[1],
            $definition[2],
            $definition[3]
        );
    }

    /**
     * @return list<JudgeResult>
     */
    public function all(): array
    {
        return array_map(
            fn (int $code): JudgeResult => $this->find($code),
            array_keys(self::DEFINITIONS)
        );
    }
}
