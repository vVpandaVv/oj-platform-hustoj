<?php
/**
 * SaveWorksheetDraft result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the immutable C02 save receipt without persistence details.
 */
final class Save_Worksheet_Draft_Result {
	/**
	 * Worksheet public identifier.
	 *
	 * @var string
	 */
	private string $worksheet_id;
	/**
	 * Revision id.
	 *
	 * @var string
	 */
	private string $revision_id;
	/**
	 * Revision no.
	 *
	 * @var int
	 */
	private int $revision_no;
	/**
	 * Revision state.
	 *
	 * @var string
	 */
	private string $revision_state;
	/**
	 * Source revision id.
	 *
	 * @var string
	 */
	private string $source_revision_id;
	/**
	 * Document id.
	 *
	 * @var string
	 */
	private string $document_id;
	/**
	 * Document hash.
	 *
	 * @var string
	 */
	private string $document_hash;
	/**
	 * Validation hash.
	 *
	 * @var string
	 */
	private string $validation_hash;
	/**
	 * Title.
	 *
	 * @var string
	 */
	private string $title;
	/**
	 * Worksheet state.
	 *
	 * @var string
	 */
	private string $worksheet_state;
	/**
	 * Saved at utc.
	 *
	 * @var string
	 */
	private string $saved_at_utc;

	/**
	 * Temporary id map.
	 *
	 * @var array<string,string>
	 */
	private array $temporary_id_map;

	/**
	 * Replayed.
	 *
	 * @var bool
	 */
	private bool $replayed;
	/**
	 * Unchanged.
	 *
	 * @var bool
	 */
	private bool $unchanged;

	/**
	 * Initialize save worksheet draft result dependencies and state.
	 *
	 * @param string                 $worksheet_id Worksheet public identifier.
	 * @param string                 $revision_id Revision id.
	 * @param int                    $revision_no Revision no.
	 * @param string                 $revision_state Revision state.
	 * @param string                 $source_revision_id Source revision id.
	 * @param string                 $document_id Document id.
	 * @param string                 $document_hash Document hash.
	 * @param string                 $validation_hash Validation hash.
	 * @param string                 $title Title.
	 * @param string                 $worksheet_state Worksheet state.
	 * @param string                 $saved_at_utc Saved at utc.
	 * @param array<array-key,mixed> $temporary_id_map Temporary id map.
	 * @param bool                   $replayed Replayed.
	 * @param bool                   $unchanged Unchanged.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		string $worksheet_id,
		string $revision_id,
		int $revision_no,
		string $revision_state,
		string $source_revision_id,
		string $document_id,
		string $document_hash,
		string $validation_hash,
		string $title,
		string $worksheet_state,
		string $saved_at_utc,
		array $temporary_id_map,
		bool $replayed,
		bool $unchanged
	) {
		new Public_Id( $worksheet_id );
		new Public_Id( $revision_id );
		new Public_Id( $source_revision_id );
		new Public_Id( $document_id );

		if ( $revision_no < 1 ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft revision number is invalid.' );
		}
		if ( ! in_array( $revision_state, array( 'draft', 'approved' ), true ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft revision state is invalid.' );
		}
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $document_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $validation_hash )
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft receipt hash is invalid.' );
		}
		if (
			'' === trim( $title )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/u', $title )
			|| ! in_array( $worksheet_state, array( 'draft', 'active' ), true )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $saved_at_utc )
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft receipt is invalid.' );
		}
		if ( $unchanged !== hash_equals( $revision_id, $source_revision_id ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft source revision relation is invalid.' );
		}
		if ( ! $unchanged && 'draft' !== $revision_state ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft new revision must be a draft.' );
		}
		if ( array() !== $temporary_id_map && array_is_list( $temporary_id_map ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft temporary ID map must be a JSON object.' );
		}

		$stable_ids = array();
		foreach ( $temporary_id_map as $temporary_id => $stable_id ) {
			if (
				! is_string( $temporary_id )
				|| 1 !== preg_match(
					'/^tmp_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',
					$temporary_id
				)
				|| ! is_string( $stable_id )
			) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft temporary ID mapping is invalid.' );
			}
			new Public_Id( $stable_id );
			if ( isset( $stable_ids[ $stable_id ] ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft stable mapped IDs must be unique.' );
			}
			$stable_ids[ $stable_id ] = true;
		}

		$this->worksheet_id       = $worksheet_id;
		$this->revision_id        = $revision_id;
		$this->revision_no        = $revision_no;
		$this->revision_state     = $revision_state;
		$this->source_revision_id = $source_revision_id;
		$this->document_id        = $document_id;
		$this->document_hash      = $document_hash;
		$this->validation_hash    = $validation_hash;
		$this->title              = $title;
		$this->worksheet_state    = $worksheet_state;
		$this->saved_at_utc       = $saved_at_utc;
		$this->temporary_id_map   = $temporary_id_map;
		$this->replayed           = $replayed;
		$this->unchanged          = $unchanged;
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
	 * Current revision id.
	 *
	 * @return string
	 */
	public function current_revision_id(): string {
		return $this->revision_id;
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
	 * Revision no.
	 *
	 * @return int
	 */
	public function revision_no(): int {
		return $this->revision_no;
	}

	/**
	 * Revision state.
	 *
	 * @return string
	 */
	public function revision_state(): string {
		return $this->revision_state;
	}

	/**
	 * Source revision id.
	 *
	 * @return string
	 */
	public function source_revision_id(): string {
		return $this->source_revision_id;
	}

	/**
	 * Document id.
	 *
	 * @return string
	 */
	public function document_id(): string {
		return $this->document_id;
	}

	/**
	 * Document hash.
	 *
	 * @return string
	 */
	public function document_hash(): string {
		return $this->document_hash;
	}

	/**
	 * Validation hash.
	 *
	 * @return string
	 */
	public function validation_hash(): string {
		return $this->validation_hash;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Worksheet state.
	 *
	 * @return string
	 */
	public function worksheet_state(): string {
		return $this->worksheet_state;
	}

	/**
	 * Saved at utc.
	 *
	 * @return string
	 */
	public function saved_at_utc(): string {
		return $this->saved_at_utc;
	}

	/**
	 * Temporary id map.
	 *
	 * @return array<string,string>
	 */
	public function temporary_id_map(): array {
		return $this->temporary_id_map;
	}

	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}

	/**
	 * Unchanged.
	 *
	 * @return bool
	 */
	public function unchanged(): bool {
		return $this->unchanged;
	}

	/**
	 * Http status.
	 *
	 * @return int
	 */
	public function http_status(): int {
		return $this->unchanged ? 200 : 201;
	}
}
