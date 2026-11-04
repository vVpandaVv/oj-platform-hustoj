<?php
declare(strict_types=1);
namespace OjMicrobit\Domain;

use InvalidArgumentException;

/** Review the exact normalized payload that will be distributed to learners. */
final class PublicationReview
{
    public static function prepareInput(array $input): array
    {
        if (($input['status'] ?? 'draft') !== 'published') { return $input; }
        $review = SchemaValue::object($input['publicationReview'] ?? null, 'exercise.publicationReview');
        $mode = self::mode($review['starterMode'] ?? null);
        if ($mode === 'blank') {
            $project = SchemaValue::object($input['starterProject'] ?? null, 'exercise.starterProject');
            $input['starterProject'] = self::blankProject(SchemaValue::string($project['name'] ?? null, 120, 'project.name'));
        }
        return $input;
    }

    public static function blankProject(string $name): array
    {
        return ProjectSchema::normalize(['name' => $name, 'files' => [
            'main.ts' => '',
            'main.blocks' => '<xml xmlns="https://developers.google.com/blockly/xml"></xml>' . "\n",
            'pxt.json' => json_encode(['name' => $name, 'dependencies' => ['core' => '*'],
                'files' => ['main.ts', 'main.blocks'], 'preferredEditor' => 'blocksprj'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]]);
    }

    public static function describe(array $exercise, mixed $mode): array
    {
        $mode = self::mode($mode);
        $visible = 0; $total = 0; $checks = 0; $scenarios = [];
        foreach ($exercise['scenarios'] as $scenario) {
            $steps = array_sum(array_map(static fn (array $sequence): int => count($sequence['steps']), $scenario['outputSequences'] ?? []));
            $assertions = count($scenario['assertions']); $i2cAssertions = count($scenario['i2cAssertions'] ?? []);
            $visible += $scenario['visible'] ? 1 : 0; $total += $scenario['durationMs']; $checks += $assertions + $i2cAssertions + $steps;
            $scenarios[] = ['id' => $scenario['id'], 'name' => $scenario['name'], 'visible' => $scenario['visible'],
                'durationMs' => $scenario['durationMs'], 'inputCount' => count($scenario['inputs']) + count($scenario['i2cInputs'] ?? []),
                'assertionCount' => $assertions, 'i2cAssertionCount' => $i2cAssertions,
                'sequenceCount' => count($scenario['outputSequences'] ?? []), 'sequenceStepCount' => $steps];
        }
        $student = $exercise;
        $student['scenarios'] = array_values(array_filter($exercise['scenarios'], static fn (array $scenario): bool => $scenario['visible'] === true));
        return ['fingerprint' => self::fingerprint($exercise, $mode), 'summary' => [
            'title' => $exercise['title'], 'audience' => $exercise['audience'] ?? ['mode' => 'site', 'classIds' => []],
            'scenarioCount' => count($scenarios), 'visibleScenarioCount' => $visible, 'hiddenScenarioCount' => count($scenarios) - $visible,
            'totalDurationMs' => $total, 'checksCount' => $checks, 'maxScore' => $checks > 0 ? 100 : null, 'scenarios' => $scenarios,
            'starter' => ['mode' => $mode, 'name' => $exercise['starterProject']['name'], 'fileCount' => count($exercise['starterProject']['files']),
                'sourceBytes' => strlen($exercise['starterProject']['files']['main.ts'])],
        ], 'studentPreview' => $student];
    }

    public static function requireConfirmed(array $exercise, mixed $value): void
    {
        if ($exercise['status'] !== 'published') { return; }
        $review = SchemaValue::object($value, 'exercise.publicationReview');
        $mode = self::mode($review['starterMode'] ?? null);
        if (($review['confirmed'] ?? null) !== true) {
            throw new InvalidArgumentException('exercise.publicationReview: 학생에게 공개하기 전에 배포 코드와 실험 예시를 확인해 주세요.');
        }
        $fingerprint = $review['fingerprint'] ?? null;
        if (!is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1
            || !hash_equals(self::fingerprint($exercise, $mode), $fingerprint)) {
            throw new InvalidArgumentException('exercise.publicationReview: 점검 후 과제 내용이 바뀌었습니다. 최신 내용으로 공개 전 점검을 다시 진행해 주세요.');
        }
    }

    private static function mode(mixed $mode): string
    {
        return SchemaValue::oneOf($mode, ['blank', 'current'], 'exercise.publicationReview.starterMode');
    }

    private static function fingerprint(array $exercise, string $mode): string
    {
        // IDs/revisions are checked separately by ownership and CAS. Bind all
        // teaching content, including hidden checks and class scope, to review.
        $content = array_intersect_key($exercise, array_flip(['title', 'description', 'status', 'connections', 'i2cDevices', 'scenarios', 'starterProject', 'audience']));
        return hash('sha256', json_encode(['starterMode' => $mode, 'exercise' => $content],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }
}
