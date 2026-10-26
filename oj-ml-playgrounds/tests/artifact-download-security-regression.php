<?php

declare(strict_types=1);

use OJML\Playgrounds\Domain\AttemptStatus;
use OJML\Playgrounds\Http\ArtifactDownload;

function ojMlDownloadAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
require_once $root . '/src/Domain/AttemptStatus.php';
require_once $root . '/src/Http/ArtifactDownload.php';

ojMlDownloadAssert(ArtifactDownload::canDownloadStatus(AttemptStatus::ACCEPTED), 'Accepted artifacts must be downloadable.');
ojMlDownloadAssert(ArtifactDownload::canDownloadStatus(AttemptStatus::UNDER_REVIEW), 'Under-review artifacts must be downloadable.');
ojMlDownloadAssert(ArtifactDownload::canDownloadStatus(AttemptStatus::GRADED), 'Graded artifacts must be downloadable.');
ojMlDownloadAssert(!ArtifactDownload::canDownloadStatus(AttemptStatus::NEEDS_RESUBMIT), 'Rejected artifacts must stay blocked.');
ojMlDownloadAssert(!ArtifactDownload::canDownloadStatus(AttemptStatus::SYSTEM_PENDING), 'Pending artifacts must stay blocked.');

$download = file_get_contents($root . '/src/Http/ArtifactDownload.php');
$presenter = file_get_contents($root . '/src/Rest/Presenter.php');
$storage = file_get_contents($root . '/src/Storage/PrivateArtifactStore.php');
$verifier = file_get_contents($root . '/src/Storage/ArtifactIntegrityVerifier.php');
$composition = file_get_contents($root . '/src/Core/Composition_Root.php');
ojMlDownloadAssert(is_string($download) && is_string($presenter) && is_string($storage) && is_string($verifier) && is_string($composition), 'Download sources must be readable.');

ojMlDownloadAssert(str_contains($download, 'Addon_Capabilities::REVIEW') && str_contains($download, 'Addon_Capabilities::MANAGE'), 'Original downloads require REVIEW or MANAGE.');
ojMlDownloadAssert(!str_contains($download, 'Addon_Capabilities::REPORT'), 'REPORT-only actors must not download originals.');
ojMlDownloadAssert(str_contains($download, "REQUEST_METHOD']) !== 'POST'"), 'Downloads must reject non-POST requests.');
ojMlDownloadAssert(str_contains($download, "wp_verify_nonce") && str_contains($download, "teacherResult(\$attemptUuid, \$actorId, 'evaluate')"), 'Nonce and Context evaluate authorization are mandatory.');
ojMlDownloadAssert(str_contains($download, 'getArtifactForInspection') && str_contains($download, '$this->integrity->verify') && str_contains($verifier, 'hash_equals'), 'Internal artifact lookup and shared SHA verification are mandatory.');
foreach (['Content-Disposition: attachment', 'X-Content-Type-Options: nosniff', 'Cache-Control: private, no-store', 'Vary: Cookie'] as $header) {
    ojMlDownloadAssert(str_contains($download, $header), 'Missing protected download header: ' . $header);
}
ojMlDownloadAssert(str_contains($presenter, 'evaluationFacts') && !str_contains($presenter, 'entryHashes'), 'REST facts must use an allowlist and omit raw entry hashes.');
ojMlDownloadAssert(!str_contains($presenter, 'storage_key'), 'REST presenter must never expose the private storage key.');
ojMlDownloadAssert(str_contains($presenter, 'reviewSummary') && str_contains($presenter, "'feedback' => ''"), 'REPORT-only review DTO must omit draft feedback.');
ojMlDownloadAssert(str_contains($storage, 'is_link($path)') && str_contains($storage, 'realpath($path)') && str_contains($storage, 'fstat($stream)'), 'Storage open must reject links and verify the opened regular file.');
ojMlDownloadAssert(str_contains($composition, '$artifact_download->register()'), 'The protected admin-post handler must be registered during composition.');

fwrite(STDOUT, "OJ ML artifact download security regression passed.\n");
