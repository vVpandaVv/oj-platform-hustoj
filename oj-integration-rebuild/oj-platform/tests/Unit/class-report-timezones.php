<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassStaffPolicy;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

require __DIR__ . '/learning-access.php';

$initialChecks = $learningAccessAssertions;
$reportHtml = new ClassHtml(new JudgeResultCatalog());
$cases = [
    'Seoul problem window' => ['Asia/Seoul', '2026-09-18 09:30:00',
        '2026-09-18 09:00:00', '2026-09-18 10:00:00',
        '2026-09-18 09:05:00', '2026-09-18 09:55:00',
        '2026-09-18 00:05:00', '2026-09-18 00:55:00', [2, 3, 4]],
    'Seoul class window' => ['Asia/Seoul', '2026-09-18 09:30:00',
        '2026-09-18 09:00:00', '2026-09-18 10:00:00',
        '2026-09-18 08:00:00', '2026-09-18 11:00:00',
        '2026-09-18 00:00:00', '2026-09-18 01:00:00', [2, 3, 4]],
    'UTC site' => ['UTC', '2026-09-18 09:30:00',
        '2026-09-18 09:00:00', '2026-09-18 10:00:00',
        '2026-09-18 09:05:00', '2026-09-18 09:55:00',
        '2026-09-18 09:05:00', '2026-09-18 09:55:00', [2, 3, 4]],
    'Negative offset' => ['America/New_York', '2026-07-01 09:30:00',
        '2026-07-01 09:00:00', '2026-07-01 10:00:00',
        '2026-07-01 09:05:00', '2026-07-01 09:55:00',
        '2026-07-01 13:05:00', '2026-07-01 13:55:00', [2, 3, 4]],
    'DST boundary' => ['America/New_York', '2026-11-01 02:15:00',
        '2026-11-01 00:00:00', '2026-11-01 03:00:00',
        '2026-11-01 00:30:00', '2026-11-01 02:30:00',
        '2026-11-01 04:30:00', '2026-11-01 07:30:00', [2, 3, 4]],
    'Fractional offset' => ['Asia/Kathmandu', '2026-09-18 09:30:00',
        '2026-09-18 09:00:00', '2026-09-18 10:00:00',
        '2026-09-18 09:05:00', '2026-09-18 09:55:00',
        '2026-09-18 03:20:00', '2026-09-18 04:10:00', [2, 3, 4]],
    'Start only' => ['Asia/Seoul', '2026-09-18 09:30:00',
        '2026-09-18 09:05:00', null, null, null,
        '2026-09-18 00:05:00', '2026-09-18 00:55:00', [2, 3, 4, 5]],
    'End only' => ['Asia/Seoul', '2026-09-18 09:30:00',
        null, null, null, '2026-09-18 09:55:00',
        '2026-09-18 00:05:00', '2026-09-18 00:55:00', [1, 2, 3, 4]],
    'No schedule' => ['Asia/Seoul', '2026-09-18 09:30:00',
        null, null, null, null,
        '2026-09-18 00:05:00', '2026-09-18 00:55:00', [1, 2, 3, 4, 5]],
];

foreach ($cases as $label => [$zone, $now, $classStart, $classEnd, $problemStart, $problemEnd, $utcStart, $utcEnd, $expectedIds]) {
    $repo = new LearningAccessClasses();
    $repo->items[1] = new LearningClass(
        1, $label, 'timezone-class', '', 'private', 'participants_live', true,
        $classStart, $classEnd, null, null, 10, null
    );
    $repo->problemItems[1] = [new ClassProblem(1000, 0, true, $problemStart, $problemEnd)];
    $start = new DateTimeImmutable($utcStart, new DateTimeZone('UTC'));
    $end = new DateTimeImmutable($utcEnd, new DateTimeZone('UTC'));
    $repo->participantApprovedAt[1] = [30 => $start->modify('-1 hour')->format('Y-m-d H:i:s')];
    $source = new LearningAccessSubmissions();
    $times = [
        $start->getTimestamp() - 1,
        $start->getTimestamp(),
        intdiv($start->getTimestamp() + $end->getTimestamp(), 2),
        $end->getTimestamp(),
        $end->getTimestamp() + 1,
    ];
    foreach ($times as $index => $timestamp) {
        $source->items[] = new SubmissionProjection(
            $index + 1, 1000, 'participant', 4, 6, 10, 128,
            gmdate('Y-m-d H:i:s', $timestamp), true, 1.0
        );
    }
    $source->items[] = new SubmissionProjection(
        6, 9999, 'participant', 4, 6, 10, 128, $utcStart, true, 1.0
    );
    $storedSubmissions = serialize($source->items);
    $clock = new class($now, $zone) implements Clock {
        public function __construct(private string $date, private string $zone) {}
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->date, new DateTimeZone($this->zone));
        }
    };
    $service = new ClassService(
        $repo, new ClassStaffPolicy($repo, new LearningAccessAuthorization()),
        new LearningAccessUsers(), new LearningAccessProblems(), $source,
        new LearningAccessGroups(true), $clock
    );
    foreach ([[30, 'participant', true], [30, 'participant', false], [10, 'owner', false]] as [$actor, $login, $ownOnly]) {
        $report = $service->report($actor, $login, 1, $ownOnly);
        learningAccessAssert(
            array_map(static fn (SubmissionProjection $row): int => $row->submissionId(), $report->submissions()) === $expectedIds,
            "$label: personal, participant and staff reports must use the same inclusive time window."
        );
        learningAccessAssert(
            str_contains($reportHtml->submissionTable($report), '/submissions/3?class_id=1'),
            "$label: the accepted submission must appear in the submission table."
        );
        learningAccessAssert(
            str_contains($reportHtml->reportTable($report), 'ojp-class-progress-count"><strong>1 / 1</strong>'),
            "$label: repeated accepted submissions must count as one solved problem."
        );
        $restrictedHtml = $reportHtml->submissionTable($report, false);
        learningAccessAssert(
            str_contains($restrictedHtml, '<strong>3</strong>') && ! str_contains($restrictedHtml, '/submissions/3'),
            "$label: performance mode must retain the result without revealing its detail link."
        );
    }
    learningAccessAssert(
        serialize($source->items) === $storedSubmissions,
        "$label: reporting must not rewrite UTC timestamps or submission history."
    );
}

echo 'Class report timezones passed: ' . ($learningAccessAssertions - $initialChecks) . " assertions\n";
