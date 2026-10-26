<?php
/**
 * Q25-Q27 and C55-C60 assessment configuration use cases.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Assessment\Configuration\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;
use OJAI\Dashboard\Assessment\Configuration\Port\Assessment_Configuration_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Q25-Q27 and C55-C60 assessment configuration use cases.
 */
final class Manage_Assessment_Configuration {
	/**
	 * Initialize assessment configuration dependencies and state.
	 *
	 * @param Assessment_Configuration_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Assessment_Configuration_Profile                   $profile Profile.
	 * @param Canonical_Json                                     $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Assessment_Configuration_Repository_Port_Interface $repository,
		private Assessment_Configuration_Profile $profile,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Read assessment configuration.
	 *
	 * @param string $resource_type Resource type.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( string $resource_type ): array {
		$this->assert_resource( $resource_type );
		$rows     = $this->repository->list_revisions( $resource_type );
		$subjects = array();
		foreach ( Assessment_Configuration_Profile::subject_types() as $subject_type ) {
			$subjects[ $subject_type ] = array(
				'subject_type'       => $subject_type,
				'active_revision_id' => null,
				'latest_revision_no' => 0,
				'revisions'          => array(),
			);
		}

		foreach ( $rows as $row ) {
			$subject_type = $row['subject_type'] ?? null;
			if ( ! is_string( $subject_type ) || ! isset( $subjects[ $subject_type ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
			}
			$subjects[ $subject_type ]['revisions'][]        = $row;
			$subjects[ $subject_type ]['latest_revision_no'] = max(
				(int) $subjects[ $subject_type ]['latest_revision_no'],
				(int) ( $row['revision_no'] ?? 0 )
			);
			if ( Assessment_Configuration_Profile::STATE_ACTIVE === ( $row['state'] ?? null ) ) {
				if ( null !== $subjects[ $subject_type ]['active_revision_id'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
				}
				$subjects[ $subject_type ]['active_revision_id'] = $row['revision_id'];
			}
		}

		return array(
			'resource_type' => $resource_type,
			'subjects'      => array_values( $subjects ),
		);
	}

	/**
	 * Create assessment configuration.
	 *
	 * @param string              $resource_type Resource type.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param int                 $expected_latest_revision_no Expected latest revision no.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param string              $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create(
		string $resource_type,
		int $actor_user_id,
		string $command_id,
		int $expected_latest_revision_no,
		array $payload,
		string $idempotency_fingerprint,
		string $correlation_id
	): array {
		$this->assert_command( $resource_type, $actor_user_id, $command_id, $idempotency_fingerprint, $correlation_id );
		if ( $expected_latest_revision_no < 0 ) {
			throw new InvalidArgumentException( 'Expected configuration revision is invalid.' );
		}
		$prepared           = $this->profile->prepare( $resource_type, $payload );
		$normalized_request = array(
			'command_id' => $command_id,
			'expected'   => array( 'expected_latest_revision_no' => $expected_latest_revision_no ),
			'payload'    => $this->request_payload( $prepared ),
		);

		return $this->repository->create_revision(
			array(
				'actor_user_id'               => $actor_user_id,
				'command_id'                  => $command_id,
				'correlation_id'              => $correlation_id,
				'expected_latest_revision_no' => $expected_latest_revision_no,
				'idempotency_storage_key'     => $this->storage_key( $actor_user_id, $resource_type . '.create', $idempotency_fingerprint ),
				'prepared'                    => $prepared,
				'request_digest'              => hash( 'sha256', $this->canonical->encode( $normalized_request ) ),
				'resource_type'               => $resource_type,
				'occurred_at_utc'             => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Activate.
	 *
	 * @param string      $resource_type Resource type.
	 * @param string      $revision_id Revision id.
	 * @param int         $actor_user_id Authenticated actor from the access context.
	 * @param string      $command_id Command identifier used for idempotency.
	 * @param string      $expected_state Expected state.
	 * @param int         $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param string|null $expected_active_revision_id Expected active revision id.
	 * @param string      $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string      $correlation_id Request correlation identifier for diagnostics.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function activate(
		string $resource_type,
		string $revision_id,
		int $actor_user_id,
		string $command_id,
		string $expected_state,
		int $expected_lock_version,
		?string $expected_active_revision_id,
		string $idempotency_fingerprint,
		string $correlation_id
	): array {
		$this->assert_command( $resource_type, $actor_user_id, $command_id, $idempotency_fingerprint, $correlation_id );
		if (
			! Public_Id::is_valid( $revision_id )
			|| Assessment_Configuration_Profile::STATE_DRAFT !== $expected_state
			|| $expected_lock_version < 0
			|| ( null !== $expected_active_revision_id && ! Public_Id::is_valid( $expected_active_revision_id ) )
		) {
			throw new InvalidArgumentException( 'Assessment configuration activation expectation is invalid.' );
		}
		$normalized_request = array(
			'command_id' => $command_id,
			'expected'   => array(
				'expected_active_revision_id' => $expected_active_revision_id,
				'expected_lock_version'       => $expected_lock_version,
				'expected_state'              => Assessment_Configuration_Profile::STATE_DRAFT,
			),
			'payload'    => array(),
		);

		return $this->repository->activate_revision(
			array(
				'actor_user_id'               => $actor_user_id,
				'command_id'                  => $command_id,
				'correlation_id'              => $correlation_id,
				'expected_active_revision_id' => $expected_active_revision_id,
				'expected_lock_version'       => $expected_lock_version,
				'expected_state'              => Assessment_Configuration_Profile::STATE_DRAFT,
				'idempotency_storage_key'     => $this->storage_key( $actor_user_id, $resource_type . '.activate', $idempotency_fingerprint ),
				'occurred_at_utc'             => gmdate( 'Y-m-d H:i:s' ),
				'request_digest'              => hash( 'sha256', $this->canonical->encode( $normalized_request ) ),
				'resource_type'               => $resource_type,
				'revision_id'                 => $revision_id,
			)
		);
	}

	/**
	 * Request payload.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @return array<string,mixed>
	 */
	private function request_payload( array $prepared ): array {
		$result = array(
			'subject_type' => $prepared['subject_type'],
			'value'        => $prepared['value'],
		);
		if ( Assessment_Configuration_Profile::RUBRIC === $prepared['resource_type'] ) {
			$result['total_points'] = $prepared['total_points'];
		} elseif ( Assessment_Configuration_Profile::REVIEW_RULE === $prepared['resource_type'] ) {
			$result['review_required']           = true;
			$result['explicit_release_required'] = true;
			$result['confidence_threshold']      = $prepared['confidence_threshold'];
		}
		return $result;
	}

	/**
	 * Reject invalid command.
	 *
	 * @param string $resource_type Resource type.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $command_id Command identifier used for idempotency.
	 * @param string $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_command(
		string $resource_type,
		int $actor_user_id,
		string $command_id,
		string $idempotency_fingerprint,
		string $correlation_id
	): void {
		$this->assert_resource( $resource_type );
		if (
			$actor_user_id < 1
			|| strlen( $command_id ) < 8 || strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| strlen( $correlation_id ) < 1 || strlen( $correlation_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Assessment configuration command metadata is invalid.' );
		}
	}

	/**
	 * Reject invalid resource.
	 *
	 * @param string $resource_type Resource type.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_resource( string $resource_type ): void {
		if ( ! in_array( $resource_type, Assessment_Configuration_Profile::resource_types(), true ) ) {
			throw new InvalidArgumentException( 'Unknown assessment configuration resource.' );
		}
	}

	/**
	 * Storage key.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $operation Operation.
	 * @param string $fingerprint Fingerprint.
	 * @return string
	 */
	private function storage_key( int $actor_user_id, string $operation, string $fingerprint ): string {
		return 'configuration:' . hash( 'sha256', $actor_user_id . '|' . $operation . '|' . $fingerprint );
	}
}
