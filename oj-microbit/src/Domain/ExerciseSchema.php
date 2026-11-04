<?php

declare(strict_types=1);

namespace OjMicrobit\Domain;

use InvalidArgumentException;

final class ExerciseSchema
{
    public const PINS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 19, 20];
    public const ANALOG_INPUT_PINS = [0, 1, 2, 3, 4, 10];
    public const MAX_SCENARIOS = 20;
    public const MAX_DURATION_MS = 30000;
    public const MAX_TOTAL_DURATION_MS = 120000;

    public static function normalize(array $exercise): array
    {
        $status = SchemaValue::oneOf($exercise['status'] ?? 'draft', ['draft', 'published'], 'exercise.status');
        $connections = self::connections($exercise['connections'] ?? []);
        $i2cDevices = I2cSchema::devices(array_key_exists('i2cDevices', $exercise) ? $exercise['i2cDevices'] : [], $status === 'published');
        $byPin = [];
        foreach ($connections as $connection) {
            if ($i2cDevices !== [] && in_array($connection['pin'], [19, 20], true)) { throw new InvalidArgumentException('exercise.connections: I2C를 사용할 때 P19·P20은 버스용으로 비워 두어야 합니다.'); }
            $byPin[$connection['pin']] = $connection;
        }
        $scenarios = SchemaValue::list($exercise['scenarios'] ?? [], self::MAX_SCENARIOS, 'exercise.scenarios');
        $normalized = [];
        $ids = [];
        $totalDuration = 0;
        foreach ($scenarios as $index => $scenario) {
            $path = 'exercise.scenarios.' . $index;
            $item = SchemaValue::object($scenario, $path);
            $id = SchemaValue::string($item['id'] ?? null, 48, $path . '.id');
            if (preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/D', $id) !== 1 || isset($ids[$id])) {
                throw new InvalidArgumentException($path . '.id: 고유한 영문 식별자가 필요합니다.');
            }
            $ids[$id] = true;
            $duration = SchemaValue::integer($item['durationMs'] ?? null, 1, self::MAX_DURATION_MS, $path . '.durationMs');
            $totalDuration += $duration;
            if ($totalDuration > self::MAX_TOTAL_DURATION_MS) {
                throw new InvalidArgumentException('exercise.scenarios: 전체 실행 시간은 120초 이하여야 합니다.');
            }
            $inputs = self::inputs($item['inputs'] ?? [], $byPin, $duration, $path . '.inputs');
            $assertions = self::assertions($item['assertions'] ?? [], $byPin, $duration, $path . '.assertions');
            $i2cInputs = I2cSchema::inputs(array_key_exists('i2cInputs', $item) ? $item['i2cInputs'] : [], $i2cDevices, $duration, $path . '.i2cInputs');
            $i2cAssertions = I2cSchema::assertions(array_key_exists('i2cAssertions', $item) ? $item['i2cAssertions'] : [], $i2cDevices, $duration, $path . '.i2cAssertions');
            $outputSequences = OutputSequenceSchema::normalize(array_key_exists('outputSequences', $item) ? $item['outputSequences'] : [], $byPin, $duration, $path . '.outputSequences');
            if ($status === 'published') {
                if ($assertions === [] && $i2cAssertions === [] && $outputSequences === []) {
                    throw new InvalidArgumentException($path . ': 공개 시나리오에는 기대 출력이 필요합니다.');
                }
                foreach ($connections as $connection) {
                    if ($connection['direction'] === 'input' && !self::hasInitialInput($inputs, $connection['pin'])) {
                        throw new InvalidArgumentException($path . ': 모든 입력 핀에 0ms 초기값이 필요합니다.');
                    }
                }
            }
            $normalized[] = [
                'id' => $id,
                'name' => SchemaValue::string($item['name'] ?? null, 160, $path . '.name'),
                'visible' => SchemaValue::boolean($item['visible'] ?? true, $path . '.visible'),
                'durationMs' => $duration,
                'inputs' => $inputs,
                'assertions' => $assertions,
                'i2cInputs' => $i2cInputs,
                'i2cAssertions' => $i2cAssertions,
                'outputSequences' => $outputSequences,
            ];
        }
        if ($status === 'published' && $normalized === []) {
            throw new InvalidArgumentException('exercise.scenarios: 공개 과제에는 시나리오가 필요합니다.');
        }
        return [
            'title' => SchemaValue::string($exercise['title'] ?? null, 240, 'exercise.title'),
            'description' => SchemaValue::string($exercise['description'] ?? '', 32000, 'exercise.description', true),
            'status' => $status,
            'audience' => self::audience(array_key_exists('audience', $exercise) ? $exercise['audience'] : ['mode' => 'site', 'classIds' => []]),
            'connections' => $connections,
            'i2cDevices' => $i2cDevices,
            'scenarios' => $normalized,
            'starterProject' => ProjectSchema::normalize(SchemaValue::object($exercise['starterProject'] ?? null, 'exercise.starterProject')),
        ];
    }

    public static function audience(mixed $value): array
    {
        $item = SchemaValue::object($value, 'exercise.audience');
        $mode = SchemaValue::oneOf($item['mode'] ?? null, ['site', 'groups', 'classes'], 'exercise.audience.mode');
        $key = $mode === 'groups' ? 'groupIds' : 'classIds';
        $other = $mode === 'groups' ? 'classIds' : 'groupIds';
        $label = $mode === 'groups' ? '그룹(학급)' : '수업';
        if (array_key_exists($other, $item) && $item[$other] !== []) {
            throw new InvalidArgumentException('exercise.audience.' . $other . ': 그룹과 수업의 대상 번호를 혼용할 수 없습니다.');
        }
        $ids = SchemaValue::list($item[$key] ?? ($mode === 'site' ? [] : null), 50, 'exercise.audience.' . $key);
        $normalized = [];
        foreach ($ids as $index => $id) {
            $id = SchemaValue::integer($id, 1, PHP_INT_MAX, 'exercise.audience.' . $key . '.' . $index);
            if (isset($normalized[$id])) { throw new InvalidArgumentException('exercise.audience.' . $key . ': ' . $label . '을 중복 선택할 수 없습니다.'); }
            $normalized[$id] = $id;
        }
        if (($mode === 'site' && $normalized !== []) || ($mode !== 'site' && $normalized === [])) {
            throw new InvalidArgumentException('exercise.audience.' . $key . ': 제한 배포에는 대상을 선택하고 전체 배포에는 대상을 지정하지 마세요.');
        }
        sort($normalized, SORT_NUMERIC);
        return ['mode' => $mode, $key => array_values($normalized)];
    }

    private static function connections(mixed $value): array
    {
        $items = SchemaValue::list($value, count(self::PINS), 'exercise.connections');
        $result = [];
        $seen = [];
        foreach ($items as $index => $item) {
            $path = 'exercise.connections.' . $index;
            $connection = SchemaValue::object($item, $path);
            $pin = self::pin($connection['pin'] ?? null, $path . '.pin');
            if (isset($seen[$pin])) {
                throw new InvalidArgumentException($path . ': 한 핀에 연결을 중복 설정할 수 없습니다.');
            }
            $seen[$pin] = true;
            $direction = SchemaValue::oneOf($connection['direction'] ?? null, ['input', 'output'], $path . '.direction');
            $signal = SchemaValue::oneOf($connection['signal'] ?? null, $direction === 'input' ? ['analog', 'digital'] : SignalSchema::OUTPUT_SIGNALS, $path . '.signal');
            if ($signal === 'analog' && !in_array($pin, self::ANALOG_INPUT_PINS, true)) {
                throw new InvalidArgumentException($path . ': 아날로그 입력은 P0~P4, P10만 사용할 수 있습니다.');
            }
            $device = SchemaValue::oneOf($connection['device'] ?? null, ['light_sensor', 'potentiometer', 'button', 'led', 'motor', 'servo'], $path . '.device');
            $compatible = match ($device) {
                'light_sensor', 'potentiometer' => $direction === 'input' && $signal === 'analog',
                'button' => $direction === 'input' && $signal === 'digital',
                'led', 'motor' => $direction === 'output' && in_array($signal, ['digital', 'pwm'], true),
                'servo' => $direction === 'output' && in_array($signal, ['servo', 'servo_pulse'], true),
            };
            if (!$compatible) {
                throw new InvalidArgumentException($path . ': 장치와 핀 방향 또는 신호 종류가 맞지 않습니다.');
            }
            $result[] = [
                'pin' => $pin, 'direction' => $direction, 'signal' => $signal, 'device' => $device,
                'label' => SchemaValue::string($connection['label'] ?? ('P' . $pin), 160, $path . '.label'),
            ];
        }
        usort($result, static fn (array $left, array $right): int => $left['pin'] <=> $right['pin']);
        return $result;
    }

    private static function inputs(mixed $value, array $connections, int $duration, string $path): array
    {
        $items = SchemaValue::list($value, 256, $path);
        $result = [];
        $seen = [];
        foreach ($items as $index => $item) {
            $at = $path . '.' . $index;
            $input = SchemaValue::object($item, $at);
            $pin = self::pin($input['pin'] ?? null, $at . '.pin');
            $signal = SchemaValue::oneOf($input['signal'] ?? null, ['analog', 'digital'], $at . '.signal');
            self::connection($connections, $pin, 'input', $signal, $at);
            $time = SchemaValue::integer($input['atMs'] ?? null, 0, $duration - 1, $at . '.atMs');
            $key = $pin . ':' . $time;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException($at . ': 같은 시각에 같은 입력 핀을 중복 설정할 수 없습니다.');
            }
            $seen[$key] = true;
            $result[] = [
                'atMs' => $time, 'pin' => $pin, 'signal' => $signal,
                'value' => SchemaValue::integer($input['value'] ?? null, 0, $signal === 'digital' ? 1 : 1023, $at . '.value'),
            ];
        }
        usort($result, static fn (array $left, array $right): int => [$left['atMs'], $left['pin']] <=> [$right['atMs'], $right['pin']]);
        return $result;
    }

    private static function assertions(mixed $value, array $connections, int $duration, string $path): array
    {
        $items = SchemaValue::list($value, 256, $path);
        $result = [];
        $seen = [];
        foreach ($items as $index => $item) {
            $at = $path . '.' . $index;
            $assertion = SchemaValue::object($item, $at);
            $pin = self::pin($assertion['pin'] ?? null, $at . '.pin');
            $signal = SchemaValue::oneOf($assertion['signal'] ?? null, SignalSchema::OUTPUT_SIGNALS, $at . '.signal');
            self::connection($connections, $pin, 'output', $signal, $at);
            $after = SchemaValue::integer($assertion['afterMs'] ?? null, 0, $duration - 1, $at . '.afterMs');
            $within = SchemaValue::integer($assertion['withinMs'] ?? null, 1, $duration, $at . '.withinMs');
            $hold = SchemaValue::integer($assertion['holdMs'] ?? null, 1, $duration, $at . '.holdMs');
            if ($after + $within + $hold > $duration) {
                throw new InvalidArgumentException($at . ': 응답 시간과 유지 시간이 시나리오 종료를 넘습니다.');
            }
            $key = $pin . ':' . $after;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException($at . ': 같은 시각에 같은 출력 핀을 중복 검사할 수 없습니다.');
            }
            $seen[$key] = true;
            [$minimum, $maximum] = SignalSchema::range($signal);
            $expected = SchemaValue::integer($assertion['expected'] ?? null, $minimum, $maximum, $at . '.expected');
            $tolerance = SchemaValue::integer($assertion['tolerance'] ?? null, 0, $signal === 'digital' ? 0 : $maximum, $at . '.tolerance');
            if ($signal !== 'digital' && $expected - $tolerance <= $minimum && $expected + $tolerance >= $maximum) {
                throw new InvalidArgumentException($at . ': 허용 오차가 전체 출력 범위를 포함할 수 없습니다.');
            }
            $result[] = [
                'pin' => $pin, 'signal' => $signal, 'expected' => $expected,
                'afterMs' => $after, 'withinMs' => $within, 'holdMs' => $hold, 'tolerance' => $tolerance,
            ];
        }
        usort($result, static fn (array $left, array $right): int => [$left['afterMs'], $left['pin']] <=> [$right['afterMs'], $right['pin']]);
        return $result;
    }

    private static function pin(mixed $value, string $path): int
    {
        $pin = SchemaValue::integer($value, 0, 20, $path);
        if (!in_array($pin, self::PINS, true)) {
            throw new InvalidArgumentException($path . ': GPIO 핀 번호가 올바르지 않습니다.');
        }
        return $pin;
    }

    private static function connection(array $connections, int $pin, string $direction, string $signal, string $path): void
    {
        $connection = $connections[$pin] ?? null;
        if ($connection === null || $connection['direction'] !== $direction || $connection['signal'] !== $signal) {
            throw new InvalidArgumentException($path . ': 선언된 핀 방향과 신호 종류에 맞지 않습니다.');
        }
    }

    private static function hasInitialInput(array $inputs, int $pin): bool
    {
        foreach ($inputs as $input) {
            if ($input['pin'] === $pin && $input['atMs'] === 0) {
                return true;
            }
        }
        return false;
    }
}
