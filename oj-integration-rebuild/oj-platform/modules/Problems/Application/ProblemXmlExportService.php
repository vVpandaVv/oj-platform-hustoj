<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;

final class ProblemXmlExportService
{
    public function __construct(
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemNumberChangeLock $numberChangeLock,
        private readonly ProblemXmlMaterializer $materializer,
        private readonly ProblemXmlCodec $codec
    ) {
    }

    public function export(
        int $actorUserId,
        string $problemSelection,
        bool $includeMetadata,
        bool $includeTestData
    ): string {
        if (! $this->access->canManageXml($actorUserId)) {
            throw new DomainException('문제 XML을 관리할 권한이 없습니다.');
        }
        if ($this->numberChangeLock->hasActiveChange()) {
            throw new DomainException(
                '문제 번호 변경 중에는 XML 내보내기를 실행할 수 없습니다.'
            );
        }
        $problemIds = $this->parseSelection($problemSelection);

        return $this->numberChangeLock->withExclusiveLock(
            array_map(
                static fn (ProblemId $problemId): int => $problemId->value(),
                $problemIds
            ),
            function () use (
                $problemIds,
                $includeMetadata,
                $includeTestData
            ): string {
                $items = array_map(
                    fn (ProblemId $problemId) =>
                        $this->materializer->exportItem(
                            $problemId,
                            $includeTestData
                        ),
                    $problemIds
                );

                return $this->codec->encode(
                    $items,
                    $includeMetadata,
                    $includeTestData
                );
            }
        );
    }

    /**
     * @return list<ProblemId>
     */
    public function parseSelection(string $value): array
    {
        $tokens = preg_split('/[\s,]+/', trim($value)) ?: [];
        if ($tokens === [] || $tokens === ['']) {
            throw new DomainException('내보낼 문제 번호를 입력해 주세요.');
        }
        $values = [];
        foreach ($tokens as $token) {
            if (preg_match('/^([1-9]\d*)-([1-9]\d*)$/', $token, $matches) === 1) {
                $start = $this->problemIdValue($matches[1]);
                $end = $this->problemIdValue($matches[2]);
                if ($end < $start) {
                    throw new DomainException(
                        '문제 번호 범위의 끝은 시작보다 작을 수 없습니다.'
                    );
                }
                if (($end - $start + 1) > 200) {
                    throw new DomainException(
                        '한 번에 내보낼 수 있는 문제는 최대 200개입니다.'
                    );
                }
                for ($problemId = $start; $problemId <= $end; ++$problemId) {
                    $values[$problemId] = true;
                    if (count($values) > 200) {
                        throw new DomainException(
                            '한 번에 내보낼 수 있는 문제는 최대 200개입니다.'
                        );
                    }
                }
                continue;
            }
            if (preg_match('/^[1-9]\d*$/', $token) !== 1) {
                throw new DomainException(
                    '문제 번호는 쉼표·공백 또는 시작-끝 범위로 입력해 주세요.'
                );
            }
            $values[$this->problemIdValue($token)] = true;
            if (count($values) > 200) {
                throw new DomainException(
                    '한 번에 내보낼 수 있는 문제는 최대 200개입니다.'
                );
            }
        }

        return array_map(
            static fn (int $problemId): ProblemId => new ProblemId($problemId),
            array_keys($values)
        );
    }

    private function problemIdValue(string $value): int
    {
        $value = ltrim($value, '0');
        if (
            $value === ''
            || strlen($value) > 10
            || (strlen($value) === 10 && strcmp($value, '2147483647') > 0)
        ) {
            throw new DomainException(
                '문제 번호는 1부터 2147483647까지 입력할 수 있습니다.'
            );
        }

        return (int) $value;
    }
}
