<?php
declare(strict_types=1);
namespace OjMicrobit\Domain;

/** The same redaction applies to REST history and personal-data exports. */
final class ResultVisibility
{
    public static function learner(array $result): array
    {
        // Missing original visibility must fail closed across exercise revisions.
        $visibility = is_array($result['_visibility'] ?? null) ? $result['_visibility'] : [];
        $result = array_intersect_key($result, array_flip(['status', 'passed', 'score', 'scenarios', 'trace', 'i2cTrace', 'traceScenarioId', 'runtimeVersion', 'projectHash', 'diagnostics', 'error']));
        $result['i2cTrace'] = is_array($result['i2cTrace'] ?? null) ? $result['i2cTrace'] : [];
        $hasHidden = false;
        foreach ($result['scenarios'] ?? [] as $scenario) {
            if (($visibility[$scenario['id'] ?? ''] ?? false) !== true) { $hasHidden = true; }
        }
        $result['scenarios'] = array_map(static function (array $scenario) use ($visibility): array {
            if (($visibility[$scenario['id'] ?? ''] ?? false) === true) { return $scenario + ['i2cAssertions' => [], 'outputSequences' => []]; }
            return ['id' => $scenario['id'] ?? '', 'name' => '비공개 테스트', 'passed' => ($scenario['passed'] ?? false) === true];
        }, $result['scenarios'] ?? []);
        if (($visibility[$result['traceScenarioId'] ?? ''] ?? false) !== true) {
            $result['trace'] = []; $result['i2cTrace'] = []; $result['traceScenarioId'] = null;
        }
        if ($hasHidden || in_array(false, $visibility, true)) {
            // Source compiler diagnostics run before hidden inputs are applied.
            if (($result['status'] ?? '') !== 'compile_error') { $result['diagnostics'] = []; }
            if (isset($result['error'])) { $result['error'] = '비공개 테스트 실행 중 오류가 발생했습니다.'; }
        }
        return $result;
    }
}
