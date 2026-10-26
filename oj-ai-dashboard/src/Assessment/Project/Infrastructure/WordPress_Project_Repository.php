<?php
/**
 * WordPress project repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Assessment\Project\Infrastructure;

use OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration;
use OJAI\Dashboard\Assessment\Project\Domain\Project_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Assessment\Project\Domain\Project_Input_Failure;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns assessment revisions and their worksheet membership, never platform data.
 */
final class WordPress_Project_Repository implements \OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface {
	/**
	 * Initialize WordPress project repository dependencies and state.
	 *
	 * @param Canonical_Json        $canonical Canonical JSON encoder used for stable digests.
	 * @param Public_Id_Generator   $ids Ids.
	 * @param Project_Configuration $profile Profile.
	 * @param object|null           $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private Public_Id_Generator $ids, private Project_Configuration $profile, private ?object $database = null ) {
	}

	/**
	 * Listing.
	 *
	 * @param int  $actor Actor.
	 * @param int  $after After.
	 * @param bool $archived Whether to list archived projects.
	 * @return array
	 */
	public function listing( int $actor, int $after = 0, bool $archived = false ): array {
		$this->actor( $actor );
		$filter = $archived ? 'p.state = %s' : 'p.state <> %s';
		$rows   = $this->rows( $this->sql( 'SELECT p.public_id, p.title, r.state, p.state AS project_state, p.lock_version, p.id, r.revision_no, p.updated_at_utc, (SELECT COUNT(*) FROM oj_ai_assessment_project_worksheets m WHERE m.project_id = p.id) AS worksheet_count FROM oj_ai_assessment_projects p JOIN oj_ai_assessment_project_revisions r ON r.public_id = p.current_revision_public_id AND r.project_id = p.id WHERE p.owner_user_id = %d AND p.id > %d AND ' . $filter . ' ORDER BY p.id ASC LIMIT 51', $actor, $after, 'archived' ) );
		$more   = count( $rows ) > 50;
		$rows   = array_slice( $rows, 0, 50 );
		$cursor = $more ? (int) end( $rows )['id'] : null;
		return array(
			'projects'    => array_map(
				static fn ( array $r ): array => array(
					'project_id'      => $r['public_id'],
					'title'           => $r['title'],
					'state'           => $r['state'],
					'archived'        => 'archived' === $r['project_state'],
					'lock_version'    => (int) $r['lock_version'],
					'revision_no'     => (int) $r['revision_no'],
					'worksheet_count' => (int) $r['worksheet_count'],
					'updated_at_utc'  => $r['updated_at_utc'],
				),
				$rows
			),
			'next_cursor' => $cursor,
		);
	}

	/**
	 * Archive is list organization, not withdrawal of distributed student work.
	 *
	 * @param int    $actor Owner.
	 * @param string $id Project public ID.
	 * @param int    $expected Expected root lock version.
	 * @param bool   $archived Desired archive state.
	 * @return array
	 * @throws Throwable Propagates validation or storage failures.
	 */
	public function set_archived( int $actor, string $id, int $expected, bool $archived ): array {
		$this->actor( $actor );
		$this->id( $id );
		if ( $expected < 0 || $expected > 9007199254740990 ) {
			throw new Project_Input_Failure( '평가 목록 버전이 올바르지 않습니다.' );
		}
		try {
			$this->query( 'START TRANSACTION' );
			$root = $this->one( $this->sql( 'SELECT * FROM oj_ai_assessment_projects WHERE public_id = %s AND owner_user_id = %d FOR UPDATE', $id, $actor ) );
			if ( null === $root ) {
				throw new Project_Failure( 404, '평가를 찾을 수 없거나 접근 권한이 없습니다.' );
			}
			$version = (int) $root['lock_version'];
			$same    = $archived === ( 'archived' === $root['state'] );
			// A lost response can be retried, but not across later archive/restore changes.
			if ( $version !== $expected && ! ( $same && $version === $expected + 1 ) ) {
				throw new Project_Failure( 409, '평가 상태가 변경되었습니다. 목록을 새로고침한 뒤 다시 확인해 주세요.' );
			}
			if ( ! $same ) {
				$current = $this->read( $actor, $id );
				if ( 1 !== $this->db()->update(
					'oj_ai_assessment_projects',
					array(
						'state'              => $archived ? 'archived' : $current['state'],
						'lock_version'       => $version + 1,
						'updated_by_user_id' => $actor,
						'updated_at_utc'     => gmdate( 'Y-m-d H:i:s' ),
					),
					array(
						'id'           => (int) $root['id'],
						'lock_version' => $version,
					)
				) ) {
					throw new Project_Failure( 409, '평가 보관 상태를 변경하지 못했습니다. 최신 목록을 확인해 주세요.' );
				}
			}
			$result = $this->read( $actor, $id );
			$this->query( 'COMMIT' );
			return $result;
		} catch ( Throwable $failure ) {
			$this->db()->query( 'ROLLBACK' );
			throw $failure;
		}
	}

	/**
	 * Read WordPress project repository.
	 *
	 * @param int         $actor Actor.
	 * @param string      $id Id.
	 * @param string|null $revision Revision.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( int $actor, string $id, ?string $revision = null ): array {
		$this->actor( $actor );
		$this->id( $id );
		$query = 'SELECT p.id AS project_pk, p.public_id AS project_public_id, p.title, p.owner_user_id, p.state AS project_state, p.lock_version AS root_lock_version, p.current_revision_public_id, r.* FROM oj_ai_assessment_projects p JOIN oj_ai_assessment_project_revisions r ON r.project_id = p.id AND r.public_id = ';
		if ( null !== $revision ) {
			$this->id( $revision );
			$query .= $this->sql( '%s', $revision );
		} else {
			$query .= 'p.current_revision_public_id';
		}
		$row = $this->one( $this->sql( $query . ' WHERE p.public_id = %s AND p.owner_user_id = %d', $id, $actor ) );
		if ( null === $row ) {
			throw new Project_Failure( 404, '평가를 찾을 수 없거나 접근 권한이 없습니다.' );
		}
		$project               = $this->project( $row );
		$project['worksheets'] = $this->rows( $this->sql( 'SELECT w.public_id AS worksheet_id, w.title, w.state, w.current_revision_public_id AS revision_id, w.latest_publication_public_id AS publication_id, r.public_id AS project_revision_id, r.revision_no AS criteria_revision_no FROM oj_ai_assessment_project_worksheets m JOIN oj_ai_worksheets w ON w.id = m.worksheet_id JOIN oj_ai_assessment_project_revisions r ON r.id = m.project_revision_id WHERE m.project_id = %d AND w.owner_user_id = %d ORDER BY m.id ASC', (int) $row['project_pk'], $actor ) );
		$project['history']    = $this->rows( $this->sql( 'SELECT public_id AS revision_id, revision_no, state, created_at_utc FROM oj_ai_assessment_project_revisions WHERE project_id = %d ORDER BY revision_no DESC LIMIT 50', (int) $row['project_pk'] ) );
		return $project;
	}

	/**
	 * Create WordPress project repository.
	 *
	 * @param int    $actor Actor.
	 * @param array  $payload Input payload at this boundary; see the declared shape.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create( int $actor, array $payload, string $key, string $digest ): array {
		$this->actor( $actor );
		$this->hash( $key );
		$this->hash( $digest );
		Project_Configuration::keys( $payload, array( 'title', 'copy_from', 'source_revision_id' ) );
		$title = Project_Configuration::text( $payload['title'], 255 );
		if ( ( null === $payload['copy_from'] ) !== ( null === $payload['source_revision_id'] ) ) {
			throw new Project_Input_Failure( '복사할 평가와 버전을 함께 선택해 주세요.' );
		}
		if ( null !== $payload['copy_from'] ) {
			$this->id( $payload['copy_from'] );
			$this->id( $payload['source_revision_id'] );
		}
		$creation = hash( 'sha256', $actor . '|assessment_project.create|' . $key );
		$find     = fn (): ?array => $this->one( $this->sql( 'SELECT public_id, creation_digest FROM oj_ai_assessment_projects WHERE creation_key = %s AND owner_user_id = %d', $creation, $actor ) );
		$replay   = $find();
		if ( null !== $replay ) {
			$this->same( $replay['creation_digest'], $digest );
			return $this->read( $actor, $replay['public_id'] );
		}
		try {
			$this->query( 'START TRANSACTION' );
			$config = $this->profile->empty();
			if ( null !== $payload['copy_from'] ) {
				$source = $this->read( $actor, $payload['copy_from'], $payload['source_revision_id'] );
				$config = $this->profile->copy( $source['configuration'] );
			}
			$id       = $this->ids->create()->value();
			$revision = $this->ids->create()->value();
			$now      = gmdate( 'Y-m-d H:i:s' );
			$pk       = $this->insert(
				'oj_ai_assessment_projects',
				array(
					'public_id'                  => $id,
					'title'                      => $title,
					'owner_user_id'              => $actor,
					'state'                      => 'draft',
					'current_revision_public_id' => $revision,
					'creation_key'               => $creation,
					'creation_digest'            => $digest,
				) + $this->mutable( $actor, $now )
			);
			$this->insert_revision( $pk, $revision, 1, 'draft', $config, $actor, $now, $creation, $digest );
			$this->query( 'COMMIT' );
			return $this->read( $actor, $id );
		} catch ( Throwable $failure ) {
			$this->db()->query( 'ROLLBACK' );
			$replay = $find();
			if ( null !== $replay ) {
				$this->same( $replay['creation_digest'], $digest );
				return $this->read( $actor, $replay['public_id'] );
			}
			throw $failure;
		}
	}

	/**
	 * Save WordPress project repository.
	 *
	 * @param int    $actor Actor.
	 * @param string $id Id.
	 * @param string $expected Expected.
	 * @param array  $payload Input payload at this boundary; see the declared shape.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function save( int $actor, string $id, string $expected, array $payload, string $key, string $digest ): array {
		$this->actor( $actor );
		$this->id( $id );
		$this->id( $expected );
		$this->hash( $key );
		$this->hash( $digest );
		Project_Configuration::keys( $payload, array( 'title', 'configuration', 'state' ) );
		$title = Project_Configuration::text( $payload['title'], 255 );
		$state = $payload['state'];
		if ( ! in_array( $state, array( 'draft', 'confirmed' ), true ) ) {
			throw new Project_Input_Failure( '평가 저장 상태가 올바르지 않습니다.' );
		}
		if ( ! is_array( $payload['configuration'] ) ) {
			throw new Project_Input_Failure( '평가 기준 입력 형식이 올바르지 않습니다.' );
		}
		$config  = $this->profile->normalize( $payload['configuration'], 'confirmed' === $state );
		$command = hash( 'sha256', $actor . '|assessment_project.save|' . $id . '|' . $key );
		try {
			$this->query( 'START TRANSACTION' );
			$root = $this->one( $this->sql( 'SELECT * FROM oj_ai_assessment_projects WHERE public_id = %s AND owner_user_id = %d FOR UPDATE', $id, $actor ) );
			if ( null === $root ) {
				throw new Project_Failure( 404, '평가를 찾을 수 없거나 접근 권한이 없습니다.' );
			}
			$replay = $this->one( $this->sql( 'SELECT public_id, request_digest FROM oj_ai_assessment_project_revisions WHERE project_id = %d AND command_key = %s', (int) $root['id'], $command ) );
			if ( null !== $replay ) {
				$this->same( $replay['request_digest'], $digest );
				$this->query( 'COMMIT' );
				return $this->read( $actor, $id, $replay['public_id'] );
			}
			if ( ! hash_equals( $expected, $root['current_revision_public_id'] ) ) {
				throw new Project_Failure( 409, '다른 창에서 평가 기준이 변경되었습니다. 새로고침 후 다시 확인해 주세요.' );
			}
			$current = $this->one( $this->sql( 'SELECT revision_no FROM oj_ai_assessment_project_revisions WHERE project_id = %d AND public_id = %s', (int) $root['id'], $expected ) );
			if ( null === $current ) {
				throw new Project_Failure( 503, '평가 기준 버전을 확인할 수 없습니다.' );
			}
			$revision = $this->ids->create()->value();
			$now      = gmdate( 'Y-m-d H:i:s' );
			$this->insert_revision( (int) $root['id'], $revision, (int) $current['revision_no'] + 1, $state, $config, $actor, $now, $command, $digest );
			if ( 1 !== $this->db()->update(
				'oj_ai_assessment_projects',
				array(
					'title'                      => $title,
					'state'                      => 'archived' === $root['state'] ? 'archived' : $state,
					'current_revision_public_id' => $revision,
					'lock_version'               => (int) $root['lock_version'] + 1,
					'updated_by_user_id'         => $actor,
					'updated_at_utc'             => $now,
				),
				array(
					'id'                         => (int) $root['id'],
					'current_revision_public_id' => $expected,
				)
			) ) {
				throw new Project_Failure( 409, '평가 저장이 충돌했습니다.' );
			}
			$this->query( 'COMMIT' );
			return $this->read( $actor, $id );
		} catch ( Throwable $failure ) {
			$this->db()->query( 'ROLLBACK' );
			throw $failure;
		}
	}

	/**
	 * Called only inside the worksheet repository's creation transaction.
	 *
	 * @param int   $worksheet_pk Worksheet pk.
	 * @param int   $actor Actor.
	 * @param array $pin Pin.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function attach_created( int $worksheet_pk, int $actor, array $pin ): void {
		$pin  = Project_Configuration::context( $pin );
		$root = $this->one( $this->sql( 'SELECT id, current_revision_public_id FROM oj_ai_assessment_projects WHERE public_id = %s AND owner_user_id = %d FOR UPDATE', $pin['project_id'], $actor ) );
		if ( null === $root || $root['current_revision_public_id'] !== $pin['revision_id'] ) {
			throw new Project_Failure( 409, '평가 기준이 변경되었습니다. 평가에서 최신 기준을 확인해 주세요.' );
		}
		$row = $this->one( $this->sql( 'SELECT id, content_hash, state FROM oj_ai_assessment_project_revisions WHERE project_id = %d AND public_id = %s', (int) $root['id'], $pin['revision_id'] ) );
		if ( null === $row || 'confirmed' !== $row['state'] || ! hash_equals( $pin['content_hash'], $row['content_hash'] ) ) {
			throw new Project_Failure( 409, '확정된 평가 기준을 확인해 주세요.' );
		}
		$this->insert(
			'oj_ai_assessment_project_worksheets',
			array(
				'project_id'          => (int) $root['id'],
				'project_revision_id' => (int) $row['id'],
				'worksheet_id'        => $worksheet_pk,
			) + $this->mutable( $actor, gmdate( 'Y-m-d H:i:s' ) )
		);
	}

	/**
	 * Server-owned membership is required; a document or URL cannot claim another assessment.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int|null   $actor Actor.
	 * @param bool       $workspace Include navigation-only migrated membership.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_worksheet( string $worksheet, ?array $pin = null, ?int $actor = null, bool $workspace = false ): ?array {
		$this->id( $worksheet );
		$query  = 'SELECT p.id AS project_pk, p.public_id AS project_public_id, p.title, p.owner_user_id, p.state AS project_state, p.lock_version AS root_lock_version, p.current_revision_public_id, r.* FROM oj_ai_assessment_project_worksheets m JOIN oj_ai_assessment_projects p ON p.id = m.project_id JOIN oj_ai_assessment_project_revisions r ON r.id = m.project_revision_id AND r.project_id = p.id JOIN oj_ai_worksheets w ON w.id = m.worksheet_id WHERE w.public_id = %s';
		$params = array( $worksheet );
		if ( null !== $actor ) {
			$this->actor( $actor );
			$query   .= ' AND p.owner_user_id = %d AND w.owner_user_id = %d';
			$params[] = $actor;
			$params[] = $actor;
		}
		$row = $this->one( $this->sql( $query, ...$params ) );
		if ( null === $row ) {
			if ( null !== $pin ) {
				throw new Project_Failure( 409, '이 활동지의 평가 연결을 확인할 수 없습니다.' );
			}
			return null;
		}
		// Navigation membership must not silently replace a historical grading contract.
		if ( ! $workspace && null === $pin && ! $this->worksheet_has_project_pin( $worksheet ) ) {
			return null;
		}
		$project = $this->project( $row );
		if ( null !== $pin && $this->canonical->encode( Project_Configuration::context( $pin ) ) !== $this->canonical->encode( Project_Configuration::pin( $project ) ) ) {
			throw new Project_Failure( 409, '활동지에 연결된 평가 기준 버전이 일치하지 않습니다.' );
		}
		return $project;
	}

	/**
	 * Read the current document without changing or re-encoding its stored bytes.
	 *
	 * @param string $worksheet Worksheet ID.
	 * @return bool
	 */
	private function worksheet_has_project_pin( string $worksheet ): bool {
		$row = $this->one( $this->sql( 'SELECT r.teacher_document_json, r.teacher_document_hash FROM oj_ai_worksheets w JOIN oj_ai_worksheet_revisions r ON r.public_id = w.current_revision_public_id AND r.worksheet_id = w.id WHERE w.public_id = %s', $worksheet ) );
		return null !== $row && isset( $this->migration_document( $row )['settings']['assessment_project'] );
	}

	/**
	 * Upgrade in bounded reads, committing each worksheet independently for safe replay.
	 *
	 * @return void
	 */
	public function migrate_unlinked(): void {
		do {
			$pending = $this->rows( 'SELECT w.public_id, w.owner_user_id FROM oj_ai_worksheets w LEFT JOIN oj_ai_assessment_project_worksheets m ON m.worksheet_id = w.id WHERE m.id IS NULL ORDER BY w.id ASC LIMIT 100' );
			$size    = count( $pending );
			foreach ( $pending as $row ) {
				$this->adopt_worksheet( (int) $row['owner_user_id'], $row['public_id'] );
			}
		} while ( 100 === $size );
	}

	/**
	 * Add only project records; worksheet IDs, revisions and downstream records stay intact.
	 *
	 * @param int    $actor Owner.
	 * @param string $worksheet Worksheet ID.
	 * @return array
	 * @throws Throwable Rolls back the complete worksheet adoption on failure.
	 */
	public function adopt_worksheet( int $actor, string $worksheet ): array {
		$this->actor( $actor );
		$this->id( $worksheet );
		try {
			$this->query( 'START TRANSACTION' );
			$root = $this->one( $this->sql( 'SELECT * FROM oj_ai_worksheets WHERE public_id = %s AND owner_user_id = %d FOR UPDATE', $worksheet, $actor ) );
			if ( null === $root ) {
				throw new Project_Failure( 404, '활동지를 찾을 수 없거나 접근 권한이 없습니다.' );
			}
			$existing = $this->for_worksheet( $worksheet, null, $actor, true );
			if ( null !== $existing ) {
				$this->query( 'COMMIT' );
				return $existing;
			}
			$source = $this->one( $this->sql( 'SELECT * FROM oj_ai_worksheet_revisions WHERE worksheet_id = %d AND public_id = %s', (int) $root['id'], $root['current_revision_public_id'] ) );
			if ( null === $source ) {
				throw new Project_Failure( 503, '이관할 활동지 버전을 확인할 수 없습니다.' );
			}
			$document = $this->migration_document( $source );
			if ( isset( $document['settings']['assessment_project'] ) ) {
				throw new Project_Failure( 409, '기존 평가 연결을 복구한 뒤 이관해 주세요.' );
			}
			$config = $this->migration_configuration( $document, (int) $source['id'] );
			// Copies clear preparation pins. Follow owned immutable ancestry, retaining binding identities.
			$source_revision = $source['source_revision_id'] ?? null;
			$visited         = array();
			while ( array() === $config['criteria'] && null !== $source_revision ) {
				if ( isset( $visited[ $source_revision ] ) || count( $visited ) >= 100 ) {
					throw new Project_Failure( 503, '복사 원본의 연결을 확인할 수 없습니다.' );
				}
				$visited[ $source_revision ] = true;
				$origin                      = $this->one( $this->sql( 'SELECT r.* FROM oj_ai_worksheet_revisions r JOIN oj_ai_worksheets w ON w.id = r.worksheet_id WHERE r.id = %d AND w.owner_user_id = %d', (int) $source_revision, $actor ) );
				$source_revision             = $origin['source_revision_id'] ?? null;
				if ( null !== $origin ) {
					$origin_document = $this->migration_document( $origin );
					$origin_pin      = $origin_document['settings']['assessment_project'] ?? null;
					if ( null !== $origin_pin ) {
						$original = $this->read( $actor, $origin_pin['project_id'], $origin_pin['revision_id'] );
						if ( ! hash_equals( $original['content_hash'], $origin_pin['content_hash'] ) ) {
							throw new Project_Failure( 503, '복사 원본의 평가 기준을 확인할 수 없습니다.' );
						}
						$original_config = $original['configuration'];
					} else {
						$original_config = $this->migration_configuration( $origin_document, (int) $origin['id'] );
					}
					$config['criteria']     = $original_config['criteria'];
					$config['total_points'] = $original_config['total_points'];
					if ( array() === $config['achievement_standards'] ) {
						$config['achievement_standards'] = $original_config['achievement_standards'];
					}
				}
			}
			$id       = $this->ids->create()->value();
			$revision = $this->ids->create()->value();
			$now      = gmdate( 'Y-m-d H:i:s' );
			$key      = hash( 'sha256', 'worksheet.project.adopt|' . $worksheet );
			$digest   = hash( 'sha256', $source['teacher_document_hash'] . '|' . $this->canonical->encode( $config ) );
			$pk       = $this->insert(
				'oj_ai_assessment_projects',
				array(
					'public_id'                  => $id,
					'title'                      => $root['title'],
					'owner_user_id'              => $actor,
					'state'                      => 'archived' === $root['state'] ? 'archived' : 'draft',
					'current_revision_public_id' => $revision,
					'creation_key'               => $key,
					'creation_digest'            => $digest,
				) + $this->mutable( $actor, $now )
			);
			$this->insert_revision( $pk, $revision, 1, 'draft', $config, $actor, $now, $key, $digest );
			$revision_row = $this->one( $this->sql( 'SELECT id FROM oj_ai_assessment_project_revisions WHERE project_id = %d AND public_id = %s', $pk, $revision ) );
			$this->insert(
				'oj_ai_assessment_project_worksheets',
				array(
					'project_id'          => $pk,
					'project_revision_id' => (int) $revision_row['id'],
					'worksheet_id'        => (int) $root['id'],
				) + $this->mutable( $actor, $now )
			);
			$result = $this->read( $actor, $id );
			$this->query( 'COMMIT' );
			return $result;
		} catch ( Throwable $failure ) {
			$this->db()->query( 'ROLLBACK' );
			throw $failure;
		}
	}

	/**
	 * Verify source integrity before extracting compatibility data.
	 *
	 * @param array $row Stored revision.
	 * @return array
	 * @throws Project_Failure Invalid source.
	 */
	private function migration_document( array $row ): array {
		if ( ! hash_equals( (string) $row['teacher_document_hash'], hash( 'sha256', (string) $row['teacher_document_json'] ) ) ) {
			throw new Project_Failure( 503, '활동지 원본 무결성을 확인할 수 없습니다.' );
		}
		$document = json_decode( $row['teacher_document_json'], true, 128, JSON_THROW_ON_ERROR );
		if ( ! is_array( $document ) || ! is_array( $document['settings'] ?? null ) ) {
			throw new Project_Failure( 503, '활동지 원본 형식을 확인할 수 없습니다.' );
		}
		return $document;
	}

	/**
	 * Copy recorded criteria only; never substitute today's shared rubric.
	 *
	 * @param array $document Source document.
	 * @param int   $revision Source revision primary key.
	 * @return array
	 * @throws Project_Failure Invalid recorded rubric.
	 */
	private function migration_configuration( array $document, int $revision ): array {
		$config = $this->profile->empty();
		foreach ( $document['settings']['learning_goals'] ?? array() as $goal ) {
			$config['learning_goals'][] = array(
				'goal_id' => $goal['goal_id'],
				'text'    => $goal['text'],
			);
		}
		$standards = $this->rows( $this->sql( 'SELECT standard_public_id, standard_code_snapshot, standard_label_snapshot FROM oj_ai_worksheet_achievement_links WHERE worksheet_revision_id = %d UNION SELECT l.standard_public_id, l.standard_code_snapshot, l.standard_label_snapshot FROM oj_ai_question_achievement_links l JOIN oj_ai_worksheet_revision_questions q ON q.id = l.question_id WHERE q.worksheet_revision_id = %d', $revision, $revision ) );
		$seen      = array();
		foreach ( $standards as $standard ) {
			if ( isset( $seen[ $standard['standard_public_id'] ] ) ) {
				continue;
			}
			$seen[ $standard['standard_public_id'] ] = true;
			$config['achievement_standards'][]       = array(
				'standard_id' => $standard['standard_public_id'],
				'code'        => $standard['standard_code_snapshot'],
				'text'        => $standard['standard_label_snapshot'],
			);
		}
		$pin = $document['settings']['preparation'] ?? array();
		if ( isset( $pin['rubric_revision_id'] ) ) {
			$row = $this->one( $this->sql( 'SELECT * FROM oj_ai_rubric_revisions WHERE public_id = %s AND subject_type = %s', $pin['rubric_revision_id'], 'student_submission' ) );
			if ( null === $row ) {
				throw new Project_Failure( 503, '활동지에 고정된 평가 기준을 찾을 수 없습니다.' );
			}
			$rubric = ( new \OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile( $this->canonical ) )->project_row( 'rubric', $row );
			if ( ! hash_equals( (string) ( $pin['rubric_content_hash'] ?? '' ), $rubric['content_hash'] ) ) {
				throw new Project_Failure( 503, '활동지의 평가 기준 무결성을 확인할 수 없습니다.' );
			}
			$config['criteria']     = $rubric['value']['criteria'];
			$config['total_points'] = $rubric['total_points'];
		}
		return $this->profile->normalize( $config, false );
	}

	/**
	 * For publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function for_publication( string $publication_id, string $revision_id ): ?array {
		$this->id( $publication_id );
		$this->id( $revision_id );
		$row = $this->one( $this->sql( 'SELECT p.id AS project_pk, p.public_id AS project_public_id, p.title, p.owner_user_id, p.state AS project_state, p.lock_version AS root_lock_version, p.current_revision_public_id, r.* FROM oj_ai_assessment_project_worksheets m JOIN oj_ai_assessment_projects p ON p.id = m.project_id JOIN oj_ai_assessment_project_revisions r ON r.id = m.project_revision_id AND r.project_id = p.id JOIN oj_ai_worksheet_publications pub ON pub.worksheet_id = m.worksheet_id WHERE pub.public_id = %s AND r.public_id = %s', $publication_id, $revision_id ) );
		return null === $row ? null : $this->project( $row );
	}

	/**
	 * Explicit draft updates may adopt only this assessment's latest confirmed revision.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int        $actor Actor.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_draft_save( string $worksheet, ?array $pin, int $actor ): ?array {
		$current = $this->for_worksheet( $worksheet, null, $actor, true );
		$bound   = $this->worksheet_has_project_pin( $worksheet );
		if ( null === $pin && ! $bound ) {
			return null;
		}
		if ( null === $current || null === $pin ) {
			throw new Project_Failure( 409, '활동지의 평가 연결을 변경할 수 없습니다.' );
		}
		$pin = Project_Configuration::context( $pin );
		if ( $bound && 'confirmed' === $current['state'] && $this->canonical->encode( $pin ) === $this->canonical->encode( Project_Configuration::pin( $current ) ) ) {
			return $current;
		}
		if ( $pin['project_id'] !== $current['project_id'] ) {
			throw new Project_Failure( 409, '다른 평가의 기준을 연결할 수 없습니다.' );
		}
		$latest = $this->read( $actor, $current['project_id'] );
		if ( 'confirmed' !== $latest['state'] || $this->canonical->encode( $pin ) !== $this->canonical->encode( Project_Configuration::pin( $latest ) ) ) {
			throw new Project_Failure( 409, '최신 평가 기준을 확정한 뒤 다시 적용해 주세요.' );
		}
		$draft = $this->one( $this->sql( 'SELECT w.id FROM oj_ai_worksheets w WHERE w.public_id = %s AND w.owner_user_id = %d AND w.state = %s AND NOT EXISTS (SELECT 1 FROM oj_ai_worksheet_publications pub WHERE pub.worksheet_id = w.id)', $worksheet, $actor, 'draft' ) );
		if ( null === $draft ) {
			throw new Project_Failure( 409, '이미 발행한 활동지는 기존 활동지 복사로 최신 기준에 연결해 주세요.' );
		}
		return $latest;
	}

	/**
	 * Called with the worksheet root locked, inside its revision-save transaction.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int        $actor Actor.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function sync_draft_pin( string $worksheet, ?array $pin, int $actor ): void {
		$this->actor( $actor );
		$this->id( $worksheet );
		$row     = $this->one( $this->sql( 'SELECT m.id, m.project_revision_id, m.lock_version, p.id AS project_pk FROM oj_ai_assessment_project_worksheets m JOIN oj_ai_assessment_projects p ON p.id = m.project_id JOIN oj_ai_worksheets w ON w.id = m.worksheet_id WHERE w.public_id = %s AND w.owner_user_id = %d AND p.owner_user_id = %d FOR UPDATE', $worksheet, $actor, $actor ) );
		$project = $this->for_draft_save( $worksheet, $pin, $actor );
		if ( null === $project ) {
			return;
		}
		$revision = $this->one( $this->sql( 'SELECT id FROM oj_ai_assessment_project_revisions WHERE project_id = %d AND public_id = %s', (int) $row['project_pk'], $project['revision_id'] ) );
		if ( null === $revision ) {
			throw new Project_Failure( 503, '평가 기준 버전을 확인할 수 없습니다.' );
		}
		if ( (int) $row['project_revision_id'] === (int) $revision['id'] ) {
			return;
		}
		if ( 1 !== $this->db()->update(
			'oj_ai_assessment_project_worksheets',
			array(
				'project_revision_id' => (int) $revision['id'],
				'lock_version'        => (int) $row['lock_version'] + 1,
				'updated_by_user_id'  => $actor,
				'updated_at_utc'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'                  => (int) $row['id'],
				'project_revision_id' => (int) $row['project_revision_id'],
				'lock_version'        => (int) $row['lock_version'],
			)
		) ) {
			throw new Project_Failure( 409, '활동지 기준 적용이 충돌했습니다. 최신 상태를 확인해 주세요.' );
		}
	}

	/**
	 * Project WordPress project repository.
	 *
	 * @param array $row Row.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function project( array $row ): array {
		if ( Project_Configuration::SCHEMA !== $row['configuration_schema'] || 1 !== (int) $row['configuration_version'] || ! hash_equals( $row['content_hash'], hash( 'sha256', $row['configuration_json'] ) ) || ! hash_equals( $row['content_hash'], $row['configuration_hash'] ) ) {
			throw new Project_Failure( 503, '평가 기준의 저장 무결성을 확인할 수 없습니다.' );
		}
		$config = $this->profile->normalize( json_decode( $row['configuration_json'], true, 128, JSON_THROW_ON_ERROR ), 'confirmed' === $row['state'] );
		if ( ! hash_equals( $row['content_hash'], hash( 'sha256', $this->canonical->encode( $config ) ) ) ) {
			throw new Project_Failure( 503, '평가 기준 형식을 확인할 수 없습니다.' );
		}
		$project                        = array(
			'project_id'          => $row['project_public_id'],
			'title'               => $row['title'],
			'revision_id'         => $row['public_id'],
			'current_revision_id' => $row['current_revision_public_id'],
			'revision_no'         => (int) $row['revision_no'],
			'state'               => $row['state'],
			'archived'            => 'archived' === $row['project_state'],
			'lock_version'        => (int) $row['root_lock_version'],
			'content_hash'        => $row['content_hash'],
			'configuration'       => $config,
		);
		$project['rubric_content_hash'] = Project_Configuration::rubric( $project )['content_hash'];
		return $project;
	}
	/**
	 * Insert revision.
	 *
	 * @param int    $project Project.
	 * @param string $revision Revision.
	 * @param int    $number Number.
	 * @param string $state State.
	 * @param array  $configuration Configuration.
	 * @param int    $actor Actor.
	 * @param string $now Clock value supplied for deterministic scheduling.
	 * @param string $command Command.
	 * @param string $digest Digest.
	 * @return void
	 */
	private function insert_revision( int $project, string $revision, int $number, string $state, array $configuration, int $actor, string $now, string $command, string $digest ): void {
		$json = $this->canonical->encode( $configuration );
		$hash = hash( 'sha256', $json );
		$this->insert(
			'oj_ai_assessment_project_revisions',
			array(
				'public_id'             => $revision,
				'project_id'            => $project,
				'revision_no'           => $number,
				'state'                 => $state,
				'content_hash'          => $hash,
				'configuration_schema'  => Project_Configuration::SCHEMA,
				'configuration_version' => 1,
				'configuration_json'    => $json,
				'configuration_hash'    => $hash,
				'created_by_user_id'    => $actor,
				'created_at_utc'        => $now,
				'command_key'           => $command,
				'request_digest'        => $digest,
			)
		);
	}
	/**
	 * Mutable.
	 *
	 * @param int    $actor Actor.
	 * @param string $now Clock value supplied for deterministic scheduling.
	 * @return array
	 */
	private function mutable( int $actor, string $now ): array {
		return array(
			'lock_version'       => 0,
			'created_by_user_id' => $actor,
			'updated_by_user_id' => $actor,
			'created_at_utc'     => $now,
			'updated_at_utc'     => $now,
		);
	}
	/**
	 * Same.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function same( string $a, string $b ): void {
		if ( ! hash_equals( $a, $b ) ) {
			throw new Project_Failure( 409, '이미 처리된 요청과 내용이 다릅니다. 새 요청으로 다시 시도해 주세요.' );
		}
	}
	/**
	 * Id.
	 *
	 * @param mixed $id Id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function id( mixed $id ): void {
		if ( ! is_string( $id ) || ! Public_Id::is_valid( $id ) ) {
			throw new Project_Input_Failure( '평가 ID가 올바르지 않습니다.' );
		}
	}
	/**
	 * Hash.
	 *
	 * @param string $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $hash ): void {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			throw new Project_Input_Failure( '요청 식별값이 올바르지 않습니다.' );
		}
	}
	/**
	 * Actor.
	 *
	 * @param int $actor Actor.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function actor( int $actor ): void {
		if ( $actor < 1 ) {
			throw new Project_Failure( 401, '로그인이 필요합니다.' );
		}
	}
	/**
	 * Db.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		if ( null !== $this->database ) {
			return $this->database;
		} global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			throw new Project_Failure( 503, '평가 저장소에 연결할 수 없습니다.' );
		} return $wpdb;
	}
	/**
	 * Sql.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	private function sql( string $sql, mixed ...$args ): string {
		return (string) $this->db()->prepare( $sql, ...$args );
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query( string $sql ): void {
		if ( false === $this->db()->query( $sql ) ) {
			throw new Project_Failure( 503, '평가 저장소를 처리하지 못했습니다.' );
		}
	}
	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$rows = $this->db()->get_results( $sql, 'ARRAY_A' );
		if ( ! is_array( $rows ) || '' !== (string) ( $this->db()->last_error ?? '' ) ) {
			throw new Project_Failure( 503, '평가를 불러오지 못했습니다. DB 업데이트 상태를 확인해 주세요.' );
		} return $rows;
	}
	/**
	 * Read current database state, which may change between a failed insert and replay.
	 *
	 * @param string $sql Prepared query.
	 * @return array<string,mixed>|null
	 * @phpstan-impure
	 */
	private function one( string $sql ): ?array {
		$rows = $this->rows( $sql );
		return $rows[0] ?? null;
	}
	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $record Record.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert( string $table, array $record ): int {
		if ( false === $this->db()->insert( $table, $record ) || (int) $this->db()->insert_id < 1 ) {
			throw new Project_Failure( 503, '평가 저장에 실패했습니다.' );
		} return (int) $this->db()->insert_id;
	}
}
