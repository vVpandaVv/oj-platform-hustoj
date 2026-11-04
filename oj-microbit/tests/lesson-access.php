<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use OjMicrobit\Support\ClassLessonAccess;
use OjMicrobit\Support\OperationError;

final class LessonGatewayFixture implements Api\AddonLearningContextGatewayV1, Api\AddonAssignmentRosterGatewayV1
{
    public array $calls = []; public array $ids = [7, 8]; public string $next = ''; public bool $enabled = true; public bool $allowed = true; public bool $available = true; public bool $rosterAvailable = true; public string $snapshotType = 'class'; public int $rosterSize = 2;
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 { throw new RuntimeException('Unused teaching route'); }
    public function listLearningContexts(Api\AddonLearningContextQueryV1 $query): Api\AddonContextPageV1
    {
        $this->calls[] = $query; if (!$this->available) { throw new RuntimeException('PRIVATE PROVIDER FAILURE'); }
        return new Api\AddonContextPageV1(true, array_map(fn ($id) => new Api\AddonContextSummaryV1('class', 'class:' . $id, '실습 수업 ' . $id, $this->enabled ? 'active' : 'inactive', 'context-r1', ['enabled' => $this->enabled]), $this->ids), $this->next, null);
    }
    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1
    {
        $this->calls[] = $request; if (!$this->available) { throw new RuntimeException('PRIVATE AUTH FAILURE'); }
        $snapshot = $this->allowed ? new Api\AddonContextSnapshotV1($this->snapshotType, $request->contextId(), '실습 수업 7', $this->enabled ? 'active' : 'inactive', 'context-r1', $request->operation() === 'participate' ? 'participant' : 'owner', ['enabled' => $this->enabled]) : null;
        return new Api\AddonContextAccessDecisionV1($this->allowed, $this->allowed ? '' : 'addon_context_denied', $snapshot, str_repeat('a', 32));
    }
    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 { throw new RuntimeException('Unused snapshot'); }
    public function listAssignmentLearners(Api\AddonAssignmentRosterQueryV1 $query): Api\AddonAssignmentLearnerPageV1
    {
        $this->calls[] = $query;
        if (!$this->rosterAvailable) { return new Api\AddonAssignmentLearnerPageV1(false, [], '', null, new Api\AddonApiProblemV1('addon_context_unavailable', 'PRIVATE ROSTER FAILURE', true)); }
        $items = array_map(static fn ($id) => new Api\AddonAssignmentLearnerV1($id, 'member-r1', '학생 ' . $id, '번호 ' . $id), range(20, 20 + $this->rosterSize - 1));
        return new Api\AddonAssignmentLearnerPageV1(true, $items, $query->cursor() === '' ? 'opaque-next' : '', 'roster-r1', null);
    }
}
$gateway = new LessonGatewayFixture(); $access = new ClassLessonAccess($gateway);
verify($access->learning(20) === [['id' => 7, 'title' => '실습 수업 7'], ['id' => 8, 'title' => '실습 수업 8']], 'Student course labels use one complete public learning-context projection');
verify(count($gateway->calls) === 1 && $gateway->calls[0]->types() === ['class'] && $gateway->calls[0]->limit() === 100, 'The complete course projection is bounded at 100 with no per-assignment calls');
$gateway->next = 'class:8'; verify($access->learning(20) === null, 'An incomplete course projection never silently grants a partial scope'); $gateway->next = '';
$gateway->ids = [7, 7]; verify($access->learning(20) === null, 'Duplicate course scope is rejected'); $gateway->ids = [7];
$gateway->enabled = false; verify($access->learning(20) === null, 'Disabled course summaries cannot enter active learner scope');
verify($access->title(10, 7, true) === '실습 수업 7', 'An authoritative manager decision retains access to the inactive course label and history');
throws(fn () => $access->title(20, 7, false), OperationError::class, 'A disabled lesson never authorizes a student to participate');
try { $access->roster(10, 7, '', 20, null, null); throw new RuntimeException('Expected inactive roster denial'); }
catch (OperationError $e) { verify($e->httpStatus === 403 && str_contains($e->getMessage(), '보관된 제출'), 'Inactive roster points the teacher to retained submission history instead of fabricating an empty class'); }
$gateway->enabled = true; $gateway->calls = [];
$page = $access->roster(10, 7, '', 20, null, null);
verify($page['items'] === [['userId' => 20, 'displayName' => '학생 20', 'managementName' => '번호 20'], ['userId' => 21, 'displayName' => '학생 21', 'managementName' => '번호 21']] && $page['nextCursor'] === 'opaque-next', 'Report names and user identifiers come from the real public roster DTO');
verify($page['contextRevision'] === 'context-r1' && $page['rosterRevision'] === 'roster-r1', 'Report carries both context and roster revision state');
verify(count($gateway->calls) === 2 && $gateway->calls[0]->operation() === 'report' && $gateway->calls[0]->actorUserId() === 10, 'Each report freshly authorizes the current teacher report scope');
$query = $gateway->calls[1]; verify($query instanceof Api\AddonAssignmentRosterQueryV1 && $query->contextType() === 'class' && $query->contextId() === 'class:7' && $query->expectedContextRevision() === 'context-r1' && $query->expectedRosterRevision() === null && $query->limit() === 20, 'Roster query uses the current authorized course revision and one bounded page');
$gateway->calls = []; $next = $access->roster(10, 7, 'opaque-next', 20, $page['contextRevision'], $page['rosterRevision']);
verify($next['nextCursor'] === null && $gateway->calls[1]->cursor() === 'opaque-next' && $gateway->calls[1]->expectedRosterRevision() === 'roster-r1', 'Opaque roster cursor and revision propagate without client student IDs');
throws(fn () => $access->roster(10, 7, 'opaque-next', 20, 'old-context', 'roster-r1'), OperationError::class, 'Changed course revision cannot mix report pages');
throws(fn () => $access->roster(10, 7, 'opaque-next', 20, 'context-r1', null), InvalidArgumentException::class, 'Cursor without roster revision is rejected before the gateway');
throws(fn () => $access->roster(10, 7, '', 51, null, null), InvalidArgumentException::class, 'Teacher roster UI pages are capped at fifty');
$gateway->rosterSize = 21; throws(fn () => $access->roster(10, 7, '', 20, null, null), OperationError::class, 'An oversized provider page is never exposed as a trusted SQL result scope'); $gateway->rosterSize = 2;
$gateway->rosterAvailable = false;
try { $access->roster(10, 7, '', 20, null, null); throw new RuntimeException('Expected unavailable'); }
catch (OperationError $e) { verify($e->httpStatus === 503 && !str_contains($e->getMessage(), 'PRIVATE'), 'Provider failures retain an explicit safe operational error rather than an empty roster'); }
$gateway->rosterAvailable = true; $gateway->allowed = false;
throws(fn () => $access->title(10, 7, true), OperationError::class, 'A former source author or teacher without current management is denied');
throws(fn () => $access->roster(10, 7, '', 20, null, null), OperationError::class, 'Revoked report authority is rechecked before reading names');
$gateway->allowed = true; $gateway->snapshotType = 'group'; throws(fn () => $access->title(10, 7, true), OperationError::class, 'Same numeric group ID cannot substitute for a course snapshot');
$gateway->snapshotType = 'class'; $gateway->available = false;
verify($access->learning(20) === null, 'An unavailable context provider cannot widen the student course list');
try { $access->title(10, 7, true); throw new RuntimeException('Expected denied'); }
catch (OperationError $e) { verify(!str_contains($e->getMessage(), 'PRIVATE'), 'Context failure details and secret provider strings never enter user guidance'); }
$absent = new ClassLessonAccess(null); verify($absent->learning(20) === null, 'A missing optional roster provider does not break public practice');
throws(fn () => $absent->roster(10, 7, '', 20, null, null), OperationError::class, 'A missing roster provider cannot expose another class results');
echo '[lesson-access] ' . $testChecks . " checks passed (real Addon roster/context DTOs with controlled gateway boundary; no platform private tables)\n";
