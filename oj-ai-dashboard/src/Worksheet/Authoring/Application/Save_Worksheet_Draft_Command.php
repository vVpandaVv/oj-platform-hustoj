<?php
/**
 * SaveWorksheetDraft command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts trusted actor metadata and the frozen outer C02 command profile.
 */
final class Save_Worksheet_Draft_Command {
	public const MAXIMUM_DOCUMENT_BYTES = Worksheet_Document_Profile::MAX_DOCUMENT_BYTES;

	/**
	 * Authenticated actor from the access context.
	 *
	 * @var int
	 */
	private int $actor_user_id;
	/**
	 * Worksheet public identifier.
	 *
	 * @var string
	 */
	private string $worksheet_id;
	/**
	 * Command identifier used for idempotency.
	 *
	 * @var string
	 */
	private string $command_id;
	/**
	 * Expected state.
	 *
	 * @var string
	 */
	private string $expected_state;
	/**
	 * Revision the caller read before this operation.
	 *
	 * @var string
	 */
	private string $expected_revision_id;

	/**
	 * Teacher document.
	 *
	 * @var array<string,mixed>
	 */
	private array $teacher_document;

	/**
	 * Achievement links.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $achievement_links;

	/**
	 * Staged asset receipt ids.
	 *
	 * @var array<int,string>
	 */
	private array $staged_asset_receipt_ids;

	/**
	 * Fingerprint scoped to the actor and command.
	 *
	 * @var string
	 */
	private string $idempotency_fingerprint;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string
	 */
	private string $correlation_id;
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize save worksheet draft command dependencies and state.
	 *
	 * @param int                    $actor_user_id Authenticated actor from the access context.
	 * @param string                 $worksheet_id Worksheet public identifier.
	 * @param string                 $command_id Command identifier used for idempotency.
	 * @param string                 $expected_state Expected state.
	 * @param string                 $expected_revision_id Revision the caller read before this operation.
	 * @param array<array-key,mixed> $teacher_document Teacher document.
	 * @param array<array-key,mixed> $achievement_links Achievement links.
	 * @param array<array-key,mixed> $staged_asset_receipt_ids Staged asset receipt ids.
	 * @param string                 $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string                 $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json         $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		int $actor_user_id,
		string $worksheet_id,
		string $command_id,
		string $expected_state,
		string $expected_revision_id,
		array $teacher_document,
		array $achievement_links,
		array $staged_asset_receipt_ids,
		string $idempotency_fingerprint,
		string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft requires an authenticated actor.' );
		}

		new Public_Id( $worksheet_id );
		new Public_Id( $expected_revision_id );
		$this->assert_safe_token( $command_id, 'command_id' );

		if ( ! in_array( $expected_state, array( 'draft', 'active' ), true ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft expected_state is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft idempotency fingerprint is invalid.' );
		}
		if (
			strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft correlation ID is invalid.' );
		}
		if ( array() === $teacher_document || array_is_list( $teacher_document ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft teacher_document must be a JSON object.' );
		}

		$canonical_document = $canonical->encode( $teacher_document );
		if ( strlen( $canonical_document ) > self::MAXIMUM_DOCUMENT_BYTES ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft teacher_document exceeds the byte limit.' );
		}

		if ( ! array_is_list( $achievement_links ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft achievement_links must be a JSON list.' );
		}
		foreach ( $achievement_links as $link ) {
			if ( ! is_array( $link ) || array() === $link || array_is_list( $link ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft achievement link must be a JSON object.' );
			}
		}

		if ( ! array_is_list( $staged_asset_receipt_ids ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft staged asset receipt IDs must be a JSON list.' );
		}
		$seen_receipts = array();
		foreach ( $staged_asset_receipt_ids as $receipt_id ) {
			if ( ! is_string( $receipt_id ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft staged asset receipt ID is invalid.' );
			}
			new Public_Id( $receipt_id );
			if ( isset( $seen_receipts[ $receipt_id ] ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft staged asset receipt IDs must be unique.' );
			}
			$seen_receipts[ $receipt_id ] = true;
		}

		$this->actor_user_id            = $actor_user_id;
		$this->worksheet_id             = $worksheet_id;
		$this->command_id               = $command_id;
		$this->expected_state           = $expected_state;
		$this->expected_revision_id     = $expected_revision_id;
		$this->teacher_document         = $teacher_document;
		$this->achievement_links        = $achievement_links;
		$this->staged_asset_receipt_ids = $staged_asset_receipt_ids;
		$this->idempotency_fingerprint  = $idempotency_fingerprint;
		$this->correlation_id           = $correlation_id;
		$this->request_digest           = hash(
			'sha256',
			$canonical->encode(
				array(
					'worksheet_id' => $worksheet_id,
					'command_id'   => $command_id,
					'expected'     => array(
						'expected_state'       => $expected_state,
						'expected_revision_id' => $expected_revision_id,
					),
					'payload'      => array(
						'teacher_document'         => $teacher_document,
						'achievement_links'        => $achievement_links,
						'staged_asset_receipt_ids' => $staged_asset_receipt_ids,
					),
				)
			)
		);
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
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
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}

	/**
	 * Expected state.
	 *
	 * @return string
	 */
	public function expected_state(): string {
		return $this->expected_state;
	}

	/**
	 * Expected revision id.
	 *
	 * @return string
	 */
	public function expected_revision_id(): string {
		return $this->expected_revision_id;
	}

	/**
	 * Teacher document.
	 *
	 * @return array<string,mixed>
	 */
	public function teacher_document(): array {
		return $this->teacher_document;
	}

	/**
	 * Achievement links.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function achievement_links(): array {
		return $this->achievement_links;
	}

	/**
	 * Staged asset receipt ids.
	 *
	 * @return array<int,string>
	 */
	public function staged_asset_receipt_ids(): array {
		return $this->staged_asset_receipt_ids;
	}

	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}

	/**
	 * Request digest.
	 *
	 * @return string
	 */
	public function request_digest(): string {
		return $this->request_digest;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return hash(
			'sha256',
			$this->actor_user_id . '|worksheet.save-draft|' . $this->worksheet_id . '|'
				. $this->idempotency_fingerprint
		);
	}

	/**
	 * Reject invalid safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_safe_token( string $value, string $field ): void {
		if (
			strlen( $value ) < 8
			|| strlen( $value ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value )
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
