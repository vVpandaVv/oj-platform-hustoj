<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

final class ProblemMetadataOutputContract
{
    /** @param array{version:string,topics:list<array<string,mixed>>} $dictionary
     * @return array{json_schema:array<string,mixed>}
     */
    public static function fromDictionary(array $dictionary, bool $retry = false): array
    {
        $keys = [];
        foreach ($dictionary['topics'] as $topic) {
            if (($topic['selectable'] ?? false) === true && is_string($topic['key'] ?? null)) {
                $keys[] = $topic['key'];
            }
        }
        return ['json_schema' => [
            'type' => 'object',
            'description' => $retry
                ? '메타데이터 재생성 요청입니다. 네 필수 필드를 모두 포함한 완전한 JSON 객체를 새로 작성하세요. confidence는 반드시 문제 분석에 근거한 0~1 숫자로 작성하고 생략하거나 임의의 기본값으로 채우지 마세요.'
                : '문제 메타데이터입니다. difficulty_score, topic_keys, confidence, rationale 네 필드는 모두 필수입니다.',
            'additionalProperties' => false,
            'required' => ['difficulty_score', 'topic_keys', 'confidence', 'rationale'],
            'properties' => [
                'difficulty_score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'topic_keys' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => min(3, count($keys)),
                    'uniqueItems' => true,
                    'items' => $keys === [] ? ['type' => 'string'] : ['type' => 'string', 'enum' => $keys],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'rationale' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 500,
                    'description' => '현재 input.problem의 실제 요구사항 요약과 본문·입출력에 근거한 난이도·주제 판단 이유. 한국어 500자 이내. 본문에 없는 내용을 만들지 말고 정보 부족이나 모순은 명시하세요.',
                ],
            ],
        ]];
    }
}
