<?php
declare(strict_types=1);
namespace OjMicrobit\Domain;

use InvalidArgumentException;

/** Ordered output spans, each starting when the preceding span completes. */
final class OutputSequenceSchema
{
    public static function normalize(mixed $value, array $connections, int $duration, string $path): array
    {
        $result = []; $stepCount = 0;
        foreach (SchemaValue::list($value, 64, $path) as $index => $item) {
            $at = $path . '.' . $index; $item = SchemaValue::object($item, $at);
            $pin = SchemaValue::integer($item['pin'] ?? null, 0, 20, $at . '.pin');
            $signal = SchemaValue::oneOf($item['signal'] ?? null, SignalSchema::OUTPUT_SIGNALS, $at . '.signal');
            $connection = $connections[$pin] ?? null;
            if (!in_array($pin, ExerciseSchema::PINS, true) || $connection === null || $connection['direction'] !== 'output' || $connection['signal'] !== $signal) {
                throw new InvalidArgumentException($at . ': 동작 순서는 선언된 출력 핀과 신호 종류에 맞아야 합니다.');
            }
            $after = SchemaValue::integer($item['afterMs'] ?? null, 0, $duration - 1, $at . '.afterMs');
            $incoming = SchemaValue::list($item['steps'] ?? null, 20, $at . '.steps');
            if ($incoming === []) { throw new InvalidArgumentException($at . ': 동작 순서에는 단계가 필요합니다.'); }
            $stepCount += count($incoming);
            if ($stepCount > 256) { throw new InvalidArgumentException($path . ': 시나리오의 동작 순서 단계는 전체 256개 이하여야 합니다.'); }
            $steps = []; $end = $after;
            foreach ($incoming as $stepIndex => $step) {
                $stepAt = $at . '.steps.' . $stepIndex; $step = SchemaValue::object($step, $stepAt);
                [$minimum, $maximum] = SignalSchema::range($signal);
                $expected = SchemaValue::integer($step['expected'] ?? null, $minimum, $maximum, $stepAt . '.expected');
                $within = SchemaValue::integer($step['withinMs'] ?? null, 1, $duration, $stepAt . '.withinMs');
                $hold = SchemaValue::integer($step['holdMs'] ?? null, 1, $duration, $stepAt . '.holdMs');
                $tolerance = SchemaValue::integer($step['tolerance'] ?? null, 0, $signal === 'digital' ? 0 : $maximum, $stepAt . '.tolerance');
                if ($signal !== 'digital' && $expected - $tolerance <= $minimum && $expected + $tolerance >= $maximum) {
                    throw new InvalidArgumentException($stepAt . ': 허용 오차가 전체 출력 범위를 포함할 수 없습니다.');
                }
                $end += $within + $hold;
                if ($end > $duration) { throw new InvalidArgumentException($stepAt . ': 순서의 응답 시간과 유지 시간이 시나리오 종료를 넘습니다.'); }
                $steps[] = ['expected' => $expected, 'withinMs' => $within, 'holdMs' => $hold, 'tolerance' => $tolerance];
            }
            $result[] = ['pin' => $pin, 'signal' => $signal, 'afterMs' => $after, 'steps' => $steps];
        }
        return $result;
    }

    public static function result(mixed $value, array $expected, int $duration, bool $runtimeFailed): array
    {
        $incoming = SchemaValue::list($value, 64, 'scenario.outputSequences');
        if (count($incoming) !== count($expected)) { throw new InvalidArgumentException('Incomplete output sequence results.'); }
        $result = [];
        foreach ($incoming as $index => $item) {
            $item = SchemaValue::object($item, 'outputSequence'); $contract = $expected[$index];
            foreach (['pin', 'signal', 'afterMs'] as $key) {
                if (!array_key_exists($key, $item) || $item[$key] !== $contract[$key]) { throw new InvalidArgumentException('Mismatched output sequence.'); }
            }
            $incomingSteps = SchemaValue::list($item['steps'] ?? null, 20, 'outputSequence.steps');
            if (count($incomingSteps) !== count($contract['steps'])) { throw new InvalidArgumentException('Incomplete output sequence steps.'); }
            $steps = []; $active = !$runtimeFailed; $cursor = $contract['afterMs']; $allPassed = true;
            foreach ($incomingSteps as $stepIndex => $actual) {
                $actual = SchemaValue::object($actual, 'outputSequence.step'); $step = $contract['steps'][$stepIndex];
                foreach (['expected', 'withinMs', 'holdMs', 'tolerance'] as $key) {
                    if (!array_key_exists($key, $actual) || $actual[$key] !== $step[$key]) { throw new InvalidArgumentException('Mismatched output sequence step.'); }
                }
                foreach (['matchedAtMs', 'completedAtMs', 'observed'] as $key) {
                    if (!array_key_exists($key, $actual)) { throw new InvalidArgumentException('Incomplete output sequence observation.'); }
                }
                $passed = SchemaValue::boolean($actual['passed'] ?? null, 'outputSequence.step.passed');
                $matched = $actual['matchedAtMs']; $completed = $actual['completedAtMs'];
                $observed = $actual['observed'] === null ? null : self::observed($actual['observed'], $duration);
                if (!$active) {
                    if ($passed || $matched !== null || $completed !== null || $observed !== null) { throw new InvalidArgumentException('Skipped output steps cannot claim observations or points.'); }
                } elseif ($passed) {
                    $matched = SchemaValue::integer($matched, $cursor, min($cursor + $step['withinMs'], $duration), 'outputSequence.step.matchedAtMs');
                    $completed = SchemaValue::integer($completed, 0, $duration, 'outputSequence.step.completedAtMs');
                    if ($completed !== $matched + $step['holdMs'] || $observed === null || $observed['atMs'] > $matched
                        || $observed['signal'] !== $contract['signal'] || abs($observed['value'] - $step['expected']) > $step['tolerance']) {
                        throw new InvalidArgumentException('Inconsistent output sequence hold or observation.');
                    }
                    $cursor = $completed;
                } else {
                    if ($matched !== null || $completed !== null) { throw new InvalidArgumentException('Failed output steps cannot claim completion times.'); }
                    $active = false;
                }
                $allPassed = $allPassed && $passed;
                $steps[] = $step + ['passed' => $passed, 'matchedAtMs' => $matched, 'completedAtMs' => $completed, 'observed' => $observed];
            }
            $passed = !$runtimeFailed && $allPassed;
            if (SchemaValue::boolean($item['passed'] ?? null, 'outputSequence.passed') !== $passed) { throw new InvalidArgumentException('Inconsistent output sequence verdict.'); }
            $result[] = ['pin' => $contract['pin'], 'signal' => $contract['signal'], 'afterMs' => $contract['afterMs'], 'passed' => $passed, 'steps' => $steps];
        }
        return $result;
    }

    private static function observed(mixed $value, int $duration): array
    {
        $item = SchemaValue::object($value, 'outputSequence.observed');
        $signal = SchemaValue::oneOf($item['signal'] ?? null, SignalSchema::OUTPUT_SIGNALS, 'outputSequence.observed.signal');
        [$minimum, $maximum] = SignalSchema::range($signal);
        return ['signal' => $signal, 'value' => SchemaValue::integer($item['value'] ?? null, $minimum, $maximum, 'outputSequence.observed.value'),
            'atMs' => SchemaValue::integer($item['atMs'] ?? null, 0, $duration, 'outputSequence.observed.atMs')];
    }
}
