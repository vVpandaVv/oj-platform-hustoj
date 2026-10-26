<?php
/**
 * External AI consent status, approval, and revocation use cases.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\External_Ai_Consent_Record;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Worksheet\Evaluation\Port\External_Ai_Consent_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Routing_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * External AI consent status, approval, and revocation use cases.
 */
final class Manage_External_Ai_Consent {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize external ai consent dependencies and state.
	 *
	 * @param External_Ai_Consent_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Worksheet_Evaluation_Routing_Port_Interface   $routing Routing.
	 * @param Canonical_Json                                $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                 $clock Clock.
	 */
	public function __construct(
		private External_Ai_Consent_Repository_Port_Interface $repository,
		private Worksheet_Evaluation_Routing_Port_Interface $routing,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Read external ai consent.
	 *
	 * @return array<string,mixed>
	 */
	public function read(): array {
		$record  = $this->record();
		$routing = $this->routing_or_null();
		return $this->project( $record, $routing );
	}

	/**
	 * Approve.
	 *
	 * @param Approve_External_Ai_Consent_Command $command Command.
	 * @return External_Ai_Consent_Command_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function approve( Approve_External_Ai_Consent_Command $command ): External_Ai_Consent_Command_Result {
		$replay = $this->repository->replay(
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( null !== $replay ) {
			return new External_Ai_Consent_Command_Result(
				$this->project( $this->stored_record( $replay['record'] ), $this->routing_or_null() ),
				'approve',
				$replay['occurred_at_utc'],
				true
			);
		}
		$routing = $this->required_routing();
		if (
			! hash_equals( $command->expected_routing_revision(), $routing->routing_revision() )
			|| ! hash_equals( $command->expected_profile_fingerprint(), $routing->profile_fingerprint() )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::ROUTING_CHANGED );
		}
		$current = $this->record();
		$this->assert_expected(
			$command->expected_state(),
			$command->expected_consent_hash(),
			$current,
			$routing
		);
		$occurred_at_utc = ( $this->clock )();
		$record          = External_Ai_Consent_Record::approve(
			$routing,
			$command->actor_user_id(),
			$occurred_at_utc,
			$this->canonical
		);
		$stored          = $this->repository->commit(
			'approve',
			$command->actor_user_id(),
			$command->command_id(),
			$command->idempotency_storage_key(),
			$command->request_digest(),
			$command->expected_consent_hash(),
			$record->to_array(),
			$occurred_at_utc,
			$command->correlation_id()
		);

		return new External_Ai_Consent_Command_Result(
			$this->project( $this->stored_record( $stored['record'] ), $routing ),
			'approve',
			$stored['occurred_at_utc'],
			$stored['replayed']
		);
	}

	/**
	 * Revoke.
	 *
	 * @param Revoke_External_Ai_Consent_Command $command Command.
	 * @return External_Ai_Consent_Command_Result
	 */
	public function revoke( Revoke_External_Ai_Consent_Command $command ): External_Ai_Consent_Command_Result {
		$replay = $this->repository->replay(
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( null !== $replay ) {
			return new External_Ai_Consent_Command_Result(
				$this->project( $this->stored_record( $replay['record'] ), $this->routing_or_null() ),
				'revoke',
				$replay['occurred_at_utc'],
				true
			);
		}
		$current = $this->record();
		$routing = $this->routing_or_null();
		$this->assert_expected(
			$command->expected_state(),
			$command->expected_consent_hash(),
			$current,
			$routing
		);
		$occurred_at_utc = ( $this->clock )();
		$stored          = $this->repository->commit(
			'revoke',
			$command->actor_user_id(),
			$command->command_id(),
			$command->idempotency_storage_key(),
			$command->request_digest(),
			$command->expected_consent_hash(),
			null,
			$occurred_at_utc,
			$command->correlation_id()
		);

		return new External_Ai_Consent_Command_Result(
			$this->project( $this->stored_record( $stored['record'] ), $routing ),
			'revoke',
			$stored['occurred_at_utc'],
			$stored['replayed']
		);
	}

	/**
	 * Record.
	 *
	 * @return External_Ai_Consent_Record|null
	 */
	private function record(): ?External_Ai_Consent_Record {
		return $this->stored_record( $this->repository->current_record() );
	}

	/**
	 * Stored record.
	 *
	 * @param array<string,mixed>|null $stored Stored.
	 * @return External_Ai_Consent_Record|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_record( ?array $stored ): ?External_Ai_Consent_Record {
		if ( null === $stored ) {
			return null;
		}
		try {
			return External_Ai_Consent_Record::from_array( $stored, $this->canonical );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Required routing.
	 *
	 * @return Worksheet_Evaluation_Routing_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function required_routing(): Worksheet_Evaluation_Routing_Snapshot {
		try {
			$routing = $this->routing->plan();
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::NOT_ROUTABLE );
		}
		if ( Addon_Manifest::TASK_WORKSHEET_QUALITY !== $routing->task_key() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::NOT_ROUTABLE );
		}
		return $routing;
	}

	/**
	 * Routing or null.
	 *
	 * @return Worksheet_Evaluation_Routing_Snapshot|null
	 */
	private function routing_or_null(): ?Worksheet_Evaluation_Routing_Snapshot {
		try {
			return $this->required_routing();
		} catch ( External_Ai_Consent_Failure ) {
			return null;
		}
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param string                                     $expected_state Expected state.
	 * @param string|null                                $expected_hash Expected hash.
	 * @param External_Ai_Consent_Record|null            $record Record.
	 * @param Worksheet_Evaluation_Routing_Snapshot|null $routing Routing.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected(
		string $expected_state,
		?string $expected_hash,
		?External_Ai_Consent_Record $record,
		?Worksheet_Evaluation_Routing_Snapshot $routing
	): void {
		$actual_state = null === $record
			? 'missing'
			: ( $routing instanceof Worksheet_Evaluation_Routing_Snapshot && $record->matches_routing( $routing )
				? 'approved'
				: 'stale' );
		$actual_hash  = null === $record ? null : $record->consent_hash();
		if (
			! hash_equals( $expected_state, $actual_state )
			|| ( null === $expected_hash ) !== ( null === $actual_hash )
			|| ( null !== $expected_hash && ! hash_equals( $expected_hash, (string) $actual_hash ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Project external ai consent.
	 *
	 * @param External_Ai_Consent_Record|null            $record Record.
	 * @param Worksheet_Evaluation_Routing_Snapshot|null $routing Routing.
	 * @return array<string,mixed>
	 */
	private function project(
		?External_Ai_Consent_Record $record,
		?Worksheet_Evaluation_Routing_Snapshot $routing
	): array {
		$effective = $record instanceof External_Ai_Consent_Record
			&& $routing instanceof Worksheet_Evaluation_Routing_Snapshot
			&& $record->matches_routing( $routing );
		$state     = null === $record ? 'missing' : ( $effective ? 'approved' : 'stale' );
		$consent   = array(
			'state'     => $state,
			'effective' => $effective,
		);
		if ( $record instanceof External_Ai_Consent_Record ) {
			$consent = array_merge( $consent, $record->projection() );
		} else {
			$consent = array_merge(
				$consent,
				array(
					'consent_hash'        => null,
					'approved_at_utc'     => null,
					'approved_by_user_id' => null,
					'task_key'            => Addon_Manifest::TASK_WORKSHEET_QUALITY,
					'destination'         => null,
				)
			);
		}
		$routing_projection = array( 'available' => $routing instanceof Worksheet_Evaluation_Routing_Snapshot );
		if ( $routing instanceof Worksheet_Evaluation_Routing_Snapshot ) {
			$routing_projection = array_merge(
				$routing_projection,
				array(
					'task_key'            => $routing->task_key(),
					'provider_key'        => $routing->provider_key(),
					'model_key'           => $routing->model_key(),
					'profile_fingerprint' => $routing->profile_fingerprint(),
					'routing_revision'    => $routing->routing_revision(),
				)
			);
		}

		return array(
			'consent'          => $consent,
			'routing'          => $routing_projection,
			'input_categories' => External_Ai_Consent_Record::DATA_CATEGORIES,
		);
	}
}
