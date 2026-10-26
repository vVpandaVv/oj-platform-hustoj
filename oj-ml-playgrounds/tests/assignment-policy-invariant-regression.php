<?php

declare(strict_types=1);

use OJML\Playgrounds\Application\AssignmentService;
use OJML\Playgrounds\Domain\Problem;

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value));
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string
    {
        return $value;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value): string|false
    {
        return json_encode($value);
    }
}

require dirname(__DIR__) . '/src/Domain/Problem.php';
require_once __DIR__ . '/bootstrap.php'; // classes the code under test now uses (Options, Rubric, PortfolioRequirements)
require dirname(__DIR__) . '/src/Application/AssignmentService.php';

/** @param mixed $expected @param mixed $actual */
function policyAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true)
        );
    }
}

/** @param callable():mixed $callback */
function policyAssertMismatch(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Problem $problem) {
        policyAssertSame(
            'OJ_ML_ASSIGNMENT_POLICY_TASK_TYPE_MISMATCH',
            $problem->problemCode(),
            $message . ' (problem code)'
        );
        policyAssertSame(400, $problem->httpStatus(), $message . ' (HTTP status)');
        return;
    }
    throw new RuntimeException($message . ' (exception was not raised)');
}

$reflection = new ReflectionClass(AssignmentService::class);
$service = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizeAssignment');
$normalize->setAccessible(true);

$baseCreate = [
    'title' => '분류 과제',
    'description' => '',
    'taskType' => 'classification',
    'opensAt' => null,
    'dueAt' => null,
    'maxAttempts' => 1,
    'rubric' => [],
];

policyAssertMismatch(
    static fn () => $normalize->invoke(
        $service,
        $baseCreate + ['policy' => ['allowedTaskTypes' => ['regression']]],
        true,
        null
    ),
    'create must reject a policy that excludes its task type'
);

$created = $normalize->invoke(
    $service,
    $baseCreate + ['policy' => ['allowedTaskTypes' => ['classification', 'regression']]],
    true,
    null
);
policyAssertSame(
    ['classification', 'regression'],
    $created['policy']['allowedTaskTypes'],
    'create must preserve a compatible allowed task type list'
);

policyAssertMismatch(
    static fn () => $normalize->invoke(
        $service,
        ['policy' => ['allowedTaskTypes' => ['regression']]],
        false,
        'classification'
    ),
    'policy-only update must validate against the current task type'
);

$policyOnly = $normalize->invoke(
    $service,
    ['policy' => ['allowedTaskTypes' => ['classification']]],
    false,
    'classification'
);
policyAssertSame(
    ['classification'],
    $policyOnly['policy']['allowedTaskTypes'],
    'policy-only update must accept the current task type'
);

policyAssertMismatch(
    static fn () => $normalize->invoke(
        $service,
        [
            'taskType' => 'regression',
            'policy' => ['allowedTaskTypes' => ['classification']],
        ],
        false,
        'classification'
    ),
    'task type plus policy update must validate against the new task type'
);

$taskTypeOnly = $normalize->invoke(
    $service,
    ['taskType' => 'regression'],
    false,
    'classification'
);
policyAssertSame('regression', $taskTypeOnly['task_type'], 'task-type-only update must keep the new task type');
policyAssertSame(false, array_key_exists('policy', $taskTypeOnly), 'task-type-only normalization must not synthesize a new policy');

$source = file_get_contents(dirname(__DIR__) . '/src/Application/AssignmentService.php');
if (!is_string($source) || !str_contains(
    $source,
    '$existingPolicy[\'allowedTaskTypes\'] = [$changes[\'task_type\']];'
)) {
    throw new RuntimeException('task-type-only update policy alignment block must remain present');
}

fwrite(STDOUT, "OJ ML assignment policy invariant regression passed.\n");


