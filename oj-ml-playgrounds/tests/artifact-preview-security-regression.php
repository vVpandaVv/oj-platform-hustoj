<?php

declare(strict_types=1);

use OJML\Playgrounds\Application\ArtifactPreviewService;
use OJML\Playgrounds\Domain\AttemptStatus;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Storage\ArtifactIntegrityVerifier;

function ojMlArtifactPreviewAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
require_once $root . '/src/Domain/Problem.php';
require_once $root . '/src/Domain/AttemptStatus.php';
require_once $root . '/src/Storage/ArtifactIntegrityVerifier.php';
require_once $root . '/src/Application/ArtifactPreviewService.php';

ojMlArtifactPreviewAssert(ArtifactPreviewService::canPreviewStatus(AttemptStatus::ACCEPTED), 'Accepted artifacts must be previewable.');
ojMlArtifactPreviewAssert(ArtifactPreviewService::canPreviewStatus(AttemptStatus::UNDER_REVIEW), 'Under-review artifacts must be previewable.');
ojMlArtifactPreviewAssert(ArtifactPreviewService::canPreviewStatus(AttemptStatus::GRADED), 'Graded artifacts must be previewable.');
ojMlArtifactPreviewAssert(!ArtifactPreviewService::canPreviewStatus(AttemptStatus::NEEDS_RESUBMIT), 'Rejected artifacts must not be previewed.');
ojMlArtifactPreviewAssert(!ArtifactPreviewService::canPreviewStatus(AttemptStatus::SYSTEM_PENDING), 'Pending artifacts must not be previewed.');

$contents = "verified artifact\n";
$source = tmpfile();
$snapshot = tmpfile();
if ($source === false || $snapshot === false) {
    throw new RuntimeException('Unable to create verifier test streams.');
}
fwrite($source, $contents);
rewind($source);
$verifier = new ArtifactIntegrityVerifier();
$verifier->verify($source, strlen($contents), hash('sha256', $contents), $snapshot);
ojMlArtifactPreviewAssert(stream_get_contents($snapshot) === $contents, 'Verified snapshot must contain the exact source bytes.');
fclose($source);
fclose($snapshot);

$tampered = tmpfile();
if ($tampered === false) {
    throw new RuntimeException('Unable to create tamper test stream.');
}
fwrite($tampered, $contents);
rewind($tampered);
$blocked = false;
try {
    $verifier->verify($tampered, strlen($contents), str_repeat('0', 64));
} catch (Problem $problem) {
    $blocked = $problem->problemCode() === 'OJ_ML_ARTIFACT_INTEGRITY_FAILED';
}
fclose($tampered);
ojMlArtifactPreviewAssert($blocked, 'SHA-256 mismatches must block preview.');

$permissions = file_get_contents($root . '/src/Rest/Permissions.php');
$route = file_get_contents($root . '/src/Rest/ArtifactPreviewRoute.php');
$service = file_get_contents($root . '/src/Application/ArtifactPreviewService.php');
$inspector = file_get_contents($root . '/src/Inspection/MlpxInspector.php');
$projector = file_get_contents($root . '/src/Inspection/MlpxReadOnlyProjector.php');
$composition = file_get_contents($root . '/src/Core/Composition_Root.php');
foreach ([$permissions, $route, $service, $inspector, $projector, $composition] as $sourceCode) {
    ojMlArtifactPreviewAssert(is_string($sourceCode), 'Preview security source must be readable.');
}

ojMlArtifactPreviewAssert(str_contains($permissions, 'function artifactPreview') && str_contains($permissions, 'Addon_Capabilities::REVIEW') && str_contains($permissions, 'Addon_Capabilities::MANAGE'), 'Preview requires REVIEW or MANAGE.');
ojMlArtifactPreviewAssert(str_contains($permissions, 'return self::any($request, true'), 'Preview must require the REST nonce guard.');
ojMlArtifactPreviewAssert(str_contains($route, 'WP_REST_Server::CREATABLE') && str_contains($route, '/preview'), 'Preview must use a nonce-protected POST route.');
foreach (['Cache-Control', 'no-store, private', 'Vary', 'Cookie', 'X-Content-Type-Options', 'nosniff'] as $headerMarker) {
    ojMlArtifactPreviewAssert(str_contains($route, $headerMarker), 'Missing preview response security marker: ' . $headerMarker);
}
ojMlArtifactPreviewAssert(str_contains($service, "teacherResult(\$attemptUuid, \$actorId, 'evaluate')"), 'Preview must enforce Context evaluate authorization.');
ojMlArtifactPreviewAssert(str_contains($service, 'getArtifactForInspection') && str_contains($service, '$this->storage->open') && str_contains($service, '$this->integrity->verify'), 'Preview must use internal storage and exact artifact verification.');
ojMlArtifactPreviewAssert(str_contains($service, 'tmpfile()') && str_contains($service, 'finally'), 'Preview must use and close a private verified snapshot.');
ojMlArtifactPreviewAssert(str_contains($inspector, 'readOnlyView') && str_contains($inspector, 'PREVIEW_MAX_JSON_TOTAL_BYTES'), 'Inspector must enforce a bounded read-only metadata path.');
ojMlArtifactPreviewAssert(!str_contains($inspector, 'extractTo('), 'Preview/inspection must never extract the archive.');
foreach (['projectId', 'student', 'originalFileName', 'trainIndices', 'testIndices', "['path']", 'entryHashes'] as $forbiddenKey) {
    ojMlArtifactPreviewAssert(!str_contains($projector, "'" . $forbiddenKey . "' =>"), 'Projector must not emit forbidden field: ' . $forbiddenKey);
}
ojMlArtifactPreviewAssert(str_contains($composition, '$artifact_preview_route->register') || str_contains($composition, "array( \$artifact_preview_route, 'register' )"), 'Preview route must be registered during composition.');

fwrite(STDOUT, "OJ ML artifact preview security regression passed.\n");
