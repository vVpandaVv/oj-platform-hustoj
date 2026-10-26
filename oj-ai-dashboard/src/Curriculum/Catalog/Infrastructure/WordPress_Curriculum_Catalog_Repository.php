<?php
/**
 * WordPress curriculum catalog repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Curriculum\Catalog\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Curriculum\Catalog\Application\Curriculum_Catalog_Failure;
use OJAI\Dashboard\Curriculum\Catalog\Domain\Curriculum_Catalog_Profile;
use OJAI\Dashboard\Curriculum\Catalog\Port\Curriculum_Catalog_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress curriculum catalog repository.
 */
final class WordPress_Curriculum_Catalog_Repository implements Curriculum_Catalog_Repository_Port_Interface {
	private const TABLE_CURRICULUMS = 'oj_ai_curriculums';
	private const TABLE_STANDARDS   = 'oj_ai_achievement_standards';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';
	private const AGGREGATE_TYPE    = 'curriculum_catalog';
	private const RECEIPT_SCHEMA    = 'oj_ai.curriculum_catalog_command_receipt';
	private const AUDIT_SCHEMA      = 'oj_ai.curriculum_catalog_audit';
	private const VERSION           = 1;

	/**
	 * Initialize WordPress curriculum catalog repository dependencies and state.
	 *
	 * @param Canonical_Json             $canonical Canonical JSON encoder used for stable digests.
	 * @param Public_Id_Generator        $ids Ids.
	 * @param Curriculum_Catalog_Profile $profile Profile.
	 * @param object|null                $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Public_Id_Generator $ids,
		private Curriculum_Catalog_Profile $profile,
		private ?object $database = null
	) {
	}

	/**
	 * List curriculums.
	 *
	 * @param string $search Search.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_curriculums( string $search, int $limit ): array {
		try {
			$sql    = 'SELECT public_id, lock_version, source_kind, source_key, source_version, title, locale, state, metadata_schema, metadata_version, metadata_json, metadata_hash, content_hash, updated_at_utc FROM ' . self::TABLE_CURRICULUMS . ' WHERE state = %s';
			$values = array( Curriculum_Catalog_Profile::STATE_ACTIVE );
			if ( '' !== $search ) {
				$like = $this->like( $search );
				$sql .= ' AND (title LIKE %s OR source_key LIKE %s OR source_version LIKE %s)';
				array_push( $values, $like, $like, $like );
			}
			$sql     .= ' ORDER BY title ASC, source_version DESC, public_id ASC LIMIT %d';
			$values[] = $limit;
			$rows     = $this->get_results( $this->prepare( $sql, $values ) );
			return array_map(
				fn ( array $row ): array => $this->profile->project_curriculum( $row ),
				$rows
			);
		} catch ( Curriculum_Catalog_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
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
		try {
			$curriculum = $this->curriculum_row( $curriculum_id, false );
			$sql        = 'SELECT standard.public_id, standard.lock_version, standard.code, standard.title, standard.description, standard.grade_label, standard.metadata_schema, standard.metadata_version, standard.metadata_json, standard.metadata_hash, standard.content_hash, standard.updated_at_utc, curriculum.public_id AS curriculum_public_id, curriculum.title AS curriculum_title, curriculum.source_version AS curriculum_source_version FROM ' . self::TABLE_STANDARDS . ' standard '
				. 'INNER JOIN ' . self::TABLE_CURRICULUMS . ' curriculum ON curriculum.id = standard.curriculum_id '
				. 'WHERE standard.curriculum_id = %d';
			$values     = array( $this->integer( $curriculum['id'] ?? null, 1 ) );
			if ( '' !== $search ) {
				$like = $this->like( $search );
				$sql .= ' AND (standard.code LIKE %s OR standard.title LIKE %s OR standard.description LIKE %s OR standard.grade_label LIKE %s)';
				array_push( $values, $like, $like, $like, $like );
			}
			$sql     .= ' ORDER BY standard.code ASC, standard.title ASC, standard.public_id ASC LIMIT %d';
			$values[] = $limit;
			$rows     = $this->get_results( $this->prepare( $sql, $values ) );
			return array_map(
				fn ( array $row ): array => $this->profile->project_standard( $row ),
				$rows
			);
		} catch ( Curriculum_Catalog_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Save curriculum.
	 *
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function save_curriculum( array $command ): array {
		$this->assert_command( $command, 'curriculum' );
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$receipt             = $this->receipt(
				$command['idempotency_storage_key'],
				$command['request_digest'],
				true
			);
			if ( null !== $receipt ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				$receipt['replayed'] = true;
				return $receipt;
			}

			$prepared  = $command['prepared'];
			$public_id = $prepared['public_id'];
			if ( null === $public_id ) {
				if ( null !== $command['expected_lock_version'] ) {
					throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STATE_CONFLICT );
				}
				$this->assert_curriculum_source_available( $prepared, null );
				$public_id = $this->ids->create()->value();
				$row       = $this->curriculum_insert_row( $prepared, $public_id, $command );
				$this->insert_or_fail( self::TABLE_CURRICULUMS, $row );
				$result = $this->result(
					'create',
					false,
					$command['occurred_at_utc'],
					'curriculum',
					$this->profile->project_curriculum( $row )
				);
				$this->insert_receipt( $command, $public_id, 'curriculum_catalog.curriculum_saved', $result );
				$this->insert_audit(
					'curriculum_catalog.curriculum_created',
					$command,
					$public_id,
					null,
					$prepared['content_hash']
				);
			} else {
				$current = $this->curriculum_row( $public_id, true );
				$this->assert_expected_lock( $current, $command['expected_lock_version'] );
				if ( Curriculum_Catalog_Profile::SOURCE_MANUAL !== (string) ( $current['source_kind'] ?? '' ) ) {
					throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STATE_CONFLICT );
				}
				$this->assert_curriculum_source_available( $prepared, $public_id );
				if ( hash_equals( (string) $current['content_hash'], (string) $prepared['content_hash'] ) ) {
					$result = $this->result(
						'unchanged',
						true,
						$command['occurred_at_utc'],
						'curriculum',
						$this->profile->project_curriculum( $current )
					);
					$this->insert_receipt( $command, $public_id, 'curriculum_catalog.curriculum_saved', $result );
				} else {
					$updated = $this->update_curriculum( $current, $prepared, $command );
					$result  = $this->result(
						'update',
						false,
						$command['occurred_at_utc'],
						'curriculum',
						$this->profile->project_curriculum( $updated )
					);
					$this->insert_receipt( $command, $public_id, 'curriculum_catalog.curriculum_saved', $result );
					$this->insert_audit(
						'curriculum_catalog.curriculum_updated',
						$command,
						$public_id,
						(string) $current['content_hash'],
						(string) $prepared['content_hash']
					);
				}
			}

			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $result;
		} catch ( Curriculum_Catalog_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Save standard.
	 *
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function save_standard( array $command ): array {
		$this->assert_command( $command, 'standard' );
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$receipt             = $this->receipt(
				$command['idempotency_storage_key'],
				$command['request_digest'],
				true
			);
			if ( null !== $receipt ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				$receipt['replayed'] = true;
				return $receipt;
			}

			$curriculum = $this->curriculum_row( $command['curriculum_id'], true );
			if (
				Curriculum_Catalog_Profile::STATE_ACTIVE !== ( $curriculum['state'] ?? null )
				|| Curriculum_Catalog_Profile::SOURCE_MANUAL !== ( $curriculum['source_kind'] ?? null )
			) {
				throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STATE_CONFLICT );
			}
			$prepared                 = $command['prepared'];
			$prepared['content_hash'] = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'curriculum_id' => $command['curriculum_id'],
						'standard_hash' => $prepared['content_hash'],
					)
				)
			);
			$public_id                = $prepared['public_id'];
			if ( null === $public_id ) {
				if ( null !== $command['expected_lock_version'] ) {
					throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STATE_CONFLICT );
				}
				$public_id = $this->ids->create()->value();
				$row       = $this->standard_insert_row( $prepared, $public_id, $curriculum, $command );
				$this->insert_or_fail( self::TABLE_STANDARDS, $row );
				$projected = $this->profile->project_standard(
					$this->standard_projection_row( $row, $curriculum )
				);
				$result    = $this->result(
					'create',
					false,
					$command['occurred_at_utc'],
					'achievement_standard',
					$projected
				);
				$this->insert_receipt( $command, $public_id, 'curriculum_catalog.standard_saved', $result );
				$this->insert_audit(
					'curriculum_catalog.standard_created',
					$command,
					$public_id,
					null,
					$prepared['content_hash']
				);
			} else {
				$current = $this->standard_row( $public_id, true );
				if (
					$this->integer( $current['curriculum_id'] ?? null, 1 )
					!== $this->integer( $curriculum['id'] ?? null, 1 )
				) {
					throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::NOT_FOUND );
				}
				$this->assert_expected_lock( $current, $command['expected_lock_version'] );
				if ( hash_equals( (string) $current['content_hash'], (string) $prepared['content_hash'] ) ) {
					$projected = $this->profile->project_standard(
						$this->standard_projection_row( $current, $curriculum )
					);
					$result    = $this->result(
						'unchanged',
						true,
						$command['occurred_at_utc'],
						'achievement_standard',
						$projected
					);
					$this->insert_receipt( $command, $public_id, 'curriculum_catalog.standard_saved', $result );
				} else {
					$updated   = $this->update_standard( $current, $prepared, $command );
					$projected = $this->profile->project_standard(
						$this->standard_projection_row( $updated, $curriculum )
					);
					$result    = $this->result(
						'update',
						false,
						$command['occurred_at_utc'],
						'achievement_standard',
						$projected
					);
					$this->insert_receipt( $command, $public_id, 'curriculum_catalog.standard_saved', $result );
					$this->insert_audit(
						'curriculum_catalog.standard_updated',
						$command,
						$public_id,
						(string) $current['content_hash'],
						(string) $prepared['content_hash']
					);
				}
			}

			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $result;
		} catch ( Curriculum_Catalog_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Curriculum insert row.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @param string              $public_id Public id.
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 */
	private function curriculum_insert_row( array $prepared, string $public_id, array $command ): array {
		return array_merge(
			$this->mutation_columns( $public_id, $command ),
			$this->curriculum_content_columns( $prepared )
		);
	}

	/**
	 * Standard insert row.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @param string              $public_id Public id.
	 * @param array<string,mixed> $curriculum Curriculum.
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 */
	private function standard_insert_row(
		array $prepared,
		string $public_id,
		array $curriculum,
		array $command
	): array {
		return array_merge(
			$this->mutation_columns( $public_id, $command ),
			array(
				'curriculum_id' => $this->integer( $curriculum['id'] ?? null, 1 ),
				'stable_key'    => $public_id,
			),
			$this->standard_content_columns( $prepared )
		);
	}

	/**
	 * Update curriculum.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $prepared Prepared.
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_curriculum( array $current, array $prepared, array $command ): array {
		$sql      = 'UPDATE ' . self::TABLE_CURRICULUMS . ' SET source_kind = %s, source_key = %s, '
			. 'source_version = %s, title = %s, locale = %s, state = %s, metadata_schema = %s, '
			. 'metadata_version = %d, metadata_json = %s, metadata_hash = %s, content_hash = %s, '
			. 'lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
			. 'WHERE id = %d AND lock_version = %d';
		$affected = $this->query_affected(
			$this->prepare(
				$sql,
				array(
					$prepared['source_kind'],
					$prepared['source_key'],
					$prepared['source_version'],
					$prepared['title'],
					$prepared['locale'],
					$prepared['state'],
					$prepared['metadata_schema'],
					$prepared['metadata_version'],
					$prepared['metadata_json'],
					$prepared['metadata_hash'],
					$prepared['content_hash'],
					$command['actor_user_id'],
					$command['occurred_at_utc'],
					$this->integer( $current['id'] ?? null, 1 ),
					$this->integer( $current['lock_version'] ?? null, 0 ),
				)
			)
		);
		if ( 1 !== $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STATE_CONFLICT );
		}
		return array_merge(
			$current,
			$this->curriculum_content_columns( $prepared ),
			array(
				'lock_version'       => $this->integer( $current['lock_version'] ?? null, 0 ) + 1,
				'updated_by_user_id' => $command['actor_user_id'],
				'updated_at_utc'     => $command['occurred_at_utc'],
			)
		);
	}

	/**
	 * Update standard.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $prepared Prepared.
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_standard( array $current, array $prepared, array $command ): array {
		$grade_sql = null === $prepared['grade_label'] ? 'grade_label = NULL' : 'grade_label = %s';
		$sql       = 'UPDATE ' . self::TABLE_STANDARDS . ' SET code = %s, title = %s, description = %s, '
			. $grade_sql . ', metadata_schema = %s, metadata_version = %d, metadata_json = %s, '
			. 'metadata_hash = %s, content_hash = %s, lock_version = lock_version + 1, '
			. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d';
		$values    = array(
			$prepared['code'],
			$prepared['title'],
			$prepared['description'],
		);
		if ( null !== $prepared['grade_label'] ) {
			$values[] = $prepared['grade_label'];
		}
		array_push(
			$values,
			$prepared['metadata_schema'],
			$prepared['metadata_version'],
			$prepared['metadata_json'],
			$prepared['metadata_hash'],
			$prepared['content_hash'],
			$command['actor_user_id'],
			$command['occurred_at_utc'],
			$this->integer( $current['id'] ?? null, 1 ),
			$this->integer( $current['lock_version'] ?? null, 0 )
		);
		$affected = $this->query_affected( $this->prepare( $sql, $values ) );
		if ( 1 !== $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STATE_CONFLICT );
		}
		return array_merge(
			$current,
			$this->standard_content_columns( $prepared ),
			array(
				'lock_version'       => $this->integer( $current['lock_version'] ?? null, 0 ) + 1,
				'updated_by_user_id' => $command['actor_user_id'],
				'updated_at_utc'     => $command['occurred_at_utc'],
			)
		);
	}
	/**
	 * Curriculum content columns.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @return array<string,mixed>
	 */
	private function curriculum_content_columns( array $prepared ): array {
		return array(
			'source_kind'      => $prepared['source_kind'],
			'source_key'       => $prepared['source_key'],
			'source_version'   => $prepared['source_version'],
			'title'            => $prepared['title'],
			'locale'           => $prepared['locale'],
			'state'            => $prepared['state'],
			'metadata_schema'  => $prepared['metadata_schema'],
			'metadata_version' => $prepared['metadata_version'],
			'metadata_json'    => $prepared['metadata_json'],
			'metadata_hash'    => $prepared['metadata_hash'],
			'content_hash'     => $prepared['content_hash'],
		);
	}

	/**
	 * Standard content columns.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @return array<string,mixed>
	 */
	private function standard_content_columns( array $prepared ): array {
		return array(
			'code'             => $prepared['code'],
			'title'            => $prepared['title'],
			'description'      => $prepared['description'],
			'grade_label'      => $prepared['grade_label'],
			'metadata_schema'  => $prepared['metadata_schema'],
			'metadata_version' => $prepared['metadata_version'],
			'metadata_json'    => $prepared['metadata_json'],
			'metadata_hash'    => $prepared['metadata_hash'],
			'content_hash'     => $prepared['content_hash'],
		);
	}

	/**
	 * Mutation columns.
	 *
	 * @param string              $public_id Public id.
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 */
	private function mutation_columns( string $public_id, array $command ): array {
		return array(
			'public_id'          => $public_id,
			'lock_version'       => 0,
			'created_by_user_id' => $command['actor_user_id'],
			'created_at_utc'     => $command['occurred_at_utc'],
			'updated_by_user_id' => $command['actor_user_id'],
			'updated_at_utc'     => $command['occurred_at_utc'],
		);
	}

	/**
	 * Reject invalid curriculum source available.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @param string|null         $current_public_id Current public id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_curriculum_source_available( array $prepared, ?string $current_public_id ): void {
		$sql    = 'SELECT public_id FROM ' . self::TABLE_CURRICULUMS
			. ' WHERE source_kind = %s AND source_key = %s AND source_version = %s';
		$values = array(
			$prepared['source_kind'],
			$prepared['source_key'],
			$prepared['source_version'],
		);
		if ( null !== $current_public_id ) {
			$sql     .= ' AND public_id <> %s';
			$values[] = $current_public_id;
		}
		$sql .= ' LIMIT 1 FOR UPDATE';
		if ( null !== $this->get_row( $this->prepare( $sql, $values ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::VALIDATION_FAILED );
		}
	}

	/**
	 * Curriculum row.
	 *
	 * @param string $public_id Public id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function curriculum_row( string $public_id, bool $for_update ): array {
		if ( ! Public_Id::is_valid( $public_id ) ) {
			throw new InvalidArgumentException( 'Curriculum public ID is invalid.' );
		}
		$sql = 'SELECT id, public_id, lock_version, created_by_user_id, created_at_utc, updated_by_user_id, updated_at_utc, source_kind, source_key, source_version, title, locale, state, metadata_schema, metadata_version, metadata_json, metadata_hash, content_hash FROM ' . self::TABLE_CURRICULUMS
			. ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $public_id ) ) );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::NOT_FOUND );
		}
		return $row;
	}

	/**
	 * Standard row.
	 *
	 * @param string $public_id Public id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function standard_row( string $public_id, bool $for_update ): array {
		if ( ! Public_Id::is_valid( $public_id ) ) {
			throw new InvalidArgumentException( 'Standard public ID is invalid.' );
		}
		$sql = 'SELECT id, public_id, lock_version, created_by_user_id, created_at_utc, updated_by_user_id, updated_at_utc, curriculum_id, stable_key, code, title, description, grade_label, metadata_schema, metadata_version, metadata_json, metadata_hash, content_hash FROM ' . self::TABLE_STANDARDS
			. ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $public_id ) ) );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::NOT_FOUND );
		}
		return $row;
	}

	/**
	 * Standard projection row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $curriculum Curriculum.
	 * @return array<string,mixed>
	 */
	private function standard_projection_row( array $row, array $curriculum ): array {
		return array_merge(
			$row,
			array(
				'curriculum_public_id'      => $curriculum['public_id'],
				'curriculum_title'          => $curriculum['title'],
				'curriculum_source_version' => $curriculum['source_version'],
			)
		);
	}

	/**
	 * Reject invalid expected lock.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param int|null            $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected_lock( array $current, ?int $expected ): void {
		if (
			null === $expected
			|| $this->integer( $current['lock_version'] ?? null, 0 ) !== $expected
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STATE_CONFLICT );
		}
	}
	/**
	 * Insert receipt.
	 *
	 * @param array<string,mixed> $command Command.
	 * @param string              $aggregate_public_id Aggregate public id.
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $result Result.
	 * @return void
	 */
	private function insert_receipt(
		array $command,
		string $aggregate_public_id,
		string $event_type,
		array $result
	): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $command['request_digest'],
				'result'         => $result,
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $this->ids->create()->value(),
				'lock_version'         => 0,
				'created_by_user_id'   => $command['actor_user_id'],
				'created_at_utc'       => $command['occurred_at_utc'],
				'updated_by_user_id'   => $command['actor_user_id'],
				'updated_at_utc'       => $command['occurred_at_utc'],
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => $aggregate_public_id,
				'event_type'           => $event_type,
				'idempotency_key'      => $command['idempotency_storage_key'],
				'payload_schema'       => self::RECEIPT_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $command['occurred_at_utc'],
				'published_at_utc'     => $command['occurred_at_utc'],
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?array {
		$sql = 'SELECT aggregate_type, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX
			. ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::AGGREGATE_TYPE !== ( $row['aggregate_type'] ?? null )
			|| self::RECEIPT_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::VERSION !== $this->integer( $row['payload_version'] ?? null, 1 )
			|| ! in_array(
				$row['event_type'] ?? null,
				array(
					'curriculum_catalog.curriculum_saved',
					'curriculum_catalog.standard_saved',
				),
				true
			)
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		}
		$payload = $this->decode_json(
			(string) ( $row['payload_json'] ?? '' ),
			(string) ( $row['payload_hash'] ?? '' )
		);
		$this->exact_keys( $payload, array( 'request_digest', 'result' ) );
		if (
			! is_string( $payload['request_digest'] ?? null )
			|| ! hash_equals( $request_digest, $payload['request_digest'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		}
		return $result;
	}

	/**
	 * Insert audit.
	 *
	 * @param string              $action_key Action key.
	 * @param array<string,mixed> $command Command.
	 * @param string              $object_public_id Object public id.
	 * @param string|null         $before_digest Before digest.
	 * @param string              $after_digest After digest.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit(
		string $action_key,
		array $command,
		string $object_public_id,
		?string $before_digest,
		string $after_digest
	): void {
		$previous_hash = $this->get_var(
			'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
		);
		if (
			null !== $previous_hash
			&& 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'     => $command['command_id'],
				'request_digest' => $command['request_digest'],
				'resource_type'  => $command['resource_type'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $action_key,
					'actor_user_id'       => $command['actor_user_id'],
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => $command['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $object_public_id,
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $command['occurred_at_utc'],
					'previous_event_hash' => null === $previous_hash
						? null
						: (string) $previous_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $this->ids->create()->value(),
				'occurred_at_utc'     => $command['occurred_at_utc'],
				'actor_user_id'       => $command['actor_user_id'],
				'action_key'          => $action_key,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => $object_public_id,
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $command['correlation_id'],
				'before_digest'       => $before_digest,
				'after_digest'        => $after_digest,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			)
		);
	}
	/**
	 * Reject invalid command.
	 *
	 * @param array<string,mixed> $command Command.
	 * @param string              $resource_type Resource type.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_command( array $command, string $resource_type ): void {
		$expected = array(
			'actor_user_id',
			'command_id',
			'correlation_id',
			'expected_lock_version',
			'idempotency_storage_key',
			'occurred_at_utc',
			'prepared',
			'request_digest',
			'resource_type',
		);
		if ( 'standard' === $resource_type ) {
			$expected[] = 'curriculum_id';
		}
		$this->exact_keys( $command, $expected );
		if (
			$resource_type !== ( $command['resource_type'] ?? null )
			|| ! is_int( $command['actor_user_id'] ?? null )
			|| $command['actor_user_id'] < 1
			|| ! is_string( $command['command_id'] ?? null )
			|| ! is_string( $command['correlation_id'] ?? null )
			|| ! is_string( $command['idempotency_storage_key'] ?? null )
			|| 1 !== preg_match(
				'/^curriculum_catalog:[a-f0-9]{64}$/D',
				$command['idempotency_storage_key']
			)
			|| ! is_string( $command['request_digest'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $command['request_digest'] )
			|| ! is_string( $command['occurred_at_utc'] ?? null )
			|| 1 !== preg_match(
				'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',
				$command['occurred_at_utc']
			)
			|| ! is_array( $command['prepared'] ?? null )
			|| array_is_list( $command['prepared'] )
			|| (
				null !== ( $command['expected_lock_version'] ?? null )
				&& (
					! is_int( $command['expected_lock_version'] )
					|| $command['expected_lock_version'] < 0
				)
			)
			|| (
				'standard' === $resource_type
				&& (
					! is_string( $command['curriculum_id'] ?? null )
					|| ! Public_Id::is_valid( $command['curriculum_id'] )
				)
			)
		) {
			throw new InvalidArgumentException( 'Curriculum catalog repository command is invalid.' );
		}
	}

	/**
	 * Result.
	 *
	 * @param string $operation Operation.
	 * @param bool   $unchanged Unchanged.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @param string $key Key.
	 * @param array  $record Record.
	 * @return array<string,mixed>
	 */
	private function result(
		string $operation,
		bool $unchanged,
		string $occurred_at_utc,
		string $key,
		array $record
	): array {
		return array(
			'operation'       => $operation,
			'unchanged'       => $unchanged,
			'replayed'        => false,
			'occurred_at_utc' => $occurred_at_utc,
			$key              => $record,
		);
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Curriculum catalog shape is invalid.' );
		}
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_json( string $json, string $hash ): array {
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		}
		$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $minimum Minimum.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function integer( $value, int $minimum ): int {
		if ( is_int( $value ) && $value >= $minimum ) {
			return $value;
		}
		if (
			is_string( $value )
			&& 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value )
			&& (int) $value >= $minimum
		) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORED_DATA_INVALID );
	}

	/**
	 * Like.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function like( string $value ): string {
		return '%' . addcslashes( $value, '%_\\' ) . '%';
	}

	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$database = $this->database();
		$this->reset_error( $database );
		if (
			false === $database->insert( $table, $data )
			|| '' !== trim( (string) ( $database->last_error ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$database = $this->database();
		$this->reset_error( $database );
		$rows = $database->get_results(
			$sql,
			defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
		);
		$this->assert_read( $database );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $rows;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$database = $this->database();
		$this->reset_error( $database );
		$row = $database->get_row(
			$sql,
			defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
		);
		$this->assert_read( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function get_var( string $sql ) {
		$database = $this->database();
		$this->reset_error( $database );
		$value = $database->get_var( $sql );
		$this->assert_read( $database );
		return $value;
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$database = $this->database();
		$this->reset_error( $database );
		if (
			false === $database->query( $sql )
			|| '' !== trim( (string) ( $database->last_error ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Query affected.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_affected( string $sql ): int {
		$database = $this->database();
		$this->reset_error( $database );
		$result = $database->query( $sql );
		if (
			! is_int( $result )
			|| '' !== trim( (string) ( $database->last_error ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
		return $result;
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			// The original failure remains authoritative.
		}
	}

	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}

	/**
	 * Reject invalid read.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $database ): void {
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_results', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Curriculum_Catalog_Failure::because( Curriculum_Catalog_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $database;
	}
}
