<?php
/**
 * D3-D4 historical assignment and exact learner target aggregate writer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Assignment_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Publication_Writer_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores closed historical assignments without opening its own transaction.
 * The outer migration batch owns publication, target, receipt, and checkpoint atomicity.
 */
final class WordPress_Legacy_Migration_Assignment_Writer implements Legacy_Migration_Assignment_Writer_Port_Interface {
	private const TABLE_RUNS          = 'oj_ai_import_runs';
	private const TABLE_ASSIGNMENTS   = 'oj_ai_assignments';
	private const TABLE_TARGETS       = 'oj_ai_assignment_targets';
	private const DISPLAY_SCHEMA      = 'oj_ai.assignment_target_display';
	private const RESUBMISSION_SCHEMA = 'oj_ai.assignment_resubmission_policy';
	private const PROFILE_VERSION     = 1;
	private const MAXIMUM_TARGETS     = 5000;

	/**
	 * Initialize WordPress legacy migration assignment writer dependencies and state.
	 *
	 * @param Canonical_Json                                     $canonical Canonical JSON encoder used for stable digests.
	 * @param Legacy_Migration_Publication_Writer_Port_Interface $publications Publications.
	 * @param Learner_Display_Snapshot_Port_Interface            $displays Displays.
	 * @param object|null                                        $database Database.
	 * @param Public_Id_Generator|null                           $ids Ids.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Legacy_Migration_Publication_Writer_Port_Interface $publications,
		private Learner_Display_Snapshot_Port_Interface $displays,
		private ?object $database = null,
		private ?Public_Id_Generator $ids = null
	) {
	}

	/**
	 * Write.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function write(
		int $run_database_id,
		Legacy_Source_Item $item,
		int $actor_user_id,
		string $created_at_utc
	): Legacy_Target_Write_Result {
		if ( $run_database_id < 1 || $actor_user_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		if ( 'worksheet_assignments' !== $item->source_type() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
		}
		$this->utc( $created_at_utc );
		$record = $item->record();
		$plan   = $this->run_plan( $run_database_id );
		$this->assert_source_checksum( $item, $plan );
		$projection  = $this->project( $item, $record, $plan );
		$publication = $this->publications->ensure_for_assignment(
			$run_database_id,
			$projection['template_source_id'],
			$actor_user_id,
			$created_at_utc
		);

		$assignment_public_id  = $this->ids()->create()->value();
		$target_rows           = $this->target_rows(
			$assignment_public_id,
			$projection['context'],
			$projection['learners'],
			$actor_user_id,
			$created_at_utc
		);
		$target_snapshot_hash  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'context'         => array(
						'id'       => $projection['context']['context_id'],
						'revision' => $projection['context']['context_revision'],
						'type'     => $projection['context']['context_type'],
					),
					'roster_revision' => $projection['context']['roster_revision'],
					'target_hashes'   => array_column( $target_rows, 'snapshot_hash' ),
				)
			)
		);
		$context_snapshot_hash = hash( 'sha256', $this->canonical->encode( $projection['context'] ) );
		$schedule_hash         = hash( 'sha256', $this->canonical->encode( $projection['schedule_hash_value'] ) );
		$resubmission_json     = $this->canonical->encode( $projection['resubmission_policy'] );
		$resubmission_hash     = hash( 'sha256', $resubmission_json );
		$content_hash          = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'assignment_public_id'     => $assignment_public_id,
					'context_snapshot_hash'    => $context_snapshot_hash,
					'publication_hash'         => $publication->publication_hash(),
					'resubmission_policy_hash' => $resubmission_hash,
					'schedule_hash'            => $schedule_hash,
					'source_checksum'          => $item->source_checksum(),
					'state'                    => $projection['state'],
					'target_snapshot_hash'     => $target_snapshot_hash,
					'title_snapshot'           => $projection['title'],
				)
			)
		);

		$this->insert_or_fail(
			self::TABLE_ASSIGNMENTS,
			array(
				'public_id'                       => $assignment_public_id,
				'lock_version'                    => 0,
				'created_by_user_id'              => $actor_user_id,
				'created_at_utc'                  => $created_at_utc,
				'updated_by_user_id'              => $actor_user_id,
				'updated_at_utc'                  => $created_at_utc,
				'context_type'                    => $projection['context']['context_type'],
				'context_id'                      => $projection['context']['context_id'],
				'context_revision'                => $projection['context']['context_revision'],
				'worksheet_publication_public_id' => $publication->publication_public_id(),
				'publication_hash'                => $publication->publication_hash(),
				'title_snapshot'                  => $projection['title'],
				'state'                           => $projection['state'],
				'opens_at_utc'                    => $projection['schedule']['opens_at_utc'],
				'due_at_utc'                      => $projection['schedule']['due_at_utc'],
				'closes_at_utc'                   => $projection['schedule']['closes_at_utc'],
				'target_roster_revision'          => $projection['context']['roster_revision'],
				'schedule_version'                => self::PROFILE_VERSION,
				'schedule_hash'                   => $schedule_hash,
				'auto_submit_enabled'             => $projection['schedule']['auto_submit_enabled'] ? 1 : 0,
				'auto_submit_completed_at_utc'    => null,
				'resubmission_policy_schema'      => self::RESUBMISSION_SCHEMA,
				'resubmission_policy_version'     => self::PROFILE_VERSION,
				'resubmission_policy_json'        => $resubmission_json,
				'resubmission_policy_hash'        => $resubmission_hash,
				'target_snapshot_hash'            => $target_snapshot_hash,
			)
		);
		$assignment_database_id = $this->insert_id();
		foreach ( $target_rows as $target ) {
			$target['assignment_id'] = $assignment_database_id;
			$this->insert_or_fail( self::TABLE_TARGETS, $target );
		}
		$this->assert_postcondition(
			$assignment_database_id,
			$assignment_public_id,
			$publication->publication_hash(),
			$target_snapshot_hash,
			count( $target_rows )
		);

		return new Legacy_Target_Write_Result(
			'assignment',
			$assignment_public_id,
			$content_hash,
			array(
				'assignment_source_key'    => $item->source_key(),
				'context_snapshot_hash'    => $context_snapshot_hash,
				'publication_hash'         => $publication->publication_hash(),
				'publication_public_id'    => $publication->publication_public_id(),
				'resubmission_policy_hash' => $resubmission_hash,
				'revision_document_hash'   => $publication->revision_document_hash(),
				'revision_public_id'       => $publication->revision_public_id(),
				'schedule_hash'            => $schedule_hash,
				'target_count'             => count( $target_rows ),
				'target_snapshot_hash'     => $target_snapshot_hash,
				'worksheet_public_id'      => $publication->worksheet_public_id(),
			)
		);
	}

	/**
	 * Run plan.
	 *
	 * @param int $run_database_id Run database id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function run_plan( int $run_database_id ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT state, source_kind, source_version, source_fingerprint, preview_schema, preview_version, preview_json, preview_hash FROM '
					. self::TABLE_RUNS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $run_database_id )
			)
		);
		if (
			null === $row
			|| 'running' !== ( $row['state'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_SCHEMA !== ( $row['preview_schema'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION !== $this->positive_integer( $row['preview_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$preview = $this->decode_stored( (string) ( $row['preview_json'] ?? '' ), (string) ( $row['preview_hash'] ?? '' ) );
		$this->exact_keys( $preview, array( 'plan', 'profile', 'state' ) );
		$plan = $preview['plan'] ?? null;
		if (
			Approve_Legacy_Migration::PREVIEW_PROFILE !== ( $preview['profile'] ?? null )
			|| 'approved' !== ( $preview['state'] ?? null )
			|| ! is_array( $plan )
			|| array_is_list( $plan )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		if ( Legacy_Migration_Plan::PREVIOUS_PROFILE === ( $plan['profile'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
		}
		try {
			( new Legacy_Migration_Plan( $this->canonical ) )->assert_executable( $plan );
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
		$source         = $plan['source'] ?? null;
		$source_profile = is_array( $source ) ? ( $source['profile'] ?? null ) : null;
		$source_kind    = is_string( $source_profile ) ? strstr( $source_profile, '@', true ) : false;
		if (
			Legacy_Migration_Plan::PROFILE !== ( $plan['profile'] ?? null )
			|| Legacy_Source_Profile_0_1_2::PROFILE !== $source_profile
			|| ! is_string( $source_kind )
			|| ! hash_equals( $source_kind, (string) ( $row['source_kind'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['version'] ?? '' ), (string) ( $row['source_version'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['fingerprint'] ?? '' ), (string) ( $row['source_fingerprint'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $plan;
	}

	/**
	 * Reject invalid source checksum.
	 *
	 * @param Legacy_Source_Item  $item Item.
	 * @param array<string,mixed> $plan Plan.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source_checksum( Legacy_Source_Item $item, array $plan ): void {
		$expected = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'profile'     => $plan['source']['profile'],
					'source_key'  => $item->source_key(),
					'source_type' => $item->source_type(),
					'record'      => $item->record(),
				)
			)
		);
		if ( ! hash_equals( $expected, $item->source_checksum() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Project WordPress legacy migration assignment writer.
	 *
	 * @param Legacy_Source_Item  $item Item.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $plan Plan.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function project( Legacy_Source_Item $item, array $record, array $plan ): array {
		try {
			$template_source_id = $this->positive_integer( $record['template_id'] ?? null );
			$title              = $this->text( $record['title'] ?? null, 255 );
			$status             = $record['status'] ?? null;
			if ( ! is_string( $status ) || ! in_array( $status, array( 'draft', 'assigned', 'closed', 'archived' ), true ) ) {
				throw new InvalidArgumentException( 'Legacy assignment state is invalid.' );
			}
			$deleted_at = $this->optional_utc( $record['deleted_at'] ?? null );
			$updated_at = $this->utc_value( $record['updated_at'] ?? null );
			$due_at     = null === ( $record['due_at'] ?? null ) ? $updated_at : $this->utc_value( $record['due_at'] );
			$settings   = $this->settings( $record['settings_json'] ?? null );
			$opens_at   = $this->optional_utc( $settings['starts_at'] ?? null );
			if ( null !== $opens_at && $opens_at > $due_at ) {
				throw new InvalidArgumentException( 'Legacy assignment schedule order is invalid.' );
			}
			$auto_submit = $settings['auto_submit_enabled'] ?? true;
			if ( ! is_bool( $auto_submit ) ) {
				throw new InvalidArgumentException( 'Legacy assignment auto-submit flag is invalid.' );
			}

			$mapping               = new Legacy_Mapping_Manifest( $this->canonical, (string) $plan['source']['profile'] );
			$context_source_ref    = $mapping->context_ref_for_assignment( $record );
			$assignment_source_ref = $mapping->assignment_ref( $item->source_id() );
			if ( null === $context_source_ref ) {
				throw new InvalidArgumentException( 'Legacy assignment Context is invalid.' );
			}
			$requirements = array();
			foreach ( $plan['mapping_manifest'] as $requirement ) {
				$requirements[ (string) $requirement['source_ref'] ] = $requirement;
			}
			$decisions = array();
			foreach ( $plan['mapping_decisions'] as $decision ) {
				$decisions[ (string) $decision['source_ref'] ] = $decision;
			}
			$context_requirement = $requirements[ $context_source_ref ] ?? null;
			$context_decision    = $decisions[ $context_source_ref ] ?? null;
			if (
				! is_array( $context_requirement )
				|| 'context' !== ( $context_requirement['kind'] ?? null )
				|| ! is_array( $context_decision )
				|| ! is_array( $context_decision['target'] ?? null )
			) {
				throw new InvalidArgumentException( 'Legacy assignment Context decision is missing.' );
			}
			$learners = array();
			foreach ( $plan['mapping_manifest'] as $requirement ) {
				if ( 'user' !== ( $requirement['kind'] ?? null ) || $assignment_source_ref !== ( $requirement['assignment_source_ref'] ?? null ) ) {
					continue;
				}
				if ( $context_source_ref !== ( $requirement['context_source_ref'] ?? null ) ) {
					throw new InvalidArgumentException( 'Legacy assignment learner Context drifted.' );
				}
				$decision = $decisions[ (string) $requirement['source_ref'] ] ?? null;
				if ( ! is_array( $decision ) || ! is_array( $decision['target'] ?? null ) ) {
					throw new InvalidArgumentException( 'Legacy assignment learner decision is missing.' );
				}
				$learners[] = array(
					'learner_user_id'     => $this->positive_integer( $decision['target']['learner_user_id'] ?? null ),
					'membership_revision' => $this->token( $decision['target']['membership_revision'] ?? null ),
					'source_ref'          => (string) $requirement['source_ref'],
				);
			}
			if ( count( $learners ) > self::MAXIMUM_TARGETS ) {
				throw new InvalidArgumentException( 'Legacy assignment target limit exceeded.' );
			}
			usort( $learners, static fn ( array $left, array $right ): int => array( $left['learner_user_id'], $left['source_ref'] ) <=> array( $right['learner_user_id'], $right['source_ref'] ) );
			$seen = array();
			foreach ( $learners as $learner ) {
				if ( isset( $seen[ $learner['learner_user_id'] ] ) ) {
					throw new InvalidArgumentException( 'Legacy assignment learner mapping is duplicated.' );
				}
				$seen[ $learner['learner_user_id'] ] = true;
			}
			$context = $context_decision['target'];
			return array(
				'template_source_id'  => $template_source_id,
				'title'               => $title,
				'state'               => null !== $deleted_at || in_array( $status, array( 'draft', 'archived' ), true ) ? 'withdrawn' : 'closed',
				'context'             => array(
					'context_id'       => $this->token( $context['context_id'] ?? null ),
					'context_revision' => $this->token( $context['context_revision'] ?? null ),
					'context_type'     => $this->context_type( $context['context_type'] ?? null ),
					'roster_revision'  => $this->token( $context['roster_revision'] ?? null ),
				),
				'learners'            => $learners,
				'schedule'            => array(
					'opens_at_utc'        => $opens_at,
					'due_at_utc'          => $due_at,
					'closes_at_utc'       => $due_at,
					'auto_submit_enabled' => $auto_submit,
				),
				'schedule_hash_value' => array(
					'auto_submit_enabled' => $auto_submit,
					'closes_at_utc'       => $due_at,
					'due_at_utc'          => $due_at,
					'opens_at_utc'        => $opens_at,
					'schedule_version'    => self::PROFILE_VERSION,
				),
				'resubmission_policy' => array(
					'copy_previous_answers' => false,
					'max_attempts'          => 1,
					'reopen_until_utc'      => null,
				),
			);
		} catch ( Legacy_Migration_Failure | Legacy_Target_Transformation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Target rows.
	 *
	 * @param string                                                                             $assignment_public_id Assignment public id.
	 * @param array<string,string>                                                               $context Access or execution context for this operation.
	 * @param array<int,array{learner_user_id:int,membership_revision:string,source_ref:string}> $learners Learners.
	 * @param int                                                                                $actor_user_id Authenticated actor from the access context.
	 * @param string                                                                             $created_at_utc Created at utc.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_rows( string $assignment_public_id, array $context, array $learners, int $actor_user_id, string $created_at_utc ): array {
		$rows = array();
		foreach ( $learners as $learner ) {
			try {
				$display      = $this->displays->snapshot( $learner['learner_user_id'] );
				$display_name = $this->canonical->text( trim( (string) ( $display['display_name'] ) ) );
				if ( '' === $display_name || strlen( $display_name ) > 255 ) {
					throw new InvalidArgumentException( 'Legacy assignment learner display is invalid.' );
				}
			} catch ( Throwable ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$display_json  = $this->canonical->encode( array( 'display_name' => $display_name ) );
			$display_hash  = hash( 'sha256', $display_json );
			$snapshot      = array(
				'context_id'            => $context['context_id'],
				'context_revision'      => $context['context_revision'],
				'context_type'          => $context['context_type'],
				'display_snapshot_hash' => $display_hash,
				'learner_user_id'       => $learner['learner_user_id'],
				'membership_revision'   => $learner['membership_revision'],
				'state'                 => 'active',
			);
			$snapshot_hash = hash( 'sha256', $this->canonical->encode( $snapshot ) );
			$public_id     = $this->ids()->create()->value();
			$rows[]        = array(
				'public_id'                => $public_id,
				'content_hash'             => hash(
					'sha256',
					$this->canonical->encode(
						array(
							'public_id'     => $public_id,
							'snapshot_hash' => $snapshot_hash,
						)
					)
				),
				'created_by_user_id'       => $actor_user_id,
				'created_at_utc'           => $created_at_utc,
				'target_type'              => $context['context_type'],
				'target_context_id'        => $context['context_id'],
				'target_context_revision'  => $context['context_revision'],
				'membership_revision'      => $learner['membership_revision'],
				'learner_user_id'          => $learner['learner_user_id'],
				'display_snapshot_schema'  => self::DISPLAY_SCHEMA,
				'display_snapshot_version' => self::PROFILE_VERSION,
				'display_snapshot_json'    => $display_json,
				'display_snapshot_hash'    => $display_hash,
				'snapshot_hash'            => $snapshot_hash,
				'state'                    => 'active',
			);
		}
		$issued = array_merge( array( $assignment_public_id ), array_column( $rows, 'public_id' ) );
		if ( count( $issued ) !== count( array_unique( $issued ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $rows;
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param int    $database_id Database id.
	 * @param string $public_id Public id.
	 * @param string $publication_hash Publication hash.
	 * @param string $target_hash Target hash.
	 * @param int    $target_count Target count.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( int $database_id, string $public_id, string $publication_hash, string $target_hash, int $target_count ): void {
		$row = $this->row(
			$this->prepare(
				'SELECT a.public_id, a.publication_hash, a.target_snapshot_hash, (SELECT COUNT(*) FROM ' . self::TABLE_TARGETS . ' t WHERE t.assignment_id = a.id) AS target_count FROM '
					. self::TABLE_ASSIGNMENTS . ' a WHERE a.id = %d LIMIT 1 FOR UPDATE',
				array( $database_id )
			)
		);
		if (
			null === $row
			|| ! hash_equals( $public_id, (string) ( $row['public_id'] ?? '' ) )
			|| ! hash_equals( $publication_hash, (string) ( $row['publication_hash'] ?? '' ) )
			|| ! hash_equals( $target_hash, (string) ( $row['target_snapshot_hash'] ?? '' ) )
			|| $target_count !== $this->non_negative_integer( $row['target_count'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Settings.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function settings( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( ! is_string( $value ) || strlen( $value ) > 1048576 ) {
			throw new InvalidArgumentException( 'Legacy assignment settings are invalid.' );
		}
		$decoded = json_decode( $value, true, 128, JSON_THROW_ON_ERROR );
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			throw new InvalidArgumentException( 'Legacy assignment settings are invalid.' );
		}
		return $decoded;
	}

	/**
	 * Decode stored.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_stored( string $json, string $hash ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Legacy assignment text is invalid.' );
		}
		$value = $this->canonical->text( trim( $value ) );
		if ( '' === $value || strlen( $value ) > $maximum ) {
			throw new InvalidArgumentException( 'Legacy assignment text is invalid.' );
		}
		return $value;
	}

	/**
	 * Context type.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_type( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
			throw new InvalidArgumentException( 'Legacy assignment Context type is invalid.' );
		}
		return $value;
	}

	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy assignment mapping token is invalid.' );
		}
		return $value;
	}

	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		if ( $value !== $this->utc_value( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return $this->utc_value( $value );
	}

	/**
	 * Utc value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy assignment UTC value is invalid.' );
		}

		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if (
			false === $date
			|| ( is_array( $errors ) && ( 0 < $errors['warning_count'] || 0 < $errors['error_count'] ) )
			|| $date->format( 'Y-m-d H:i:s' ) !== $value
		) {
			throw new InvalidArgumentException( 'Legacy assignment UTC value is invalid.' );
		}
		return $value;
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Legacy assignment integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
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
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->insert( $table, $data ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert id.
	 *
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_id(): int {
		try {
			return $this->positive_integer( $this->database()->insert_id ?? null );
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'insert' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}

	/**
	 * Ids.
	 *
	 * @return Public_Id_Generator
	 */
	private function ids(): Public_Id_Generator {
		return $this->ids ??= new Public_Id_Generator();
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
