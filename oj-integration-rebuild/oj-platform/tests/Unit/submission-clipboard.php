<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsService;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageSettingsLog;

require __DIR__ . '/submissions.php';
$initial = $submissionAssertions;
$store = new SubmissionTestSettings();
$provider = new SubmissionSettingsProvider($store);
submissionAssert($provider->current()->allowCodeCopy() && $provider->current()->allowCodePaste(), '기존 설치에서는 복사·붙여넣기를 기본 허용해야 합니다.');
$log = new class implements SubmissionLanguageSettingsLog { public function append(int $disabledLanguageMask, int $actorUserId): void {} };
$service = new SubmissionSettingsService($store, $log, $languages);
foreach ([[true,true], [true,false], [false,true], [false,false]] as [$copy, $paste]) {
    $service->saveRules(60, 3, true, 1, true, 30, 60, $copy, $paste);
    submissionAssert($provider->current()->allowCodeCopy() === $copy && $provider->current()->allowCodePaste() === $paste, '복사와 붙여넣기 설정은 독립적으로 저장·복원되어야 합니다.');
}
$service->saveRules(30, 5, false, 1);
submissionAssert(!$provider->current()->allowCodeCopy() && !$provider->current()->allowCodePaste(), '이전 호출 방식으로 다른 제한을 저장해도 클립보드 정책을 덮어쓰면 안 됩니다.');
submissionAssert($provider->current()->maxSourceBytes() === 30 * 1024 && $provider->current()->cooldownSeconds() === 5, '기존 제출 제한도 함께 유지해야 합니다.');
echo 'Submission clipboard: ' . ($submissionAssertions - $initial) . " checks passed\n";
