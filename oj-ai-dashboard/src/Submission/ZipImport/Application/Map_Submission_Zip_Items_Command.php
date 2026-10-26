<?php
/**
 * C68 submission ZIP item mapping command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and canonicalizes the frozen C68 optimistic-concurrency envelope.
 */
final class Map_Submission_Zip_Items_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Items.
	 *
	 * @var array<int,array{item_no:int,target_id:string,learner_user_id:int,response_id:string}>
	 */
	private array $items;

	/**
	 * Initialize submission zip items command dependencies and state.
	 *
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @param string                         $upload_id Upload id.
	 * @param string                         $command_id Command identifier used for idempotency.
	 * @param int                            $expected_mapping_version Expected mapping version.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param string                         $authorized_context_type Authorized context type.
	 * @param string                         $authorized_context_id Authorized context id.
	 * @param string                         $authorized_context_revision Authorized context revision.
	 * @param string                         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string                         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $upload_id,
		private string $command_id,
		private int $expected_mapping_version,
		array $items,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $expected_mapping_version < 1 ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping identity or version is invalid.' );
		}
		new Public_Id( $upload_id );
		$this->token( $command_id, 'command_id', 128 );
		$this->context_value( $authorized_context_type, 'context_type', 16 );
		$this->context_value( $authorized_context_id, 'context_id', 191 );
		$this->context_value( $authorized_context_revision, 'context_revision', 191 );
		$this->token( $correlation_id, 'correlation_id', 128 );
		$this->hash( $idempotency_fingerprint, 'idempotency_fingerprint' );
		$this->items = $this->mapping_items( $items );

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array( 'expected_mapping_version' => $expected_mapping_version ),
					'payload'    => array( 'items' => $this->items ),
					'upload_id'  => $upload_id,
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
	 * Upload id.
	 *
	 * @return string
	 */
	public function upload_id(): string {
		return $this->upload_id;
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
	 * Expected mapping version.
	 *
	 * @return int
	 */
	public function expected_mapping_version(): int {
		return $this->expected_mapping_version;
	}

	/**
	 * Items.
	 *
	 * @return array<int,array{item_no:int,target_id:string,learner_user_id:int,response_id:string}>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Authorized context type.
	 *
	 * @return string
	 */
	public function authorized_context_type(): string {
		return $this->authorized_context_type;
	}
	/**
	 * Authorized context id.
	 *
	 * @return string
	 */
	public function authorized_context_id(): string {
		return $this->authorized_context_id;
	}
	/**
	 * Authorized context revision.
	 *
	 * @return string
	 */
	public function authorized_context_revision(): string {
		return $this->authorized_context_revision;
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
		return 'submission-zip-map:' . hash(
			'sha256',
			$this->actor_user_id . '|submission_zip.map|' . $this->upload_id . '|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Mapping items.
	 *
	 * @param array<array-key,mixed> $items Items.
	 * @return array<int,array{item_no:int,target_id:string,learner_user_id:int,response_id:string}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function mapping_items( array $items ): array {
		if ( array() === $items || ! array_is_list( $items ) || count( $items ) > 500 ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping items are invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping item is invalid.' );
			}
			$keys = array_keys( $item );
			sort( $keys, SORT_STRING );
			if ( $keys !== array( 'item_no', 'learner_user_id', 'response_id', 'target_id' ) ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping item keys are invalid.' );
			}
			$item_no = $item['item_no'] ?? null;
			$learner = $item['learner_user_id'] ?? null;
			if ( ! is_int( $item_no ) || $item_no < 1 || $item_no > 500 || isset( $seen[ $item_no ] ) || ! is_int( $learner ) || $learner < 1 ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping item identity is invalid.' );
			}
			$target_id   = $item['target_id'] ?? null;
			$response_id = $item['response_id'] ?? null;
			if ( ! is_string( $target_id ) || ! is_string( $response_id ) ) {
				throw new InvalidArgumentException( 'Submission ZIP mapping target is invalid.' );
			}
			new Public_Id( $target_id );
			new Public_Id( $response_id );
			$seen[ $item_no ] = true;
			$result[]         = array(
				'item_no'         => $item_no,
				'learner_user_id' => $learner,
				'response_id'     => $response_id,
				'target_id'       => $target_id,
			);
		}
		usort( $result, static fn ( array $left, array $right ): int => $left['item_no'] <=> $right['item_no'] );
		return $result;
	}

	/**
	 * Token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( string $value, string $field, int $maximum ): void {
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Context value.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_value( string $value, string $field, int $maximum ): void {
		if ( '' === $value || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value, string $field ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
