<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use InvalidArgumentException;

/** Bounded, local-only response schemas. Payload text never sets routing or credentials. */
final class AiStructuredOutput
{
    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    public static function fromInput(array $input): ?array
    {
        $contract = $input['operation_payload']['output_contract'] ?? $input['output_contract'] ?? null;
        if (! is_array($contract) || ! array_key_exists('json_schema', $contract)) {
            return null;
        }
        $schema = $contract['json_schema'];
        if (
            ! is_array($schema) || ($schema['type'] ?? null) !== 'object'
            || strlen(json_encode($schema, JSON_THROW_ON_ERROR)) > 65536
        ) {
            throw new InvalidArgumentException('AI 출력 스키마가 올바르지 않거나 너무 큽니다.');
        }
        $nodes = 0;
        self::inspect($schema, $schema, [], 0, $nodes);
        return $schema;
    }

    /** @param array<string,mixed> $root @param list<string> $references */
    private static function inspect(mixed $value, array $root, array $references, int $depth, int &$nodes): void
    {
        if (++$nodes > 20000 || $depth > 24) {
            throw new InvalidArgumentException('AI 출력 스키마의 크기 또는 참조 깊이를 초과했습니다.');
        }
        if (! is_array($value)) {
            return;
        }
        if (isset($value['$ref'])) {
            $ref = $value['$ref'];
            if (
                ! is_string($ref) || preg_match('~^#/\$defs/([A-Za-z][A-Za-z0-9_]{0,63})$~D', $ref, $match) !== 1
                || ! isset($root['$defs'][$match[1]]) || in_array($ref, $references, true)
            ) {
                throw new InvalidArgumentException('AI 출력 스키마는 순환 없는 내부 참조만 허용합니다.');
            }
            self::inspect($root['$defs'][$match[1]], $root, [...$references, $ref], $depth + 1, $nodes);
        }
        foreach ($value as $key => $child) {
            if (in_array($key, ['$id', '$schema', '$dynamicRef', '$recursiveRef'], true)) {
                throw new InvalidArgumentException('외부 AI 출력 스키마 참조는 허용하지 않습니다.');
            }
            self::inspect($child, $root, $references, $depth + 1, $nodes);
        }
    }
}
