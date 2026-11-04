<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use OjMicrobit\Support\GroupAudienceAccess;
use OjMicrobit\Support\OperationError;

final class GroupGatewayFixture implements Api\AddonLearningContextGatewayV1
{
    public string $contextType = 'group'; public array $calls = []; public bool $available = true; public bool $enabled = true; public bool $allowed = true; public string $next = ''; public array $ids = [7, 8];
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1
    {
        $this->calls[] = $query; if (!$this->available) { throw new RuntimeException('PRIVATE PROVIDER FAILURE'); }
        return $this->page();
    }
    public function listLearningContexts(Api\AddonLearningContextQueryV1 $query): Api\AddonContextPageV1
    {
        $this->calls[] = $query; if (!$this->available) { throw new RuntimeException('PRIVATE PROVIDER FAILURE'); }
        return $this->page();
    }
    private function page(): Api\AddonContextPageV1
    {
        $items = array_map(fn ($id) => new Api\AddonContextSummaryV1($this->contextType, $this->contextType . ':' . $id, '그룹(학급) ' . $id, $this->enabled ? 'active' : 'inactive', 'revision-1', ['enabled' => $this->enabled]), $this->ids);
        return new Api\AddonContextPageV1(true, $items, $this->next, null);
    }
    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1
    {
        $this->calls[] = $request; if (!$this->available) { throw new RuntimeException('PRIVATE PROVIDER FAILURE'); }
        $snapshot = $this->allowed ? new Api\AddonContextSnapshotV1($this->contextType, $request->contextId(), '그룹(학급)', $this->enabled ? 'active' : 'inactive', 'revision-1', $request->operation() === 'manage' ? 'owner' : 'participant', ['enabled' => $this->enabled]) : null;
        return new Api\AddonContextAccessDecisionV1($this->allowed, $this->allowed ? '' : 'addon_context_denied', $snapshot, str_repeat('a', 32));
    }
    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 { throw new RuntimeException('Unused snapshot'); }
}
$gateway = new GroupGatewayFixture(); $access = new GroupAudienceAccess($gateway);
$teaching = $access->teaching(10, 'group:1', 20);
verify($teaching['available'] && $teaching['items'][0] === ['id' => 7, 'contextId' => 'group:7', 'title' => '그룹(학급) 7', 'revision' => 'revision-1', 'lifecycleState' => 'active'], 'Teacher class projection preserves public context identity and label');
verify($gateway->calls[0]->actorUserId() === 10 && $gateway->calls[0]->types() === ['group'] && $gateway->calls[0]->cursor() === 'group:1' && $gateway->calls[0]->limit() === 20, 'Class choices use the existing bounded teaching gateway and opaque cursor');
throws(fn () => $access->teaching(10, '', 51), InvalidArgumentException::class, 'Class choice page capped at fifty');
throws(fn () => $access->teaching(10, "bad\ncursor", 20), InvalidArgumentException::class, 'Control characters cannot enter an opaque class cursor');
$gateway->calls = [];
verify($access->learningIds(20) === [7, 8] && count($gateway->calls) === 1 && $gateway->calls[0] instanceof Api\AddonLearningContextQueryV1 && $gateway->calls[0]->limit() === 100, 'Student membership is one bounded projection, with no per-exercise authorization loop');
$gateway->calls = []; $access->requireParticipant(20, [7, 8]);
verify(count($gateway->calls) === 2 && $gateway->calls[1] instanceof Api\AddonContextAccessRequestV1 && $gateway->calls[1]->operation() === 'participate' && $gateway->calls[1]->actorUserId() === 20 && $gateway->calls[1]->contextId() === 'group:7', 'Direct access adds one fresh participate decision to the current class projection');
$gateway->calls = []; $access->requireManaged(10, [7, 8]);
verify(count($gateway->calls) === 2 && $gateway->calls[0]->operation() === 'manage' && $gateway->calls[1]->contextId() === 'group:8', 'Each selected target is staff-authorized at publication time');
$gateway->ids = range(1, 100); verify(count($access->learningIds(20)) === 100, 'A complete one-hundred-class projection is accepted');
$gateway->next = 'group:100'; verify($access->learningIds(20) === null, 'An incomplete provider page never silently grants the first one hundred classes');
$gateway->next = ''; $gateway->ids = [7, 7]; verify($access->learningIds(20) === null, 'Duplicate or malformed provider scope fails closed');
$gateway->ids = [7]; $gateway->enabled = false;
verify($access->learningIds(20) === null, 'Inactive classes cannot enter the learner SQL scope');
throws(fn () => $access->requireManaged(10, [7]), OperationError::class, 'Disabled class cannot be selected as an active distribution target');
$gateway->enabled = true; $gateway->allowed = false;
throws(fn () => $access->requireParticipant(20, [7]), OperationError::class, 'A fresh denied decision revokes a formerly listed participant');
$gateway->allowed = true; $gateway->contextType = 'class';
verify($access->learningIds(20) === null && !$access->teaching(10, '', 20)['available'], 'A lesson result cannot impersonate an identically numbered school group');
throws(fn () => $access->requireManaged(10, [7]), OperationError::class, 'A decision for another context namespace cannot grant group management');
$gateway->contextType = 'group'; $gateway->available = false;
verify($access->learningIds(20) === null && !$access->teaching(10, '', 20)['available'], 'Provider failures expose unavailability, never an assumed site assignment');
try { $access->requireParticipant(20, [7]); throw new RuntimeException('Expected denied'); }
catch (OperationError $error) { verify($error->httpStatus === 403 && $error->fieldPath === 'exercise.audience.groupIds' && !str_contains($error->getMessage(), 'PRIVATE'), 'Class denial exposes a safe actionable typed error without provider details'); }
$absent = new GroupAudienceAccess(null);
verify($absent->learningIds(20) === null && $absent->teaching(10, '', 20) === ['available' => false, 'items' => [], 'nextCursor' => null], 'Missing public provider retains an explicit unavailable state');
throws(fn () => $absent->requireManaged(10, [7]), OperationError::class, 'Missing class provider cannot publish a class exercise');

use OjMicrobit\Domain\ExerciseSchema;
verify(ExerciseSchema::audience(['mode' => 'groups', 'groupIds' => [9, 7]]) === ['mode' => 'groups', 'groupIds' => [7, 9]], 'Group audience canonicalizes IDs in its own namespace');
verify(ExerciseSchema::audience(['mode' => 'classes', 'classIds' => [7]]) === ['mode' => 'classes', 'classIds' => [7]], 'Legacy lesson IDs remain unchanged rather than reinterpreted as group IDs');
foreach ([['mode' => 'groups', 'groupIds' => []], ['mode' => 'groups', 'groupIds' => [7, 7]], ['mode' => 'groups', 'groupIds' => ['7']], ['mode' => 'groups', 'groupIds' => range(1, 51)], ['mode' => 'groups', 'groupIds' => [7], 'classIds' => [7]], ['mode' => 'site', 'classIds' => [], 'groupIds' => [7]], ['mode' => 'classes', 'classIds' => [7], 'groupIds' => [7]]] as $invalid) {
    throws(fn () => ExerciseSchema::audience($invalid), InvalidArgumentException::class, 'Malformed or mixed group/lesson scopes cannot widen audience access');
}
echo '[groups-access] ' . $testChecks . " checks passed (real public Addon API value objects, gateway boundary fixtures; no private platform tables)\n";
