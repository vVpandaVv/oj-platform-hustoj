<?php
declare(strict_types=1);
use CodingPanda\OjPlatform\AddonApi\V1 as Api;
// Implements only the published gateway contract; no platform storage or private classes.
final class OclTestGateway implements Api\AddonAssignmentRosterGatewayV1 {
    public bool $failed = false;
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 { throw new LogicException('unused'); }
    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 { throw new LogicException('unused'); }
    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1 {
        $allowed = $request->actorUserId() === 1 && $request->contextId() === 'own' && ($request->learnerUserId() === null || in_array($request->learnerUserId(), range(1000,1099), true));
        return new Api\AddonContextAccessDecisionV1($allowed, $allowed ? '' : 'addon_context_denied',
            $allowed ? new Api\AddonContextSnapshotV1('class', 'own', 'Test class', 'active', 'context-r1', 'teacher') : null, 'test-context');
    }
    public function listAssignmentLearners(Api\AddonAssignmentRosterQueryV1 $query): Api\AddonAssignmentLearnerPageV1 {
        if ($this->failed || ($query->cursor() !== '' && $query->expectedRosterRevision() !== 'roster-r1')) {
            return new Api\AddonAssignmentLearnerPageV1(false, array(), '', null, new Api\AddonApiProblemV1('addon_context_unavailable', 'Fixture roster changed', 'test-roster', true));
        }
        $ids = $query->cursor() === '' ? range(1000,1049) : range(1050,1099);
        return new Api\AddonAssignmentLearnerPageV1(true, array_map(static fn($id) => new Api\AddonAssignmentLearnerV1($id, 'member-r1'), array_slice($ids,0,$query->limit())), $query->cursor() === '' ? 'next' : '', 'roster-r1', null);
    }
}
final class OclTestRuntime implements Api\AddonRuntimeContextV1 {
    public function __construct(private OclTestGateway $gateway) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 { return new Api\AddonRuntimeSnapshotV1('oj-code-lab', OJ_CODE_LAB_VERSION, '^2', '2.0.0-dev.99', '^1', '1.1.0', 'accepted', '', 'catalog-r1', array(), 'test-runtime'); }
    public function contexts(): Api\AddonContextGatewayV1 { return $this->gateway; }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('unused'); }
}
