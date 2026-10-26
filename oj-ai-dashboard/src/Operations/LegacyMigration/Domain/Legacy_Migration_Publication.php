<?php
/**
 * Legacy migration publication.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable hand-off from migration publication storage to the assignment aggregate writer.
 */
final class Legacy_Migration_Publication {
	/**
	 * Initialize legacy migration publication dependencies and state.
	 *
	 * @param string $worksheet_public_id Worksheet public id.
	 * @param string $revision_public_id Revision public id.
	 * @param string $revision_document_hash Revision document hash.
	 * @param string $publication_public_id Publication public id.
	 * @param string $publication_hash Publication hash.
	 * @param string $manifest_version Manifest version.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $worksheet_public_id,
		private string $revision_public_id,
		private string $revision_document_hash,
		private string $publication_public_id,
		private string $publication_hash,
		private string $manifest_version,
		private bool $replayed
	) {
		foreach ( array( $worksheet_public_id, $revision_public_id, $publication_public_id ) as $public_id ) {
			if ( ! Public_Id::is_valid( $public_id ) ) {
				throw new InvalidArgumentException( 'Legacy migration publication public ID is invalid.' );
			}
		}
		foreach ( array( $revision_document_hash, $publication_hash ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Legacy migration publication hash is invalid.' );
			}
		}
		if ( '1.0.0' !== $manifest_version ) {
			throw new InvalidArgumentException( 'Legacy migration publication manifest is invalid.' );
		}
	}

	/**
	 * Worksheet public id.
	 *
	 * @return string
	 */
	public function worksheet_public_id(): string {
		return $this->worksheet_public_id;
	}
	/**
	 * Revision public id.
	 *
	 * @return string
	 */
	public function revision_public_id(): string {
		return $this->revision_public_id;
	}
	/**
	 * Revision document hash.
	 *
	 * @return string
	 */
	public function revision_document_hash(): string {
		return $this->revision_document_hash;
	}
	/**
	 * Publication public id.
	 *
	 * @return string
	 */
	public function publication_public_id(): string {
		return $this->publication_public_id;
	}
	/**
	 * Publication hash.
	 *
	 * @return string
	 */
	public function publication_hash(): string {
		return $this->publication_hash;
	}
	/**
	 * Manifest version.
	 *
	 * @return string
	 */
	public function manifest_version(): string {
		return $this->manifest_version;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}
}
