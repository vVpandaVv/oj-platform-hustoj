<?php
/**
 * Curriculum and achievement-standard catalog use cases.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Curriculum\Catalog\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Curriculum\Catalog\Domain\Curriculum_Catalog_Profile;
use OJAI\Dashboard\Curriculum\Catalog\Port\Curriculum_Catalog_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Curriculum and achievement-standard catalog use cases.
 */
final class Manage_Curriculum_Catalog {
	/**
	 * Initialize curriculum catalog dependencies and state.
	 *
	 * @param Curriculum_Catalog_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Curriculum_Catalog_Profile                   $profile Profile.
	 * @param Canonical_Json                               $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Curriculum_Catalog_Repository_Port_Interface $repository,
		private Curriculum_Catalog_Profile $profile,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * List curriculums.
	 *
	 * @param string $search Search.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_curriculums( string $search, int $limit ): array {
		return $this->repository->list_curriculums(
			$this->search( $search ),
			$this->limit( $limit )
		);
	}

	/**
	 * List standards.
	 *
	 * @param string $curriculum_id Curriculum id.
	 * @param string $search Search.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_standards( string $curriculum_id, string $search, int $limit ): array {
		if ( ! Public_Id::is_valid( $curriculum_id ) ) {
			throw new InvalidArgumentException( 'Curriculum ID is invalid.' );
		}
		return $this->repository->list_standards(
			$curriculum_id,
			$this->search( $search ),
			$this->limit( $limit )
		);
	}

	/**
	 * Save curriculum.
	 *
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param int|null            $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param string              $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @return array<string,mixed>
	 */
	public function save_curriculum(
		int $actor_user_id,
		string $command_id,
		?int $expected_lock_version,
		array $payload,
		string $idempotency_fingerprint,
		string $correlation_id
	): array {
		$this->assert_command(
			$actor_user_id,
			$command_id,
			$expected_lock_version,
			$idempotency_fingerprint,
			$correlation_id
		);
		$prepared = $this->profile->prepare_curriculum( $payload );
		$request  = array(
			'command_id' => $command_id,
			'expected'   => array( 'expected_lock_version' => $expected_lock_version ),
			'payload'    => $this->curriculum_payload( $prepared ),
		);

		return $this->repository->save_curriculum(
			array(
				'actor_user_id'           => $actor_user_id,
				'command_id'              => $command_id,
				'correlation_id'          => $correlation_id,
				'expected_lock_version'   => $expected_lock_version,
				'idempotency_storage_key' => $this->storage_key(
					$actor_user_id,
					'curriculum.save',
					$prepared['public_id'],
					$idempotency_fingerprint
				),
				'occurred_at_utc'         => gmdate( 'Y-m-d H:i:s' ),
				'prepared'                => $prepared,
				'request_digest'          => hash( 'sha256', $this->canonical->encode( $request ) ),
				'resource_type'           => 'curriculum',
			)
		);
	}

	/**
	 * Save standard.
	 *
	 * @param string              $curriculum_id Curriculum id.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param int|null            $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param string              $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function save_standard(
		string $curriculum_id,
		int $actor_user_id,
		string $command_id,
		?int $expected_lock_version,
		array $payload,
		string $idempotency_fingerprint,
		string $correlation_id
	): array {
		if ( ! Public_Id::is_valid( $curriculum_id ) ) {
			throw new InvalidArgumentException( 'Curriculum ID is invalid.' );
		}
		$this->assert_command(
			$actor_user_id,
			$command_id,
			$expected_lock_version,
			$idempotency_fingerprint,
			$correlation_id
		);
		$prepared = $this->profile->prepare_standard( $payload );
		$request  = array(
			'command_id'    => $command_id,
			'curriculum_id' => $curriculum_id,
			'expected'      => array( 'expected_lock_version' => $expected_lock_version ),
			'payload'       => $this->standard_payload( $prepared ),
		);

		return $this->repository->save_standard(
			array(
				'actor_user_id'           => $actor_user_id,
				'command_id'              => $command_id,
				'correlation_id'          => $correlation_id,
				'curriculum_id'           => $curriculum_id,
				'expected_lock_version'   => $expected_lock_version,
				'idempotency_storage_key' => $this->storage_key(
					$actor_user_id,
					'standard.save',
					$prepared['public_id'] ?? $curriculum_id,
					$idempotency_fingerprint
				),
				'occurred_at_utc'         => gmdate( 'Y-m-d H:i:s' ),
				'prepared'                => $prepared,
				'request_digest'          => hash( 'sha256', $this->canonical->encode( $request ) ),
				'resource_type'           => 'standard',
			)
		);
	}

	/**
	 * Reject invalid command.
	 *
	 * @param int      $actor_user_id Authenticated actor from the access context.
	 * @param string   $command_id Command identifier used for idempotency.
	 * @param int|null $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param string   $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string   $correlation_id Request correlation identifier for diagnostics.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_command(
		int $actor_user_id,
		string $command_id,
		?int $expected_lock_version,
		string $idempotency_fingerprint,
		string $correlation_id
	): void {
		if (
			$actor_user_id < 1
			|| ( null !== $expected_lock_version && $expected_lock_version < 0 )
			|| strlen( $command_id ) < 8
			|| strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| strlen( $correlation_id ) < 1
			|| strlen( $correlation_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Curriculum catalog command metadata is invalid.' );
		}
	}

	/**
	 * Search.
	 *
	 * @param string $search Search.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function search( string $search ): string {
		$search = trim( $this->canonical->text( $search ) );
		if (
			$this->characters( $search ) > Curriculum_Catalog_Profile::MAX_SEARCH_CHARACTERS
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $search )
		) {
			throw new InvalidArgumentException( 'Catalog search is invalid.' );
		}
		return $search;
	}

	/**
	 * Limit.
	 *
	 * @param int $limit Maximum number of entries requested.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function limit( int $limit ): int {
		if ( $limit < 1 || $limit > Curriculum_Catalog_Profile::MAX_QUERY_ITEMS ) {
			throw new InvalidArgumentException( 'Catalog limit is invalid.' );
		}
		return $limit;
	}

	/**
	 * Curriculum payload.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @return array<string,mixed>
	 */
	private function curriculum_payload( array $prepared ): array {
		return array(
			'curriculum_id'  => $prepared['public_id'],
			'locale'         => $prepared['locale'],
			'source_key'     => $prepared['source_key'],
			'source_version' => $prepared['source_version'],
			'title'          => $prepared['title'],
		);
	}

	/**
	 * Standard payload.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @return array<string,mixed>
	 */
	private function standard_payload( array $prepared ): array {
		return array(
			'code'        => $prepared['code'],
			'description' => $prepared['description'],
			'grade_label' => $prepared['grade_label'],
			'standard_id' => $prepared['public_id'],
			'title'       => $prepared['title'],
		);
	}

	/**
	 * Storage key.
	 *
	 * @param int         $actor_user_id Authenticated actor from the access context.
	 * @param string      $operation Operation.
	 * @param string|null $resource_id Resource id.
	 * @param string      $fingerprint Fingerprint.
	 * @return string
	 */
	private function storage_key(
		int $actor_user_id,
		string $operation,
		?string $resource_id,
		string $fingerprint
	): string {
		return 'curriculum_catalog:' . hash(
			'sha256',
			$actor_user_id . '|' . $operation . '|' . ( $resource_id ?? 'new' ) . '|' . $fingerprint
		);
	}

	/**
	 * Characters.
	 *
	 * @param string $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function characters( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}
		$count = preg_match_all( '/./us', $value );
		if ( false === $count ) {
			throw new InvalidArgumentException( 'Catalog search is invalid UTF-8.' );
		}
		return $count;
	}
}
