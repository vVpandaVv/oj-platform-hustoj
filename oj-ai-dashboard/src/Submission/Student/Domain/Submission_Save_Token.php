<?php
/**
 * Submission optimistic-concurrency token.
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
 * Keeps C22, Q32, and C23 on one deterministic CAS token.
 */
final class Submission_Save_Token {
	/**
	 * Initialize submission save token dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Create submission save token.
	 *
	 * @param string      $submission_id Submission public identifier.
	 * @param string      $assignment_id Distribution public identifier.
	 * @param int         $lock_version Lock version.
	 * @param string|null $current_revision_id Current revision id.
	 * @param string      $publication_hash Publication hash.
	 * @param string      $schedule_hash Schedule hash.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create(
		string $submission_id,
		string $assignment_id,
		int $lock_version,
		?string $current_revision_id,
		string $publication_hash,
		string $schedule_hash
	): string {
		new Public_Id( $submission_id );
		new Public_Id( $assignment_id );
		if ( null !== $current_revision_id ) {
			new Public_Id( $current_revision_id );
		}
		if ( $lock_version < 0 ) {
			throw new InvalidArgumentException( 'Submission save token lock version is invalid.' );
		}
		foreach ( array( $publication_hash, $schedule_hash ) as $digest ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
				throw new InvalidArgumentException( 'Submission save token digest is invalid.' );
			}
		}

		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'assignment_id'       => $assignment_id,
					'current_revision_id' => $current_revision_id,
					'lock_version'        => $lock_version,
					'publication_hash'    => $publication_hash,
					'schedule_hash'       => $schedule_hash,
					'submission_id'       => $submission_id,
				)
			)
		);
	}
}
