<?php
/**
 * ReadWorksheetPreview query use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ReadWorksheetPreview query use case.
 */
final class Read_Worksheet_Preview {
	/**
	 * Initialize worksheet preview dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface $worksheets Worksheets.
	 * @param Worksheet_Document_Canonicalizer    $documents Documents.
	 * @param Worksheet_Preview_Projector         $previews Previews.
	 */
	public function __construct(
		private Worksheet_Repository_Port_Interface $worksheets,
		private Worksheet_Document_Canonicalizer $documents,
		private Worksheet_Preview_Projector $previews
	) {
	}

	/**
	 * Read worksheet preview.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @param string $kind Kind.
	 * @param string $profile Profile.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read(
		int $actor_user_id,
		string $revision_id,
		string $kind,
		string $profile
	): array {
		if ( $actor_user_id < 1 || ! in_array( $kind, array( 'student', 'print' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet preview query is invalid.' );
		}
		new Public_Id( $revision_id );
		if (
			( 'student' === $kind && 'student' !== $profile )
			|| ( 'print' === $kind && ! in_array( $profile, Worksheet_Preview_Projector::PRINT_PROFILES, true ) )
		) {
			throw new InvalidArgumentException( 'Worksheet preview profile is invalid.' );
		}

		$source = $this->worksheets->find_owned_revision( $actor_user_id, $revision_id );
		if ( ! is_array( $source ) ) {
			throw Worksheet_Failure::not_found();
		}
		$revision         = $source['revision'] ?? null;
		$teacher_document = $source['teacher_document'] ?? null;
		$worksheet_id     = $source['worksheet_id'] ?? null;
		if (
			! is_array( $revision ) || array_is_list( $revision )
			|| ! is_array( $teacher_document ) || array_is_list( $teacher_document )
			|| ! is_string( $worksheet_id )
			|| ! hash_equals( $revision_id, (string) ( $revision['id'] ?? '' ) )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		try {
			$document = $this->documents->canonicalize( $teacher_document );
		} catch ( Worksheet_Document_Validation_Failure | InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$document_hash   = (string) ( $revision['document_hash'] ?? '' );
		$validation_hash = (string) ( $revision['validation_hash'] ?? '' );
		if (
			array() !== $document->temporary_id_map()
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $document_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $validation_hash )
			|| ! hash_equals( $document_hash, $document->document_hash() )
			|| ! hash_equals( $validation_hash, $document->validation_hash() )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		try {
			$preview = 'student' === $kind
				? $this->previews->student( $document )
				: $this->previews->print( $document, $profile );
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		return array(
			'worksheet_id' => $worksheet_id,
			'revision'     => array(
				'id'              => $revision_id,
				'state'           => (string) ( $revision['state'] ?? '' ),
				'document_hash'   => $document_hash,
				'validation_hash' => $validation_hash,
			),
			'preview'      => array(
				'kind'            => $preview->kind(),
				'profile'         => $preview->profile(),
				'projection_hash' => $preview->projection_hash(),
				'projection'      => $preview->projection(),
			),
		);
	}
}
