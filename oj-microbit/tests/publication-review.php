<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OjMicrobit\Domain\ExerciseSchema;
use OjMicrobit\Domain\ProjectSchema;
use OjMicrobit\Domain\PublicationReview;

$original = fixtureSequenceExercise(); $originalHash = ProjectSchema::hash($original['starterProject']);
throws(fn () => PublicationReview::prepareInput($original), InvalidArgumentException::class, 'Publishing requires an explicit starter selection');
$input = $original + ['publicationReview' => ['starterMode' => 'blank']];
$blank = ExerciseSchema::normalize(PublicationReview::prepareInput($input));
verify($blank['starterProject']['files']['main.ts'] === '', 'Blank distribution has no reference answer');
verify(count($blank['starterProject']['files']) === 3 && isset($blank['starterProject']['files']['main.blocks']), 'Blank starter retains a usable MakeCode blocks project');
verify(ProjectSchema::hash($original['starterProject']) === $originalHash, 'Review does not modify the teacher reference project');
$review = PublicationReview::describe($blank, 'blank');
verify($review['summary']['hiddenScenarioCount'] === 1 && $review['summary']['visibleScenarioCount'] === 1, 'Review counts public and hidden examples separately');
verify($review['summary']['checksCount'] === 4 && $review['summary']['maxScore'] === 100, 'Sequence actions contribute to the grading summary');
verify(count($review['studentPreview']['scenarios']) === 1 && $review['studentPreview']['scenarios'][0]['visible'], 'Student preview excludes hidden checks');
verify(!str_contains(json_encode($review['studentPreview']), 'secret'), 'Hidden scenario identifiers do not leak into student preview');
$confirmation = ['starterMode' => 'blank', 'confirmed' => true, 'fingerprint' => $review['fingerprint']];
PublicationReview::requireConfirmed($blank, $confirmation); verify(true, 'Exact reviewed payload can be published');
throws(fn () => PublicationReview::requireConfirmed($blank, array_replace($confirmation, ['confirmed' => 1])), InvalidArgumentException::class, 'Confirmation must be a real boolean');
throws(fn () => PublicationReview::requireConfirmed($blank, array_replace($confirmation, ['starterMode' => 'current'])), InvalidArgumentException::class, 'A starter choice cannot change after review');
$changed = $blank; $changed['scenarios'][1]['outputSequences'][0]['steps'][0]['holdMs']--;
throws(fn () => PublicationReview::requireConfirmed($changed, $confirmation), InvalidArgumentException::class, 'Hidden time conditions are bound to review');
$changed = $blank; $changed['description'] .= ' 수정';
throws(fn () => PublicationReview::requireConfirmed($changed, $confirmation), InvalidArgumentException::class, 'Description changes invalidate the review');
$changed = $blank; $changed['audience'] = ['mode' => 'classes', 'classIds' => [19]];
throws(fn () => PublicationReview::requireConfirmed($changed, $confirmation), InvalidArgumentException::class, 'Class distribution changes invalidate the review');
$changed = $blank; $changed['starterProject']['files']['main.ts'] = 'basic.pause(1)';
throws(fn () => PublicationReview::requireConfirmed($changed, $confirmation), InvalidArgumentException::class, 'Changed starter code requires fresh review');
$current = ExerciseSchema::normalize(PublicationReview::prepareInput($original + ['publicationReview' => ['starterMode' => 'current']]));
verify(ProjectSchema::hash($current['starterProject']) === $originalHash, 'Intentional current-code distribution preserves source and blocks');
$draft = $original; $draft['status'] = 'draft';
verify(PublicationReview::prepareInput($draft) === $draft, 'Drafts need no publishing confirmation');
PublicationReview::requireConfirmed($draft, null); verify(true, 'Draft saving is not blocked by review');
echo '[publication-review] ' . $testChecks . " checks passed\n";
