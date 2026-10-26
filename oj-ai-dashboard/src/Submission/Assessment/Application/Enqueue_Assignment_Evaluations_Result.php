<?php
/**
 * C30 durable batch receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C30 durable batch receipt.
 */
final class Enqueue_Assignment_Evaluations_Result {
	public const RECEIPT_SCHEMA  = 'oj_ai.assignment_evaluation_batch_receipt';
	public const RECEIPT_VERSION = '1.0.0';

	/**
	 * Initialize enqueue assignment evaluations result dependencies and state.
	 *
	 * @param string                         $assignment_id Distribution public identifier.
	 * @param string                         $source_cutoff_utc Source cutoff utc.
	 * @param string                         $item_cursor Item cursor.
	 * @param string                         $next_item_cursor Next item cursor.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,int>              $counts Counts.
	 * @param string                         $processed_at_utc Processed at utc.
	 * @param string                         $receipt_digest Receipt digest.
	 * @param bool                           $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $assignment_id,
		private string $source_cutoff_utc,
		private string $item_cursor,
		private string $next_item_cursor,
		private array $items,
		private array $counts,
		private string $processed_at_utc,
		private string $receipt_digest,
		private bool $replayed
	) {
		new Public_Id( $assignment_id );
		if (
			1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $source_cutoff_utc )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $processed_at_utc )
			|| strlen( $item_cursor ) > Enqueue_Assignment_Evaluations_Command::MAXIMUM_CURSOR
			|| strlen( $next_item_cursor ) > Enqueue_Assignment_Evaluations_Command::MAXIMUM_CURSOR
			|| ! array_is_list( $items ) || count( $items ) > Enqueue_Assignment_Evaluations_Command::MAXIMUM_LIMIT
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $receipt_digest )
		) {
			throw new InvalidArgumentException( 'Assignment evaluation batch receipt is invalid.' );
		}
		$this->assert_counts( $counts, count( $items ) );
		foreach ( $items as $item ) {
			$this->assert_item( $item );
		}
	}

	/**
	 * Assignment id.
	 *
	 * @return string
	 */
	public function assignment_id(): string {
		return $this->assignment_id;
	}
	/**
	 * Source cutoff utc.
	 *
	 * @return string
	 */
	public function source_cutoff_utc(): string {
		return $this->source_cutoff_utc;
	}
	/**
	 * Item cursor.
	 *
	 * @return string
	 */
	public function item_cursor(): string {
		return $this->item_cursor;
	}
	/**
	 * Next item cursor.
	 *
	 * @return string
	 */
	public function next_item_cursor(): string {
		return $this->next_item_cursor;
	}
	/**
	 * Items.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function items(): array {
		return $this->items;
	}
	/**
	 * Counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		return $this->counts;
	}
	/**
	 * Processed at utc.
	 *
	 * @return string
	 */
	public function processed_at_utc(): string {
		return $this->processed_at_utc;
	}
	/**
	 * Receipt digest.
	 *
	 * @return string
	 */
	public function receipt_digest(): string {
		return $this->receipt_digest;
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
	 * Receipt.
	 *
	 * @return array<string,mixed>
	 */
	public function receipt(): array {
		return array(
			'assignment_id'     => $this->assignment_id,
			'counts'            => $this->counts,
			'item_cursor'       => $this->item_cursor,
			'items'             => $this->items,
			'next_item_cursor'  => $this->next_item_cursor,
			'processed_at_utc'  => $this->processed_at_utc,
			'receipt_digest'    => $this->receipt_digest,
			'schema_id'         => self::RECEIPT_SCHEMA,
			'schema_version'    => self::RECEIPT_VERSION,
			'source_cutoff_utc' => $this->source_cutoff_utc,
		);
	}

	/**
	 * With replayed.
	 *
	 * @param bool $replayed Replayed.
	 * @return self
	 */
	public function with_replayed( bool $replayed ): self {
		return new self(
			$this->assignment_id,
			$this->source_cutoff_utc,
			$this->item_cursor,
			$this->next_item_cursor,
			$this->items,
			$this->counts,
			$this->processed_at_utc,
			$this->receipt_digest,
			$replayed
		);
	}

	/**
	 * From receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function from_receipt( array $receipt, bool $replayed ): self {
		self::exact_keys(
			$receipt,
			array(
				'assignment_id',
				'counts',
				'item_cursor',
				'items',
				'next_item_cursor',
				'processed_at_utc',
				'receipt_digest',
				'schema_id',
				'schema_version',
				'source_cutoff_utc',
			)
		);
		if ( self::RECEIPT_SCHEMA !== ( $receipt['schema_id'] ?? null ) || self::RECEIPT_VERSION !== ( $receipt['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt schema is invalid.' );
		}
		return new self(
			(string) ( $receipt['assignment_id'] ?? '' ),
			(string) ( $receipt['source_cutoff_utc'] ?? '' ),
			(string) ( $receipt['item_cursor'] ?? '' ),
			(string) ( $receipt['next_item_cursor'] ?? '' ),
			is_array( $receipt['items'] ?? null ) ? $receipt['items'] : array(),
			is_array( $receipt['counts'] ?? null ) ? $receipt['counts'] : array(),
			(string) ( $receipt['processed_at_utc'] ?? '' ),
			(string) ( $receipt['receipt_digest'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Reject invalid counts.
	 *
	 * @param array<string,int> $counts Counts.
	 * @param int               $total Total.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_counts( array $counts, int $total ): void {
		self::exact_keys( $counts, array( 'created', 'existing', 'failed', 'skipped', 'total' ) );
		$sum = 0;
		foreach ( array( 'created', 'existing', 'failed', 'skipped' ) as $key ) {
			if ( ! is_int( $counts[ $key ] ?? null ) || $counts[ $key ] < 0 ) {
				throw new InvalidArgumentException( 'Assignment evaluation receipt count is invalid.' );
			}
			$sum += $counts[ $key ];
		}
		if ( ! is_int( $counts['total'] ?? null ) || $counts['total'] !== $total || $sum !== $total ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt total is invalid.' );
		}
	}

	/**
	 * Reject invalid item.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_item( array $item ): void {
		self::exact_keys( $item, array( 'evaluation_id', 'reason', 'run_id', 'status', 'submission_id', 'submission_revision_id', 'target_id' ) );
		new Public_Id( (string) ( $item['target_id'] ?? '' ) );
		$status = $item['status'] ?? null;
		if ( ! is_string( $status ) || ! in_array( $status, array( 'created', 'existing', 'skipped', 'failed' ), true ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation item status is invalid.' );
		}
		foreach ( array( 'submission_id', 'submission_revision_id', 'evaluation_id', 'run_id' ) as $field ) {
			if ( null !== ( $item[ $field ] ?? null ) ) {
				new Public_Id( (string) $item[ $field ] );
			}
		}
		if ( null !== ( $item['submission_revision_id'] ?? null ) && null === ( $item['submission_id'] ?? null ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation item revision has no submission.' );
		}
		if ( in_array( $status, array( 'created', 'existing' ), true ) ) {
			if (
			null !== ( $item['reason'] ?? null )
			|| null === ( $item['submission_id'] ?? null )
			|| null === ( $item['submission_revision_id'] ?? null )
			|| null === ( $item['evaluation_id'] ?? null )
			|| null === ( $item['run_id'] ?? null )
			) {
				throw new InvalidArgumentException( 'Successful assignment evaluation item is incomplete.' );
			}
			return;
		}
		$reason = $item['reason'] ?? null;
		if (
		! is_string( $reason ) || strlen( $reason ) < 3 || strlen( $reason ) > 100
		|| 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $reason )
		|| null !== ( $item['evaluation_id'] ?? null ) || null !== ( $item['run_id'] ?? null )
		) {
			throw new InvalidArgumentException( 'Non-success assignment evaluation item is invalid.' );
		}
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt must be an object.' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt fields are invalid.' );
		}
	}
}
