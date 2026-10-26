<?php
/**
 * Assignment-owned C50 official submission bundle source.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Source_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only Submission-owned literal tables and emits server-generated archive paths.
 */
final class WordPress_Submission_Bundle_Source_Query implements Submission_Bundle_Source_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_ATTACHMENTS = 'oj_ai_submission_attachments';
	private const DISPLAY_SCHEMA    = 'oj_ai.assignment_target_display';

	/**
	 * WordPress submission bundle source query.
	 *
	 * @var array<string,string>
	 */
	private const MIME_EXTENSIONS = array(
		'application/json' => 'json',
		'application/pdf'  => 'pdf',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'image/jpeg'       => 'jpg',
		'image/png'        => 'png',
		'image/webp'       => 'webp',
		'text/csv'         => 'csv',
		'text/plain'       => 'txt',
	);

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress submission bundle source query dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * Read WordPress submission bundle source query.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param bool   $include_allowed_originals Include allowed originals.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read(
		string $assignment_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		bool $include_allowed_originals
	): ?array {
		if (
			! Public_Id::is_valid( $assignment_id )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| ! $this->is_context_token( $context_id )
			|| ! $this->is_context_token( $context_revision )
		) {
			throw new InvalidArgumentException( 'Submission bundle source query is invalid.' );
		}

		$assignment_row = $this->get_row(
			$this->prepare(
				'SELECT id AS assignment_pk, public_id AS assignment_id, updated_at_utc, '
				. 'worksheet_publication_public_id AS publication_id, publication_hash, title_snapshot, state, schedule_version, schedule_hash, '
				. 'target_roster_revision, target_snapshot_hash FROM ' . self::TABLE_ASSIGNMENTS
				. ' WHERE public_id = %s AND context_type = %s AND context_id = %s AND context_revision = %s AND state IN (%s,%s) LIMIT 1',
				array( $assignment_id, $context_type, $context_id, $context_revision, 'open', 'closed' )
			)
		);
		if ( null === $assignment_row ) {
			return null;
		}

		$assignment_pk       = $this->positive_integer( $assignment_row['assignment_pk'] ?? null );
		$limit               = Submission_Bundle_Request_Snapshot::MAXIMUM_ARCHIVE_FILES - 1;
		$rows                = $this->get_results(
			$this->prepare(
				'SELECT t.public_id AS target_id, t.content_hash AS target_hash, t.membership_revision, '
				. 't.display_snapshot_schema, t.display_snapshot_version, t.display_snapshot_json, t.display_snapshot_hash, t.snapshot_hash AS target_snapshot_hash, '
				. 's.public_id AS submission_id, s.updated_at_utc AS submission_updated_at_utc, s.state AS submission_state, s.submitted_at_utc, '
				. 'r.id AS revision_pk, r.public_id AS revision_id, r.content_hash AS revision_hash, r.attachment_set_hash '
				. 'FROM ' . self::TABLE_TARGETS . ' t INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s '
				. 'ON s.assignment_id = t.assignment_id AND s.learner_user_id = t.learner_user_id '
				. 'LEFT JOIN ' . self::TABLE_REVISIONS . ' r ON r.submission_id = s.id AND r.public_id = s.official_revision_public_id '
				. 'WHERE t.assignment_id = %d AND t.state = %s AND t.learner_user_id IS NOT NULL AND s.official_revision_public_id IS NOT NULL '
				. 'ORDER BY t.public_id ASC LIMIT %d',
				array( $assignment_pk, 'active', $limit )
			)
		);
		$maximum_submissions = Submission_Bundle_Request_Snapshot::MAXIMUM_ARCHIVE_FILES - 2;
		if ( count( $rows ) > $maximum_submissions ) {
			$this->too_large();
		}
		if ( array() === $rows ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_INVALID );
		}

		try {
			$submissions      = array();
			$revision_indexes = array();
			$revision_pks     = array();
			foreach ( $rows as $index => $row ) {
				$display       = $this->display_snapshot( $row );
				$target_id     = $this->public_id( $row['target_id'] ?? null );
				$submission_id = $this->public_id( $row['submission_id'] ?? null );
				$revision_id   = $this->public_id( $row['revision_id'] ?? null );
				$revision_pk   = $this->positive_integer( $row['revision_pk'] ?? null );
				if ( isset( $revision_indexes[ $revision_pk ] ) ) {
					$this->stored_invalid();
				}
				$revision_indexes[ $revision_pk ] = $index;
				$revision_pks[]                   = $revision_pk;
				$submissions[]                    = array(
					'target'            => array(
						'id'                  => $target_id,
						'hash'                => $this->hash_value( $row['target_hash'] ?? null ),
						'display_name'        => $display['display_name'],
						'membership_revision' => $this->token( $row['membership_revision'] ?? null ),
						'snapshot_hash'       => $this->hash_value( $row['target_snapshot_hash'] ?? null ),
					),
					'submission'        => array(
						'id'               => $submission_id,
						'hash'             => $this->submission_content_hash( $submission_id, $this->required_utc( $row['submission_updated_at_utc'] ?? null ) ),
						'state'            => $this->submission_state( $row['submission_state'] ?? null ),
						'submitted_at_utc' => $this->required_utc( $row['submitted_at_utc'] ?? null ),
					),
					'official_revision' => array(
						'id'                  => $revision_id,
						'hash'                => $this->hash_value( $row['revision_hash'] ?? null ),
						'attachment_set_hash' => $this->hash_value( $row['attachment_set_hash'] ?? null ),
					),
					'pdf_path'          => sprintf( 'submissions/%04d-%s.pdf', $index + 1, strtolower( $target_id ) ),
					'attachments'       => array(),
				);
			}

			if ( $include_allowed_originals ) {
				$this->attach_originals( $submissions, $revision_indexes, $revision_pks );
			}

			$source                = array(
				'schema_id'      => Submission_Bundle_Request_Snapshot::SOURCE_SCHEMA,
				'schema_version' => Submission_Bundle_Request_Snapshot::SOURCE_VERSION,
				'context'        => array(
					'type'     => $context_type,
					'id'       => $context_id,
					'revision' => $context_revision,
				),
				'assignment'     => array(
					'id'                     => $this->public_id( $assignment_row['assignment_id'] ?? null ),
					'hash'                   => $this->assignment_content_hash( $this->public_id( $assignment_row['assignment_id'] ?? null ), $this->required_utc( $assignment_row['updated_at_utc'] ?? null ) ),
					'publication_id'         => $this->public_id( $assignment_row['publication_id'] ?? null ),
					'publication_hash'       => $this->hash_value( $assignment_row['publication_hash'] ?? null ),
					'title'                  => $this->text( $assignment_row['title_snapshot'] ?? null, 255 ),
					'state'                  => $this->assignment_state( $assignment_row['state'] ?? null ),
					'schedule_version'       => $this->positive_integer( $assignment_row['schedule_version'] ?? null ),
					'schedule_hash'          => $this->hash_value( $assignment_row['schedule_hash'] ?? null ),
					'target_roster_revision' => $this->token( $assignment_row['target_roster_revision'] ?? null ),
					'target_snapshot_hash'   => $this->hash_value( $assignment_row['target_snapshot_hash'] ?? null ),
				),
				'submissions'    => $submissions,
			);
			$source['source_hash'] = hash( 'sha256', $this->canonical->encode( $source ) );
			Submission_Bundle_Request_Snapshot::assert_source( $source, $include_allowed_originals, $this->canonical );
			return $source;
		} catch ( Submission_Bundle_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Attach originals.
	 *
	 * @param array<int,array<string,mixed>> &$submissions Submissions.
	 * @param array<int,int>                 $revision_indexes Revision indexes.
	 * @param array<int,int>                 $revision_pks Revision pks.
	 * @return void
	 */
	private function attach_originals( array &$submissions, array $revision_indexes, array $revision_pks ): void {
		$remaining    = Submission_Bundle_Request_Snapshot::MAXIMUM_ARCHIVE_FILES - 2 - count( $submissions );
		$placeholders = implode( ', ', array_fill( 0, count( $revision_pks ), '%d' ) );
		$values       = $revision_pks;
		$values[]     = 'bound';
		$values[]     = $remaining + 1;
		$rows         = $this->get_results(
			$this->prepare(
				'SELECT public_id, submission_revision_id, response_key, file_public_id, original_name, mime_type, byte_size, sha256 '
				. 'FROM ' . self::TABLE_ATTACHMENTS . ' WHERE submission_revision_id IN (' . $placeholders . ') AND state = %s '
				. 'ORDER BY submission_revision_id ASC, public_id ASC LIMIT %d',
				$values
			)
		);
		if ( count( $rows ) > $remaining ) {
			$this->too_large();
		}

		$total_bytes = 0;
		foreach ( $rows as $row ) {
			$revision_pk = $this->positive_integer( $row['submission_revision_id'] ?? null );
			$index       = $revision_indexes[ $revision_pk ] ?? null;
			if ( ! is_int( $index ) || ! isset( $submissions[ $index ] ) ) {
				$this->stored_invalid();
			}
			$attachment_id = $this->public_id( $row['public_id'] ?? null );
			$file_id       = $this->public_id( $row['file_public_id'] ?? null );
			$mime          = $row['mime_type'] ?? null;
			if ( ! is_string( $mime ) || ! isset( self::MIME_EXTENSIONS[ $mime ] ) ) {
				$this->stored_invalid();
			}
			$bytes        = $this->positive_integer( $row['byte_size'] ?? null );
			$total_bytes += $bytes;
			if ( $total_bytes > Submission_Bundle_Request_Snapshot::MAXIMUM_ARCHIVE_BYTES ) {
				$this->too_large();
			}
			$target_id                              = (string) $submissions[ $index ]['target']['id'];
			$submissions[ $index ]['attachments'][] = array(
				'id'            => $attachment_id,
				'file_id'       => $file_id,
				'response_key'  => $this->token( $row['response_key'] ?? null, 100 ),
				'original_name' => $this->original_name( $row['original_name'] ?? null ),
				'mime_type'     => $mime,
				'byte_size'     => $bytes,
				'sha256'        => $this->hash_value( $row['sha256'] ?? null ),
				'archive_path'  => sprintf(
					'attachments/%04d-%s/%s.%s',
					$index + 1,
					strtolower( $target_id ),
					strtolower( $attachment_id ),
					self::MIME_EXTENSIONS[ $mime ]
				),
			);
		}
	}

	/**
	 * Display snapshot.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{display_name:string}
	 */
	private function display_snapshot( array $row ): array {
		if ( self::DISPLAY_SCHEMA !== ( $row['display_snapshot_schema'] ?? null ) || 1 !== $this->positive_integer( $row['display_snapshot_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$json = $row['display_snapshot_json'] ?? null;
		$hash = $this->hash_value( $row['display_snapshot_hash'] ?? null );
		if ( ! is_string( $json ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		$display = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
		if (
			! is_array( $display ) || array_keys( $display ) !== array( 'display_name' )
			|| ! is_string( $display['display_name'] ) || '' === trim( $display['display_name'] )
			|| strlen( $display['display_name'] ) > 1000 || ! hash_equals( $json, $this->canonical->encode( $display ) )
		) {
			$this->stored_invalid();
		}
		return array( 'display_name' => $this->canonical->text( $display['display_name'] ) );
	}

	/**
	 * Original name.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function original_name( mixed $value ): string {
		$name = $this->text( $value, 255 );
		if ( basename( str_replace( '\\', '/', $name ) ) !== $name || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			$this->stored_invalid();
		}
		return $name;
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function token( mixed $value, int $maximum = 191 ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum ) {
			$this->stored_invalid();
		} return $this->canonical->text( $value );
	}
	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function required_utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Assignment state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function assignment_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'open', 'closed' ), true ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * `oj_ai_assignments` has no stored content-hash column (it uses the MUT
	 * profile, not IMM) -- derive a stable, deterministic staleness marker
	 * from the assignment's public id and its own updated_at_utc instead,
	 * which changes exactly when the row itself changes.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $updated_at_utc Updated at utc.
	 * @return string
	 */
	private function assignment_content_hash( string $assignment_id, string $updated_at_utc ): string {
		return hash( 'sha256', $assignment_id . '|' . $updated_at_utc );
	}
	/**
	 * `oj_ai_submissions` has no stored content-hash column either (MUT profile, not IMM) -- same derivation, scoped to the submission's own id and updated_at_utc.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $updated_at_utc Updated at utc.
	 * @return string
	 */
	private function submission_content_hash( string $submission_id, string $updated_at_utc ): string {
		return hash( 'sha256', $submission_id . '|' . $updated_at_utc );
	}
	/**
	 * Submission state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function submission_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 1 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Is context token.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_context_token( string $value ): bool {
		return '' !== $value && strlen( $value ) <= 191 && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
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
		$prepared = $this->db()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		} return $prepared;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$db = $this->db();
		$this->reset( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		} if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		} return $row;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$db = $this->db();
		$this->reset( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_ok( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		} foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
			}
		} return $rows;
	}
	/**
	 * Db.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		} foreach ( array( 'prepare', 'get_row', 'get_results' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
			}
		} return $db;
	}
	/**
	 * Reset.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORED_DATA_INVALID );
	}
	/**
	 * Too large.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function too_large(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::TOO_LARGE );
	}
}
