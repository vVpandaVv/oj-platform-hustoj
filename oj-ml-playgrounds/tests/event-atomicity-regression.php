<?php

declare(strict_types=1);

/** @param mixed $actual */
function ojMlEventAtomicityAssert(bool $condition, string $message, mixed $actual = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($actual === null ? '' : ' actual=' . var_export($actual, true)));
    }
}

$root = dirname(__DIR__);
$repository = file_get_contents($root . '/src/Database/Repository.php');
ojMlEventAtomicityAssert(is_string($repository), 'Repository source must be readable.');

$inspectionStart = strpos($repository, 'public function completeInspection(');
$inspectionEnd = strpos($repository, 'public function getAttemptResult(', $inspectionStart ?: 0);
ojMlEventAtomicityAssert(
    $inspectionStart !== false && $inspectionEnd !== false,
    'Inspection transaction boundaries must be discoverable.'
);
$inspection = substr($repository, $inspectionStart, $inspectionEnd - $inspectionStart);
ojMlEventAtomicityAssert(
    str_contains($inspection, 'if ($event !== null)') && str_contains($inspection, '$this->insertEvent('),
    'Inspection result and its audit event must be written in the same repository transaction.'
);

$reviewStart = strpos($repository, 'public function appendReview(');
$reviewEnd = strpos($repository, 'public function releaseReview(', $reviewStart ?: 0);
ojMlEventAtomicityAssert(
    $reviewStart !== false && $reviewEnd !== false,
    'Review transaction boundaries must be discoverable.'
);
$review = substr($repository, $reviewStart, $reviewEnd - $reviewStart);
ojMlEventAtomicityAssert(
    str_contains($review, 'if ($eventType !== null)') && str_contains($review, '$this->insertEvent('),
    'Review result and its audit event must be written in the same repository transaction.'
);

foreach (['SubmissionService.php', 'ReinspectionService.php', 'AssignmentService.php'] as $file) {
    $service = file_get_contents($root . '/src/Application/' . $file);
    ojMlEventAtomicityAssert(is_string($service), $file . ' source must be readable.');
    ojMlEventAtomicityAssert(
        !str_contains($service, '->insertEvent('),
        $file . ' must not append an audit event after the primary repository transaction.'
    );
}

fwrite(STDOUT, "OJ ML audit event atomicity regression passed.\n");
