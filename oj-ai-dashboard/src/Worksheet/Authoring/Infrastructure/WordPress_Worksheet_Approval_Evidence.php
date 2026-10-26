<?php
/**
 * WordPress worksheet approval evidence adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Approval_Evidence_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Configuration_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Query_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress worksheet approval evidence adapter.
 */
final class WordPress_Worksheet_Approval_Evidence implements Worksheet_Approval_Evidence_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_worksheet_evaluations';
	private const TABLE_REVISIONS   = 'oj_ai_worksheet_revisions';
	private const TABLE_WORKSHEETS  = 'oj_ai_worksheets';

	/**
	 * Initialize WordPress worksheet approval evidence dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Configuration_Port_Interface $configuration Configuration.
	 * @param Worksheet_Evaluation_Query_Port_Interface         $queries Queries.
	 * @param Canonical_Json                                    $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                                       $database Database.
	 */
	public function __construct(
		private Worksheet_Evaluation_Configuration_Port_Interface $configuration,
		private Worksheet_Evaluation_Query_Port_Interface $queries,
		private Canonical_Json $canonical,
		private ?object $database = null
	) {
	}

	/**
	 * Current.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function current( int $actor_user_id, string $worksheet_id, string $revision_id ): array {
		try {
			if ( $actor_user_id < 1 ) {
				throw new InvalidArgumentException( 'Worksheet approval evidence actor is invalid.' );
			}
			new Public_Id( $worksheet_id );
			new Public_Id( $revision_id );
			$configuration = $this->configuration instanceof \OJAI\Dashboard\Worksheet\Evaluation\Port\Scoped_Evaluation_Configuration_Port_Interface ? $this->configuration->execution() : $this->configuration->active();
			$guard         = $this->configuration_guard( $configuration );
			$policy        = new Worksheet_Approval_Policy(
				$guard['policy_revision_id'],
				Worksheet_Approval_Policy::EVALUATION_OPTIONAL,
				true,
				$this->canonical
			);

			$row = $this->row(
				$this->prepare(
					'SELECT e.public_id AS evaluation_id, e.lock_version, e.state, e.updated_at_utc, '
						. 'e.worksheet_revision_hash AS source_hash, e.policy_revision_public_id, e.policy_hash, '
						. 'e.rubric_revision_public_id, e.rubric_hash, '
						. 'e.review_rule_revision_public_id, e.review_rule_hash '
						. 'FROM ' . self::TABLE_EVALUATIONS . ' e '
						. 'INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.public_id = e.worksheet_revision_public_id '
						. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
						. 'WHERE e.worksheet_revision_public_id = %s AND w.public_id = %s AND w.owner_user_id = %d '
						. 'ORDER BY e.updated_at_utc DESC, e.public_id DESC LIMIT 1',
					array( $revision_id, $worksheet_id, $actor_user_id )
				)
			);

			$evaluation_guard = $this->empty_evaluation_guard();
			$detail           = null;
			if ( null !== $row ) {
				$evaluation_guard = $this->evaluation_guard( $row );
				if ( $this->pins_match( $guard, $evaluation_guard ) || ! $policy->evaluation_required() ) {
					$detail = $this->queries->find_owned( $actor_user_id, $evaluation_guard['evaluation_id'] );
					if ( ! is_array( $detail ) || array() === $detail ) {
						throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
					}
					$evaluation = $detail['evaluation'] ?? null;
					if (
						! is_array( $evaluation )
						|| ! hash_equals( $evaluation_guard['evaluation_id'], (string) ( $evaluation['id'] ?? '' ) )
						|| ! hash_equals( $evaluation_guard['state'], (string) ( $evaluation['state'] ?? '' ) )
						|| ! hash_equals( $evaluation_guard['updated_at_utc'], (string) ( $evaluation['updated_at_utc'] ?? '' ) )
					) {
						throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
					}
					$evaluation_guard['projection_hash'] = hash( 'sha256', $this->canonical->encode( $detail ) );
				}
			}

			return array(
				'policy'              => $policy,
				'evaluation_detail'   => $detail,
				'configuration_guard' => $guard,
				'evaluation_guard'    => $evaluation_guard,
			);
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Configuration guard.
	 *
	 * @param array<string,mixed> $configuration Configuration.
	 * @return array<string,string>
	 */
	private function configuration_guard( array $configuration ): array {
		$policy = $this->object( $configuration['policy'] ?? null );
		$rubric = $this->object( $configuration['rubric'] ?? null );
		$review = $this->object( $configuration['review_rule'] ?? null );
		return array(
			'policy_revision_id'       => $this->public_id( $policy['revision_id'] ?? null ),
			'policy_content_hash'      => $this->sha256( $policy['content_hash'] ?? null ),
			'rubric_revision_id'       => $this->public_id( $rubric['revision_id'] ?? null ),
			'rubric_content_hash'      => $this->sha256( $rubric['content_hash'] ?? null ),
			'review_rule_revision_id'  => $this->public_id( $review['revision_id'] ?? null ),
			'review_rule_content_hash' => $this->sha256( $review['content_hash'] ?? null ),
		);
	}

	/**
	 * Evaluation guard.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function evaluation_guard( array $row ): array {
		return array(
			'evaluation_id'            => $this->public_id( $row['evaluation_id'] ?? null ),
			'lock_version'             => $this->non_negative_integer( $row['lock_version'] ?? null ),
			'state'                    => $this->state( $row['state'] ?? null ),
			'updated_at_utc'           => $this->utc( $row['updated_at_utc'] ?? null ),
			'source_hash'              => $this->sha256( $row['source_hash'] ?? null ),
			'policy_revision_id'       => $this->public_id( $row['policy_revision_public_id'] ?? null ),
			'policy_content_hash'      => $this->sha256( $row['policy_hash'] ?? null ),
			'rubric_revision_id'       => $this->public_id( $row['rubric_revision_public_id'] ?? null ),
			'rubric_content_hash'      => $this->sha256( $row['rubric_hash'] ?? null ),
			'review_rule_revision_id'  => $this->public_id( $row['review_rule_revision_public_id'] ?? null ),
			'review_rule_content_hash' => $this->sha256( $row['review_rule_hash'] ?? null ),
			'projection_hash'          => null,
		);
	}

	/**
	 * Empty evaluation guard.
	 *
	 * @return array<string,mixed>
	 */
	private function empty_evaluation_guard(): array {
		return array(
			'evaluation_id'            => null,
			'lock_version'             => null,
			'state'                    => null,
			'updated_at_utc'           => null,
			'source_hash'              => null,
			'policy_revision_id'       => null,
			'policy_content_hash'      => null,
			'rubric_revision_id'       => null,
			'rubric_content_hash'      => null,
			'review_rule_revision_id'  => null,
			'review_rule_content_hash' => null,
			'projection_hash'          => null,
		);
	}

	/**
	 * Pins match.
	 *
	 * @param array<string,string> $configuration Configuration.
	 * @param array<string,mixed>  $evaluation Evaluation.
	 * @return bool
	 */
	private function pins_match( array $configuration, array $evaluation ): bool {
		foreach ( array_keys( $configuration ) as $key ) {
			if ( ! is_string( $evaluation[ $key ] ?? null ) || ! hash_equals( $configuration[ $key ], $evaluation[ $key ] ) ) {
				return false;
			}
		}
		return true;
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
			throw new InvalidArgumentException( 'Worksheet approval configuration object is invalid.' );
		}
		return $value;
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
			throw new InvalidArgumentException( 'Worksheet approval public ID is invalid.' );
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Sha256.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet approval hash is invalid.' );
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
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Worksheet approval lock version is invalid.' );
	}

	/**
	 * State.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function state( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'requested', 'queued', 'running', 'review_required', 'reviewed', 'failed', 'cancelled' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation state is invalid.' );
		}
		return $value;
	}

	/**
	 * Utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet approval UTC value is invalid.' );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
		$row = $this->database()->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $database;
	}
}
