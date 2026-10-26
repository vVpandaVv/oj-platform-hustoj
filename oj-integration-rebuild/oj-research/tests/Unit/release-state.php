<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/tools/release-state.php';
$head = str_repeat('a', 40);
$other = str_repeat('b', 40);
$checks = 0;
foreach ([
    ['1.0.0', $head, true, $head, false],
    ['1.0.0', $head, false, null, false],
    ['1.0.0', $head, false, $other, false],
    ['1.0.0', $head, false, $head, true],
    ['1.0.0', 'unavailable', false, null, false],
    ['../1.0.0', $head, false, $head, false],
    ['1.0.1-dev.1', $head, true, null, false],
] as $case) {
    $rejected = false;
    try { researchValidateRelease(...$case); } catch (RuntimeException) { $rejected = true; }
    if (! $rejected) { throw new RuntimeException('Unsafe release state was accepted'); }
    ++$checks;
}
$stable = researchValidateRelease('1.0.0', $head, false, $head, false);
if ($stable !== ['source_commit'=>$head, 'source_dirty'=>false, 'source_tag'=>'oj-research-v1.0.0']) { throw new RuntimeException('Stable provenance was not preserved'); }
++ $checks;
$development = researchValidateRelease('1.0.1-dev.1', $head, true, null, true);
if (! $development['source_dirty'] || $development['source_tag'] !== null) { throw new RuntimeException('A development package cannot claim stable provenance'); }
++ $checks;
echo "Research release guards: {$checks} checks passed\n";
