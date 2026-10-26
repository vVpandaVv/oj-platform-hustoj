<?php
/**
 * Active worksheet-quality configuration reader.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Configuration_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves one active policy and the exact rubric/review revisions it pins.
 */
final class WordPress_Worksheet_Evaluation_Configuration implements \OJAI\Dashboard\Worksheet\Evaluation\Port\Scoped_Evaluation_Configuration_Port_Interface {
	private const TABLE_POLICIES  = 'oj_ai_evaluation_policy_revisions';
	private const TABLE_RUBRICS   = 'oj_ai_rubric_revisions';
	private const TABLE_REVIEWS   = 'oj_ai_review_rule_revisions';
	private const SUBJECT         = 'worksheet_quality';
	private const STUDENT_SUBJECT = 'student_submission';

	/**
	 * Initialize WordPress worksheet evaluation configuration dependencies and state.
	 *
	 * @param Canonical_Json                                                                 $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                                                                    $database Database.
	 * @param \OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface|null $projects Projects.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null,
		private ?\OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface $projects = null
	) {
	}

	/**
	 * Active.
	 *
	 * @return array<string,mixed>
	 */
	public function active(): array {
		return $this->configuration( null );
	}
	/**
	 * For worksheet.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array
	 */
	public function for_worksheet( string $worksheet_id ): array {
		return $this->configuration( $this->projects?->for_worksheet( $worksheet_id ) );
	}
	/**
	 * Execution.
	 *
	 * @return array
	 */
	public function execution(): array {
		return $this->configuration( null, false );
	}
	/**
	 * Configuration.
	 *
	 * @param array|null $project Project.
	 * @param bool       $with_grading With grading.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function configuration( ?array $project, bool $with_grading = true ): array {
		try {
			$policies = $this->rows(
				$this->prepare(
					'SELECT public_id, subject_type, revision_no, state, policy_schema, policy_version, '
						. 'policy_json, policy_hash, content_hash FROM ' . self::TABLE_POLICIES
						. ' WHERE subject_type = %s AND state = %s ORDER BY revision_no DESC LIMIT 2',
					array( self::SUBJECT, 'active' )
				)
			);
			if ( 1 !== count( $policies ) ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
			}
			$policy_row = $policies[0];
			$policy     = $this->payload( $policy_row, 'policy', 'oj_ai.worksheet_quality_policy' );
			$this->exact_keys(
				$policy,
				array( 'evaluation_enabled', 'rubric_revision_id', 'review_rule_revision_id', 'prompt', 'input_schema', 'output_schema' )
			);
			if ( true !== ( $policy['evaluation_enabled'] ?? null ) ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
			}
			$rubric_id = $this->public_id( $policy['rubric_revision_id'] ?? null );
			$review_id = $this->public_id( $policy['review_rule_revision_id'] ?? null );

			$prompt = $this->object( $policy['prompt'] ?? null );
			$this->exact_keys( $prompt, array( 'contract_version', 'content_hash' ) );
			if (
				Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT !== ( $prompt['contract_version'] ?? null )
				|| Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH !== ( $prompt['content_hash'] ?? null )
			) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::PROMPT_UNAVAILABLE );
			}

			$input_schema  = $this->schema_descriptor(
				$policy['input_schema'] ?? null,
				Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA,
				Worksheet_Evaluation_Input_Builder::REQUEST_VERSION
			);
			$output_schema = $this->schema_descriptor(
				$policy['output_schema'] ?? null,
				Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA,
				Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION
			);

			$rubric_row = $this->one_by_id(
				self::TABLE_RUBRICS,
				$rubric_id,
				'criteria_schema, criteria_version, criteria_json, criteria_hash, total_points'
			);
			$criteria   = $this->payload( $rubric_row, 'criteria', 'oj_ai.worksheet_quality_rubric' );
			$this->exact_keys( $criteria, array( 'dimensions' ) );
			if ( null !== ( $rubric_row['total_points'] ?? null ) || ! is_array( $criteria['dimensions'] ?? null ) ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
			}

			$review_row = $this->one_by_id(
				self::TABLE_REVIEWS,
				$review_id,
				'rules_schema, rules_version, rules_json, rules_hash, review_required, '
					. 'explicit_release_required, confidence_threshold'
			);
			$rules      = $this->payload( $review_row, 'rules', 'oj_ai.worksheet_quality_review_rule' );
			$this->exact_keys( $rules, array( 'abstain_handling', 'safety_handling' ) );
			if (
				1 !== (int) ( $review_row['review_required'] ?? 0 )
				|| 1 !== (int) ( $review_row['explicit_release_required'] ?? 0 )
				|| 'require_review' !== ( $rules['abstain_handling'] ?? null )
				|| 'require_explicit_review' !== ( $rules['safety_handling'] ?? null )
			) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
			}

			$result = array(
				'policy'        => array(
					'revision_id'  => (string) $policy_row['public_id'],
					'content_hash' => (string) $policy_row['content_hash'],
					'subject_type' => self::SUBJECT,
				),
				'rubric'        => array(
					'revision_id'  => $rubric_id,
					'content_hash' => (string) $rubric_row['content_hash'],
					'dimensions'   => $criteria['dimensions'],
				),
				'review_rule'   => array(
					'revision_id'               => $review_id,
					'content_hash'              => (string) $review_row['content_hash'],
					'review_required'           => true,
					'explicit_release_required' => true,
					'confidence_threshold'      => $this->decimal( $review_row['confidence_threshold'] ?? null ),
					'abstain_handling'          => 'require_review',
					'safety_handling'           => 'require_explicit_review',
				),
				'prompt'        => $prompt,
				'input_schema'  => $input_schema,
				'output_schema' => $output_schema,
			);
			if ( $with_grading ) {
				$result['grading_rubric'] = null === $project ? $this->student_rubric() : array( 'assessment_enabled' => true ) + \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::rubric( $project );
			}
			return $result;
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
	}

	/**
	 * One by id.
	 *
	 * @param string $table Table.
	 * @param string $id Id.
	 * @param string $additional_columns Additional columns.
	 * @param string $subject Subject.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function one_by_id( string $table, string $id, string $additional_columns, string $subject = self::SUBJECT ): array {
		$rows = $this->rows(
			$this->prepare(
				'SELECT public_id, subject_type, revision_no, state, content_hash, ' . $additional_columns
					. ' FROM ' . $table . ' WHERE public_id = %s AND subject_type = %s AND state IN (%s, %s) LIMIT 2',
				array( $id, $subject, 'active', 'retired' )
			)
		);
		if ( 1 !== count( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		return $rows[0];
	}

	/**
	 * Payload.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $stem Stem.
	 * @param string              $schema Schema.
	 * @param string              $subject Subject.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function payload( array $row, string $stem, string $schema, string $subject = self::SUBJECT ): array {
		$id = $this->public_id( $row['public_id'] ?? null );
		unset( $id );
		$json         = $row[ $stem . '_json' ] ?? null;
		$hash         = $row[ $stem . '_hash' ] ?? null;
		$content_hash = $row['content_hash'] ?? null;
		if (
			$subject !== ( $row['subject_type'] ?? null )
			|| ! in_array( $row['state'] ?? null, array( 'active', 'retired' ), true )
			|| $schema !== ( $row[ $stem . '_schema' ] ?? null )
			|| 1 !== (int) ( $row[ $stem . '_version' ] ?? 0 )
			|| ! is_string( $json )
			|| ! is_string( $hash )
			|| ! is_string( $content_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
			|| ! hash_equals( $hash, $content_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Student rubric.
	 *
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function student_rubric(): array {
		$policies = $this->rows(
			$this->prepare(
				'SELECT public_id, subject_type, revision_no, state, policy_schema, policy_version, '
					. 'policy_json, policy_hash, content_hash FROM ' . self::TABLE_POLICIES
					. ' WHERE subject_type = %s AND state = %s ORDER BY revision_no DESC LIMIT 2',
				array( self::STUDENT_SUBJECT, 'active' )
			)
		);
		if ( 1 !== count( $policies ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		$policy = $this->payload(
			$policies[0],
			'policy',
			'oj_ai.student_submission_policy',
			self::STUDENT_SUBJECT
		);
		$this->exact_keys( $policy, array( 'assessment_enabled', 'rubric_revision_id', 'review_rule_revision_id' ) );
		if ( ! is_bool( $policy['assessment_enabled'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		$rubric_id  = $this->public_id( $policy['rubric_revision_id'] ?? null );
		$rubric_row = $this->one_by_id(
			self::TABLE_RUBRICS,
			$rubric_id,
			'criteria_schema, criteria_version, criteria_json, criteria_hash, total_points',
			self::STUDENT_SUBJECT
		);
		$criteria   = $this->payload(
			$rubric_row,
			'criteria',
			'oj_ai.student_submission_rubric',
			self::STUDENT_SUBJECT
		);
		$this->exact_keys( $criteria, array( 'criteria' ) );
		if ( ! is_string( $rubric_row['total_points'] ?? null ) || ! is_array( $criteria['criteria'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		return array(
			'assessment_enabled' => $policy['assessment_enabled'],
			'revision_id'        => $rubric_id,
			'content_hash'       => (string) $rubric_row['content_hash'],
			'total_points'       => (string) $rubric_row['total_points'],
			'criteria'           => $criteria['criteria'],
		);
	}

	/**
	 * Schema descriptor.
	 *
	 * @param mixed  $value Value.
	 * @param string $id Id.
	 * @param string $version Version.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function schema_descriptor( $value, string $id, string $version ): array {
		$value = $this->object( $value );
		$this->exact_keys( $value, array( 'schema_id', 'schema_version', 'content_hash' ) );
		if (
			$id !== ( $value['schema_id'] ?? null )
			|| $version !== ( $value['schema_version'] ?? null )
			|| ! is_string( $value['content_hash'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value['content_hash'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function object( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0(?:\.[0-9]{1,4})?|1(?:\.0{1,4})?)$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE );
		}
		$value = rtrim( rtrim( $value, '0' ), '.' );
		return '' === $value ? '0' : $value;
	}

	/**
	 * Prepare.
	 *
	 * @param string            $sql Sql.
	 * @param array<int,string> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$database = $this->database();
		$prepared = $database->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$database = $this->database();
		$rows     = $database->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $rows ) || '' !== (string) ( $database->last_error ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $rows;
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_results' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $database;
	}
}
