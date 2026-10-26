<?php
/**
 * Literal-table policy, rubric, and review-rule revision repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Assessment\Configuration\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Assessment\Configuration\Application\Assessment_Configuration_Failure;
use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;
use OJAI\Dashboard\Assessment\Configuration\Port\Assessment_Configuration_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table policy, rubric, and review-rule revision repository.
 */
final class WordPress_Assessment_Configuration_Repository implements Assessment_Configuration_Repository_Port_Interface {
	private const TABLE_AUDIT    = 'oj_ai_audit_events';
	private const TABLE_OUTBOX   = 'oj_ai_outbox_events';
	private const AGGREGATE_TYPE = 'assessment_configuration';
	private const RECEIPT_SCHEMA = 'oj_ai.assessment_configuration_command_receipt';
	private const AUDIT_SCHEMA   = 'oj_ai.assessment_configuration_audit';
	private const VERSION        = 1;

	/**
	 * Initialize WordPress assessment configuration repository dependencies and state.
	 *
	 * @param Canonical_Json                   $canonical Canonical JSON encoder used for stable digests.
	 * @param Public_Id_Generator              $ids Ids.
	 * @param Assessment_Configuration_Profile $profile Profile.
	 * @param object|null                      $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Public_Id_Generator $ids,
		private Assessment_Configuration_Profile $profile,
		private ?object $database = null
	) {
	}

	/**
	 * List revisions.
	 *
	 * @param string $resource_type Resource type.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_revisions( string $resource_type ): array {
		try {
			$table = Assessment_Configuration_Profile::table( $resource_type );
			$rows  = $this->get_results(
				'SELECT ' . $this->columns( $resource_type ) . ' FROM ' . $table . ' ORDER BY subject_type ASC, revision_no DESC'
			);
			return array_map(
				fn ( array $row ): array => $this->profile->project_row( $resource_type, $row ),
				$rows
			);
		} catch ( Assessment_Configuration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Create revision.
	 *
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_revision( array $command ): array {
		$this->assert_command( $command, false );
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$receipt             = $this->receipt( $command['idempotency_storage_key'], $command['request_digest'], true );
			if ( null !== $receipt ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				$receipt['replayed'] = true;
				return $receipt;
			}

			$prepared  = $command['prepared'];
			$table     = Assessment_Configuration_Profile::table( $command['resource_type'] );
			$latest    = $this->get_row(
				$this->prepare(
					'SELECT revision_no FROM ' . $table . ' WHERE subject_type = %s ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',
					array( $prepared['subject_type'] )
				)
			);
			$latest_no = null === $latest ? 0 : $this->integer( $latest['revision_no'] ?? null, 1 );
			if ( $latest_no !== $command['expected_latest_revision_no'] ) {
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STATE_CONFLICT );
			}

			$public_id = $this->ids->create()->value();
			$row       = $this->revision_row(
				$prepared,
				$public_id,
				$latest_no + 1,
				Assessment_Configuration_Profile::STATE_DRAFT,
				$command['actor_user_id'],
				$command['occurred_at_utc']
			);
			$this->insert_or_fail( $table, $row );
			$revision = $this->profile->project_row( $command['resource_type'], $row );
			$result   = array(
				'operation'           => 'create',
				'occurred_at_utc'     => $command['occurred_at_utc'],
				'replayed'            => false,
				'retired_revision_id' => null,
				'revision'            => $revision,
			);
			$this->insert_receipt( $command, $public_id, 'assessment_configuration.revision_created', $result );
			$this->insert_audit(
				'assessment_configuration.revision_created',
				$command,
				$public_id,
				null,
				$prepared['content_hash'],
				array(
					'revision_no'  => $latest_no + 1,
					'subject_type' => $prepared['subject_type'],
				)
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $result;
		} catch ( Assessment_Configuration_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Activate revision.
	 *
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function activate_revision( array $command ): array {
		$this->assert_command( $command, true );
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$receipt             = $this->receipt( $command['idempotency_storage_key'], $command['request_digest'], true );
			if ( null !== $receipt ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				$receipt['replayed'] = true;
				return $receipt;
			}

			$resource_type = $command['resource_type'];
			$table         = Assessment_Configuration_Profile::table( $resource_type );
			$target_row    = $this->get_row(
				$this->prepare(
					'SELECT ' . $this->columns( $resource_type ) . ' FROM ' . $table . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
					array( $command['revision_id'] )
				)
			);
			if ( null === $target_row ) {
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::NOT_FOUND );
			}
			$target = $this->profile->project_row( $resource_type, $target_row );
			if (
				$command['expected_state'] !== $target['state']
				|| $command['expected_lock_version'] !== $target['lock_version']
			) {
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STATE_CONFLICT );
			}
			try {
				$this->profile->assert_activatable_revision( $target );
			} catch ( InvalidArgumentException ) {
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::VALIDATION_FAILED );
			}

			$active_rows = $this->get_results(
				$this->prepare(
					'SELECT ' . $this->columns( $resource_type ) . ' FROM ' . $table
						. ' WHERE subject_type = %s AND state = %s ORDER BY revision_no DESC LIMIT 2 FOR UPDATE',
					array( $target['subject_type'], Assessment_Configuration_Profile::STATE_ACTIVE )
				)
			);
			if ( count( $active_rows ) > 1 ) {
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
			}
			$current    = array() === $active_rows ? null : $this->profile->project_row( $resource_type, $active_rows[0] );
			$current_id = null === $current ? null : $current['revision_id'];
			if ( $current_id !== $command['expected_active_revision_id'] ) {
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STATE_CONFLICT );
			}
			if ( Assessment_Configuration_Profile::POLICY === $resource_type ) {
				$this->assert_policy_references( $target );
			}

			if ( null !== $current ) {
				$changed = $this->query_affected(
					$this->prepare(
						'UPDATE ' . $table . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, '
							. 'updated_at_utc = %s, retired_by_user_id = %d, retired_at_utc = %s '
							. 'WHERE public_id = %s AND state = %s AND lock_version = %d',
						array(
							Assessment_Configuration_Profile::STATE_RETIRED,
							$command['actor_user_id'],
							$command['occurred_at_utc'],
							$command['actor_user_id'],
							$command['occurred_at_utc'],
							$current['revision_id'],
							Assessment_Configuration_Profile::STATE_ACTIVE,
							$current['lock_version'],
						)
					)
				);
				if ( 1 !== $changed ) {
					throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STATE_CONFLICT );
				}
			}

			$changed = $this->query_affected(
				$this->prepare(
					'UPDATE ' . $table . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, '
						. 'updated_at_utc = %s, activated_by_user_id = %d, activated_at_utc = %s '
						. 'WHERE public_id = %s AND state = %s AND lock_version = %d',
					array(
						Assessment_Configuration_Profile::STATE_ACTIVE,
						$command['actor_user_id'],
						$command['occurred_at_utc'],
						$command['actor_user_id'],
						$command['occurred_at_utc'],
						$target['revision_id'],
						Assessment_Configuration_Profile::STATE_DRAFT,
						$target['lock_version'],
					)
				)
			);
			if ( 1 !== $changed ) {
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STATE_CONFLICT );
			}

			$target_row['state']                = Assessment_Configuration_Profile::STATE_ACTIVE;
			$target_row['lock_version']         = $target['lock_version'] + 1;
			$target_row['updated_by_user_id']   = $command['actor_user_id'];
			$target_row['updated_at_utc']       = $command['occurred_at_utc'];
			$target_row['activated_by_user_id'] = $command['actor_user_id'];
			$target_row['activated_at_utc']     = $command['occurred_at_utc'];
			$revision                           = $this->profile->project_row( $resource_type, $target_row );
			$result                             = array(
				'operation'           => 'activate',
				'occurred_at_utc'     => $command['occurred_at_utc'],
				'replayed'            => false,
				'retired_revision_id' => $current_id,
				'revision'            => $revision,
			);
			$this->insert_receipt( $command, $target['revision_id'], 'assessment_configuration.revision_activated', $result );
			$this->insert_audit(
				'assessment_configuration.revision_activated',
				$command,
				$target['revision_id'],
				null === $current ? null : $current['content_hash'],
				$target['content_hash'],
				array(
					'retired_revision_id' => $current_id,
					'revision_no'         => $target['revision_no'],
					'subject_type'        => $target['subject_type'],
				)
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $result;
		} catch ( Assessment_Configuration_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Seed defaults.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function seed_defaults( int $actor_user_id, string $correlation_id ): array {
		if ( $actor_user_id < 0 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/D', $correlation_id ) ) {
			throw new InvalidArgumentException( 'Assessment configuration seed metadata is invalid.' );
		}
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$defaults            = $this->profile->defaults();
			$statuses            = array();
			$occurred_at_utc     = gmdate( 'Y-m-d H:i:s' );
			foreach ( Assessment_Configuration_Profile::subject_types() as $subject_type ) {
				$existing_count = 0;
				$inserted_count = 0;
				foreach ( array( Assessment_Configuration_Profile::RUBRIC, Assessment_Configuration_Profile::REVIEW_RULE, Assessment_Configuration_Profile::POLICY ) as $resource_type ) {
					$existing = $this->get_row(
						$this->prepare(
							'SELECT public_id FROM ' . Assessment_Configuration_Profile::table( $resource_type ) . ' WHERE subject_type = %s ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',
							array( $subject_type )
						)
					);
					if ( null !== $existing ) {
						++$existing_count;
						continue;
					}
					$prepared = $defaults[ $subject_type ][ $resource_type ];
					$row      = $this->revision_row(
						$prepared,
						$prepared['public_id'],
						1,
						Assessment_Configuration_Profile::STATE_ACTIVE,
						$actor_user_id,
						$occurred_at_utc
					);
					$this->insert_or_fail( Assessment_Configuration_Profile::table( $resource_type ), $row );
					++$inserted_count;
				}
				if ( $inserted_count > 0 ) {
					$policy = $defaults[ $subject_type ][ Assessment_Configuration_Profile::POLICY ];
					$this->insert_seed_audit( $actor_user_id, $correlation_id, $subject_type, $defaults[ $subject_type ], $policy['public_id'], $occurred_at_utc );
				}
				$upgraded                  = Assessment_Configuration_Profile::WORKSHEET_QUALITY === $subject_type
					? $this->upgrade_worksheet_prompt_policy( $actor_user_id, $correlation_id, $occurred_at_utc )
					: $this->upgrade_package_student_rubric( $actor_user_id, $correlation_id, $occurred_at_utc );
				$statuses[ $subject_type ] = $upgraded
					? 'upgraded'
					: ( 0 === $inserted_count
						? 'preserved'
						: ( 0 === $existing_count ? 'seeded' : 'repaired' ) );
			}
			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $statuses;
		} catch ( Assessment_Configuration_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Append a current active policy while preserving the historical revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function upgrade_worksheet_prompt_policy(
		int $actor_user_id,
		string $correlation_id,
		string $occurred_at_utc
	): bool {
		$table       = Assessment_Configuration_Profile::table( Assessment_Configuration_Profile::POLICY );
		$active_rows = $this->get_results(
			$this->prepare(
				'SELECT ' . $this->columns( Assessment_Configuration_Profile::POLICY ) . ' FROM ' . $table
					. ' WHERE subject_type = %s AND state = %s ORDER BY revision_no DESC LIMIT 2 FOR UPDATE',
				array( Assessment_Configuration_Profile::WORKSHEET_QUALITY, Assessment_Configuration_Profile::STATE_ACTIVE )
			)
		);
		if ( 1 !== count( $active_rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}

		$current  = $this->profile->project_row( Assessment_Configuration_Profile::POLICY, $active_rows[0] );
		$prepared = $this->profile->prepare_worksheet_prompt_upgrade( $current );
		if ( null === $prepared ) {
			return false;
		}
		$this->assert_policy_references( $prepared );

		$latest = $this->get_row(
			$this->prepare(
				'SELECT revision_no FROM ' . $table . ' WHERE subject_type = %s ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',
				array( Assessment_Configuration_Profile::WORKSHEET_QUALITY )
			)
		);
		if ( null === $latest ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		$latest_no = $this->integer( $latest['revision_no'] ?? null, 1 );

		if ( $actor_user_id > 0 ) {
			$changed = $this->query_affected(
				$this->prepare(
					'UPDATE ' . $table . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, '
						. 'updated_at_utc = %s, retired_by_user_id = %d, retired_at_utc = %s '
						. 'WHERE public_id = %s AND state = %s AND lock_version = %d',
					array(
						Assessment_Configuration_Profile::STATE_RETIRED,
						$actor_user_id,
						$occurred_at_utc,
						$actor_user_id,
						$occurred_at_utc,
						$current['revision_id'],
						Assessment_Configuration_Profile::STATE_ACTIVE,
						$current['lock_version'],
					)
				)
			);
		} else {
			$changed = $this->query_affected(
				$this->prepare(
					'UPDATE ' . $table . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = NULL, '
						. 'updated_at_utc = %s, retired_by_user_id = NULL, retired_at_utc = %s '
						. 'WHERE public_id = %s AND state = %s AND lock_version = %d',
					array(
						Assessment_Configuration_Profile::STATE_RETIRED,
						$occurred_at_utc,
						$occurred_at_utc,
						$current['revision_id'],
						Assessment_Configuration_Profile::STATE_ACTIVE,
						$current['lock_version'],
					)
				)
			);
		}
		if ( 1 !== $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STATE_CONFLICT );
		}

		$public_id = $this->ids->create()->value();
		$row       = $this->revision_row(
			$prepared,
			$public_id,
			$latest_no + 1,
			Assessment_Configuration_Profile::STATE_ACTIVE,
			$actor_user_id,
			$occurred_at_utc
		);
		$this->insert_or_fail( $table, $row );

		$this->insert_audit_row(
			'assessment_configuration.prompt_contract_upgraded',
			$actor_user_id,
			$correlation_id,
			$public_id,
			$current['content_hash'],
			$prepared['content_hash'],
			array(
				'package_owned'       => true,
				'resource_type'       => Assessment_Configuration_Profile::POLICY,
				'subject_type'        => Assessment_Configuration_Profile::WORKSHEET_QUALITY,
				'revision_no'         => $latest_no + 1,
				'retired_revision_id' => $current['revision_id'],
				'from_prompt'         => $current['value']['prompt'],
				'to_prompt'           => $prepared['value']['prompt'],
			),
			$occurred_at_utc
		);

		return true;
	}

	/**
	 * Upgrade only the package-owned student rubric that predates level scoring.
	 *
	 * If an operator already activated a current rubric, only the active policy
	 * pin is advanced. Otherwise a current package rubric and policy are appended
	 * together, preserving every historical revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function upgrade_package_student_rubric(
		int $actor_user_id,
		string $correlation_id,
		string $occurred_at_utc
	): bool {
		$policy_table = Assessment_Configuration_Profile::table( Assessment_Configuration_Profile::POLICY );
		$rubric_table = Assessment_Configuration_Profile::table( Assessment_Configuration_Profile::RUBRIC );
		$policy_rows  = $this->get_results(
			$this->prepare(
				'SELECT ' . $this->columns( Assessment_Configuration_Profile::POLICY ) . ' FROM ' . $policy_table
					. ' WHERE subject_type = %s AND state = %s ORDER BY revision_no DESC LIMIT 2 FOR UPDATE',
				array( Assessment_Configuration_Profile::STUDENT_SUBMISSION, Assessment_Configuration_Profile::STATE_ACTIVE )
			)
		);
		if ( 1 !== count( $policy_rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		$policy           = $this->profile->project_row( Assessment_Configuration_Profile::POLICY, $policy_rows[0] );
		$pinned_rubric_id = $policy['value']['rubric_revision_id'] ?? null;
		if ( ! is_string( $pinned_rubric_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}

		$pinned_rows = $this->get_results(
			$this->prepare(
				'SELECT ' . $this->columns( Assessment_Configuration_Profile::RUBRIC ) . ' FROM ' . $rubric_table
					. ' WHERE public_id = %s AND subject_type = %s AND state IN (%s, %s) LIMIT 2 FOR UPDATE',
				array(
					$pinned_rubric_id,
					Assessment_Configuration_Profile::STUDENT_SUBMISSION,
					Assessment_Configuration_Profile::STATE_ACTIVE,
					Assessment_Configuration_Profile::STATE_RETIRED,
				)
			)
		);
		if ( 1 !== count( $pinned_rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		$pinned                  = $this->profile->project_row( Assessment_Configuration_Profile::RUBRIC, $pinned_rows[0] );
		$prepared_package_rubric = $this->profile->prepare_package_student_rubric_upgrade( $pinned );
		if ( null === $prepared_package_rubric ) {
			return false;
		}

		$active_rows = $this->get_results(
			$this->prepare(
				'SELECT ' . $this->columns( Assessment_Configuration_Profile::RUBRIC ) . ' FROM ' . $rubric_table
					. ' WHERE subject_type = %s AND state = %s ORDER BY revision_no DESC LIMIT 2 FOR UPDATE',
				array( Assessment_Configuration_Profile::STUDENT_SUBMISSION, Assessment_Configuration_Profile::STATE_ACTIVE )
			)
		);
		if ( 1 !== count( $active_rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		$active          = $this->profile->project_row( Assessment_Configuration_Profile::RUBRIC, $active_rows[0] );
		$rubric_appended = false;
		if ( $active['revision_id'] !== $pinned['revision_id'] ) {
			try {
				$this->profile->assert_activatable_revision( $active );
			} catch ( InvalidArgumentException ) {
				return false;
			}
			$target_rubric_id   = $active['revision_id'];
			$target_rubric_hash = $active['content_hash'];
		} else {
			$latest_rubric = $this->get_row(
				$this->prepare(
					'SELECT revision_no FROM ' . $rubric_table . ' WHERE subject_type = %s ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',
					array( Assessment_Configuration_Profile::STUDENT_SUBMISSION )
				)
			);
			if ( null === $latest_rubric ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
			}
			$this->retire_active_revision( $rubric_table, $active, $actor_user_id, $occurred_at_utc );
			$target_rubric_id  = $this->ids->create()->value();
			$target_rubric_row = $this->revision_row(
				$prepared_package_rubric,
				$target_rubric_id,
				$this->integer( $latest_rubric['revision_no'] ?? null, 1 ) + 1,
				Assessment_Configuration_Profile::STATE_ACTIVE,
				$actor_user_id,
				$occurred_at_utc
			);
			$this->insert_or_fail( $rubric_table, $target_rubric_row );
			$target_rubric_hash = $prepared_package_rubric['content_hash'];
			$rubric_appended    = true;
		}

		$policy_value                       = $policy['value'];
		$policy_value['rubric_revision_id'] = $target_rubric_id;
		$prepared_policy                    = $this->profile->prepare(
			Assessment_Configuration_Profile::POLICY,
			array(
				'subject_type' => Assessment_Configuration_Profile::STUDENT_SUBMISSION,
				'value'        => $policy_value,
			),
			true
		);
		$this->assert_policy_references( $prepared_policy );

		$latest_policy = $this->get_row(
			$this->prepare(
				'SELECT revision_no FROM ' . $policy_table . ' WHERE subject_type = %s ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',
				array( Assessment_Configuration_Profile::STUDENT_SUBMISSION )
			)
		);
		if ( null === $latest_policy ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		$this->retire_active_revision( $policy_table, $policy, $actor_user_id, $occurred_at_utc );
		$target_policy_id = $this->ids->create()->value();
		$this->insert_or_fail(
			$policy_table,
			$this->revision_row(
				$prepared_policy,
				$target_policy_id,
				$this->integer( $latest_policy['revision_no'] ?? null, 1 ) + 1,
				Assessment_Configuration_Profile::STATE_ACTIVE,
				$actor_user_id,
				$occurred_at_utc
			)
		);

		$this->insert_audit_row(
			'assessment_configuration.student_rubric_contract_upgraded',
			$actor_user_id,
			$correlation_id,
			$target_policy_id,
			$policy['content_hash'],
			$prepared_policy['content_hash'],
			array(
				'package_owned'             => true,
				'subject_type'              => Assessment_Configuration_Profile::STUDENT_SUBMISSION,
				'source_policy_revision_id' => $policy['revision_id'],
				'source_rubric_revision_id' => $pinned['revision_id'],
				'target_rubric_revision_id' => $target_rubric_id,
				'target_rubric_hash'        => $target_rubric_hash,
				'rubric_appended'           => $rubric_appended,
			),
			$occurred_at_utc
		);

		return true;
	}

	/**
	 * Retire active revision.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $revision Revision.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $occurred_at_utc Occurred at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function retire_active_revision(
		string $table,
		array $revision,
		int $actor_user_id,
		string $occurred_at_utc
	): void {
		if ( $actor_user_id > 0 ) {
			$changed = $this->query_affected(
				$this->prepare(
					'UPDATE ' . $table . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, '
						. 'updated_at_utc = %s, retired_by_user_id = %d, retired_at_utc = %s '
						. 'WHERE public_id = %s AND state = %s AND lock_version = %d',
					array(
						Assessment_Configuration_Profile::STATE_RETIRED,
						$actor_user_id,
						$occurred_at_utc,
						$actor_user_id,
						$occurred_at_utc,
						$revision['revision_id'],
						Assessment_Configuration_Profile::STATE_ACTIVE,
						$revision['lock_version'],
					)
				)
			);
		} else {
			$changed = $this->query_affected(
				$this->prepare(
					'UPDATE ' . $table . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = NULL, '
						. 'updated_at_utc = %s, retired_by_user_id = NULL, retired_at_utc = %s '
						. 'WHERE public_id = %s AND state = %s AND lock_version = %d',
					array(
						Assessment_Configuration_Profile::STATE_RETIRED,
						$occurred_at_utc,
						$occurred_at_utc,
						$revision['revision_id'],
						Assessment_Configuration_Profile::STATE_ACTIVE,
						$revision['lock_version'],
					)
				)
			);
		}
		if ( 1 !== $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Revision row.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @param string              $public_id Public id.
	 * @param int                 $revision_no Revision no.
	 * @param string              $state State.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $occurred_at_utc Occurred at utc.
	 * @return array<string,mixed>
	 */
	private function revision_row(
		array $prepared,
		string $public_id,
		int $revision_no,
		string $state,
		int $actor_user_id,
		string $occurred_at_utc
	): array {
		$stem = $prepared['stem'];
		$row  = array(
			'public_id'            => $public_id,
			'lock_version'         => 0,
			'created_by_user_id'   => $actor_user_id,
			'created_at_utc'       => $occurred_at_utc,
			'updated_by_user_id'   => $actor_user_id,
			'updated_at_utc'       => $occurred_at_utc,
			'subject_type'         => $prepared['subject_type'],
			'revision_no'          => $revision_no,
			'state'                => $state,
			$stem . '_schema'      => $prepared['schema'],
			$stem . '_version'     => $prepared['version'],
			$stem . '_json'        => $prepared['json'],
			$stem . '_hash'        => $prepared['hash'],
			'content_hash'         => $prepared['content_hash'],
			'activated_by_user_id' => Assessment_Configuration_Profile::STATE_ACTIVE === $state && $actor_user_id > 0 ? $actor_user_id : null,
			'activated_at_utc'     => Assessment_Configuration_Profile::STATE_ACTIVE === $state ? $occurred_at_utc : null,
			'retired_by_user_id'   => null,
			'retired_at_utc'       => null,
		);
		if ( Assessment_Configuration_Profile::RUBRIC === $prepared['resource_type'] ) {
			$row['total_points'] = $prepared['total_points'];
		} elseif ( Assessment_Configuration_Profile::REVIEW_RULE === $prepared['resource_type'] ) {
			$row['review_required']           = 1;
			$row['explicit_release_required'] = 1;
			$row['confidence_threshold']      = $prepared['confidence_threshold'];
		}
		return $row;
	}

	/**
	 * Reject invalid policy references.
	 *
	 * @param array<string,mixed> $policy Policy.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_policy_references( array $policy ): void {
		$value = $policy['value'];
		foreach (
			array(
				Assessment_Configuration_Profile::RUBRIC => $value['rubric_revision_id'] ?? null,
				Assessment_Configuration_Profile::REVIEW_RULE => $value['review_rule_revision_id'] ?? null,
			) as $resource_type => $revision_id
		) {
			if ( ! is_string( $revision_id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
			}
			$rows = $this->get_results(
				$this->prepare(
					'SELECT public_id, subject_type, state FROM ' . Assessment_Configuration_Profile::table( $resource_type )
						. ' WHERE public_id = %s LIMIT 2 FOR UPDATE',
					array( $revision_id )
				)
			);
			if (
				1 !== count( $rows )
				|| $policy['subject_type'] !== ( $rows[0]['subject_type'] ?? null )
				|| ! in_array( $rows[0]['state'] ?? null, array( Assessment_Configuration_Profile::STATE_ACTIVE, Assessment_Configuration_Profile::STATE_RETIRED ), true )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::VALIDATION_FAILED );
			}
		}
	}

	/**
	 * Insert receipt.
	 *
	 * @param array<string,mixed> $command Command.
	 * @param string              $aggregate_public_id Aggregate public id.
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $result Result.
	 * @return void
	 */
	private function insert_receipt( array $command, string $aggregate_public_id, string $event_type, array $result ): void {
		$payload      = array(
			'request_digest' => $command['request_digest'],
			'result'         => $result,
		);
		$payload_json = $this->canonical->encode( $payload );
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $this->ids->create()->value(),
				'lock_version'         => 0,
				'created_by_user_id'   => $command['actor_user_id'],
				'created_at_utc'       => $command['occurred_at_utc'],
				'updated_by_user_id'   => $command['actor_user_id'],
				'updated_at_utc'       => $command['occurred_at_utc'],
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => $aggregate_public_id,
				'event_type'           => $event_type,
				'idempotency_key'      => $command['idempotency_storage_key'],
				'payload_schema'       => self::RECEIPT_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $command['occurred_at_utc'],
				'published_at_utc'     => $command['occurred_at_utc'],
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?array {
		$sql = 'SELECT aggregate_type, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::AGGREGATE_TYPE !== ( $row['aggregate_type'] ?? null )
			|| self::RECEIPT_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::VERSION !== $this->integer( $row['payload_version'] ?? null, 1 )
			|| ! in_array( $row['event_type'] ?? null, array( 'assessment_configuration.revision_created', 'assessment_configuration.revision_activated' ), true )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		$this->exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ?? null ) || ! hash_equals( $request_digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		return $result;
	}

	/**
	 * Insert audit.
	 *
	 * @param string              $action_key Action key.
	 * @param array<string,mixed> $command Command.
	 * @param string              $object_public_id Object public id.
	 * @param string|null         $before_digest Before digest.
	 * @param string              $after_digest After digest.
	 * @param array<string,mixed> $metadata Metadata.
	 * @return void
	 */
	private function insert_audit(
		string $action_key,
		array $command,
		string $object_public_id,
		?string $before_digest,
		string $after_digest,
		array $metadata
	): void {
		$metadata = array_merge(
			array(
				'command_id'     => $command['command_id'],
				'request_digest' => $command['request_digest'],
				'resource_type'  => $command['resource_type'],
			),
			$metadata
		);
		$this->insert_audit_row(
			$action_key,
			$command['actor_user_id'],
			$command['correlation_id'],
			$object_public_id,
			$before_digest,
			$after_digest,
			$metadata,
			$command['occurred_at_utc']
		);
	}

	/**
	 * Insert seed audit.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $correlation_id Request correlation identifier for diagnostics.
	 * @param string                            $subject_type Subject type.
	 * @param array<string,array<string,mixed>> $records Records.
	 * @param string                            $object_public_id Object public id.
	 * @param string                            $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_seed_audit(
		int $actor_user_id,
		string $correlation_id,
		string $subject_type,
		array $records,
		string $object_public_id,
		string $occurred_at_utc
	): void {
		$hashes = array();
		foreach ( Assessment_Configuration_Profile::resource_types() as $resource_type ) {
			$hashes[ $resource_type ] = $records[ $resource_type ]['content_hash'];
		}
		$this->insert_audit_row(
			'assessment_configuration.seeded',
			$actor_user_id,
			$correlation_id,
			$object_public_id,
			null,
			hash( 'sha256', $this->canonical->encode( $hashes ) ),
			array(
				'package_owned'  => true,
				'subject_type'   => $subject_type,
				'revision_no'    => 1,
				'content_hashes' => $hashes,
			),
			$occurred_at_utc
		);
	}

	/**
	 * Insert audit row.
	 *
	 * @param string              $action_key Action key.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @param string              $object_public_id Object public id.
	 * @param string|null         $before_digest Before digest.
	 * @param string              $after_digest After digest.
	 * @param array<string,mixed> $metadata Metadata.
	 * @param string              $occurred_at_utc Occurred at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit_row(
		string $action_key,
		int $actor_user_id,
		string $correlation_id,
		string $object_public_id,
		?string $before_digest,
		string $after_digest,
		array $metadata,
		string $occurred_at_utc
	): void {
		$previous_hash = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		$metadata_json       = $this->canonical->encode( $metadata );
		$event_actor_user_id = $actor_user_id > 0 ? $actor_user_id : null;
		$metadata_hash       = hash( 'sha256', $metadata_json );
		$event_hash          = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $action_key,
					'actor_user_id'       => $event_actor_user_id,
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => $correlation_id,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $object_public_id,
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $occurred_at_utc,
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $this->ids->create()->value(),
				'occurred_at_utc'     => $occurred_at_utc,
				'actor_user_id'       => $event_actor_user_id,
				'action_key'          => $action_key,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => $object_public_id,
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $correlation_id,
				'before_digest'       => $before_digest,
				'after_digest'        => $after_digest,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @param string $resource_type Resource type.
	 * @return string
	 */
	private function columns( string $resource_type ): string {
		$stem    = Assessment_Configuration_Profile::stem( $resource_type );
		$columns = 'public_id, lock_version, created_by_user_id, created_at_utc, updated_by_user_id, updated_at_utc, subject_type, revision_no, state, ' . $stem . '_schema, ' . $stem . '_version, '
			. $stem . '_json, ' . $stem . '_hash, content_hash, activated_by_user_id, activated_at_utc, '
			. 'retired_by_user_id, retired_at_utc';
		if ( Assessment_Configuration_Profile::RUBRIC === $resource_type ) {
			$columns .= ', total_points';
		} elseif ( Assessment_Configuration_Profile::REVIEW_RULE === $resource_type ) {
			$columns .= ', review_required, explicit_release_required, confidence_threshold';
		}
		return $columns;
	}

	/**
	 * Reject invalid command.
	 *
	 * @param array<string,mixed> $command Command.
	 * @param bool                $activation Activation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_command( array $command, bool $activation ): void {
		$required = array(
			'actor_user_id',
			'command_id',
			'correlation_id',
			'idempotency_storage_key',
			'occurred_at_utc',
			'request_digest',
			'resource_type',
		);
		$required = array_merge(
			$required,
			$activation
				? array( 'expected_active_revision_id', 'expected_lock_version', 'expected_state', 'revision_id' )
				: array( 'expected_latest_revision_no', 'prepared' )
		);
		$this->exact_keys( $command, $required );
		if (
			! in_array( $command['resource_type'] ?? null, Assessment_Configuration_Profile::resource_types(), true )
			|| ! is_int( $command['actor_user_id'] ?? null ) || $command['actor_user_id'] < 1
			|| ! is_string( $command['command_id'] ?? null )
			|| ! is_string( $command['correlation_id'] ?? null )
			|| ! is_string( $command['idempotency_storage_key'] ?? null )
			|| 1 !== preg_match( '/^configuration:[a-f0-9]{64}$/D', $command['idempotency_storage_key'] )
			|| ! is_string( $command['request_digest'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $command['request_digest'] )
			|| ! is_string( $command['occurred_at_utc'] ?? null ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $command['occurred_at_utc'] )
		) {
			throw new InvalidArgumentException( 'Assessment configuration repository command is invalid.' );
		}
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Assessment configuration repository shape is invalid.' );
		}
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_json( string $json, string $hash ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $minimum Minimum.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function integer( $value, int $minimum ): int {
		if ( is_int( $value ) && $value >= $minimum ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) && (int) $value >= $minimum ) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORED_DATA_INVALID );
	}

	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->insert( $table, $data ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$database = $this->database();
		$this->reset_error( $database );
		$rows = $database->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $rows;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$database = $this->database();
		$this->reset_error( $database );
		$row = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function get_var( string $sql ) {
		$database = $this->database();
		$this->reset_error( $database );
		$value = $database->get_var( $sql );
		$this->assert_read( $database );
		return $value;
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->query( $sql ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Query affected.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_affected( string $sql ): int {
		$database = $this->database();
		$this->reset_error( $database );
		$result = $database->query( $sql );
		if ( ! is_int( $result ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
		return $result;
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			}
	}

	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}

	/**
	 * Reject invalid read.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $database ): void {
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_results', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assessment_Configuration_Failure::because( Assessment_Configuration_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $database;
	}
}
