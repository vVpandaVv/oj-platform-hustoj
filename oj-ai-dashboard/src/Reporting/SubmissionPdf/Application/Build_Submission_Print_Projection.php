<?php
/**
 * Builds the canonical teacher submission print projection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combines owner-validated Submission and Worksheet projections without cross-owner SQL.
 */
final class Build_Submission_Print_Projection {
	public const ARTIFACT_PROFILE_VERSION = 'submission_print.v1';
	public const SCHEMA                   = 'oj_ai.submission_print';
	public const VERSION                  = '1.0.0';
	public const PROJECTOR_VERSION        = '1.0.0';
	public const PROFILE                  = 'teacher_submission';
	public const PAGE_PROFILE             = 'worksheet_a4@1';

	/**
	 * Initialize submission print projection dependencies and state.
	 *
	 * @param Submission_Print_Source_Query_Port_Interface $submissions Submissions.
	 * @param Build_Worksheet_Print_Projection             $worksheets Worksheets.
	 * @param Submission_Response_Validator                $responses Responses.
	 * @param Canonical_Json                               $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Submission_Print_Source_Query_Port_Interface $submissions,
		private Build_Worksheet_Print_Projection $worksheets,
		private Submission_Response_Validator $responses,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Build.
	 *
	 * @param string $revision_id Revision id.
	 * @param string $expected_revision_hash Expected revision hash.
	 * @param string $expected_publication_hash Expected publication hash.
	 * @param string $expected_attachment_set_hash Expected attachment set hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build(
		string $revision_id,
		string $expected_revision_hash,
		string $expected_publication_hash,
		string $expected_attachment_set_hash
	): array {
		$this->assert_request( $revision_id, $expected_revision_hash, $expected_publication_hash, $expected_attachment_set_hash );
		try {
			$submission = $this->submissions->find_official_revision( $revision_id );
		} catch ( Assignment_Failure $failure ) {
			throw Submission_Pdf_Failure::because(
				Assignment_Failure::STORAGE_UNAVAILABLE === $failure->reason()
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					? Submission_Pdf_Failure::STORAGE_UNAVAILABLE
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					: Submission_Pdf_Failure::SOURCE_INVALID
			);
		}
		if ( null === $submission ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::NOT_FOUND );
		}

		$official            = $this->map( $submission['official_revision'] ?? null );
		$response            = $this->map( $official['response'] ?? null );
		$source              = $this->map( $submission['source'] ?? null );
		$context             = $this->map( $submission['context'] ?? null );
		$target              = $this->map( $submission['target'] ?? null );
		$receipt             = $this->map( $submission['receipt'] ?? null );
		$revision_hash       = $this->hash_value( $official['content_hash'] ?? null );
		$publication_hash    = $this->hash_value( $source['publication_hash'] ?? null );
		$attachment_set_hash = $this->hash_value( $official['attachment_set_hash'] ?? null );
		if (
			! hash_equals( $revision_id, $this->public_id( $official['id'] ?? null ) )
			|| ! hash_equals( $revision_id, $this->public_id( $submission['official_revision_id'] ?? null ) )
			|| ! hash_equals( $expected_revision_hash, $revision_hash )
			|| ! hash_equals( $expected_publication_hash, $publication_hash )
			|| ! hash_equals( $expected_attachment_set_hash, $attachment_set_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_STALE );
		}

		$publication_id = $this->public_id( $source['worksheet_publication_id'] ?? null );
		try {
			$worksheet = $this->worksheets->build_exact_publication( $publication_id, $publication_hash );
		} catch ( Worksheet_Failure $failure ) {
			$reason = match ( $failure->reason() ) {
				Worksheet_Failure::NOT_FOUND => Submission_Pdf_Failure::NOT_FOUND,
				Worksheet_Failure::PRINT_SOURCE_CONFLICT => Submission_Pdf_Failure::SOURCE_STALE,
				Worksheet_Failure::STORAGE_UNAVAILABLE => Submission_Pdf_Failure::STORAGE_UNAVAILABLE,
				default => Submission_Pdf_Failure::SOURCE_INVALID,
			};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( $reason );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}

		$p05             = $this->map( $worksheet['print']['projection'] ?? null );
		$p05_envelope    = $this->map( $p05['print_projection'] ?? null );
		$p05_payload     = $this->map( $p05_envelope['payload'] ?? null );
		$response_schema = $this->map( $p05_payload['response_schema'] ?? null );
		$stored_answers  = $this->map( $response['answers'] ?? null );
		if (
			! hash_equals( $publication_id, $this->public_id( $response['publication_id'] ?? null ) )
			|| ! hash_equals( $publication_hash, $this->hash_value( $response['publication_hash'] ?? null ) )
			|| '1.0.0' !== ( $response['response_schema_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		$profile = array(
			'payload'           => $response_schema,
			'payload_hash'      => hash( 'sha256', $this->canonical->encode( $response_schema ) ),
			'projector_version' => Worksheet_Preview_Projector::PROJECTOR_VERSION,
			'schema_id'         => Worksheet_Preview_Projector::RESPONSE_SCHEMA,
			'schema_version'    => Worksheet_Preview_Projector::RESPONSE_VERSION,
			'source_hash'       => $publication_hash,
		);
		try {
			$validated = $this->responses->validate_complete( $profile, $stored_answers );
		} catch ( Assignment_Failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		if ( ! hash_equals( $this->canonical->encode( $stored_answers ), $this->canonical->encode( $validated['answers'] ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		$attachments = $this->attachments(
			is_array( $submission['attachments'] ?? null ) ? $submission['attachments'] : array(),
			$validated['attachments'],
			$attachment_set_hash
		);

		$submission_id           = $this->public_id( $submission['id'] ?? null );
		$submission_lock_version = $this->non_negative_integer( $submission['lock_version'] ?? null );
		$assignment_id           = $this->public_id( $submission['assignment_id'] ?? null );
		$target_id               = $this->public_id( $target['id'] ?? null );
		$receipt_id              = $this->public_id( $receipt['id'] ?? null );
		$receipt_hash            = $this->hash_value( $receipt['content_hash'] ?? null );
		$this->assert_receipt( $receipt, $revision_id, $revision_hash, $publication_hash, $attachment_set_hash );
		$payload         = array(
			'profile'         => self::PROFILE,
			'layout'          => $p05_payload['layout'] ?? null,
			'document'        => $p05_payload['document'] ?? null,
			'response_schema' => $response_schema,
			'answers'         => $validated['answers'],
			'attachments'     => $attachments,
			'submission'      => array(
				'id'               => $submission_id,
				'state'            => $this->submission_state( $submission['state'] ?? null ),
				'attempt_no'       => $this->positive_integer( $submission['official_attempt_no'] ?? null ),
				'submitted_at_utc' => $this->utc( $submission['submitted_at_utc'] ?? null ),
			),
			'assignment'      => array(
				'id'      => $assignment_id,
				'title'   => $this->text( $submission['assignment_title'] ?? null, 255 ),
				'context' => array(
					'type'     => $this->context_type( $context['type'] ?? null ),
					'id'       => $this->token( $context['id'] ?? null, 191 ),
					'revision' => $this->token( $context['revision'] ?? null, 191 ),
				),
			),
			'learner'         => array(
				'target_id'    => $target_id,
				'display_name' => $this->text( $target['display_name'] ?? null, 255 ),
			),
			'provenance'      => array(
				'submission_revision_id'   => $revision_id,
				'submission_revision_hash' => $revision_hash,
				'publication_id'           => $publication_id,
				'publication_hash'         => $publication_hash,
				'attachment_set_hash'      => $attachment_set_hash,
				'receipt_id'               => $receipt_id,
				'receipt_hash'             => $receipt_hash,
			),
		);
		$payload_json    = $this->canonical->encode( $payload );
		$envelope        = array(
			'schema_id'         => self::SCHEMA,
			'schema_version'    => self::VERSION,
			'projector_version' => self::PROJECTOR_VERSION,
			'source_hash'       => $revision_hash,
			'payload_hash'      => hash( 'sha256', $payload_json ),
			'payload'           => $payload,
		);
		$projection      = array(
			'kind'                        => 'submission_print',
			'profile'                     => self::PROFILE,
			'submission_print_projection' => $envelope,
		);
		$projection_hash = hash( 'sha256', $this->canonical->encode( $projection ) );

		return array(
			'source'      => array(
				'type' => 'submission_revision',
				'id'   => $revision_id,
				'hash' => $revision_hash,
			),
			'context'     => $payload['assignment']['context'],
			'submission'  => array(
				'id'           => $submission_id,
				'state'        => $payload['submission']['state'],
				'lock_version' => $submission_lock_version,
			),
			'publication' => array(
				'id'   => $publication_id,
				'hash' => $publication_hash,
			),
			'print'       => array(
				'artifact_profile_version' => self::ARTIFACT_PROFILE_VERSION,
				'schema_id'                => self::SCHEMA,
				'schema_version'           => self::VERSION,
				'projector_version'        => self::PROJECTOR_VERSION,
				'profile'                  => self::PROFILE,
				'page_profile'             => self::PAGE_PROFILE,
				'locale'                   => (string) ( $payload['document']['locale'] ?? '' ),
				'profile_hash'             => hash( 'sha256', $this->canonical->encode( $envelope ) ),
				'projection_hash'          => $projection_hash,
				'projection'               => $projection,
			),
		);
	}

	/**
	 * Attachments.
	 *
	 * @param array<int,mixed>                                                                                           $stored Stored.
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> $expected Expected.
	 * @param string                                                                                                     $expected_set_hash Expected set hash.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachments( array $stored, array $expected, string $expected_set_hash ): array {
		$official = array();
		foreach ( $stored as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
			}
			$scope = $item['revision_scope'] ?? null;
			if ( is_array( $scope ) && in_array( 'official', $scope, true ) ) {
				$official[ $this->public_id( $item['id'] ?? null ) ] = $item;
			}
		}
		if ( count( $official ) !== count( $expected ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		$result = array();
		foreach ( $expected as $id => $expectation ) {
			$item = $official[ $id ] ?? null;
			if (
				! is_array( $item )
				|| 'bound' !== ( $item['state'] ?? null )
				|| ! hash_equals( $expectation['response_id'], (string) ( $item['response_id'] ?? '' ) )
				|| ! in_array( $item['mime_type'] ?? null, $expectation['mime_types'], true )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
			}
			$bytes = $this->non_negative_integer( $item['byte_size'] ?? null );
			if ( $bytes > $expectation['max_bytes'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
			}
			$result[] = array(
				'id'          => $id,
				'response_id' => $expectation['response_id'],
				'kind'        => $expectation['kind'],
				'role'        => $expectation['role'],
				'name'        => $this->file_name( $item['name'] ?? null ),
				'mime_type'   => (string) $item['mime_type'],
				'byte_size'   => $bytes,
				'sha256'      => $this->hash_value( $item['sha256'] ?? null ),
			);
		}
		$ids = array_keys( $expected );
		sort( $ids, SORT_STRING );
		if ( ! hash_equals( $expected_set_hash, hash( 'sha256', $this->canonical->encode( $ids ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		return $result;
	}

	/**
	 * Reject invalid receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param string              $revision_id Revision id.
	 * @param string              $revision_hash Revision hash.
	 * @param string              $publication_hash Publication hash.
	 * @param string              $attachment_hash Attachment hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_receipt( array $receipt, string $revision_id, string $revision_hash, string $publication_hash, string $attachment_hash ): void {
		if (
			! hash_equals( $revision_id, (string) ( $receipt['submission_revision_id'] ?? '' ) )
			|| ! hash_equals( $revision_hash, (string) ( $receipt['submission_revision_hash'] ?? '' ) )
			|| ! hash_equals( $publication_hash, (string) ( $receipt['publication_hash'] ?? '' ) )
			|| ! hash_equals( $attachment_hash, (string) ( $receipt['attachment_set_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Reject invalid request.
	 *
	 * @param string $id Id.
	 * @param string ...$hashes Hashes.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_request( string $id, string ...$hashes ): void {
		new Public_Id( $id );
		foreach ( $hashes as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Submission PDF source hash is invalid.' );
			}
		}
	}

	/**
	 * Map submission print projection.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function map( mixed $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
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
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
	}
	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
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
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
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
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum || 1 !== preg_match( '//u', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
	}
	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( mixed $value, int $maximum ): string {
		$value = $this->text( $value, $maximum );
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
	}
	/**
	 * File name.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function file_name( mixed $value ): string {
		$name = $this->text( $value, 255 );
		if ( basename( str_replace( '\\', '/', $name ) ) !== $name || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		return $name;
	}
	/**
	 * Utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
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
		if ( ! in_array( $value, array( 'class', 'group' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		return (string) $value;
	}
	/**
	 * Submission state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function submission_state( mixed $value ): string {
		if ( ! in_array( $value, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		return (string) $value;
	}
}
