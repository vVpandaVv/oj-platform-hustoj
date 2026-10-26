<?php
/** AI-optional worksheet approval through the production evidence adapter. */
declare(strict_types=1);

use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Approval_Evidence;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Approval_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Scoped_Evaluation_Configuration_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Query_Port_Interface;

require __DIR__ . '/worksheet-approval-command-s02.php';

$configuration = new class($evidence_snapshot['configuration_guard']) implements Scoped_Evaluation_Configuration_Port_Interface {
	public function __construct(private array $guard) {}
	public function active(): array { throw new RuntimeException('Manual approval must not request AI execution input.'); }
	public function for_worksheet(string $worksheet_id): array { return $this->active(); }
	public function execution(): array {
		$values = array();
		foreach (array('policy', 'rubric', 'review_rule') as $name) {
			$values[$name] = array('revision_id' => $this->guard[$name . '_revision_id'], 'content_hash' => $this->guard[$name . '_content_hash']);
		}
		return $values;
	}
};
$queries = new class implements Worksheet_Evaluation_Query_Port_Interface {
	public ?array $detail = null;
	public int $reads = 0;
	public function list_owned(int $actor_user_id, string $state, string $worksheet_id, string $revision_id, string $cursor, int $limit, bool $latest_only = false): array { throw new RuntimeException('Unexpected list.'); }
	public function find_owned(int $actor_user_id, string $evaluation_id): ?array { ++$this->reads; return $this->detail; }
	public function authorization_snapshot(int $actor_user_id, string $evaluation_id): ?array { return null; }
};
$database = new class {
	public ?array $row = null;
	public string $last_error = '';
	public array $values = array();
	public function prepare(string $sql, ...$values): string { $this->values = $values; return $sql; }
	public function get_results(string $sql, string $format): array { throw new RuntimeException('Unexpected configuration list.'); }
	public function get_var(string $sql): mixed { throw new RuntimeException('Unexpected scalar query.'); }
	public function query(string $sql): int { throw new RuntimeException('Readiness must not write.'); }
	public function insert(string $table, array $data): bool { throw new RuntimeException('Readiness must not insert.'); }
	public function get_row(string $sql, string $format): ?array {
		approval_s02_assert(str_contains($sql, 'w.owner_user_id = %d'), 'Ownership must be part of the evidence query.');
		return $this->row;
	}
};
$manual_evidence = new WordPress_Worksheet_Approval_Evidence($configuration, $queries, $canonical, $database);
$snapshot = $manual_evidence->current(42, $worksheet_id, $revision_id);
approval_s02_same(false, $snapshot['policy']->evaluation_required(), 'Production approval must make AI optional.');
approval_s02_same(array($revision_id, $worksheet_id, 42), $database->values, 'Evidence must bind the actor and exact worksheet revision.');
approval_s02_same(null, $snapshot['evaluation_detail'], 'No AI output may be synthesized.');
approval_s02_same(0, $queries->reads, 'Missing AI result must not trigger a query or job.');
approval_s02_assert(count(array_filter($snapshot['evaluation_guard'], static fn($value) => null !== $value)) === 0, 'No AI execution identity may be invented.');

$manual_repository = new Approval_S02_Approval_Repository();
$manual_application = new Approve_Worksheet_Revision(
	$worksheets, $manual_repository, $validator, $manual_evidence, $gate,
	new Worksheet_Document_Id_Rewriter(), $ids, $canonical, static fn(): string => '2026-10-23 03:00:00'
);
$manual_decision = $manual_application->readiness(42, $validation);
approval_s02_same(true, $manual_decision->approval_ready(), 'No AI connection or consent must be needed for readiness.');
approval_s02_same(array('worksheet_evaluation_not_run'), array_column($manual_decision->warnings(), 'code'), 'Missing AI must remain explicit.');
$manual_command = new Approve_Worksheet_Revision_Command(
	42, $revision_id, 'teacher-approval-1', 'draft', $revision_id, $validation->document_hash(),
	$validation->projection_hash(), $manual_decision->decision_hash(), hash('sha256', 'teacher-approval-key'), 'teacher-approval-correlation', $canonical
);
$manual_result = $manual_application->execute($manual_command);
approval_s02_same('approved', $manual_result->revision_state(), 'A teacher must be able to approve without an AI result.');
approval_s02_same(null, $manual_repository->records[0]['evaluation_projection_hash'], 'Manual approval must preserve NULL AI evidence.');
approval_s02_same(42, $manual_repository->records[0]['actor_user_id'], 'Teacher approval must retain its actor.');
approval_s02_same(true, $manual_application->execute($manual_command)->replayed(), 'Manual approval must be idempotent.');

$manual_controller = new Worksheet_Rest_Controller(
	$permissions, new Create_Worksheet($worksheets, $ids, new Initial_Document_Factory($ids, $canonical)),
	new Read_Worksheets($worksheets), $canonical, $envelopes, $correlations, null, null, $validator, null, $manual_application
);
$body['command_id'] = 'teacher-approval-rest-1';
$body['expected']['expected_approval_decision_hash'] = $manual_decision->decision_hash();
$manual_request = new Approval_S02_Request(array('X-WP-Nonce' => 'approval-s02-nonce', 'Idempotency-Key' => 'teacher-approval-rest-key'), $body, $revision_id);
approval_s02_same(true, $manual_controller->permissions_approve_revision($manual_request), 'Manage-only teacher permission must suffice without an AI capability.');
approval_s02_same(200, $manual_controller->approve_revision($manual_request)->get_status(), 'Manual C06 REST must succeed.');
$bad_nonce = new Approval_S02_Request(array('X-WP-Nonce' => 'bad', 'Idempotency-Key' => 'teacher-approval-rest-bad'), $body, $revision_id);
approval_s02_assert(true !== $manual_controller->permissions_approve_revision($bad_nonce), 'No-AI approval must not bypass nonce verification.');

$repository = new WordPress_Worksheet_Approval_Repository($canonical, $database);
$lock = new ReflectionMethod($repository, 'lock_evaluation');
$lock->invoke($repository, $manual_repository->records[0]);
$old_guard = $evidence_snapshot['evaluation_guard'];
$database->row = array(
	'evaluation_id' => $old_guard['evaluation_id'], 'lock_version' => $old_guard['lock_version'], 'state' => $old_guard['state'],
	'updated_at_utc' => $old_guard['updated_at_utc'], 'source_hash' => $old_guard['source_hash'],
	'policy_revision_public_id' => $old_guard['policy_revision_id'], 'policy_hash' => $old_guard['policy_content_hash'],
	'rubric_revision_public_id' => $old_guard['rubric_revision_id'], 'rubric_hash' => $old_guard['rubric_content_hash'],
	'review_rule_revision_public_id' => $old_guard['review_rule_revision_id'], 'review_rule_hash' => $old_guard['review_rule_content_hash'],
);
approval_s02_failure(static fn() => $lock->invoke($repository, $manual_repository->records[0]), Worksheet_Failure::APPROVAL_EVIDENCE_UNAVAILABLE, 'AI starting after manual validation must invalidate approval.');

// A changed AI policy must not hide an existing result or its safety warnings.
$database->row['policy_hash'] = hash('sha256', 'old-ai-policy');
$queries->detail = $evidence->detail();
$changed_pins = $manual_evidence->current(42, $worksheet_id, $revision_id);
approval_s02_same($queries->detail, $changed_pins['evaluation_detail'], 'Older AI configuration results must be retained.');
approval_s02_assert(is_string($changed_pins['evaluation_guard']['projection_hash']), 'Retained results must keep a transactional projection hash.');
foreach (array('failed', 'cancelled', 'running') as $state) {
	$database->row['state'] = $state;
	$queries->detail = $evidence->detail();
	$queries->detail['evaluation']['state'] = $state;
	$queries->detail['evaluation']['status'] = array('overall_status' => null, 'finding_count' => 0, 'needs_attention_count' => 0, 'safety_flagged' => false, 'latest_review_no' => null, 'next_action' => 'retry');
	$queries->detail['result'] = null;
	$queries->detail['reviews'] = array();
	$readiness = $manual_application->readiness(42, $validation);
	approval_s02_same('running' !== $state, $readiness->approval_ready(), 'No-result failure/cancellation must allow teacher approval; an active job must not.');
}
$database->row['state'] = 'review_required';
$queries->detail = $evidence->detail();
$queries->detail['evaluation']['state'] = 'review_required';
$queries->detail['evaluation']['status']['latest_review_no'] = null;
$queries->detail['evaluation']['status']['safety_flagged'] = true;
$queries->detail['result']['safety_flagged'] = true;
$queries->detail['reviews'] = array();
$safety = $manual_application->readiness(42, $validation);
approval_s02_assert(in_array('worksheet_evaluation_safety_review_required', array_column($safety->blockers(), 'code'), true), 'AI-optional approval must not hide known safety findings.');
echo "Worksheet teacher approval passed: production optional policy, no AI result/permission/consent, C06, nonce, replay, locks, old policy evidence and safety.\n";
