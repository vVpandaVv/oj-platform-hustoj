<?php
/**
 * Submission revision integrity contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps Q32 reads and C23 writes on one immutable revision digest.
 */
final class Submission_Revision_Integrity {
	public const RESPONSE_SCHEMA         = 'oj_ai.submission_response';
	public const RESPONSE_VERSION        = 1;
	public const RESPONSE_SCHEMA_VERSION = '1.0.0';
	public const STATES                  = array( 'saved', 'reopened_fork' );

	/**
	 * Initialize submission revision integrity dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Content hash.
	 *
	 * @param string   $submission_id Submission public identifier.
	 * @param int      $revision_no Revision no.
	 * @param int|null $base_revision_no Base revision no.
	 * @param string   $state State.
	 * @param string   $response_schema_version Response schema version.
	 * @param string   $response_hash Response hash.
	 * @param string   $attachment_set_hash Attachment set hash.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function content_hash(
		string $submission_id,
		int $revision_no,
		?int $base_revision_no,
		string $state,
		string $response_schema_version,
		string $response_hash,
		string $attachment_set_hash
	): string {
		new Public_Id( $submission_id );
		if (
			$revision_no < 1
			|| ( null !== $base_revision_no && ( $base_revision_no < 1 || $base_revision_no >= $revision_no ) )
			|| ! in_array( $state, self::STATES, true )
			|| self::RESPONSE_SCHEMA_VERSION !== $response_schema_version
		) {
			throw new InvalidArgumentException( 'Submission revision identity is invalid.' );
		}
		foreach ( array( $response_hash, $attachment_set_hash ) as $digest ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
				throw new InvalidArgumentException( 'Submission revision digest is invalid.' );
			}
		}

		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_set_hash'     => $attachment_set_hash,
					'base_revision_no'        => $base_revision_no,
					'response_hash'           => $response_hash,
					'response_schema'         => self::RESPONSE_SCHEMA,
					'response_schema_version' => $response_schema_version,
					'response_version'        => self::RESPONSE_VERSION,
					'revision_no'             => $revision_no,
					'state'                   => $state,
					'submission_id'           => $submission_id,
				)
			)
		);
	}
}
