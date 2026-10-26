<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Application;

use CodingPanda\OjPlatform\Modules\Blockly\Domain\ValidatedBlocklyWorkspace;
use DomainException;
use JsonException;

final class BlocklyWorkspaceValidator
{
    private const MAX_BLOCKS = 500;

    private const MAX_DEPTH = 100;

    private int $blockCount = 0;

    private bool $usesInput = false;

    public function __construct(
        private readonly BlocklyProfileCatalog $profiles,
        private readonly BlocklySettingsProvider $settings
    ) {
    }

    public function validate(
        string $payloadJson,
        string $requestedProfile
    ): ValidatedBlocklyWorkspace {
        $settings = $this->settings->current();
        if (! $settings->isEnabled()) {
            throw new DomainException('Blockly 제출 기능을 사용할 수 없습니다.');
        }
        if (
            strlen($payloadJson) < 2
            || strlen($payloadJson) > $settings->maximumWorkspaceBytes()
            || str_contains($payloadJson, "\0")
        ) {
            throw new DomainException('Blockly 작업공간 크기가 올바르지 않습니다.');
        }
        try {
            $payload = json_decode(
                $payloadJson,
                true,
                128,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new DomainException(
                'Blockly 작업공간 JSON이 올바르지 않습니다.',
                0,
                $error
            );
        }
        if (! is_array($payload)) {
            throw new DomainException('Blockly 작업공간 형식이 올바르지 않습니다.');
        }
        $profile = strtolower(trim((string) ($payload['profile'] ?? '')));
        $this->profiles->assertProfile($profile);
        if (! hash_equals($profile, strtolower(trim($requestedProfile)))) {
            throw new DomainException(
                '선택한 Blockly 프로필과 작업공간 프로필이 다릅니다.'
            );
        }
        foreach ([
            'format' => BlocklyProfileCatalog::FORMAT,
            'engine_version' => BlocklyProfileCatalog::ENGINE_VERSION,
            'registry_version' => BlocklyProfileCatalog::REGISTRY_VERSION,
            'generator_version' => BlocklyProfileCatalog::GENERATOR_VERSION,
        ] as $key => $expected) {
            if (! hash_equals($expected, (string) ($payload[$key] ?? ''))) {
                throw new DomainException(
                    'Blockly ' . $key . ' 버전이 현재 서버와 다릅니다.'
                );
            }
        }
        $workspace = $payload['workspace'] ?? null;
        if (! is_array($workspace)) {
            throw new DomainException('Blockly 작업공간 본문이 없습니다.');
        }
        $allowed = array_fill_keys(
            $this->profiles->allowedTypes($profile),
            true
        );
        $this->blockCount = 0;
        $this->usesInput = false;
        $sanitized = $this->sanitizeWorkspace($workspace, $allowed);
        $topBlocks = $sanitized['blocks']['blocks'] ?? [];
        $startCount = 0;
        foreach ($topBlocks as $topBlock) {
            $type = (string) ($topBlock['type'] ?? '');
            if ($type === 'oj_start') {
                ++$startCount;
                continue;
            }
            if (! str_starts_with($type, 'procedures_def')) {
                throw new DomainException(
                    '연결되지 않은 Blockly 블록이 있어 제출할 수 없습니다.'
                );
            }
        }
        if ($startCount !== 1) {
            throw new DomainException(
                '삭제할 수 없는 프로그램 시작 블록이 하나 필요합니다.'
            );
        }
        if ($this->blockCount > self::MAX_BLOCKS) {
            throw new DomainException(
                'Blockly 작업공간은 블록 ' . self::MAX_BLOCKS
                . '개까지 사용할 수 있습니다.'
            );
        }
        $canonicalPayload = [
            'engine_version' => BlocklyProfileCatalog::ENGINE_VERSION,
            'format' => BlocklyProfileCatalog::FORMAT,
            'generator_version' => BlocklyProfileCatalog::GENERATOR_VERSION,
            'profile' => $profile,
            'registry_version' => BlocklyProfileCatalog::REGISTRY_VERSION,
            'workspace' => $sanitized,
        ];
        $canonical = json_encode(
            $this->sortObject($canonicalPayload),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        return new ValidatedBlocklyWorkspace(
            $profile,
            $sanitized,
            $canonical,
            $this->blockCount,
            $this->usesInput
        );
    }

    /**
     * @param array<string, mixed> $workspace
     * @param array<string, true> $allowed
     * @return array<string, mixed>
     */
    private function sanitizeWorkspace(
        array $workspace,
        array $allowed
    ): array {
        $top = $workspace['blocks']['blocks'] ?? null;
        if (! is_array($top) || ! array_is_list($top)) {
            throw new DomainException('Blockly 최상위 블록 목록이 올바르지 않습니다.');
        }
        $blocks = [];
        foreach ($top as $block) {
            if (! is_array($block)) {
                throw new DomainException('Blockly 블록 데이터가 올바르지 않습니다.');
            }
            $blocks[] = $this->sanitizeBlock($block, $allowed, 0, true);
        }
        $sanitized = ['blocks' => ['blocks' => $blocks]];
        $variables = $workspace['variables'] ?? [];
        if (! is_array($variables) || ! array_is_list($variables)) {
            throw new DomainException('Blockly 변수 목록이 올바르지 않습니다.');
        }
        if ($variables !== []) {
            $sanitized['variables'] = array_map(
                fn (mixed $variable): array => $this->sanitizeVariable(
                    $variable
                ),
                $variables
            );
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, true> $allowed
     * @return array<string, mixed>
     */
    private function sanitizeBlock(
        array $block,
        array $allowed,
        int $depth,
        bool $topLevel = false
    ): array {
        if ($depth > self::MAX_DEPTH) {
            throw new DomainException('Blockly 블록 연결 깊이가 너무 큽니다.');
        }
        ++$this->blockCount;
        if ($this->blockCount > self::MAX_BLOCKS) {
            throw new DomainException('Blockly 블록 수가 최대값을 초과했습니다.');
        }
        $type = (string) ($block['type'] ?? '');
        if (! isset($allowed[$type])) {
            throw new DomainException(
                '현재 프로필에서 사용할 수 없는 Blockly 블록입니다: '
                . ($type === '' ? '알 수 없음' : $type)
            );
        }
        if (in_array(
            $type,
            ['oj_input_int', 'oj_input_float', 'oj_input_token', 'oj_input_line'],
            true
        )) {
            $this->usesInput = true;
        }
        if (($block['disabled'] ?? false) === true) {
            throw new DomainException('비활성화된 Blockly 블록을 제출할 수 없습니다.');
        }
        $sanitized = ['type' => $type];
        foreach (['id', 'x', 'y', 'collapsed', 'inline'] as $key) {
            if (! array_key_exists($key, $block)) {
                continue;
            }
            $value = $block[$key];
            if ($key === 'id') {
                $sanitized[$key] = $this->shortString($value, 128);
            } elseif (in_array($key, ['x', 'y'], true)) {
                $sanitized[$key] = is_numeric($value) ? (int) $value : 0;
            } else {
                $sanitized[$key] = (bool) $value;
            }
        }
        if (isset($block['fields'])) {
            if (! is_array($block['fields'])) {
                throw new DomainException('Blockly 필드가 올바르지 않습니다.');
            }
            $fields = [];
            foreach ($block['fields'] as $name => $value) {
                if (preg_match('/^[A-Z0-9_]{1,64}$/', (string) $name) !== 1) {
                    throw new DomainException('Blockly 필드 이름이 올바르지 않습니다.');
                }
                $fields[(string) $name] = $this->sanitizeField($value);
            }
            $sanitized['fields'] = $fields;
        }
        if (isset($block['extraState'])) {
            $sanitized['extraState'] = $this->sanitizeExtraState(
                $block['extraState'],
                0
            );
        }
        if (isset($block['inputs'])) {
            if (! is_array($block['inputs'])) {
                throw new DomainException('Blockly 입력 연결이 올바르지 않습니다.');
            }
            $inputs = [];
            foreach ($block['inputs'] as $name => $input) {
                if (
                    preg_match('/^[A-Z0-9_]{1,64}$/', (string) $name) !== 1
                    || ! is_array($input)
                ) {
                    throw new DomainException('Blockly 입력 이름이 올바르지 않습니다.');
                }
                $inputs[(string) $name] = $this->sanitizeInput(
                    $input,
                    $allowed,
                    $depth + 1
                );
            }
            $sanitized['inputs'] = $inputs;
        }
        if (isset($block['next'])) {
            $next = $block['next'];
            if (
                ! is_array($next)
                || ! is_array($next['block'] ?? null)
            ) {
                throw new DomainException('Blockly 다음 블록 연결이 올바르지 않습니다.');
            }
            $sanitized['next'] = [
                'block' => $this->sanitizeBlock(
                    $next['block'],
                    $allowed,
                    $depth + 1
                ),
            ];
        }
        if ($topLevel && isset($block['next'])) {
            throw new DomainException(
                '최상위 Blockly 블록은 다음 블록으로 직접 연결할 수 없습니다.'
            );
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, true> $allowed
     * @return array<string, mixed>
     */
    private function sanitizeInput(
        array $input,
        array $allowed,
        int $depth
    ): array {
        $sanitized = [];
        foreach (['block', 'shadow'] as $key) {
            if (! isset($input[$key])) {
                continue;
            }
            if (! is_array($input[$key])) {
                throw new DomainException('Blockly 입력 블록이 올바르지 않습니다.');
            }
            $sanitized[$key] = $this->sanitizeBlock(
                $input[$key],
                $allowed,
                $depth
            );
        }
        if ($sanitized === []) {
            throw new DomainException('비어 있는 Blockly 입력 연결이 있습니다.');
        }

        return $sanitized;
    }

    private function sanitizeField(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return is_string($value) ? $this->shortString($value, 8192) : $value;
        }
        if (! is_array($value)) {
            throw new DomainException('Blockly 필드 값이 올바르지 않습니다.');
        }
        $field = [];
        foreach (['id', 'name', 'value'] as $key) {
            if (array_key_exists($key, $value)) {
                $field[$key] = $this->shortString($value[$key], 8192);
            }
        }
        if ($field === []) {
            throw new DomainException('Blockly 필드 객체가 올바르지 않습니다.');
        }

        return $field;
    }

    private function sanitizeExtraState(mixed $value, int $depth): mixed
    {
        if ($depth > 10) {
            throw new DomainException('Blockly 추가 상태 깊이가 너무 큽니다.');
        }
        if (is_string($value)) {
            return $this->shortString($value, 2048);
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (! is_array($value)) {
            throw new DomainException('Blockly 추가 상태가 올바르지 않습니다.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_int($key) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', (string) $key) !== 1) {
                throw new DomainException('Blockly 추가 상태 키가 올바르지 않습니다.');
            }
            $result[$key] = $this->sanitizeExtraState($item, $depth + 1);
        }

        return $result;
    }

    /**
     * @return array{name: string, id: string, type?: string}
     */
    private function sanitizeVariable(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('Blockly 변수 정보가 올바르지 않습니다.');
        }
        $variable = [
            'name' => $this->shortString($value['name'] ?? '', 128),
            'id' => $this->shortString($value['id'] ?? '', 128),
        ];
        if ($variable['name'] === '' || $variable['id'] === '') {
            throw new DomainException('Blockly 변수 이름과 ID가 필요합니다.');
        }
        if (isset($value['type'])) {
            $variable['type'] = $this->shortString($value['type'], 64);
        }

        return $variable;
    }

    private function shortString(mixed $value, int $maximum): string
    {
        if (! is_scalar($value)) {
            throw new DomainException('Blockly 문자열 값이 올바르지 않습니다.');
        }
        $value = (string) $value;
        if (strlen($value) > $maximum || str_contains($value, "\0")) {
            throw new DomainException('Blockly 문자열 값이 너무 깁니다.');
        }

        return $value;
    }

    private function sortObject(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'sortObject'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortObject($item);
        }

        return $value;
    }
}
