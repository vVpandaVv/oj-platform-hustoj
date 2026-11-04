<?php

declare(strict_types=1);

namespace OjMicrobit\Domain;

use InvalidArgumentException;
use RuntimeException;

/** Reject stale, incomplete or inconsistent responses from the authenticated runner. */
final class RunnerResultSchema
{
    public const RUNTIME_VERSION = 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/3';

    public static function normalize(array $data, array $project, array $exercise): array
    {
        try {
            return self::validate($data, $project, $exercise);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('Invalid microbit runner result.', 0, $error);
        }
    }

    private static function validate(array $data, array $project, array $exercise): array
    {
        $status = SchemaValue::oneOf($data['status'] ?? null, ['graded', 'preview', 'compile_error', 'runtime_error'], 'result.status');
        if (($data['runtimeVersion'] ?? null) !== self::RUNTIME_VERSION
            || !is_string($data['projectHash'] ?? null)
            || !hash_equals(ProjectSchema::hash($project), $data['projectHash'])) {
            throw new InvalidArgumentException('Runner project or runtime does not match the request.');
        }
        if (!array_key_exists('passed', $data) || !array_key_exists('score', $data)) {
            throw new InvalidArgumentException('Runner verdict fields are missing.');
        }
        $passed = $data['passed'] ?? null;
        $score = $data['score'] ?? null;
        $incoming = SchemaValue::list($data['scenarios'] ?? null, ExerciseSchema::MAX_SCENARIOS, 'result.scenarios');
        $scenarios = [];
        $byId = [];
        foreach ($exercise['scenarios'] as $scenario) { $byId[$scenario['id']] = $scenario; }
        $total = 0; $points = 0; $hasError = false;
        if ($status === 'compile_error') {
            if ($passed !== false || $score !== 0 || $incoming !== []) {
                throw new InvalidArgumentException('Compile error cannot have a successful verdict.');
            }
        } else {
            if (count($incoming) !== count($exercise['scenarios'])) { throw new InvalidArgumentException('Incomplete runner scenarios.'); }
            foreach ($incoming as $index => $item) {
                $scenario = SchemaValue::object($item, 'result.scenarios');
                $expected = $exercise['scenarios'][$index];
                if (($scenario['id'] ?? null) !== $expected['id']) { throw new InvalidArgumentException('Mismatched runner scenario.'); }
                $error = ($scenario['error'] ?? null) === null ? null : SchemaValue::string($scenario['error'], 4000, 'scenario.error', false, false);
                $hasError = $hasError || $error !== null;
                $assertions = SchemaValue::list($scenario['assertions'] ?? null, 256, 'scenario.assertions');
                if (count($assertions) !== count($expected['assertions'])) { throw new InvalidArgumentException('Incomplete runner assertions.'); }
                $normalized = []; $scenarioPass = true;
                foreach ($assertions as $assertionIndex => $actual) {
                    $actual = SchemaValue::object($actual, 'assertion');
                    $contract = $expected['assertions'][$assertionIndex];
                    foreach (['pin', 'signal', 'expected', 'afterMs', 'withinMs', 'holdMs', 'tolerance'] as $key) {
                        if (!array_key_exists($key, $actual) || $actual[$key] !== $contract[$key]) { throw new InvalidArgumentException('Mismatched runner assertion.'); }
                    }
                    $assertionPassed = SchemaValue::boolean($actual['passed'] ?? null, 'assertion.passed');
                    $matched = ($actual['matchedAtMs'] ?? null) === null ? null
                        : SchemaValue::integer($actual['matchedAtMs'], $contract['afterMs'], $contract['afterMs'] + $contract['withinMs'], 'assertion.matchedAtMs');
                    if ($assertionPassed && ($matched === null || $error !== null)) { throw new InvalidArgumentException('Inconsistent assertion verdict.'); }
                    $observed = ($actual['observed'] ?? null) === null ? null : self::observed($actual['observed'], $expected['durationMs']);
                    $normalized[] = $contract + ['passed' => $assertionPassed, 'matchedAtMs' => $matched, 'observed' => $observed];
                    $scenarioPass = $scenarioPass && $assertionPassed;
                    $points += $assertionPassed ? 1 : 0; $total++;
                }
                $i2cAssertions = SchemaValue::list($scenario['i2cAssertions'] ?? [], 256, 'scenario.i2cAssertions');
                $expectedI2c = $expected['i2cAssertions'] ?? [];
                if (count($i2cAssertions) !== count($expectedI2c)) { throw new InvalidArgumentException('Incomplete I2C runner assertions.'); }
                $normalizedI2c = [];
                foreach ($i2cAssertions as $assertionIndex => $actual) {
                    $actual = SchemaValue::object($actual, 'i2cAssertion');
                    $contract = $expectedI2c[$assertionIndex];
                    foreach (['address', 'operation', 'data', 'repeated', 'afterMs', 'withinMs'] as $key) {
                        if (!array_key_exists($key, $actual) || $actual[$key] !== $contract[$key]) { throw new InvalidArgumentException('Mismatched I2C runner assertion.'); }
                    }
                    $assertionPassed = SchemaValue::boolean($actual['passed'] ?? null, 'i2cAssertion.passed');
                    $matched = ($actual['matchedAtMs'] ?? null) === null ? null
                        : SchemaValue::integer($actual['matchedAtMs'], $contract['afterMs'], $contract['afterMs'] + $contract['withinMs'], 'i2cAssertion.matchedAtMs');
                    $observed = ($actual['observed'] ?? null) === null ? null
                        : I2cSchema::transaction($actual['observed'], $exercise['i2cDevices'] ?? [], $expected['durationMs'], 'i2cAssertion.observed');
                    if ($assertionPassed) {
                        if ($matched === null || $error !== null || $observed === null || $observed['atMs'] !== $matched) { throw new InvalidArgumentException('Inconsistent I2C assertion verdict.'); }
                        foreach (['address', 'operation', 'data', 'repeated'] as $key) {
                            if ($observed[$key] !== $contract[$key]) { throw new InvalidArgumentException('Successful I2C observation does not match.'); }
                        }
                    }
                    $normalizedI2c[] = $contract + ['passed' => $assertionPassed, 'matchedAtMs' => $matched, 'observed' => $observed];
                    $scenarioPass = $scenarioPass && $assertionPassed;
                    $points += $assertionPassed ? 1 : 0; $total++;
                }
                $outputSequences = OutputSequenceSchema::result($scenario['outputSequences'] ?? [], $expected['outputSequences'] ?? [], $expected['durationMs'], $error !== null);
                foreach ($outputSequences as $sequence) {
                    foreach ($sequence['steps'] as $step) { $points += $step['passed'] ? 1 : 0; $total++; }
                    $scenarioPass = $scenarioPass && $sequence['passed'];
                }
                $scenarioVerdict = $normalized === [] && $normalizedI2c === [] && $outputSequences === [] ? null : ($error === null && $scenarioPass);
                if (($scenario['passed'] ?? null) !== $scenarioVerdict) { throw new InvalidArgumentException('Inconsistent scenario verdict.'); }
                $scenarios[] = ['id' => $expected['id'], 'name' => $expected['name'], 'passed' => $scenarioVerdict, 'error' => $error, 'assertions' => $normalized, 'i2cAssertions' => $normalizedI2c, 'outputSequences' => $outputSequences];
            }
            $expectedStatus = $hasError ? 'runtime_error' : ($total > 0 ? 'graded' : 'preview');
            $expectedPassed = $total > 0 ? (!$hasError && $points === $total) : null;
            $expectedScore = $total > 0 ? round($points * 100 / $total, 2) : null;
            $scoreMatches = $expectedScore === null ? $score === null
                : ((is_int($score) || is_float($score)) && is_finite((float) $score) && abs($score - $expectedScore) <= 0.000001);
            if ($status !== $expectedStatus || $passed !== $expectedPassed || !$scoreMatches) {
                throw new InvalidArgumentException('Inconsistent overall runner verdict.');
            }
        }
        $traceId = $data['traceScenarioId'] ?? null;
        if ($traceId !== null && (!is_string($traceId) || !isset($byId[$traceId]))) { throw new InvalidArgumentException('Unknown trace scenario.'); }
        $trace = SchemaValue::list($data['trace'] ?? null, 50000, 'result.trace');
        if (($traceId === null || $status === 'compile_error') && $trace !== []) { throw new InvalidArgumentException('Unscoped runner trace.'); }
        $normalizedTrace = []; $previous = -1;
        foreach ($trace as $event) {
            $event = SchemaValue::object($event, 'trace');
            if (($event['kind'] ?? null) !== 'write' || !in_array($event['pin'] ?? null, ExerciseSchema::PINS, true)) { throw new InvalidArgumentException('Invalid trace write.'); }
            $write = self::observed($event, $byId[$traceId]['durationMs']);
            if ($write['atMs'] < $previous) { throw new InvalidArgumentException('Unordered trace.'); }
            $previous = $write['atMs'];
            $normalizedTrace[] = ['atMs' => $write['atMs'], 'pin' => $event['pin'], 'signal' => $write['signal'], 'value' => $write['value'], 'kind' => 'write'];
        }
        $i2cTrace = SchemaValue::list($data['i2cTrace'] ?? [], 50000, 'result.i2cTrace');
        if (($traceId === null || $status === 'compile_error') && $i2cTrace !== []) { throw new InvalidArgumentException('Unscoped I2C trace.'); }
        $normalizedI2cTrace = []; $previous = -1;
        foreach ($i2cTrace as $event) {
            $transaction = I2cSchema::transaction($event, $exercise['i2cDevices'] ?? [], $byId[$traceId]['durationMs'], 'i2cTrace');
            if ($transaction['atMs'] < $previous) { throw new InvalidArgumentException('Unordered I2C trace.'); }
            $previous = $transaction['atMs']; $normalizedI2cTrace[] = $transaction;
        }
        $diagnostics = [];
        foreach (SchemaValue::list($data['diagnostics'] ?? [], 30, 'result.diagnostics') as $item) {
            $item = SchemaValue::object($item, 'diagnostic');
            $diagnostics[] = [
                'code' => SchemaValue::integer($item['code'] ?? null, 0, 1000000, 'diagnostic.code'),
                'file' => SchemaValue::string($item['file'] ?? '', 240, 'diagnostic.file', true),
                'line' => SchemaValue::integer($item['line'] ?? null, 0, 1000000, 'diagnostic.line'),
                'message' => SchemaValue::string($item['message'] ?? null, 12000, 'diagnostic.message'),
            ];
        }
        $result = ['status' => $status, 'passed' => $passed, 'score' => $score, 'scenarios' => $scenarios, 'trace' => $normalizedTrace, 'i2cTrace' => $normalizedI2cTrace,
            'traceScenarioId' => $traceId, 'runtimeVersion' => self::RUNTIME_VERSION, 'projectHash' => $data['projectHash'], 'diagnostics' => $diagnostics];
        if (isset($data['error'])) { $result['error'] = SchemaValue::string($data['error'], 4000, 'result.error'); }
        return $result;
    }

    private static function observed(mixed $item, int $duration): array
    {
        $item = SchemaValue::object($item, 'observed');
        $signal = SchemaValue::oneOf($item['signal'] ?? null, SignalSchema::OUTPUT_SIGNALS, 'observed.signal');
        [$minimum, $maximum] = SignalSchema::range($signal);
        return ['signal' => $signal, 'value' => SchemaValue::integer($item['value'] ?? null, $minimum, $maximum, 'observed.value'),
            'atMs' => SchemaValue::integer($item['atMs'] ?? null, 0, $duration, 'observed.atMs')];
    }
}
