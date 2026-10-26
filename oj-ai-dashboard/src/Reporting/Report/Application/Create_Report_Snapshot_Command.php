<?php
/**
 * Frozen A-DS08-C45 request and authorization evidence.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-C45 request and authorization evidence.
 */
final class Create_Report_Snapshot_Command {
	public const REQUEST_SCHEMA  = 'oj_ai.report_snapshot_request';
	public const REQUEST_VERSION = '1.0.0';
	public const SOURCE_TYPE     = 'context';

	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;
	/**
	 * Request payload.
	 *
	 * @var array<string,mixed>
	 */
	private array $request_payload;

	/**
	 * Initialize report snapshot command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_source_type Expected source type.
	 * @param string         $expected_source_id Expected source id.
	 * @param string         $expected_source_revision Expected source revision.
	 * @param string         $expected_live_source_manifest_hash Expected live source manifest hash.
	 * @param string         $expected_filter_hash Expected filter hash.
	 * @param string         $expected_request_hash Expected request hash.
	 * @param string         $context_type Context type.
	 * @param string         $context_id Context id.
	 * @param string         $context_revision Context revision.
	 * @param Report_Filter  $filter Filter.
	 * @param int            $source_page_size Source page size.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $context_actor_relation Context actor relation.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $command_id,
		private string $expected_source_type,
		private string $expected_source_id,
		private string $expected_source_revision,
		private string $expected_live_source_manifest_hash,
		private string $expected_filter_hash,
		private string $expected_request_hash,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private Report_Filter $filter,
		private int $source_page_size,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $context_actor_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $source_page_size < 1 || $source_page_size > Read_Worksheet_Reports::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Report snapshot command is invalid.' );
		}
		new Public_Id( $command_id );
		new Context_Scope( $context_type, $context_id, Context_Scope::OPERATION_REPORT, null, $context_revision );
		$this->hash_value( $expected_live_source_manifest_hash );
		$this->hash_value( $expected_filter_hash );
		$this->hash_value( $expected_request_hash );
		$this->hash_value( $idempotency_fingerprint );
		$this->safe_token( $correlation_id, 1, 100 );
		$this->safe_token( $context_actor_relation, 1, 80 );

		if (
			self::SOURCE_TYPE !== $expected_source_type
			|| ! hash_equals( $context_id, $expected_source_id )
			|| ! hash_equals( $context_revision, $expected_source_revision )
		) {
			throw new InvalidArgumentException( 'Report snapshot source evidence is invalid.' );
		}

		$this->request_payload = array(
			'lens'             => 'worksheet',
			'context'          => array(
				'type'     => $context_type,
				'id'       => $context_id,
				'revision' => $context_revision,
			),
			'filter'           => $filter->payload(),
			'source_page_size' => $source_page_size,
		);
		$request_profile       = array(
			'schema_id'      => self::REQUEST_SCHEMA,
			'schema_version' => self::REQUEST_VERSION,
			'payload'        => $this->request_payload,
		);
		if ( ! hash_equals( $expected_request_hash, hash( 'sha256', $canonical->encode( $request_profile ) ) ) ) {
			throw new InvalidArgumentException( 'Report snapshot request hash is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'expected_source_type'     => $expected_source_type,
						'expected_source_id'       => $expected_source_id,
						'expected_source_revision' => $expected_source_revision,
						'expected_live_source_manifest_hash' => $expected_live_source_manifest_hash,
						'expected_filter_hash'     => $expected_filter_hash,
						'expected_request_schema'  => self::REQUEST_SCHEMA,
						'expected_request_version' => self::REQUEST_VERSION,
						'expected_request_hash'    => $expected_request_hash,
					),
					'payload'    => $this->request_payload,
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
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}
	/**
	 * Expected live source manifest hash.
	 *
	 * @return string
	 */
	public function expected_live_source_manifest_hash(): string {
		return $this->expected_live_source_manifest_hash;
	}
	/**
	 * Expected filter hash.
	 *
	 * @return string
	 */
	public function expected_filter_hash(): string {
		return $this->expected_filter_hash;
	}
	/**
	 * Expected request hash.
	 *
	 * @return string
	 */
	public function expected_request_hash(): string {
		return $this->expected_request_hash;
	}
	/**
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
	}
	/**
	 * Filter.
	 *
	 * @return Report_Filter
	 */
	public function filter(): Report_Filter {
		return $this->filter;
	}
	/**
	 * Source page size.
	 *
	 * @return int
	 */
	public function source_page_size(): int {
		return $this->source_page_size;
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
	 * Context actor relation.
	 *
	 * @return string
	 */
	public function context_actor_relation(): string {
		return $this->context_actor_relation;
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
	 * Request payload.
	 *
	 * @return array<string,mixed>
	 */
	public function request_payload(): array {
		return $this->request_payload;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return 'command:' . hash(
			'sha256',
			$this->actor_user_id . '|report.snapshot.create|' . $this->context_type . '|'
			. $this->context_id . '|' . $this->context_revision . '|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Hash value.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Report snapshot hash is invalid.' );
		}
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, int $minimum, int $maximum ): void {
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Report snapshot authorization token is invalid.' );
		}
	}
}
