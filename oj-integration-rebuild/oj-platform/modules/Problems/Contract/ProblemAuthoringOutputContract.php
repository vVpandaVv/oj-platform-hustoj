<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use DomainException;

final class ProblemAuthoringOutputContract
{
    /** @return array{json_schema:array<string,mixed>} */
    public static function forMode(string $mode): array
    {
        if (! in_array($mode, ['title_only', 'full_draft'], true)) {
            throw new DomainException('AI 문제 작성 방식이 올바르지 않습니다.');
        }
        $properties = [
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        ];
        if ($mode === 'full_draft') {
            $properties += [
                'description' => ['type' => 'string', 'minLength' => 1],
                'input' => ['type' => 'string',
                    'description' => '입력 형식 설명 문자열. 입력이 없는 문제는 빈 문자열도 허용합니다. 배열·객체·null이 아닙니다.'],
                'output' => ['type' => 'string', 'minLength' => 1],
                'sample_input' => ['type' => 'string',
                    'description' => '예시 입력의 공백과 줄바꿈을 보존한 문자열. 입력이 없으면 빈 문자열입니다.'],
                'sample_output' => ['type' => 'string', 'minLength' => 1,
                    'description' => '예시 출력의 공백과 줄바꿈을 보존한 문자열입니다.'],
                'hint' => ['type' => 'string',
                    'description' => '힌트 문자열. 힌트가 없으면 빈 문자열입니다.'],
            ];
        }

        return ['json_schema' => [
            'type' => 'object',
            'description' => '프로그래밍 문제 초안입니다. 모든 필드는 문자열이며 필수 키를 생략하지 마세요.',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ]];
    }
}
