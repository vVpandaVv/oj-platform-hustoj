<?php
/**
 * Active student-submission publication configuration reader.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Configuration_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Active student-submission publication configuration reader.
 */
final class WordPress_Worksheet_Publication_Configuration implements \OJAI\Dashboard\Worksheet\Authoring\Port\Scoped_Publication_Configuration_Port_Interface {
	private const TABLE_POLICIES = 'oj_ai_evaluation_policy_revisions';
	private const TABLE_RUBRICS  = 'oj_ai_rubric_revisions';
	private const TABLE_REVIEWS  = 'oj_ai_review_rule_revisions';
	private const SUBJECT        = 'student_submission';

	/**
	 * Initialize WordPress worksheet publication configuration dependencies and state.
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
	 * @param array  $document Document.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_worksheet( string $worksheet_id, array $document ): array {
		$pin     = $document['settings']['assessment_project'] ?? null;
		$project = $this->projects?->for_worksheet( $worksheet_id, $pin );
		if ( ( null === $project ) !== ( null === $pin ) ) {
			throw Worksheet_Failure::publication_configuration_unavailable();
		}
		$config = $this->configuration( $project );
		if ( null !== $project ) {
			$config['guard']['project_id']           = $project['project_id'];
			$config['guard']['project_worksheet_id'] = $worksheet_id;
			$config['guard']['project_content_hash'] = $project['content_hash'];
			$policy                                  = \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::policy( $project, $config['review_rule']['revision_id'] );
			$config['policy']                        = array_intersect_key( $policy, array_flip( array( 'revision_id', 'content_hash', 'subject_type' ) ) );
		}
		return $config;
	}

	/**
	 * Configuration.
	 *
	 * @param array|null $project Project.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function configuration( ?array $project ): array {
		try {
			$policies = $this->rows(
				$this->prepare(
					'SELECT public_id, subject_type, revision_no, state, policy_schema, policy_version, policy_json, policy_hash, content_hash '
						. 'FROM ' . self::TABLE_POLICIES . ' WHERE subject_type = %s AND state = %s ORDER BY revision_no DESC LIMIT 2',
					array( self::SUBJECT, 'active' )
				)
			);
			if ( 1 !== count( $policies ) ) {
				throw Worksheet_Failure::publication_configuration_unavailable();
			}
			$policy_row = $policies[0];
			$policy     = $this->payload( $policy_row, 'policy', 'oj_ai.student_submission_policy' );
			$this->exact_keys( $policy, array( 'assessment_enabled', 'review_rule_revision_id', 'rubric_revision_id' ) );
			if ( true !== ( $policy['assessment_enabled'] ?? null ) ) {
				throw Worksheet_Failure::publication_configuration_unavailable();
			}
			$rubric_id = $this->public_id( $policy['rubric_revision_id'] ?? null );
			$review_id = $this->public_id( $policy['review_rule_revision_id'] ?? null );

			if ( null === $project ) {
				$rubric_row = $this->one_by_id(
					self::TABLE_RUBRICS,
					$rubric_id,
					'criteria_schema, criteria_version, criteria_json, criteria_hash, total_points'
				);
				$criteria   = $this->payload( $rubric_row, 'criteria', 'oj_ai.student_submission_rubric' );
				$this->exact_keys( $criteria, array( 'criteria' ) );
				if ( ! is_array( $criteria['criteria'] ?? null ) || ! array_is_list( $criteria['criteria'] ) || ! is_string( $rubric_row['total_points'] ?? null ) ) {
					throw Worksheet_Failure::publication_configuration_unavailable();
				}
			} else {
				if ( 'confirmed' !== $project['state'] ) {
					throw Worksheet_Failure::publication_configuration_unavailable();
				}
				$rubric_row = array(
					'public_id'    => $project['revision_id'],
					'content_hash' => $project['rubric_content_hash'],
					'total_points' => $project['configuration']['total_points'],
				);
				$criteria   = array( 'criteria' => $project['configuration']['criteria'] );
			}
			$review_row = $this->one_by_id(
				self::TABLE_REVIEWS,
				$review_id,
				'rules_schema, rules_version, rules_json, rules_hash, review_required, explicit_release_required'
			);
			$rules      = $this->payload( $review_row, 'rules', 'oj_ai.student_submission_review_rule' );
			$this->exact_keys( $rules, array( 'abstain_handling', 'safety_handling' ) );
			if (
				1 !== (int) ( $review_row['review_required'] ?? 0 )
				|| 1 !== (int) ( $review_row['explicit_release_required'] ?? 0 )
				|| 'require_review' !== ( $rules['abstain_handling'] ?? null )
				|| 'require_explicit_review' !== ( $rules['safety_handling'] ?? null )
			) {
				throw Worksheet_Failure::publication_configuration_unavailable();
			}

			$guard = array(
				'policy_content_hash'      => (string) $policy_row['content_hash'],
				'policy_revision_id'       => (string) $policy_row['public_id'],
				'review_rule_content_hash' => (string) $review_row['content_hash'],
				'review_rule_revision_id'  => (string) $review_row['public_id'],
				'rubric_content_hash'      => (string) $rubric_row['content_hash'],
				'rubric_revision_id'       => (string) $rubric_row['public_id'],
			);

			return array(
				'guard'       => $guard,
				'policy'      => array(
					'content_hash' => $guard['policy_content_hash'],
					'revision_id'  => $guard['policy_revision_id'],
					'subject_type' => self::SUBJECT,
				),
				'review_rule' => array(
					'content_hash'              => $guard['review_rule_content_hash'],
					'explicit_release_required' => true,
					'review_required'           => true,
					'revision_id'               => $guard['review_rule_revision_id'],
				),
				'rubric'      => array(
					'content_hash' => $guard['rubric_content_hash'],
					'criteria'     => $criteria['criteria'],
					'revision_id'  => $guard['rubric_revision_id'],
					'total_points' => (string) $rubric_row['total_points'],
				),
			);
		} catch ( Worksheet_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw Worksheet_Failure::publication_configuration_unavailable();
		}
	}

	/**
	 * One by id.
	 *
	 * @param string $table Table.
	 * @param string $public_id Public id.
	 * @param string $columns Columns.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function one_by_id( string $table, string $public_id, string $columns ): array {
		$rows = $this->rows(
			$this->prepare(
				'SELECT public_id, subject_type, revision_no, state, content_hash, ' . $columns . ' FROM ' . $table
					. ' WHERE public_id = %s AND subject_type = %s AND state IN (%s, %s) LIMIT 2',
				array( $public_id, self::SUBJECT, 'active', 'retired' )
			)
		);
		if ( 1 !== count( $rows ) ) {
			throw Worksheet_Failure::publication_configuration_unavailable();
		}
		return $rows[0];
	}

	/**
	 * Payload.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $stem Stem.
	 * @param string              $schema Schema.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function payload( array $row, string $stem, string $schema ): array {
		$this->public_id( $row['public_id'] ?? null );
		$json         = $row[ $stem . '_json' ] ?? null;
		$hash         = $row[ $stem . '_hash' ] ?? null;
		$content_hash = $row['content_hash'] ?? null;
		if (
			self::SUBJECT !== ( $row['subject_type'] ?? null )
			|| ! in_array( $row['state'] ?? null, array( 'active', 'retired' ), true )
			|| $schema !== ( $row[ $stem . '_schema' ] ?? null )
			|| 1 !== (int) ( $row[ $stem . '_version' ] ?? 0 )
			|| ! is_string( $json ) || ! is_string( $hash ) || ! is_string( $content_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
			|| ! hash_equals( $hash, $content_hash )
		) {
			throw Worksheet_Failure::publication_configuration_unavailable();
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			throw Worksheet_Failure::publication_configuration_unavailable();
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				throw Worksheet_Failure::publication_configuration_unavailable();
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
			throw Worksheet_Failure::publication_configuration_unavailable();
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
			throw Worksheet_Failure::publication_configuration_unavailable();
		}
		new Public_Id( $value );
		return $value;
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
			throw Worksheet_Failure::storage_unavailable();
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
			throw Worksheet_Failure::storage_unavailable();
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
			throw Worksheet_Failure::storage_unavailable();
		}
		return $database;
	}
}
