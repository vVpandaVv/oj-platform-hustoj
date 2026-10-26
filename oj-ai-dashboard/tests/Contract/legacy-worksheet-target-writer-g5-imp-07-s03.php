<?php
/**
 * G5-IMP-07-S03 worksheet aggregate target writer and batch transaction contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Plate_Document_Service;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s03wa assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03wa_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s03wa same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03wa_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s03wa storage failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03wa_storage_failure( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s03wa_same( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no storage failure was raised.' );
}

/**
 * G5 s03wa source failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03wa_source_failure( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Target_Transformation_Failure $failure ) {
		g5_s03wa_same( Legacy_Target_Transformation_Failure::SOURCE_INVALID, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no source failure was raised.' );
}

/**
 * G5 s03wa id.
 *
 * @return string
 */
function g5_s03wa_id(): string {
	static $ids = null;
	$ids      ??= new Public_Id_Generator();
	return $ids->create()->value();
}

/**
 * G5-IMP-07-S03 worksheet aggregate target writer and batch transaction contract.
 */
final class G5_S03WA_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Fail table.
	 *
	 * @var string|null
	 */
	public ?string $fail_table = null;
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_import_runs'                  => array(),
		'oj_ai_import_items'                 => array(),
		'oj_ai_audit_events'                 => array(),
		'oj_ai_outbox_events'                => array(),
		'oj_ai_curriculums'                  => array(),
		'oj_ai_achievement_standards'        => array(),
		'oj_ai_worksheets'                   => array(),
		'oj_ai_worksheet_revisions'          => array(),
		'oj_ai_worksheet_revision_questions' => array(),
		'oj_ai_worksheet_achievement_links'  => array(),
		'oj_ai_question_achievement_links'   => array(),
	);
	/**
	 * Backup.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $backup = null;

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->queries[] = $sql;
		return 'G5S03WA:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, string $output ): ?array {
		list( $sql, $values ) = $this->decode( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_import_runs WHERE public_id = %s' ) ) {
			return $this->find( 'oj_ai_import_runs', 'public_id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_import_items WHERE import_run_id = %d' ) ) {
			foreach ( $this->tables['oj_ai_import_items'] as $row ) {
				if (
					(string) ( $row['import_run_id'] ?? '' ) === (string) ( $values[0] ?? '' )
					&& (string) ( $row['source_type'] ?? '' ) === (string) ( $values[1] ?? '' )
					&& (string) ( $row['source_key'] ?? '' ) === (string) ( $values[2] ?? '' )
				) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_curriculums WHERE public_id = %s' ) ) {
			return $this->find( 'oj_ai_curriculums', 'public_id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_achievement_standards standard INNER JOIN oj_ai_curriculums' ) ) {
			$standard = $this->find( 'oj_ai_achievement_standards', 'public_id', $values[0] ?? null );
			if ( null === $standard ) {
				return null;
			}
			$curriculum = $this->find( 'oj_ai_curriculums', 'id', $standard['curriculum_id'] ?? null );
			if ( null === $curriculum ) {
				return null;
			}
			$standard['curriculum_public_id'] = $curriculum['public_id'];
			$standard['source_version']       = $curriculum['source_version'];
			return $standard;
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheets WHERE public_id = %s' ) ) {
			return $this->find( 'oj_ai_worksheets', 'public_id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_revisions WHERE public_id = %s' ) ) {
			return $this->find( 'oj_ai_worksheet_revisions', 'public_id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_revision_questions WHERE public_id = %s' ) ) {
			return $this->find( 'oj_ai_worksheet_revision_questions', 'public_id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_achievement_links WHERE worksheet_revision_id = %d' ) ) {
			foreach ( $this->tables['oj_ai_worksheet_achievement_links'] as $row ) {
				if (
					(string) ( $row['worksheet_revision_id'] ?? '' ) === (string) ( $values[0] ?? '' )
					&& (string) ( $row['standard_public_id'] ?? '' ) === (string) ( $values[1] ?? '' )
					&& (string) ( $row['alignment_type'] ?? '' ) === (string) ( $values[2] ?? '' )
				) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_question_achievement_links WHERE question_id = %d' ) ) {
			foreach ( $this->tables['oj_ai_question_achievement_links'] as $row ) {
				if (
					(string) ( $row['question_id'] ?? '' ) === (string) ( $values[0] ?? '' )
					&& (string) ( $row['standard_public_id'] ?? '' ) === (string) ( $values[1] ?? '' )
					&& (string) ( $row['alignment_type'] ?? '' ) === (string) ( $values[2] ?? '' )
				) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected worksheet target row query: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			throw new RuntimeException( 'Unexpected worksheet target scalar query: ' . $sql );
		}
		$last = end( $this->tables['oj_ai_audit_events'] );
		return is_array( $last ) ? (string) $last['event_hash'] : null;
	}

	/**
	 * Update g5 s03 wa database.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where ): int|false {
		if ( $this->fail_table === $table || ! isset( $this->tables[ $table ] ) ) {
			$this->last_error = 'forced update failure';
			return false;
		}
		foreach ( $this->tables[ $table ] as $index => $row ) {
			$matches = true;
			foreach ( $where as $key => $value ) {
				if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				$this->tables[ $table ][ $index ] = array_merge( $row, $data );
				return 1;
			}
		}
		return 0;
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( $this->fail_table === $table || ! array_key_exists( $table, $this->tables ) ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		$this->insert_id          = count( $this->tables[ $table ] ) + 1;
		$data['id']             ??= $this->insert_id;
		$this->tables[ $table ][] = $data;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ): int|false {
		if ( 'START TRANSACTION' === $sql ) {
			$this->backup = unserialize( serialize( $this->tables ), array( 'allowed_classes' => false ) );
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->backup = null;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->backup ) ) {
				$this->tables = $this->backup;
			}
			$this->backup     = null;
			$this->last_error = '';
			return 1;
		}
		throw new RuntimeException( 'Unexpected worksheet target transaction query: ' . $sql );
	}

	/**
	 * Counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		return array_map( 'count', $this->tables );
	}

	/**
	 * Decode g5 s03 wa database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5S03WA:' ) ) {
			throw new RuntimeException( 'Worksheet target query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 8 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || ! is_string( $value[0] ?? null ) || ! is_array( $value[1] ?? null ) ) {
			throw new RuntimeException( 'Worksheet target prepared query is invalid.' );
		}
		return array( $value[0], $value[1] );
	}

	/**
	 * Find g5 s03 wa database.
	 *
	 * @param string $table Table.
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return array<string,mixed>|null
	 */
	private function find( string $table, string $field, mixed $value ): ?array {
		foreach ( $this->tables[ $table ] as $row ) {
			if ( (string) ( $row[ $field ] ?? '' ) === (string) $value ) {
				return $row;
			}
		}
		return null;
	}
}

/**
 * G5 s03wa item.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
 * @param int                         $item_no Item no.
 * @param string                      $source_type Source type.
 * @param int                         $source_id Source id.
 * @param array<string,mixed>         $overrides Overrides.
 * @return Legacy_Source_Item
 */
function g5_s03wa_item(
	Legacy_Source_Profile_0_1_2 $profile,
	Canonical_Json $canonical,
	int $item_no,
	string $source_type,
	int $source_id,
	array $overrides
): Legacy_Source_Item {
	$record                   = array_fill_keys( $profile->columns( $source_type ), null );
	$record['id']             = (string) $source_id;
	$record['tenant_context'] = 'legacy-site-7';
	$record['created_by']     = '7';
	$record['updated_by']     = '7';
	$record['created_at']     = '2025-01-01 00:00:00';
	$record['updated_at']     = '2025-01-01 00:00:00';
	$record['deleted_at']     = null;
	$record['source_type']    = '';
	$record['source_key']     = '';
	$record['source_id']      = '';
	foreach ( $overrides as $key => $value ) {
		$record[ $key ] = $value;
	}
	$checksum = hash(
		'sha256',
		$canonical->encode(
			array(
				'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
				'source_key'  => (string) $source_id,
				'source_type' => $source_type,
				'record'      => $record,
			)
		)
	);
	return new Legacy_Source_Item( $item_no, $source_type, $source_id, $checksum, $record );
}

/**
 * G5 s03wa sources.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
 * @return array{0:Legacy_Source_Item,1:Legacy_Source_Item}
 */
function g5_s03wa_sources( Legacy_Source_Profile_0_1_2 $profile, Canonical_Json $canonical ): array {
	$nodes             = array(
		array(
			'type'     => 'h1',
			'children' => array( array( 'text' => 'Fractions Check' ) ),
		),
		array(
			'type'            => 'worksheet_response',
			'question_id'     => 'choice_1',
			'response_type'   => 'radio',
			'prompt'          => 'Which fraction is larger?',
			'required'        => true,
			'points'          => '2.00',
			'grading_enabled' => true,
			'correct_answer'  => 'one_half',
			'options'         => array(
				array(
					'value' => 'one_half',
					'label' => '1/2',
				),
				array(
					'value' => 'one_third',
					'label' => '1/3',
				),
			),
			'children'        => array( array( 'text' => '' ) ),
		),
	);
	$legacy_projection = ( new Plate_Document_Service() )->build_schemas(
		$nodes,
		array(
			'title'            => 'Fractions Check',
			'rubric_mode'      => 'worksheet',
			'worksheet_rubric' => array(),
			'curriculum_links' => array(),
		)
	);
	$legacy_question   = $legacy_projection['teacher_schema']['questions'][0];
	$template          = g5_s03wa_item(
		$profile,
		$canonical,
		1,
		'worksheet_templates',
		10,
		array(
			'title'                 => 'Fractions Check',
			'status'                => 'active',
			'plate_value_json'      => json_encode( $nodes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
			'form_schema_json'      => json_encode( $legacy_projection, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
			'rubric_mode'           => 'worksheet',
			'worksheet_rubric_json' => '{}',
			'curriculum_links_json' => '[]',
			'settings_json'         => '{}',
		)
	);
	$question          = g5_s03wa_item(
		$profile,
		$canonical,
		2,
		'worksheet_questions',
		20,
		array(
			'template_id'          => '10',
			'question_id'          => $legacy_question['question_id'],
			'title'                => $legacy_question['title'],
			'sort_order'           => (string) $legacy_question['sort_order'],
			'points'               => number_format( (float) $legacy_question['points'], 2, '.', '' ),
			'grading_enabled'      => $legacy_question['grading_enabled'] ? '1' : '0',
			'question_schema_json' => json_encode( $legacy_question, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
		)
	);
	return array( $template, $question );
}

/**
 * G5 s03wa linked sources.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
 * @return array<int,Legacy_Source_Item>
 */
function g5_s03wa_linked_sources( Legacy_Source_Profile_0_1_2 $profile, Canonical_Json $canonical ): array {
	$worksheet_links   = array( array( 'id' => 200 ) );
	$question_links    = array( array( 'standard_id' => '200' ) );
	$nodes             = array(
		array(
			'type'     => 'h1',
			'children' => array( array( 'text' => 'Linked Fractions Check' ) ),
		),
		array(
			'type'             => 'worksheet_response',
			'question_id'      => 'choice_1',
			'response_type'    => 'radio',
			'prompt'           => 'Which fraction is larger?',
			'required'         => true,
			'points'           => '2.00',
			'grading_enabled'  => true,
			'correct_answer'   => 'one_half',
			'curriculum_links' => $question_links,
			'options'          => array(
				array(
					'value' => 'one_half',
					'label' => '1/2',
				),
				array(
					'value' => 'one_third',
					'label' => '1/3',
				),
			),
			'children'         => array( array( 'text' => '' ) ),
		),
	);
	$legacy_projection = ( new Plate_Document_Service() )->build_schemas(
		$nodes,
		array(
			'title'            => 'Linked Fractions Check',
			'rubric_mode'      => 'worksheet',
			'worksheet_rubric' => array(),
			'curriculum_links' => $worksheet_links,
		)
	);
	$legacy_question   = $legacy_projection['teacher_schema']['questions'][0];

	return array(
		g5_s03wa_item(
			$profile,
			$canonical,
			1,
			'curriculums',
			100,
			array(
				'curriculum_key' => 'national-2022',
				'title'          => 'National Curriculum 2022',
				'description'    => 'Linked worksheet curriculum.',
				'version'        => '2022.1',
				'active'         => '1',
			)
		),
		g5_s03wa_item(
			$profile,
			$canonical,
			2,
			'achievement_standards',
			200,
			array(
				'curriculum_id' => '100',
				'standard_key'  => 'MATH-FRACTION-01',
				'title'         => 'Compare unit fractions',
				'description'   => 'Compare fractions with the same numerator.',
				'metadata_json' => '{"grade":"3"}',
				'active'        => '1',
			)
		),
		g5_s03wa_item(
			$profile,
			$canonical,
			3,
			'worksheet_templates',
			300,
			array(
				'title'                 => 'Linked Fractions Check',
				'status'                => 'active',
				'plate_value_json'      => json_encode( $nodes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
				'form_schema_json'      => json_encode( $legacy_projection, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
				'rubric_mode'           => 'worksheet',
				'worksheet_rubric_json' => '{}',
				'curriculum_links_json' => json_encode( $worksheet_links, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
				'settings_json'         => '{}',
			)
		),
		g5_s03wa_item(
			$profile,
			$canonical,
			4,
			'worksheet_questions',
			400,
			array(
				'template_id'          => '300',
				'question_id'          => $legacy_question['question_id'],
				'title'                => $legacy_question['title'],
				'sort_order'           => (string) $legacy_question['sort_order'],
				'points'               => number_format( (float) $legacy_question['points'], 2, '.', '' ),
				'grading_enabled'      => $legacy_question['grading_enabled'] ? '1' : '0',
				'question_schema_json' => json_encode( $legacy_question, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
			)
		),
		g5_s03wa_item(
			$profile,
			$canonical,
			5,
			'worksheet_achievement_links',
			500,
			array(
				'template_id' => '300',
				'standard_id' => '200',
			)
		),
		g5_s03wa_item(
			$profile,
			$canonical,
			6,
			'question_achievement_links',
			600,
			array(
				'template_id' => '300',
				'question_id' => $legacy_question['question_id'],
				'standard_id' => '200',
			)
		),
	);
}

/**
 * G5 s03wa store template receipt.
 *
 * @param G5_S03WA_Database          $database Database.
 * @param int                        $run_id Run id.
 * @param Legacy_Source_Item         $source Source.
 * @param Legacy_Target_Write_Result $target Target.
 * @param Canonical_Json             $canonical Canonical JSON encoder used for stable digests.
 * @return void
 */
function g5_s03wa_store_template_receipt(
	G5_S03WA_Database $database,
	int $run_id,
	Legacy_Source_Item $source,
	Legacy_Target_Write_Result $target,
	Canonical_Json $canonical
): void {
	$payload = array(
		'profile'    => 'oj_ai.legacy_item_payload@1.0.0',
		'source'     => array(
			'type'     => $source->source_type(),
			'key'      => $source->source_key(),
			'checksum' => $source->source_checksum(),
		),
		'state'      => 'success',
		'target'     => array(
			'type'         => $target->target_type(),
			'public_id'    => $target->target_public_id(),
			'content_hash' => $target->content_hash(),
			'metadata'     => $target->metadata(),
		),
		'error_code' => null,
	);
	$json    = $canonical->encode( $payload );
	$database->insert(
		'oj_ai_import_items',
		array(
			'import_run_id'    => $run_id,
			'source_type'      => $source->source_type(),
			'source_key'       => $source->source_key(),
			'state'            => 'success',
			'target_type'      => $target->target_type(),
			'target_public_id' => $target->target_public_id(),
			'payload_schema'   => 'oj_ai.legacy_item_payload',
			'payload_version'  => 1,
			'payload_json'     => $json,
			'payload_hash'     => hash( 'sha256', $json ),
		)
	);
}

/**
 * G5 s03wa running row.
 *
 * @param int                         $database_id Database id.
 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param array|null                  $summaries Summaries.
 * @return array<string,mixed>
 */
function g5_s03wa_running_row( int $database_id, Canonical_Json $canonical, Legacy_Source_Profile_0_1_2 $profile, ?array $summaries = null ): array {
	$summaries     ??= array(
		array(
			'logical_table' => 'worksheet_templates',
			'source_table'  => 'wp_oj_ai_worksheet_templates',
			'disposition'   => 'migrate',
			'rows'          => 1,
		),
		array(
			'logical_table' => 'worksheet_questions',
			'source_table'  => 'wp_oj_ai_worksheet_questions',
			'disposition'   => 'migrate',
			'rows'          => 1,
		),
	);
	$item_total      = array_sum( array_map( static fn ( array $summary ): int => (int) $summary['rows'], $summaries ) );
	$source          = new Legacy_Source_Snapshot(
		Legacy_Source_Profile_0_1_2::PROFILE,
		Legacy_Source_Profile_0_1_2::VERSION,
		hash( 'sha256', 'worksheet-batch-' . $database_id ),
		$summaries,
		0,
		0,
		array(
			'context' => 0,
			'user'    => 0,
		),
		array(),
		array()
	);
	$plan            = ( new Legacy_Migration_Plan( $canonical ) )->build( '7', $source );
	$preview_json    = $canonical->encode(
		array(
			'profile' => Approve_Legacy_Migration::PREVIEW_PROFILE,
			'state'   => 'approved',
			'plan'    => $plan,
		)
	);
	$checkpoint      = array(
		'profile'    => Execute_Legacy_Migration::CHECKPOINT_PROFILE,
		'phase'      => 'running',
		'batch_size' => Execute_Legacy_Migration::BATCH_SIZE,
		'source'     => array(
			'profile'     => $source->profile(),
			'fingerprint' => $source->source_fingerprint(),
		),
		'plan_hash'  => $plan['plan_hash'],
		'cursor'     => array(
			'table_index'     => 0,
			'after_source_id' => 0,
			'next_item_no'    => 1,
		),
		'totals'     => array(
			'success' => 0,
			'failure' => 0,
			'skipped' => 0,
		),
	);
	$checkpoint_json = $canonical->encode( $checkpoint );
	return array(
		'id'                 => $database_id,
		'public_id'          => g5_s03wa_id(),
		'lock_version'       => 1,
		'updated_by_user_id' => 77,
		'source_kind'        => 'oj_ai_dashboard_wpdb_prefixed',
		'source_version'     => Legacy_Source_Profile_0_1_2::VERSION,
		'source_fingerprint' => $source->source_fingerprint(),
		'state'              => 'running',
		'preview_schema'     => Approve_Legacy_Migration::PREVIEW_SCHEMA,
		'preview_version'    => Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION,
		'preview_json'       => $preview_json,
		'preview_hash'       => hash( 'sha256', $preview_json ),
		'checkpoint_schema'  => Execute_Legacy_Migration::CHECKPOINT_SCHEMA,
		'checkpoint_version' => Execute_Legacy_Migration::CHECKPOINT_VERSION,
		'checkpoint_json'    => $checkpoint_json,
		'checkpoint_hash'    => hash( 'sha256', $checkpoint_json ),
		'item_total'         => $item_total,
		'success_total'      => 0,
		'failure_total'      => 0,
		'skipped_total'      => 0,
		'error_code'         => null,
	);
}

$canonical                                 = new Canonical_Json();
$profile                                   = new Legacy_Source_Profile_0_1_2();
list( $template_source, $question_source ) = g5_s03wa_sources( $profile, $canonical );

$direct_database = new G5_S03WA_Database();
$direct_writer   = new WordPress_Legacy_Target_Writer( $canonical, $direct_database, null, $profile );
$worksheet       = $direct_writer->write( 41, $template_source, 77, '2026-08-25 15:00:00' );
g5_s03wa_same( 'worksheet', $worksheet->target_type(), 'Template source did not create a worksheet aggregate.' );
g5_s03wa_same( 1, count( $direct_database->tables['oj_ai_worksheets'] ), 'Worksheet root was not inserted exactly once.' );
g5_s03wa_same( 1, count( $direct_database->tables['oj_ai_worksheet_revisions'] ), 'Worksheet revision was not inserted exactly once.' );
g5_s03wa_same( 1, count( $direct_database->tables['oj_ai_worksheet_revision_questions'] ), 'Projected question was not inserted exactly once.' );
$root_row     = $direct_database->tables['oj_ai_worksheets'][0];
$revision_row = $direct_database->tables['oj_ai_worksheet_revisions'][0];
$question_row = $direct_database->tables['oj_ai_worksheet_revision_questions'][0];
g5_s03wa_same( 'draft', $root_row['state'], 'Legacy active template was incorrectly auto-published.' );
g5_s03wa_same( $root_row['id'], $revision_row['worksheet_id'], 'Revision did not reference the inserted worksheet root.' );
g5_s03wa_same( $revision_row['id'], $question_row['worksheet_revision_id'], 'Question did not reference the inserted revision.' );
g5_s03wa_same( hash( 'sha256', $revision_row['teacher_document_json'] ), $revision_row['teacher_document_hash'], 'Teacher document hash drifted.' );
g5_s03wa_assert( ! str_contains( $question_row['question_json'], 'correct_answer' ), 'Correct answer leaked into public question JSON.' );
g5_s03wa_assert( str_contains( (string) $question_row['private_metadata_json'], 'correct_answer' ), 'Correct answer was not preserved in private grading metadata.' );
$question_map = $worksheet->metadata()['question_public_ids'];
g5_s03wa_same( $question_row['public_id'], $question_map[ hash( 'sha256', 'q_choice_1' ) ], 'Hashed legacy question receipt did not point to the projected question.' );
$question_hashes = $worksheet->metadata()['question_source_hashes'];
g5_s03wa_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $question_hashes[ hash( 'sha256', 'q_choice_1' ) ] ), 'Template receipt lost the full legacy question fingerprint.' );

g5_s03wa_store_template_receipt( $direct_database, 41, $template_source, $worksheet, $canonical );
$question = $direct_writer->write( 41, $question_source, 77, '2026-08-25 15:00:01' );
g5_s03wa_same( 'worksheet_question', $question->target_type(), 'Question source did not become a verification receipt.' );
g5_s03wa_same( $question_row['public_id'], $question->target_public_id(), 'Question verification receipt drifted from the aggregate projection.' );
g5_s03wa_same( 1, count( $direct_database->tables['oj_ai_worksheet_revision_questions'] ), 'Question item inserted a duplicate target question.' );
g5_s03wa_assert( Public_Id::is_valid( $question->metadata()['worksheet_public_id'] ), 'Question receipt lost the worksheet public ID.' );

$drift_record                         = $question_source->record();
$drift_schema                         = json_decode( (string) $drift_record['question_schema_json'], true, 64, JSON_THROW_ON_ERROR );
$drift_schema['prompt']               = 'Tampered prompt that is absent from the template Plate document.';
$drift_record['question_schema_json'] = json_encode( $drift_schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
$drift_source                         = g5_s03wa_item( $profile, $canonical, 2, 'worksheet_questions', 20, $drift_record );
g5_s03wa_source_failure(
	static fn () => $direct_writer->write( 41, $drift_source, 77, '2026-08-25 15:00:02' ),
	'Question schema drift from the template Plate projection was accepted.'
);

$batch_database                                = new G5_S03WA_Database();
$batch_database->tables['oj_ai_import_runs'][] = g5_s03wa_running_row( 1, $canonical, $profile );
$batch_writer                                  = new WordPress_Legacy_Target_Writer( $canonical, $batch_database, null, $profile );
$batch_repository                              = new WordPress_Legacy_Migration_Batch_Repository( $batch_writer, $canonical, $batch_database );
$run_public_id                                 = (string) $batch_database->tables['oj_ai_import_runs'][0]['public_id'];
$running                                       = $batch_repository->running_run( $run_public_id );
$batch  = new Legacy_Source_Item_Batch( array( $template_source, $question_source ), count( $profile->tables() ), 0, 3, true );
$result = $batch_repository->commit_batch( $running, $batch, '2026-08-25 15:01:00' );
g5_s03wa_same( 'completed', $result->state(), 'Production worksheet batch did not complete.' );
g5_s03wa_same( 2, $result->success_total(), 'Template and question did not both receive success receipts.' );
g5_s03wa_same( 2, count( $batch_database->tables['oj_ai_import_items'] ), 'Production worksheet batch lost an item receipt.' );
g5_s03wa_same( 1, count( $batch_database->tables['oj_ai_worksheet_revision_questions'] ), 'Production worksheet batch duplicated a projected question.' );
foreach ( $batch_database->tables['oj_ai_import_items'] as $item_receipt ) {
	g5_s03wa_assert( ! str_contains( (string) $item_receipt['payload_json'], 'q_choice_1' ), 'Import receipt persisted a raw legacy question ID instead of a hash.' );
}

$rollback_database                                = new G5_S03WA_Database();
$rollback_database->tables['oj_ai_import_runs'][] = g5_s03wa_running_row( 2, $canonical, $profile );
$rollback_writer                                  = new WordPress_Legacy_Target_Writer( $canonical, $rollback_database, null, $profile );
$rollback_repository                              = new WordPress_Legacy_Migration_Batch_Repository( $rollback_writer, $canonical, $rollback_database );
$rollback_public_id                               = (string) $rollback_database->tables['oj_ai_import_runs'][0]['public_id'];
$rollback_run                                     = $rollback_repository->running_run( $rollback_public_id );
$before_counts                                    = $rollback_database->counts();
$before_run                                       = $rollback_database->tables['oj_ai_import_runs'][0];
$rollback_database->fail_table                    = 'oj_ai_worksheet_revision_questions';
g5_s03wa_storage_failure(
	static fn () => $rollback_repository->commit_batch( $rollback_run, $batch, '2026-08-25 15:02:00' ),
	'Question insert failure did not abort the production worksheet batch.'
);
$rollback_database->fail_table = null;
g5_s03wa_same( $before_counts, $rollback_database->counts(), 'Failed question insert left root, revision, item, or event rows behind.' );
g5_s03wa_same( $before_run, $rollback_database->tables['oj_ai_import_runs'][0], 'Failed question insert advanced the migration checkpoint.' );
$retry = $rollback_repository->commit_batch( $rollback_run, $batch, '2026-08-25 15:03:00' );
g5_s03wa_same( 'completed', $retry->state(), 'Worksheet aggregate retry after rollback did not complete.' );

$linked_sources                                 = g5_s03wa_linked_sources( $profile, $canonical );
$linked_summaries                               = array(
	array(
		'logical_table' => 'curriculums',
		'source_table'  => 'wp_oj_ai_curriculums',
		'disposition'   => 'migrate',
		'rows'          => 1,
	),
	array(
		'logical_table' => 'achievement_standards',
		'source_table'  => 'wp_oj_ai_achievement_standards',
		'disposition'   => 'migrate',
		'rows'          => 1,
	),
	array(
		'logical_table' => 'worksheet_templates',
		'source_table'  => 'wp_oj_ai_worksheet_templates',
		'disposition'   => 'migrate',
		'rows'          => 1,
	),
	array(
		'logical_table' => 'worksheet_questions',
		'source_table'  => 'wp_oj_ai_worksheet_questions',
		'disposition'   => 'migrate',
		'rows'          => 1,
	),
	array(
		'logical_table' => 'worksheet_achievement_links',
		'source_table'  => 'wp_oj_ai_worksheet_achievement_links',
		'disposition'   => 'migrate',
		'rows'          => 1,
	),
	array(
		'logical_table' => 'question_achievement_links',
		'source_table'  => 'wp_oj_ai_question_achievement_links',
		'disposition'   => 'migrate',
		'rows'          => 1,
	),
);
$linked_database                                = new G5_S03WA_Database();
$linked_database->tables['oj_ai_import_runs'][] = g5_s03wa_running_row( 3, $canonical, $profile, $linked_summaries );
$linked_writer                                  = new WordPress_Legacy_Target_Writer( $canonical, $linked_database, null, $profile );
$linked_repository                              = new WordPress_Legacy_Migration_Batch_Repository( $linked_writer, $canonical, $linked_database );
$linked_public_id                               = (string) $linked_database->tables['oj_ai_import_runs'][0]['public_id'];
$linked_run                                     = $linked_repository->running_run( $linked_public_id );
$linked_batch                                   = new Legacy_Source_Item_Batch( $linked_sources, count( $profile->tables() ), 0, 7, true );
$linked_result                                  = $linked_repository->commit_batch( $linked_run, $linked_batch, '2026-08-25 15:04:00' );
g5_s03wa_same( 'completed', $linked_result->state(), 'Achievement-linked worksheet batch did not complete.' );
g5_s03wa_same( 6, $linked_result->success_total(), 'Achievement-linked source items did not all receive success receipts.' );
g5_s03wa_same(
	array( 'curriculum', 'achievement_standard', 'worksheet', 'worksheet_question', 'worksheet_achievement_link', 'question_achievement_link' ),
	array_column( $linked_database->tables['oj_ai_import_items'], 'target_type' ),
	'Linked batch target receipt order drifted from dependency execution order.'
);
g5_s03wa_same( 1, count( $linked_database->tables['oj_ai_worksheet_achievement_links'] ), 'Worksheet link was not inserted exactly once by the aggregate.' );
g5_s03wa_same( 1, count( $linked_database->tables['oj_ai_question_achievement_links'] ), 'Question link was not inserted exactly once by the aggregate.' );
$linked_revision        = $linked_database->tables['oj_ai_worksheet_revisions'][0];
$linked_question        = $linked_database->tables['oj_ai_worksheet_revision_questions'][0];
$linked_worksheet_link  = $linked_database->tables['oj_ai_worksheet_achievement_links'][0];
$linked_question_link   = $linked_database->tables['oj_ai_question_achievement_links'][0];
$expected_revision_hash = hash(
	'sha256',
	$canonical->encode(
		array(
			'document_hash'                     => $linked_revision['document_hash'],
			'worksheet_achievement_link_hashes' => array( $linked_worksheet_link['content_hash'] ),
			'question_achievement_link_hashes'  => array( $linked_question_link['content_hash'] ),
		)
	)
);
g5_s03wa_same( $expected_revision_hash, $linked_revision['content_hash'], 'Linked revision content hash omitted immutable achievement links.' );
g5_s03wa_assert( ! hash_equals( $linked_revision['document_hash'], $linked_revision['content_hash'] ), 'Linked revision incorrectly reused the document-only hash.' );
g5_s03wa_same( $linked_revision['id'], $linked_worksheet_link['worksheet_revision_id'], 'Worksheet link did not reference the imported revision.' );
g5_s03wa_same( $linked_question['id'], $linked_question_link['question_id'], 'Question link did not reference the projected question.' );
g5_s03wa_same( 'primary', $linked_worksheet_link['alignment_type'], 'Legacy worksheet link did not use the explicit primary mapping.' );
g5_s03wa_same( 'primary', $linked_question_link['alignment_type'], 'Legacy question link did not use the explicit primary mapping.' );
g5_s03wa_same( 0, $linked_worksheet_link['position'], 'Worksheet link position did not preserve embedded order.' );
g5_s03wa_same( 0, $linked_question_link['position'], 'Question link position did not preserve embedded order.' );
$template_payload  = json_decode( (string) $linked_database->tables['oj_ai_import_items'][2]['payload_json'], true, 64, JSON_THROW_ON_ERROR );
$template_metadata = $template_payload['target']['metadata'];
g5_s03wa_same( $linked_revision['content_hash'], $template_metadata['revision_content_hash'], 'Template receipt lost the composite revision hash.' );
g5_s03wa_same( $linked_worksheet_link['content_hash'], $template_metadata['worksheet_link_hashes'][ hash( 'sha256', '200' ) ], 'Template receipt lost the worksheet link fingerprint.' );
g5_s03wa_same( $linked_question_link['content_hash'], $template_metadata['question_link_hashes'][ hash( 'sha256', 'q_choice_1|200' ) ], 'Template receipt lost the question link fingerprint.' );
foreach ( $linked_database->tables['oj_ai_import_items'] as $item_receipt ) {
	g5_s03wa_assert( ! str_contains( (string) $item_receipt['payload_json'], 'q_choice_1' ), 'Linked import receipt persisted a raw legacy question ID.' );
}

$linked_rollback_database                                = new G5_S03WA_Database();
$linked_rollback_database->tables['oj_ai_import_runs'][] = g5_s03wa_running_row( 4, $canonical, $profile, $linked_summaries );
$linked_rollback_writer                                  = new WordPress_Legacy_Target_Writer( $canonical, $linked_rollback_database, null, $profile );
$linked_rollback_repository                              = new WordPress_Legacy_Migration_Batch_Repository( $linked_rollback_writer, $canonical, $linked_rollback_database );
$linked_rollback_public_id                               = (string) $linked_rollback_database->tables['oj_ai_import_runs'][0]['public_id'];
$linked_rollback_run                                     = $linked_rollback_repository->running_run( $linked_rollback_public_id );
$linked_before_counts                                    = $linked_rollback_database->counts();
$linked_before_run                                       = $linked_rollback_database->tables['oj_ai_import_runs'][0];
$linked_rollback_database->fail_table                    = 'oj_ai_question_achievement_links';
g5_s03wa_storage_failure(
	static fn () => $linked_rollback_repository->commit_batch( $linked_rollback_run, $linked_batch, '2026-08-25 15:05:00' ),
	'Question achievement link failure did not abort the complete dependency batch.'
);
$linked_rollback_database->fail_table = null;
g5_s03wa_same( $linked_before_counts, $linked_rollback_database->counts(), 'Failed link insert left a curriculum, standard, worksheet, item, or event row behind.' );
g5_s03wa_same( $linked_before_run, $linked_rollback_database->tables['oj_ai_import_runs'][0], 'Failed link insert advanced the migration checkpoint.' );
$linked_retry = $linked_rollback_repository->commit_batch( $linked_rollback_run, $linked_batch, '2026-08-25 15:06:00' );
g5_s03wa_same( 'completed', $linked_retry->state(), 'Achievement-linked aggregate retry after rollback did not complete.' );

foreach ( array_merge( $direct_database->queries, $batch_database->queries, $rollback_database->queries, $linked_database->queries, $linked_rollback_database->queries ) as $sql ) {
	foreach ( array( 'wp_oj_ai_', '$wpdb->prefix', '$wpdb->base_prefix', 'CodingPanda\\OjPlatform\\Kernel\\', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE' ) as $forbidden ) {
		g5_s03wa_assert( ! str_contains( $sql, $forbidden ), 'Worksheet migration target SQL crossed a frozen boundary: ' . $forbidden );
	}
}
$writer_source = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Target_Writer.php' );
foreach ( array( "'oj_ai_worksheets'", "'oj_ai_worksheet_revisions'", "'oj_ai_worksheet_revision_questions'", "'oj_ai_worksheet_achievement_links'", "'oj_ai_question_achievement_links'" ) as $literal_table ) {
	g5_s03wa_assert( str_contains( (string) $writer_source, $literal_table ), 'Worksheet target writer lost a literal table: ' . $literal_table );
}

fwrite( STDOUT, "G5-IMP-07-S03 worksheet target writer passed: Plate aggregate writes, immutable achievement links, verification-only relation items, full batch rollback, and retry are frozen.\n" );
