<?php
/**
 * WordPress worksheet-owned source reader for self-evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Source;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Source_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds P04 source inputs from one owned immutable worksheet revision.
 */
final class WordPress_Worksheet_Evaluation_Source implements Worksheet_Evaluation_Source_Port_Interface {
	private const TABLE_REVISIONS       = 'oj_ai_worksheet_revisions';
	private const TABLE_WORKSHEET_LINKS = 'oj_ai_worksheet_achievement_links';
	private const TABLE_QUESTION_LINKS  = 'oj_ai_question_achievement_links';
	private const TABLE_QUESTIONS       = 'oj_ai_worksheet_revision_questions';

	/**
	 * Initialize WordPress worksheet evaluation source dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface      $worksheets Worksheets.
	 * @param Worksheet_Document_Canonicalizer         $documents Documents.
	 * @param Worksheet_Revision_Projector             $projector Projector.
	 * @param Worksheet_Asset_Lifecycle_Port_Interface $assets Assets.
	 * @param Canonical_Json                           $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                              $database Database.
	 */
	public function __construct(
		private Worksheet_Repository_Port_Interface $worksheets,
		private Worksheet_Document_Canonicalizer $documents,
		private Worksheet_Revision_Projector $projector,
		private Worksheet_Asset_Lifecycle_Port_Interface $assets,
		private Canonical_Json $canonical,
		private ?object $database = null
	) {
	}

	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return Worksheet_Evaluation_Source|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned( int $actor_user_id, string $revision_id ): ?Worksheet_Evaluation_Source {
		new Public_Id( $revision_id );
		try {
			$stored = $this->worksheets->find_owned_revision( $actor_user_id, $revision_id );
			if ( null === $stored ) {
				return null;
			}
			$worksheet_id     = (string) ( $stored['worksheet_id'] ?? '' );
			$revision         = $stored['revision'] ?? null;
			$teacher_document = $stored['teacher_document'] ?? null;
			$stored_questions = $stored['questions'] ?? null;
			if (
				! is_array( $revision ) || array_is_list( $revision )
				|| ! is_array( $teacher_document ) || array_is_list( $teacher_document )
				|| ! is_array( $stored_questions ) || ! array_is_list( $stored_questions )
			) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
			}

			$source      = $this->documents->canonicalize( $teacher_document );
			$source_hash = (string) ( $revision['document_hash'] ?? '' );
			$state       = (string) ( $revision['state'] ?? '' );
			if (
				array() !== $source->temporary_id_map()
				|| ! hash_equals( $revision_id, (string) ( $revision['id'] ?? '' ) )
				|| ! hash_equals( $source_hash, $source->document_hash() )
				|| ! in_array( $state, array( 'draft', 'approved' ), true )
			) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
			}

			$link_source = $this->achievement_source( $revision_id );
			$projection  = $this->projector->project(
				$source,
				$link_source['achievement_links'],
				$link_source['standard_snapshots']
			);
			$this->assert_questions_match( $stored_questions, $projection->questions() );
			$asset_snapshots = $this->asset_snapshots(
				$worksheet_id,
				$projection->asset_bindings()
			);

			return new Worksheet_Evaluation_Source(
				$worksheet_id,
				$revision_id,
				$state,
				$source_hash,
				$source,
				$projection,
				$asset_snapshots
			);
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Worksheet_Failure $failure ) {
			$reason = Worksheet_Failure::STORAGE_UNAVAILABLE === $failure->reason()
				? Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE
				: Worksheet_Evaluation_Failure::SOURCE_INVALID;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( $reason );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Achievement source.
	 *
	 * @param string $revision_id Revision id.
	 * @return array{achievement_links:array<int,array<string,mixed>>,standard_snapshots:array<int,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function achievement_source( string $revision_id ): array {
		$worksheet_sql  = 'SELECT l.standard_public_id, l.standard_source_version, l.standard_code_snapshot, '
			. 'l.standard_label_snapshot, l.alignment_type, l.position FROM ' . self::TABLE_WORKSHEET_LINKS . ' l '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.id = l.worksheet_revision_id '
			. 'WHERE r.public_id = %s ORDER BY l.position ASC, l.id ASC';
		$question_sql   = 'SELECT q.question_key AS response_id, l.standard_public_id, l.standard_source_version, '
			. 'l.standard_code_snapshot, l.standard_label_snapshot, l.alignment_type, l.position '
			. 'FROM ' . self::TABLE_QUESTION_LINKS . ' l '
			. 'INNER JOIN ' . self::TABLE_QUESTIONS . ' q ON q.id = l.question_id '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.id = q.worksheet_revision_id '
			. 'WHERE r.public_id = %s ORDER BY q.position ASC, l.position ASC, l.id ASC';
		$worksheet_rows = $this->rows( $this->prepare( $worksheet_sql, $revision_id ) );
		$question_rows  = $this->rows( $this->prepare( $question_sql, $revision_id ) );
		$links          = array();
		$snapshots      = array();

		foreach ( $worksheet_rows as $position => $row ) {
			if ( $position !== $this->non_negative_integer( $row['position'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
			}
			$links[] = $this->link( 'worksheet', null, $row, $snapshots );
		}
		$positions = array();
		foreach ( $question_rows as $row ) {
			$response_id = (string) ( $row['response_id'] ?? '' );
			new Public_Id( $response_id );
			$position = $positions[ $response_id ] ?? 0;
			if ( $position !== $this->non_negative_integer( $row['position'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
			}
			$positions[ $response_id ] = $position + 1;
			$links[]                   = $this->link( 'response', $response_id, $row, $snapshots );
		}
		ksort( $snapshots, SORT_STRING );

		return array(
			'achievement_links'  => $links,
			'standard_snapshots' => array_values( $snapshots ),
		);
	}

	/**
	 * Link.
	 *
	 * @param string                            $scope Scope.
	 * @param string|null                       $response_id Response id.
	 * @param array<string,mixed>               $row Row.
	 * @param array<string,array<string,mixed>> &$snapshots Snapshots.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function link( string $scope, ?string $response_id, array $row, array &$snapshots ): array {
		$standard_id = (string) ( $row['standard_public_id'] ?? '' );
		new Public_Id( $standard_id );
		$alignment = (string) ( $row['alignment_type'] ?? '' );
		if ( ! in_array( $alignment, Worksheet_Revision_Projector::ALIGNMENT_TYPES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
		}
		$snapshot = array(
			'standard_public_id'      => $standard_id,
			'standard_source_version' => (string) ( $row['standard_source_version'] ?? '' ),
			'standard_code_snapshot'  => (string) ( $row['standard_code_snapshot'] ?? '' ),
			'standard_label_snapshot' => (string) ( $row['standard_label_snapshot'] ?? '' ),
		);
		foreach ( array_slice( $snapshot, 1 ) as $value ) {
			if ( '' === $value ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
			}
		}
		if ( isset( $snapshots[ $standard_id ] ) && $snapshots[ $standard_id ] !== $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
		}
		$snapshots[ $standard_id ] = $snapshot;

		return array(
			'scope'              => $scope,
			'response_id'        => $response_id,
			'standard_public_id' => $standard_id,
			'alignment_type'     => $alignment,
		);
	}

	/**
	 * Reject invalid questions match.
	 *
	 * @param array<int,array<string,mixed>> $stored Stored.
	 * @param array<int,array<string,mixed>> $projected Projected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_questions_match( array $stored, array $projected ): void {
		$normalize_stored     = static fn ( array $question ): array => array(
			'question_key'     => $question['question_key'] ?? null,
			'position'         => $question['position'] ?? null,
			'response_type'    => $question['response_type'] ?? null,
			'question_schema'  => $question['question_schema'] ?? null,
			'question_version' => $question['question_version'] ?? null,
			'question'         => $question['question'] ?? null,
			'private_metadata' => $question['private_metadata'] ?? null,
		);
		$normalize_projected  = static fn ( array $question ): array => array(
			'question_key'     => $question['question_key'] ?? null,
			'position'         => $question['position'] ?? null,
			'response_type'    => $question['response_type'] ?? null,
			'question_schema'  => $question['question_schema'] ?? null,
			'question_version' => $question['question_version'] ?? null,
			'question'         => $question['question'] ?? null,
			'private_metadata' => $question['private_metadata'] ?? null,
		);
		$stored_normalized    = array_map( $normalize_stored, $stored );
		$projected_normalized = array_map( $normalize_projected, $projected );
		if ( ! hash_equals( $this->canonical->encode( $stored_normalized ), $this->canonical->encode( $projected_normalized ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Asset snapshots.
	 *
	 * @param string                         $worksheet_id Worksheet public identifier.
	 * @param array<int,array<string,mixed>> $bindings Bindings.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function asset_snapshots( string $worksheet_id, array $bindings ): array {
		$snapshots = array();
		foreach ( $bindings as $binding ) {
			$asset_id = (string) ( $binding['asset_id'] ?? '' );
			new Public_Id( $asset_id );
			$row = $this->assets->find_by_public_id( $asset_id );
			if (
				! is_array( $row )
				|| 'worksheet' !== (string) ( $row['owner_type'] ?? '' )
				|| ! hash_equals( $worksheet_id, (string) ( $row['owner_public_id'] ?? '' ) )
				|| 'worksheet_asset' !== (string) ( $row['purpose'] ?? '' )
				|| 'ready' !== (string) ( $row['state'] ?? '' )
				|| 'clean' !== (string) ( $row['scan_state'] ?? '' )
				|| null !== ( $row['purged_at_utc'] ?? null )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
			}
			$mime = (string) ( $row['mime_type'] ?? '' );
			$hash = (string) ( $row['sha256'] ?? '' );
			if (
				strlen( $mime ) > 127
				|| 1 !== preg_match( '#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#D', $mime )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
			}
			$snapshots[] = array(
				'asset_id'            => $asset_id,
				'kind'                => $this->asset_kind( $binding['references'] ?? null ),
				'mime_type'           => $mime,
				'sha256'              => $hash,
				'text_representation' => null,
			);
		}
		return $snapshots;
	}

	/**
	 * Asset kind.
	 *
	 * @param mixed $references References.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function asset_kind( $references ): string {
		if ( ! is_array( $references ) || ! array_is_list( $references ) || array() === $references ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
		}
		$kinds = array();
		foreach ( $references as $reference ) {
			$property = is_array( $reference ) ? (string) ( $reference['property'] ?? '' ) : '';
			$type     = is_array( $reference ) ? (string) ( $reference['node_type'] ?? '' ) : '';
			$kind     = 'poster_asset_id' === $property ? 'image' : match ( $type ) {
				'img'            => 'image',
				'audio'          => 'audio',
				'video'          => 'video',
				'file'           => 'file',
				'oj_ai_drawing'  => 'drawing',
				default          => '',
			};
			if ( '' === $kind ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
			}
			$kinds[ $kind ] = true;
		}
		if ( 1 !== count( $kinds ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
		}
		return (string) array_key_first( $kinds );
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
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::SOURCE_INVALID );
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, string $value ): string {
		$database = $this->database();
		$prepared = $database->prepare( $sql, $value );
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
