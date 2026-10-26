<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();
require_once __DIR__ . '/Support/SqlWpdb.php';
require_once __DIR__ . '/Support/ReleaseFixture.php';
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

use OjPrimm\Application\PlatformAccess;
use OjPrimm\Application\PrimmService;
use OjPrimm\Application\SubmissionDraftAccess;
use OjPrimm\Storage\WpdbManagementStore;
use OjPrimm\Storage\WpdbRepository;
use OjPrimmTests\SqlWpdb;

$checks = 0;
function submissionCheck(bool $condition, string $label): void
{
    global $checks; ++$checks;
    if (! $condition) { throw new RuntimeException($label); }
}
function submissionRejects(callable $work, string $label): void
{
    try { $work(); } catch (DomainException) { submissionCheck(true, $label); return; }
    submissionCheck(false, $label);
}

/** Only the existing public PlatformAccess contract is implemented. No judge DB is available. */
final class SubmissionPlatform implements PlatformAccess, SubmissionDraftAccess
{
    public int $actor = 20;
    public bool $learn = true;
    public bool $teach = false;
    public bool $allowed = true;
    public string $hash = 'problem-one';
    public ?int $latest = 500;
    public ?int $cursorOverride = null;
    public bool $cursorAvailable = true;
    public bool $includeCursor = true;
    public array $submissions = [];
    public array $unavailable = [];
    public array $reads = [];
    public ?Closure $onRead = null;
    public ?Closure $onPrepare = null;
    public array $prepared = [];
    public ?array $launchResult = null;
    public string $contextType = 'class';
    public string $contextId = 'class:7';
    public int $formalSubmissions = 0;
    public function actorId(): int { return $this->actor; }
    public function assertRole(string $role): void
    {
        if (! ($role === 'teach' ? $this->teach : $this->learn)) { throw new DomainException('역할 권한이 없습니다.'); }
    }
    public function contexts(bool $teaching): array { return [['type' => $this->contextType, 'id' => $this->contextId, 'revision' => 'rev1']]; }
    public function authorize(string $type, string $id, string $operation, ?int $learnerId = null): array
    {
        if (! $this->allowed || $type !== $this->contextType || $id !== $this->contextId
            || ($operation === 'participate' && $learnerId !== $this->actor)
            || ($operation !== 'participate' && ! $this->teach)) { throw new DomainException('현재 수업 권한이 없습니다.'); }
        return ['type' => $type, 'id' => $id, 'revision' => 'rev1'];
    }
    public function searchProblems(string $search): array { return []; }
    public function assertSupport(int $problemId, string $kind, string $contextType, string $contextId): void {}
    public function problem(int $problemId, string $contextType, string $contextId, ?int $submissionId = null): array
    {
        $this->reads[] = $submissionId;
        if ($this->onRead !== null) { ($this->onRead)($submissionId); }
        $id = $submissionId ?? $this->latest;
        if ($submissionId !== null && ! isset($this->submissions[$submissionId])) { throw new DomainException('본인 제출에 접근할 수 없습니다.'); }
        $result = ['problem' => ['id' => $problemId, 'title' => '덧셈', 'content_hash' => $this->hash],
            'submission' => $id === null || isset($this->unavailable[$id]) ? null : ($this->submissions[$id] ?? null)];
        if ($this->includeCursor) { $result['latest_own_submission_id'] = $submissionId === null && $this->cursorAvailable ? ($this->cursorOverride ?? $this->latest ?? 0) : null; }
        return $result;
    }
    public function problemUrl(int $problemId, string $contextType, string $contextId): string { return '/problems/' . $problemId . '?' . $contextType . '_id=' . substr($contextId, strlen($contextType) + 1); }
    public function prepareSubmissionDraft(int $problemId, string $contextType, string $contextId, string $language, string $source): array
    {
        $this->prepared[] = ['actor' => $this->actor, 'problem_id' => $problemId, 'context_type' => $contextType, 'context_id' => $contextId, 'language' => $language, 'source' => $source];
        if ($this->onPrepare !== null) { ($this->onPrepare)(); }
        return $this->launchResult ?? ['url' => '/submit/' . $problemId . '?' . $contextType . '_id=' . substr($contextId, strlen($contextType) + 1) . '&draft=HANDOFF_PRIVATE_TICKET_' . count($this->prepared),
            'language' => $language, 'expires_at' => time() + 300];
    }
    public function put(int $id, int $result, bool $processing, string $source = 'SUBMITTED_SOURCE_SECRET'): void
    {
        $this->submissions[$id] = ['id' => $id, 'problem_id' => 100, 'source' => $source, 'language_id' => 6,
            'language' => 'python', 'result_code' => $result, 'processing' => $processing, 'submitted_at' => gmdate('Y-m-d H:i:s')];
    }
}

$db = SqlWpdb::create();
$records = new WpdbRepository($db, true);
$management = new WpdbManagementStore($db, $records);
$platform = new SubmissionPlatform(); $platform->put(500, 4, false);
$service = new PrimmService($records, $platform, $management);
$events = static fn (): int => (int) $db->get_var('SELECT COUNT(*) FROM test_oj_primm_events');
$snapshot = static fn (): array => [$db->get_results('SELECT * FROM test_oj_primm_attempts ORDER BY id'), $db->get_results('SELECT * FROM test_oj_primm_events ORDER BY id')];
$version = static fn (int $id): int => (int) $records->findAttempt($id, $platform->actor)['version'];
$make = static function (string $key, bool $advance = true, array $patch = []) use ($management, $service): array {
    $data = array_replace(\OjPrimmTests\activity(\OjPrimmTests\lesson()), $patch); $data['practice_required'] = false;
    $activity = $management->version(null, $data, 'submission-fixture:' . $key);
    $view = $service->activity($activity['id']);
    if ($advance) {
        foreach (['predict', 'run', 'investigate', 'modify'] as $stage) {
            $view = $service->progress($activity['id'], ['expected_version' => $view['attempt']['version'], 'stage' => $stage,
                'responses' => [$stage => $stage === 'predict' ? '최초 예측은 3입니다.' : $stage . ' 관찰 기록'],
                'draft_code' => "print('my draft')\n", 'advance' => true]);
        }
    }
    return $view;
};
$assertSummary = static function (?array $summary): void {
    if ($summary === null) { return; }
    submissionCheck(array_diff(array_keys($summary), ['id', 'result_code', 'processing', 'label', 'submitted_at', 'verified_at', 'language', 'draft_matches', 'detail_url']) === [], 'Public submission summaries contain only receipt fields.');
    submissionCheck(! str_contains(json_encode($summary, JSON_THROW_ON_ERROR), 'SUBMITTED_SOURCE_SECRET') && ! array_key_exists('source', $summary), 'A submitted source is never included in the public receipt.');
};
$assertPrivate = static function () use ($db): void {
    $json = json_encode($db->get_results('SELECT state_json FROM test_oj_primm_attempts'), JSON_THROW_ON_ERROR)
        . json_encode($db->get_results('SELECT payload_json FROM test_oj_primm_events'), JSON_THROW_ON_ERROR);
    submissionCheck(! str_contains($json, 'SUBMITTED_SOURCE_SECRET'), 'Automatic linking stores no submitted source in attempts or events.');
};

$view = $make('main'); $id = $view['activity']['id'];
$idle = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'watch']);
submissionCheck($idle['attempt']['submission_id'] === null && ! $idle['attempt']['is_accepted'], 'An old accepted submission is not silently linked on first Make entry.');
$before = $snapshot();
$service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'watch']);
submissionCheck($snapshot() === $before, 'An unchanged idle automatic check does not write progress or events.');
$watch = $service->submissionWatch($id, ['expected_version' => $version($id)]);
submissionCheck($watch['make_submission']['available'] && $watch['make_submission']['status'] === 'watching'
    && $watch['make_submission']['watch']['active'] && $watch['attempt']['submission_id'] === null, 'Opening the judge starts a watch without importing the previous AC.');
$before = $snapshot();
$again = $service->submissionWatch($id, ['expected_version' => $version($id)]);
submissionCheck($snapshot() === $before && $again['make_submission']['watch'] === $watch['make_submission']['watch'], 'Repeated clicks preserve an active watch and create no duplicate progress event.');
$same = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'watch']);
submissionCheck($same['attempt']['submission_id'] === null && $snapshot() === $before, 'The baseline submission remains unlinked while waiting for a new submission.');

$platform->put(501, 0, true); $platform->latest = 501;
$pending = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'watch']);
submissionCheck($pending['attempt']['submission_id'] === 501 && $pending['make_submission']['status'] === 'processing'
    && ! $pending['attempt']['is_accepted'], 'A newly detected own submission is linked while judging is pending.');
$assertSummary($pending['make_submission']['submission']);
$assertSummary($pending['make_submission']['recent']);
submissionCheck(! str_contains(json_encode($pending, JSON_THROW_ON_ERROR), 'SUBMITTED_SOURCE_SECRET'), 'Neither receipt projection nor the rest of the student view exposes the submitted source.');
submissionCheck($pending['make_submission']['submission']['draft_matches'] === false, 'A differing submitted program is reported without changing the PRIMM draft.');
$before = $snapshot();
$service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'linked']);
submissionCheck($snapshot() === $before, 'An unchanged pending result does not bump the progress version or add an event.');
$platform->put(502, 4, false); $platform->latest = 502;
$platform->put(501, 6, false); $platform->reads = [];
$wrong = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'watch']);
submissionCheck($wrong['attempt']['submission_id'] === 501 && $wrong['attempt']['result_code'] === 6
    && $wrong['make_submission']['status'] === 'judged' && ! $wrong['attempt']['is_accepted'], 'A watched WA remains attached to its own ID when a different tab has a newer AC.');
submissionCheck(in_array(501, $platform->reads, true) && ! in_array(502, $platform->reads, true), 'Watching an already linked result explicitly reads that fixed submission ID.');
$platform->put(501, 4, false);
$accepted = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'linked']);
submissionCheck($accepted['attempt']['submission_id'] === 501 && $accepted['attempt']['is_accepted']
    && $accepted['make_submission']['status'] === 'accepted', 'The same linked submission can transition from WA to an actual AC.');
$before = $snapshot();
$service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'linked']);
submissionCheck($snapshot() === $before, 'Repeated terminal polling creates no duplicate writes.');
submissionCheck($accepted['attempt']['draft_code'] === "print('my draft')\n"
    && $accepted['attempt']['first_prediction'] === '최초 예측은 3입니다.'
    && $accepted['attempt']['responses']['investigate'] === 'investigate 관찰 기록', 'Linking preserves the draft, stage responses and immutable first prediction.');
$assertPrivate();
$reloaded = (new PrimmService(new WpdbRepository($db, true), $platform, new WpdbManagementStore($db, $records)))->activity($id);
submissionCheck($reloaded['attempt']['submission_id'] === 501 && $reloaded['attempt']['is_accepted'], 'A fresh service instance restores the linked result after refresh.');
$before = $snapshot();
$review = $service->activity($id, 'predict');
submissionCheck($review['review_only'] && ! $review['make_submission']['available'] && $review['make_submission']['status'] === 'unavailable'
    && $snapshot() === $before, 'Reviewing a completed earlier stage does not advertise writable Make submission controls or update the saved attempt.');

$explicit = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'latest']);
submissionCheck($explicit['attempt']['submission_id'] === 502, 'Only the explicit latest mode replaces the linked result with another own submission.');
$assertSummary($explicit['make_submission']['submission']);
$assertSummary($explicit['make_submission']['recent']);
$platform->put(503, 0, true, "print('my draft')\r\n"); $platform->latest = 503;
$matched = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'latest']);
submissionCheck($matched['make_submission']['submission']['draft_matches'] === true, 'CRLF and LF describe the same saved program for draft comparison.');
$platform->put(504, 4, false, ''); $platform->latest = 504;
$legacy = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'latest']);
submissionCheck($legacy['make_submission']['submission']['draft_matches'] === null, 'A legacy receipt without a saved user source is explicitly incomparable.');
$before = $snapshot(); $platform->latest = null;
$missing = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'latest']);
submissionCheck($missing['attempt']['submission_id'] === 504 && $missing['attempt']['is_accepted'] && $snapshot() === $before, 'An unavailable latest source does not erase or reinterpret previously verified evidence.');
submissionCheck($missing['make_submission']['status'] === 'unavailable' && $missing['make_submission']['available'], 'An unavailable latest result is presented as retryable without claiming there are no submissions.');
$platform->unavailable[504] = true;
$missingLinked = $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'linked']);
submissionCheck($missingLinked['make_submission']['status'] === 'unavailable' && $missingLinked['attempt']['submission_id'] === 504
    && $missingLinked['attempt']['is_accepted'] && $snapshot() === $before, 'A temporarily unavailable linked receipt preserves the existing evidence with no write.');
unset($platform->unavailable[504]);

$platform->latest = 504;
$newWatch = $service->submissionWatch($id, ['expected_version' => $version($id)]);
submissionCheck($newWatch['attempt']['submission_id'] === null && ! $newWatch['attempt']['is_accepted']
    && $newWatch['make_submission']['watch']['active'], 'A deliberate new watch clears the previous linked receipt and takes a fresh baseline.');
$state = json_decode((string) $db->get_var('SELECT state_json FROM test_oj_primm_attempts WHERE activity_id=' . $id), true, 32, JSON_THROW_ON_ERROR);
$aged = false;
$ageWatch = static function (array &$node) use (&$ageWatch, &$aged): void {
    foreach ($node as $key => &$value) {
        if ($key === 'started_at' && is_string($value)) { $value = gmdate('Y-m-d H:i:s', time() - 721); $aged = true; }
        elseif (is_array($value)) { $ageWatch($value); }
    }
    unset($value);
};
$ageWatch($state);
submissionCheck($aged, 'The persisted watch has a start time suitable for bounded recovery.');
$db->update('test_oj_primm_attempts', ['state_json' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['activity_id' => $id]);
$expired = $service->activity($id);
submissionCheck(! $expired['make_submission']['watch']['active'], 'A watch stops advertising automatic polling after twelve minutes.');
$platform->put(505, 0, true); $platform->latest = 505;
$restarted = $service->submissionWatch($id, ['expected_version' => $version($id)]);
submissionCheck($restarted['make_submission']['watch']['active'] && $restarted['attempt']['submission_id'] === null, 'An expired watch may be deliberately restarted with a new baseline.');
$before = $snapshot();
$service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'watch']);
submissionCheck($snapshot() === $before && $records->findAttempt($id, 20)['submission_id'] === null, 'Restarting uses the currently latest submission as its new baseline.');

$before = $snapshot();
foreach ([['mode' => 'watch', 'expected_version' => $version($id) - 1], ['mode' => 'unsafe', 'expected_version' => $version($id)], ['mode' => 'watch']] as $invalid) {
    submissionRejects(fn () => $service->submissionCheck($id, $invalid), 'Invalid modes and stale or missing versions must fail closed.');
}
submissionCheck($snapshot() === $before, 'Rejected requests do not change any attempt or event.');
$platform->learn = false;
submissionRejects(fn () => $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'watch']), 'A learner capability is required on every poll.');
$platform->learn = true; $platform->allowed = false;
submissionRejects(fn () => $service->submissionWatch($id, ['expected_version' => $version($id)]), 'Revoked membership blocks new watches.');
$platform->allowed = true; $platform->hash = 'changed-problem';
submissionRejects(fn () => $service->submissionCheck($id, ['expected_version' => $version($id), 'mode' => 'watch']), 'Changed problem content cannot reuse an old activity for linking.');
$platform->hash = 'problem-one';

$early = $make('early', false);
submissionRejects(fn () => $service->submissionCheck($early['activity']['id'], ['expected_version' => 0, 'mode' => 'latest']), 'A learner cannot attach an AC before reaching Make.');
$deniedView = $make('deny-after-read'); $deniedId = $deniedView['activity']['id']; $before = $snapshot();
$platform->onRead = static function (?int $requested) use ($platform): void { $platform->onRead = null; $platform->allowed = false; };
submissionRejects(fn () => $service->submissionWatch($deniedId, ['expected_version' => $version($deniedId)]), 'Membership is rechecked after reading the recent submission and before saving.');
submissionCheck($snapshot() === $before, 'A mid-query membership revocation leaves no watch or receipt behind.');
$platform->allowed = true;

$firstView = $make('first-formal-submission'); $firstId = $firstView['activity']['id'];
$platform->latest = null; $platform->cursorOverride = 0;
$firstWatch = $service->submissionWatch($firstId, ['expected_version' => $version($firstId)]);
submissionCheck($firstWatch['make_submission']['watch']['active'] && $firstWatch['attempt']['submission_id'] === null,
    'A confirmed empty own-submission cursor starts an active watch for a first-time submitter.');
$platform->put(700, 0, true); $platform->latest = 700; $platform->cursorOverride = 700;
$firstPending = $service->submissionCheck($firstId, ['expected_version' => $version($firstId), 'mode' => 'watch']);
submissionCheck($firstPending['attempt']['submission_id'] === 700 && $firstPending['make_submission']['status'] === 'processing',
    'The first formal submission after cursor zero is automatically linked.');

$hiddenView = $make('hidden-old-source'); $hiddenId = $hiddenView['activity']['id'];
$platform->put(799, 4, false); $platform->latest = 799; $platform->cursorOverride = 800;
$hiddenWatch = $service->submissionWatch($hiddenId, ['expected_version' => $version($hiddenId)]);
submissionCheck($hiddenWatch['make_submission']['watch']['active'] && $hiddenWatch['make_submission']['recent']['id'] === 799,
    'An own result cursor takes precedence over an older visible source when the latest source is unavailable.');
$platform->put(800, 4, false); $platform->latest = 800;
$becameVisible = $service->submissionCheck($hiddenId, ['expected_version' => $version($hiddenId), 'mode' => 'watch']);
submissionCheck($becameVisible['attempt']['submission_id'] === null && ! $becameVisible['attempt']['is_accepted'],
    'An old hidden source becoming visible cannot be mistaken for a new submission beyond the baseline cursor.');
$platform->put(801, 0, true); $platform->latest = 801; $platform->cursorOverride = 801;
$newVisible = $service->submissionCheck($hiddenId, ['expected_version' => $version($hiddenId), 'mode' => 'watch']);
submissionCheck($newVisible['attempt']['submission_id'] === 801, 'A genuinely new visible submission beyond the hidden-source baseline is linked.');
$platform->cursorOverride = null;

$unknownView = $make('old-api-unknown-baseline'); $unknownId = $unknownView['activity']['id'];
$platform->includeCursor = false; $platform->latest = null;
$before = $snapshot();
$unknownWatch = $service->submissionWatch($unknownId, ['expected_version' => $version($unknownId)]);
submissionCheck(! $unknownWatch['make_submission']['watch']['active'], 'An older API without a visible source or a cursor cannot invent a zero baseline.');
submissionCheck($unknownWatch['make_submission']['status'] === 'unavailable' && $unknownWatch['make_submission']['available']
    && $snapshot() === $before, 'An unknown watch baseline is retryable and cannot clear existing progress or append an event.');
$platform->put(900, 4, false); $platform->latest = 900;
$unknownLater = $service->submissionCheck($unknownId, ['expected_version' => $version($unknownId), 'mode' => 'watch']);
submissionCheck($unknownLater['attempt']['submission_id'] === null, 'An old source appearing after an unknown baseline is never silently imported.');
$legacyWatch = $service->submissionWatch($unknownId, ['expected_version' => $version($unknownId)]);
submissionCheck($legacyWatch['make_submission']['watch']['active'], 'An older API can safely use a positively identified visible own submission as a baseline.');
$platform->put(901, 0, true); $platform->latest = 901;
$legacyPending = $service->submissionCheck($unknownId, ['expected_version' => $version($unknownId), 'mode' => 'watch']);
submissionCheck($legacyPending['attempt']['submission_id'] === 901, 'A newer own submission remains auto-linkable through the older visible-source fallback.');
$platform->includeCursor = true;

$legacyView = $make('legacy-linked-id'); $legacyId = $legacyView['activity']['id'];
$legacyState = json_decode((string) $db->get_var('SELECT state_json FROM test_oj_primm_attempts WHERE activity_id=' . $legacyId), true, 32, JSON_THROW_ON_ERROR);
unset($legacyState['make_submission_watch'], $legacyState['make_submission_recent'], $legacyState['make_submission_linked']);
$legacyState = array_replace($legacyState, ['submission_id' => 950, 'result_code' => 4, 'is_accepted' => true, 'verified_at' => '2026-10-24 01:02:03']);
$db->update('test_oj_primm_attempts', ['state_json' => json_encode($legacyState, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['activity_id' => $legacyId]);
$before = $snapshot();
$legacyRestored = $service->activity($legacyId);
submissionCheck($legacyRestored['make_submission']['submission']['id'] === 950 && $legacyRestored['make_submission']['status'] === 'accepted'
    && $legacyRestored['make_submission']['submission']['verified_at'] === '2026-10-24 01:02:03'
    && $legacyRestored['make_submission']['submission']['draft_matches'] === null, 'A legacy attempt with only a verified ID/result restores its evidence without inventing a source comparison.');
submissionCheck($snapshot() === $before, 'Restoring an older linked receipt is a projection and never rewrites progress.');
$assertSummary($legacyRestored['make_submission']['submission']);
$platform->put(950, 4, false); $platform->reads = [];
$legacyRefresh = $service->submissionCheck($legacyId, ['expected_version' => $version($legacyId), 'mode' => 'linked']);
submissionCheck($legacyRefresh['attempt']['submission_id'] === 950 && $legacyRefresh['attempt']['is_accepted']
    && $platform->reads === [950], 'An explicit refresh enriches a restored legacy receipt through its exact original own ID.');

$raceView = $make('draft-race'); $raceId = $raceView['activity']['id']; $raceVersion = $version($raceId);
$platform->onRead = static function (?int $requested) use ($platform, $service, $raceId, $raceVersion): void {
    $platform->onRead = null;
    $service->progress($raceId, ['expected_version' => $raceVersion, 'stage' => 'make', 'responses' => ['make' => '다른 탭의 최신 성찰'], 'draft_code' => 'OTHER_TAB_DRAFT', 'advance' => false]);
};
submissionRejects(fn () => $service->submissionWatch($raceId, ['expected_version' => $raceVersion]), 'An automatic watch cannot overwrite a concurrent draft save.');
$raceSaved = $records->findAttempt($raceId, 20);
submissionCheck($raceSaved['draft_code'] === 'OTHER_TAB_DRAFT' && $raceSaved['responses']['make'] === '다른 탭의 최신 성찰'
    && $raceSaved['first_prediction'] === '최초 예측은 3입니다.', 'Compare-and-swap preserves the newer tab and the original first prediction.');

$withdrawnView = $make('withdrawn'); $withdrawnId = $withdrawnView['activity']['id'];
$management->update($withdrawnId, 1, ['status' => 'withdrawn']); $before = $snapshot();
submissionRejects(fn () => $service->submissionCheck($withdrawnId, ['expected_version' => $version($withdrawnId), 'mode' => 'latest']), 'Withdrawn activities deny automatic or explicit result linking.');
submissionCheck($snapshot() === $before, 'Withdrawal cannot append result events.');
$teacherView = $make('teacher-preview'); $teacherId = $teacherView['activity']['id']; $platform->teach = true;
submissionRejects(fn () => $service->submissionWatch($teacherId, ['expected_version' => $version($teacherId)]), 'A teacher preview remains read-only even if that account also holds the learner role.');
$platform->teach = false;

$launchView = $make('saved-draft-launch'); $launchId = $launchView['activity']['id'];
$program = "# 한글 · \"따옴표\" & <tag>\nmessage = '</textarea><script>noop()</script>'\n\tprint(message)\n\n";
$savedDraft = $service->progress($launchId, ['expected_version' => $version($launchId), 'stage' => 'make', 'responses' => ['make' => '내 제작 성찰'], 'draft_code' => $program, 'advance' => false]);
$launched = $service->submissionLaunch($launchId, ['expected_version' => $version($launchId), 'draft_code' => 'UNTRUSTED_BROWSER_CODE', 'language' => 'java']);
$prepared = $platform->prepared[array_key_last($platform->prepared)];
submissionCheck($prepared === ['actor' => 20, 'problem_id' => 100, 'context_type' => 'class', 'context_id' => 'class:7', 'language' => 'python', 'source' => $program], 'Editor prefill uses exactly the server-saved program and immutable activity language, ignoring forged browser fields.');
submissionCheck($launched['attempt']['draft_code'] === $program && $launched['attempt']['responses']['make'] === '내 제작 성찰'
    && $launched['attempt']['first_prediction'] === '최초 예측은 3입니다.', 'Preparing the editor preserves whitespace, Unicode, HTML-like text, responses and the first prediction.');
submissionCheck(array_keys($launched['submission_launch']) === ['url', 'language', 'expires_at'] && $launched['submission_launch']['language'] === 'python'
    && $launched['submission_launch']['expires_at'] > time() && str_starts_with($launched['submission_launch']['url'], '/submit/100?class_id=7&draft='), 'The launch response contains only an expiring native-editor link, language and Unix expiry.');
submissionCheck($launched['attempt']['version'] === $savedDraft['attempt']['version'] + 1 && $launched['make_submission']['watch']['active'], 'Launch establishes the watch once and uses the actual post-CAS progress version.');
$before = $snapshot(); $oldWatch = $launched['make_submission']['watch'];
$relaunched = $service->submissionLaunch($launchId, ['expected_version' => $version($launchId)]);
submissionCheck($snapshot() === $before && $relaunched['make_submission']['watch'] === $oldWatch
    && $relaunched['submission_launch']['url'] !== $launched['submission_launch']['url'], 'An active unlinked watch can issue a fresh prefill link without resetting its baseline or writing progress again.');
submissionCheck(! array_key_exists('submission_launch', $service->activity($launchId))
    && ! str_contains(json_encode($snapshot(), JSON_THROW_ON_ERROR), 'HANDOFF_PRIVATE_TICKET_'), 'Ephemeral handoff references never persist in attempts, events or ordinary activity views.');
$checkedLaunch = $service->submissionCheck($launchId, ['expected_version' => $version($launchId), 'mode' => 'watch']);
submissionCheck(! array_key_exists('submission_launch', $checkedLaunch) && $platform->formalSubmissions === 0, 'Watching or prefilling a program cannot create an official submission or replay a handoff URL.');
$before = $snapshot(); $callsBefore = count($platform->prepared);
submissionRejects(fn () => $service->submissionLaunch($launchId, ['expected_version' => $version($launchId) - 1]), 'A stale browser version cannot prepare another editor.');
submissionCheck($snapshot() === $before && count($platform->prepared) === $callsBefore, 'Stale launch requests do not create a handoff or overwrite the saved program.');

$baseView = $make('manual-python-launch', true, ['lesson' => null, 'demo_code' => 'sum']);
$service->submissionLaunch($baseView['activity']['id'], ['expected_version' => $version($baseView['activity']['id'])]);
submissionCheck($platform->prepared[array_key_last($platform->prepared)]['language'] === 'python', 'Legacy built-in sum or condition material has an explicit Python transfer language.');
$unknownLesson = \OjPrimmTests\lesson(); $unknownLesson['language'] = 'ruby';
$unknownLanguage = $make('unknown-language', true, ['lesson' => $unknownLesson]); $before = $snapshot(); $callsBefore = count($platform->prepared);
submissionRejects(fn () => $service->submissionLaunch($unknownLanguage['activity']['id'], ['expected_version' => $version($unknownLanguage['activity']['id'])]), 'An unknown activity language cannot silently fall back to another compiler.');
submissionCheck($snapshot() === $before && count($platform->prepared) === $callsBefore, 'Invalid languages are rejected before changing the watch or forwarding code.');
$emptyLaunch = $make('empty-launch'); $emptyId = $emptyLaunch['activity']['id'];
$service->progress($emptyId, ['expected_version' => $version($emptyId), 'stage' => 'make', 'responses' => [], 'draft_code' => " \n\t", 'advance' => false]);
$before = $snapshot(); $callsBefore = count($platform->prepared);
submissionRejects(fn () => $service->submissionLaunch($emptyId, ['expected_version' => $version($emptyId)]), 'A blank program must be written and saved before opening a prefilled editor.');
submissionCheck($snapshot() === $before && count($platform->prepared) === $callsBefore, 'Empty programs do not change watch state or create handoff records.');

$duringPrepare = $make('launch-draft-race'); $duringId = $duringPrepare['activity']['id'];
$platform->onPrepare = static function () use ($platform, $service, $duringId, $version): void {
    $platform->onPrepare = null;
    $service->progress($duringId, ['expected_version' => $version($duringId), 'stage' => 'make', 'responses' => ['make' => '새로 저장한 성찰'], 'draft_code' => 'LATEST_DRAFT_AFTER_PREPARE', 'advance' => false]);
};
submissionRejects(fn () => $service->submissionLaunch($duringId, ['expected_version' => $version($duringId)]), 'A draft saved by another tab during preparation prevents returning an obsolete prefill link.');
submissionCheck($records->findAttempt($duringId, 20)['draft_code'] === 'LATEST_DRAFT_AFTER_PREPARE', 'A launch race preserves the other tab\'s newest program.');
$revokedLaunch = $make('launch-membership-race'); $revokedId = $revokedLaunch['activity']['id'];
$platform->onPrepare = static function () use ($platform): void { $platform->onPrepare = null; $platform->allowed = false; };
submissionRejects(fn () => $service->submissionLaunch($revokedId, ['expected_version' => $version($revokedId)]), 'Membership revoked during preparation blocks delivery of the prefill URL.');
$platform->allowed = true;
$withdrawLaunch = $make('launch-withdraw-race'); $withdrawId = $withdrawLaunch['activity']['id'];
$platform->onPrepare = static function () use ($platform, $management, $withdrawId): void { $platform->onPrepare = null; $management->update($withdrawId, 1, ['status' => 'withdrawn']); };
submissionRejects(fn () => $service->submissionLaunch($withdrawId, ['expected_version' => $version($withdrawId)]), 'Withdrawal during preparation blocks delivery without reviving the activity.');
$changedProblem = $make('launch-problem-race'); $changedId = $changedProblem['activity']['id'];
$platform->onPrepare = static function () use ($platform): void { $platform->onPrepare = null; $platform->hash = 'changed-while-preparing'; };
submissionRejects(fn () => $service->submissionLaunch($changedId, ['expected_version' => $version($changedId)]), 'A problem changed during platform preparation invalidates the launch response.');
$platform->hash = 'problem-one';
$actorLaunch = $make('launch-actor-race'); $actorId = $actorLaunch['activity']['id'];
$platform->onPrepare = static function () use ($platform): void { $platform->onPrepare = null; $platform->actor = 21; };
submissionRejects(fn () => $service->submissionLaunch($actorId, ['expected_version' => $version($actorId)]), 'Changing the current actor during preparation cannot deliver the previous learner\'s source link.');
$platform->actor = 20;

$invalidLaunch = $make('invalid-launch-response'); $invalidId = $invalidLaunch['activity']['id'];
$platform->launchResult = ['url' => '/submit/100?draft=expired', 'language' => 'python', 'expires_at' => time() - 1];
submissionRejects(fn () => $service->submissionLaunch($invalidId, ['expected_version' => $version($invalidId)]), 'An already expired editor URL cannot be returned as successful.');
$platform->launchResult = null;
$platform->contextType = 'group'; $platform->contextId = 'group:8';
$groupLesson = \OjPrimmTests\lesson(); $groupLesson['language'] = 'cpp';
$groupView = $make('group-cpp-launch', true, ['context_type' => 'group', 'context_id' => 'group:8', 'lesson' => $groupLesson]);
$groupId = $groupView['activity']['id'];
$service->submissionLaunch($groupId, ['expected_version' => $version($groupId)]);
$groupPrepared = $platform->prepared[array_key_last($platform->prepared)];
submissionCheck($groupPrepared['context_type'] === 'group' && $groupPrepared['context_id'] === 'group:8' && $groupPrepared['language'] === 'cpp', 'Group identity and the immutable C++ activity language reach the public prefill gateway unchanged.');
submissionCheck($platform->formalSubmissions === 0 && ! str_contains(json_encode($snapshot(), JSON_THROW_ON_ERROR), 'HANDOFF_PRIVATE_TICKET_'), 'Successful and rejected prefill operations never store launch references or create formal submissions.');
$platform->contextType = 'class'; $platform->contextId = 'class:7';
$assertPrivate();
submissionCheck(! $db->pdo->inTransaction(), 'All successful and rejected link operations leave the transaction closed.');
fwrite(STDOUT, "Submission auto-link SQL integration: {$checks} checks passed\n");
