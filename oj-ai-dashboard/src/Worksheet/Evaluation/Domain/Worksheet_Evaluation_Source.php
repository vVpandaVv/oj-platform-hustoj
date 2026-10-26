<?php
/**
 * Exact worksheet revision source for self-evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Worksheet_Document;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the immutable source and its verified owned projections.
 */
final class Worksheet_Evaluation_Source {
	/**
	 * Initialize worksheet evaluation source dependencies and state.
	 *
	 * @param string                         $worksheet_id Worksheet public identifier.
	 * @param string                         $revision_id Revision id.
	 * @param string                         $state State.
	 * @param string                         $source_hash Source hash.
	 * @param Canonical_Worksheet_Document   $document Document.
	 * @param Worksheet_Revision_Projection  $projection Projection.
	 * @param array<int,array<string,mixed>> $asset_snapshots Asset snapshots.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $worksheet_id,
		private string $revision_id,
		private string $state,
		private string $source_hash,
		private Canonical_Worksheet_Document $document,
		private Worksheet_Revision_Projection $projection,
		private array $asset_snapshots
	) {
		new Public_Id( $worksheet_id );
		new Public_Id( $revision_id );
		if (
			! in_array( $state, array( 'draft', 'approved' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| ! hash_equals( $source_hash, $document->document_hash() )
			|| ! array_is_list( $asset_snapshots )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation source is invalid.' );
		}
	}

	/**
	 * Worksheet id.
	 *
	 * @return string
	 */
	public function worksheet_id(): string {
		return $this->worksheet_id;
	}

	/**
	 * Revision id.
	 *
	 * @return string
	 */
	public function revision_id(): string {
		return $this->revision_id;
	}

	/**
	 * State.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return $this->source_hash;
	}

	/**
	 * Document.
	 *
	 * @return Canonical_Worksheet_Document
	 */
	public function document(): Canonical_Worksheet_Document {
		return $this->document;
	}

	/**
	 * Projection.
	 *
	 * @return Worksheet_Revision_Projection
	 */
	public function projection(): Worksheet_Revision_Projection {
		return $this->projection;
	}

	/**
	 * Asset snapshots.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function asset_snapshots(): array {
		return $this->asset_snapshots;
	}
}
