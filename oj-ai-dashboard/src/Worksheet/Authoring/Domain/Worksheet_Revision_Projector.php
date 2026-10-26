<?php
/**
 * Worksheet revision question, achievement, and asset projector.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds deterministic persistence records from one canonical teacher document.
 */
final class Worksheet_Revision_Projector {
	public const QUESTION_SCHEMA          = 'oj_ai.worksheet_question';
	public const QUESTION_VERSION         = 1;
	public const PRIVATE_METADATA_SCHEMA  = 'oj_ai.worksheet_question_private_metadata';
	public const PRIVATE_METADATA_VERSION = 1;
	public const PROJECTION_SCHEMA        = 'oj_ai.worksheet_revision_projection';
	public const PROJECTION_VERSION       = '1.0.0';
	public const ALIGNMENT_TYPES          = array( 'primary', 'supporting' );

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Ids.
	 *
	 * @var Public_Id_Generator
	 */
	private Public_Id_Generator $ids;

	/**
	 * Initialize worksheet revision projector dependencies and state.
	 *
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @param Public_Id_Generator $ids Ids.
	 */
	public function __construct( Canonical_Json $canonical, Public_Id_Generator $ids ) {
		$this->canonical = $canonical;
		$this->ids       = $ids;
	}

	/**
	 * Required standard public ids.
	 *
	 * @param array<array-key,mixed> $achievement_links Achievement links.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function required_standard_public_ids( array $achievement_links ): array {
		if ( ! array_is_list( $achievement_links ) ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.achievement_links' );
		}
		$ids = array();
		foreach ( $achievement_links as $index => $link ) {
			$path = '$.achievement_links[' . $index . ']';
			if ( ! is_array( $link ) || array() === $link || array_is_list( $link ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			$this->assert_exact_keys(
				$link,
				array( 'scope', 'response_id', 'standard_public_id', 'alignment_type' ),
				$path
			);
			$standard_id         = $this->public_id(
				$link['standard_public_id'] ?? null,
				$path . '.standard_public_id'
			);
			$ids[ $standard_id ] = true;
		}
		$result = array_keys( $ids );
		sort( $result, SORT_STRING );

		return $result;
	}

	/**
	 * Project worksheet revision projector.
	 *
	 * @param Canonical_Worksheet_Document   $source Source.
	 * @param array<int,array<string,mixed>> $achievement_links C02 link DTO list.
	 * @param array<int,array<string,mixed>> $standard_snapshots Authoritative repository snapshots.
	 * @return Worksheet_Revision_Projection
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project(
		Canonical_Worksheet_Document $source,
		array $achievement_links,
		array $standard_snapshots
	): Worksheet_Revision_Projection {
		$document                                       = $this->assert_source( $source );
		list( $questions, $questions_by_response )      = $this->project_questions( $document['nodes'] );
		list( $link_requests, $requested_standard_ids ) = $this->normalize_link_requests(
			$achievement_links,
			$questions_by_response
		);
		$snapshots                                      = $this->normalize_standard_snapshots( $standard_snapshots, $requested_standard_ids );
		list( $worksheet_links, $question_links )       = $this->project_achievement_links(
			$link_requests,
			$snapshots,
			$questions_by_response
		);
		$asset_bindings                                 = $this->project_asset_bindings( $document['nodes'] );

		$source_statistics = $source->statistics();
		if (
			( $source_statistics['response_count'] ?? null ) !== count( $questions )
			|| ( $source_statistics['body_asset_count'] ?? null ) !== count( $asset_bindings )
		) {
			throw Worksheet_Document_Validation_Failure::schema_unsupported( '$.statistics' );
		}

		$statistics      = array(
			'question_count'                   => count( $questions ),
			'worksheet_achievement_link_count' => count( $worksheet_links ),
			'question_achievement_link_count'  => count( $question_links ),
			'asset_binding_count'              => count( $asset_bindings ),
			'asset_reference_count'            => array_sum(
				array_map(
					static fn ( array $binding ): int => count( $binding['references'] ),
					$asset_bindings
				)
			),
		);
		$manifest        = array(
			'schema_id'                         => self::PROJECTION_SCHEMA,
			'schema_version'                    => self::PROJECTION_VERSION,
			'source_document_hash'              => $source->document_hash(),
			'question_content_hashes'           => $this->hash_list( $questions ),
			'worksheet_achievement_link_hashes' => $this->hash_list( $worksheet_links ),
			'question_achievement_link_hashes'  => $this->hash_list( $question_links ),
			'asset_binding_hashes'              => $this->hash_list( $asset_bindings ),
			'statistics'                        => $statistics,
		);
		$projection_json = $this->canonical->encode( $manifest );

		return new Worksheet_Revision_Projection(
			$questions,
			$worksheet_links,
			$question_links,
			$asset_bindings,
			$projection_json,
			hash( 'sha256', $projection_json ),
			$statistics
		);
	}

	/**
	 * Reject invalid source.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source( Canonical_Worksheet_Document $source ): array {
		$document = $source->document();
		try {
			$encoded = $this->canonical->encode( $document );
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Document_Validation_Failure::schema_unsupported( '$' );
		}
		if (
			! hash_equals( $source->document_hash(), hash( 'sha256', $source->document_json() ) )
			|| ! hash_equals( $source->document_json(), $encoded )
			|| Worksheet_Document_Profile::SCHEMA_ID !== ( $document['schema_id'] ?? null )
			|| Worksheet_Document_Profile::SCHEMA_VERSION !== ( $document['schema_version'] ?? null )
			|| Worksheet_Document_Profile::EDITOR_PROFILE !== ( $document['editor_profile'] ?? null )
			|| ! isset( $document['nodes'] )
			|| ! is_array( $document['nodes'] )
			|| ! array_is_list( $document['nodes'] )
		) {
			throw Worksheet_Document_Validation_Failure::schema_unsupported( '$' );
		}

		return $document;
	}

	/**
	 * Project questions.
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @return array{0:array<int,array<string,mixed>>,1:array<string,array<string,mixed>>}
	 */
	private function project_questions( array $nodes ): array {
		$questions           = array();
		$by_response         = array();
		$question_public_ids = array();
		$this->walk_questions( $nodes, '$.nodes', $questions, $by_response, $question_public_ids );

		return array( $questions, $by_response );
	}

	/**
	 * Walk questions.
	 *
	 * @param array<array-key,mixed>            $nodes Nodes.
	 * @param string                            $path Path.
	 * @param array<int,array<string,mixed>>    &$questions Questions.
	 * @param array<string,array<string,mixed>> &$by_response By response.
	 * @param array<string,bool>                &$question_public_ids Question public ids.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function walk_questions(
		array $nodes,
		string $path,
		array &$questions,
		array &$by_response,
		array &$question_public_ids
	): void {
		foreach ( $nodes as $index => $node ) {
			$node_path = $path . '[' . $index . ']';
			if ( ! is_array( $node ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $node_path );
			}
			if ( array_key_exists( 'text', $node ) ) {
				continue;
			}
			$type = $node['type'] ?? null;
			if ( ! is_string( $type ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $node_path . '.type' );
			}

			if ( Worksheet_Document_Profile::RESPONSE_NODE_TYPE === $type ) {
				$response_id = $this->public_id( $node['response_id'] ?? null, $node_path . '.response_id' );
				if ( isset( $by_response[ $response_id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::id_invalid( $node_path . '.response_id' );
				}
				$kind = $node['kind'] ?? null;
				if ( ! is_string( $kind ) || ! in_array( $kind, Worksheet_Document_Profile::RESPONSE_KINDS, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::property_not_allowed( $node_path . '.kind' );
				}

				$question = array(
					'response_id' => $response_id,
					'kind'        => $kind,
					'label'       => $node['label'] ?? null,
					'required'    => $node['required'] ?? null,
				);
				foreach ( array( 'help_text', 'constraints' ) as $key ) {
					if ( array_key_exists( $key, $node ) ) {
						$question[ $key ] = $node[ $key ];
					}
				}
				$question_json = $this->canonical->encode( $question );
				$question_hash = hash( 'sha256', $question_json );

				$private      = null;
				$private_json = null;
				$private_hash = null;
				if ( array_key_exists( 'grading', $node ) ) {
					$private      = array( 'grading' => $node['grading'] );
					$private_json = $this->canonical->encode( $private );
					$private_hash = hash( 'sha256', $private_json );
				}

				$question_public_id = $this->ids->create()->value();
				if ( isset( $question_public_ids[ $question_public_id ] ) ) {
					throw Worksheet_Document_Validation_Failure::id_invalid( '$.questions.public_id' );
				}
				$question_public_ids[ $question_public_id ] = true;
				$position                                   = count( $questions );
				$content_hash                               = $this->content_hash(
					array(
						'position'                 => $position,
						'question_schema'          => self::QUESTION_SCHEMA,
						'question_version'         => self::QUESTION_VERSION,
						'private_metadata_hash'    => $private_hash,
						'private_metadata_schema'  => null === $private ? null : self::PRIVATE_METADATA_SCHEMA,
						'private_metadata_version' => null === $private ? null : self::PRIVATE_METADATA_VERSION,
						'question_hash'            => $question_hash,
						'question_key'             => $response_id,
						'response_type'            => $kind,
					)
				);
				$record                                     = array(
					'public_id'                => $question_public_id,
					'content_hash'             => $content_hash,
					'question_key'             => $response_id,
					'position'                 => $position,
					'response_type'            => $kind,
					'question_schema'          => self::QUESTION_SCHEMA,
					'question_version'         => self::QUESTION_VERSION,
					'question'                 => $question,
					'question_json'            => $question_json,
					'question_hash'            => $question_hash,
					'private_metadata_schema'  => null === $private ? null : self::PRIVATE_METADATA_SCHEMA,
					'private_metadata_version' => null === $private ? null : self::PRIVATE_METADATA_VERSION,
					'private_metadata'         => $private,
					'private_metadata_json'    => $private_json,
					'private_metadata_hash'    => $private_hash,
				);
				$questions[]                                = $record;
				$by_response[ $response_id ]                = $record;
			}

			if ( array_key_exists( 'children', $node ) ) {
				if ( ! is_array( $node['children'] ) || ! array_is_list( $node['children'] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::node_not_allowed( $node_path . '.children' );
				}
				$this->walk_questions(
					$node['children'],
					$node_path . '.children',
					$questions,
					$by_response,
					$question_public_ids
				);
			}
		}
	}

	/**
	 * Normalize link requests.
	 *
	 * @param array<array-key,mixed>            $links Links.
	 * @param array<string,array<string,mixed>> $questions_by_response Questions by response.
	 * @return array{0:array<int,array<string,string|null>>,1:array<string,bool>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_link_requests( array $links, array $questions_by_response ): array {
		if ( ! array_is_list( $links ) ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.achievement_links' );
		}
		$result                 = array();
		$requested_standard_ids = array();
		$seen                   = array();

		foreach ( $links as $index => $link ) {
			$path = '$.achievement_links[' . $index . ']';
			if ( ! is_array( $link ) || array() === $link || array_is_list( $link ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			$this->assert_exact_keys(
				$link,
				array( 'scope', 'response_id', 'standard_public_id', 'alignment_type' ),
				$path
			);
			$scope = $link['scope'] ?? null;
			if ( ! is_string( $scope ) || ! in_array( $scope, array( 'worksheet', 'response' ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.scope' );
			}
			$response_id = $link['response_id'] ?? null;
			if ( 'worksheet' === $scope ) {
				if ( null !== $response_id ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.response_id' );
				}
			} else {
				$response_id = $this->public_id( $response_id, $path . '.response_id' );
				if ( ! isset( $questions_by_response[ $response_id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::id_invalid( $path . '.response_id' );
				}
			}

			$standard_id = $this->public_id( $link['standard_public_id'] ?? null, $path . '.standard_public_id' );
			$alignment   = $link['alignment_type'] ?? null;
			if ( ! is_string( $alignment ) || ! in_array( $alignment, self::ALIGNMENT_TYPES, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.alignment_type' );
			}
			$identity = $scope . '|' . ( $response_id ?? '' ) . '|' . $standard_id . '|' . $alignment;
			if ( isset( $seen[ $identity ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			$seen[ $identity ]                      = true;
			$requested_standard_ids[ $standard_id ] = true;
			$result[]                               = array(
				'scope'              => $scope,
				'response_id'        => $response_id,
				'standard_public_id' => $standard_id,
				'alignment_type'     => $alignment,
			);
		}

		return array( $result, $requested_standard_ids );
	}

	/**
	 * Normalize standard snapshots.
	 *
	 * @param array<array-key,mixed> $snapshots Snapshots.
	 * @param array<string,bool>     $requested_ids Requested ids.
	 * @return array<string,array<string,string>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_standard_snapshots( array $snapshots, array $requested_ids ): array {
		if ( ! array_is_list( $snapshots ) ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.standard_snapshots' );
		}
		$result = array();
		foreach ( $snapshots as $index => $snapshot ) {
			$path = '$.standard_snapshots[' . $index . ']';
			if ( ! is_array( $snapshot ) || array() === $snapshot || array_is_list( $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			$this->assert_exact_keys(
				$snapshot,
				array(
					'standard_public_id',
					'standard_source_version',
					'standard_code_snapshot',
					'standard_label_snapshot',
				),
				$path
			);
			$standard_id = $this->public_id( $snapshot['standard_public_id'] ?? null, $path . '.standard_public_id' );
			if ( isset( $result[ $standard_id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::id_invalid( $path . '.standard_public_id' );
			}
			$result[ $standard_id ] = array(
				'standard_public_id'      => $standard_id,
				'standard_source_version' => $this->line_text( $snapshot['standard_source_version'] ?? null, $path . '.standard_source_version', 64 ),
				'standard_code_snapshot'  => $this->line_text( $snapshot['standard_code_snapshot'] ?? null, $path . '.standard_code_snapshot', 100 ),
				'standard_label_snapshot' => $this->line_text( $snapshot['standard_label_snapshot'] ?? null, $path . '.standard_label_snapshot', 255 ),
			);
		}

		$expected = array_keys( $requested_ids );
		$actual   = array_keys( $result );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		if ( $expected !== $actual ) {
			throw Worksheet_Document_Validation_Failure::id_invalid( '$.standard_snapshots' );
		}

		return $result;
	}

	/**
	 * Project achievement links.
	 *
	 * @param array<int,array<string,string|null>> $requests Requests.
	 * @param array<string,array<string,string>>   $snapshots Snapshots.
	 * @param array<string,array<string,mixed>>    $questions_by_response Questions by response.
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
	 */
	private function project_achievement_links(
		array $requests,
		array $snapshots,
		array $questions_by_response
	): array {
		$worksheet_links    = array();
		$question_links     = array();
		$question_positions = array();

		foreach ( $requests as $request ) {
			$standard = $snapshots[ $request['standard_public_id'] ];
			$common   = array(
				'standard_public_id'      => $standard['standard_public_id'],
				'standard_source_version' => $standard['standard_source_version'],
				'standard_code_snapshot'  => $standard['standard_code_snapshot'],
				'standard_label_snapshot' => $standard['standard_label_snapshot'],
				'alignment_type'          => $request['alignment_type'],
			);

			if ( 'worksheet' === $request['scope'] ) {
				$record                 = $common;
				$record['position']     = count( $worksheet_links );
				$record['content_hash'] = $this->content_hash(
					array_merge( array( 'scope' => 'worksheet' ), $common, array( 'position' => $record['position'] ) )
				);
				$worksheet_links[]      = $record;
				continue;
			}

			$response_id                        = (string) $request['response_id'];
			$position                           = $question_positions[ $response_id ] ?? 0;
			$question_positions[ $response_id ] = $position + 1;
			$record                             = array_merge(
				array(
					'question_public_id' => $questions_by_response[ $response_id ]['public_id'],
					'question_key'       => $response_id,
				),
				$common,
				array( 'position' => $position )
			);
			$record['content_hash']             = $this->content_hash(
				array_merge(
					array(
						'scope'        => 'response',
						'question_key' => $response_id,
					),
					$common,
					array( 'position' => $position )
				)
			);
			$question_links[]                   = $record;
		}

		return array( $worksheet_links, $question_links );
	}

	/**
	 * Project asset bindings.
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @return array<int,array<string,mixed>>
	 */
	private function project_asset_bindings( array $nodes ): array {
		$bindings = array();
		$order    = array();
		$this->walk_assets( $nodes, '$.nodes', $bindings, $order );

		$result = array();
		foreach ( $order as $asset_id ) {
			$record                 = $bindings[ $asset_id ];
			$record['content_hash'] = $this->content_hash( $record );
			$result[]               = $record;
		}
		return $result;
	}

	/**
	 * Walk assets.
	 *
	 * @param array<array-key,mixed>            $nodes Nodes.
	 * @param string                            $path Path.
	 * @param array<string,array<string,mixed>> &$bindings Bindings.
	 * @param array<int,string>                 &$order Order.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function walk_assets( array $nodes, string $path, array &$bindings, array &$order ): void {
		foreach ( $nodes as $index => $node ) {
			$node_path = $path . '[' . $index . ']';
			if ( ! is_array( $node ) || array_key_exists( 'text', $node ) ) {
				continue;
			}
			$node_id   = $this->public_id( $node['node_id'] ?? null, $node_path . '.node_id' );
			$node_type = $node['type'] ?? null;
			if ( ! is_string( $node_type ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $node_path . '.type' );
			}
			foreach ( array( 'asset_id', 'poster_asset_id' ) as $property ) {
				if ( ! array_key_exists( $property, $node ) ) {
					continue;
				}
				$asset_id = $this->public_id( $node[ $property ], $node_path . '.' . $property );
				if ( ! isset( $bindings[ $asset_id ] ) ) {
					$bindings[ $asset_id ] = array(
						'asset_id'   => $asset_id,
						'position'   => count( $order ),
						'references' => array(),
					);
					$order[]               = $asset_id;
				}
				$bindings[ $asset_id ]['references'][] = array(
					'node_id'   => $node_id,
					'node_type' => $node_type,
					'property'  => $property,
					'path'      => $node_path . '.' . $property,
				);
			}

			if ( isset( $node['children'] ) && is_array( $node['children'] ) && array_is_list( $node['children'] ) ) {
				$this->walk_assets( $node['children'], $node_path . '.children', $bindings, $order );
			}
		}
	}

	/**
	 * Reject invalid exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @param string              $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $value, array $expected, string $path ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value, string $path ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		return $value;
	}

	/**
	 * Line text.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function line_text( $value, string $path, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		try {
			$text = $this->canonical->text( str_replace( array( "\r\n", "\r" ), "\n", trim( $value ) ) );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		if (
			'' === $text
			|| str_contains( $text, "\n" )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $text )
			|| $this->character_length( $text ) > $maximum
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $text;
	}

	/**
	 * Character length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function character_length( string $value ): int {
		$count = preg_match_all( '/./us', $value, $matches );
		return false === $count ? strlen( $value ) : $count;
	}

	/**
	 * Content hash.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return string
	 */
	private function content_hash( array $value ): string {
		return hash( 'sha256', $this->canonical->encode( $value ) );
	}

	/**
	 * Hash list.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 * @return array<int,string>
	 */
	private function hash_list( array $records ): array {
		return array_map( static fn ( array $record ): string => (string) $record['content_hash'], $records );
	}
}
