<?php
/**
 * CreateWorksheet result.
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
 * Carries the minimum receipt required by C01.
 */
final class Create_Worksheet_Result {
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
	 * Title.
	 *
	 * @var string
	 */
	private string $title;
	/**
	 * Created at utc.
	 *
	 * @var string
	 */
	private string $created_at_utc;
	/**
	 * Replayed.
	 *
	 * @var bool
	 */
	private bool $replayed;

	/**
	 * Initialize worksheet result dependencies and state.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @param string $document_id Document id.
	 * @param string $document_hash Document hash.
	 * @param string $title Title.
	 * @param string $created_at_utc Created at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		string $worksheet_id,
		string $revision_id,
		string $document_id,
		string $document_hash,
		string $title,
		string $created_at_utc,
		bool $replayed
	) {
		new Public_Id( $worksheet_id );
		new Public_Id( $revision_id );
		new Public_Id( $document_id );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $document_hash ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet document hash is invalid.' );
		}
		if ( '' === $title || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created_at_utc ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet receipt is invalid.' );
		}

		$this->worksheet_id   = $worksheet_id;
		$this->revision_id    = $revision_id;
		$this->document_id    = $document_id;
		$this->document_hash  = $document_hash;
		$this->title          = $title;
		$this->created_at_utc = $created_at_utc;
		$this->replayed       = $replayed;
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
	 * Title.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Created at utc.
	 *
	 * @return string
	 */
	public function created_at_utc(): string {
		return $this->created_at_utc;
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
