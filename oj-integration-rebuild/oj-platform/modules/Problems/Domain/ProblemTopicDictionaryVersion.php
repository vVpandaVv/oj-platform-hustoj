<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;
use JsonException;
use LogicException;

final class ProblemTopicDictionaryVersion
{
    public const MAX_BYTES = 50;

    private const PREFIX = 'td-v1:';

    /**
     * @param list<ProblemTopic> $topics
     */
    public static function fromTopics(array $topics): string
    {
        $canonical = [];
        foreach ($topics as $topic) {
            if (! $topic instanceof ProblemTopic) {
                throw new InvalidArgumentException(
                    '문제 주제 사전 버전 입력이 올바르지 않습니다.'
                );
            }
            $aliases = $topic->aliases();
            sort($aliases, SORT_STRING);
            $canonical[] = [
                'key' => $topic->key(),
                'label' => $topic->label(),
                'category' => $topic->categoryKey(),
                'aliases' => $aliases,
                'active' => $topic->isActive(),
            ];
        }
        usort(
            $canonical,
            static fn (array $left, array $right): int =>
                strcmp((string) $left['key'], (string) $right['key'])
        );

        try {
            $json = json_encode(
                $canonical,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                '문제 주제 사전 버전을 만들 수 없습니다.',
                0,
                $error
            );
        }
        $fingerprint = rtrim(
            strtr(base64_encode(hash('sha256', $json, true)), '+/', '-_'),
            '='
        );
        $version = self::PREFIX . $fingerprint;
        if (strlen($version) > self::MAX_BYTES) {
            throw new LogicException(
                '문제 주제 사전 버전이 저장 길이를 초과했습니다.'
            );
        }

        return $version;
    }

    private function __construct()
    {
    }
}
