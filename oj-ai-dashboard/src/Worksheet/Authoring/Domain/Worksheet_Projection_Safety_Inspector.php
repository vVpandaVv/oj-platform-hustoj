<?php
/**
 * Worksheet preview renderer parity and private-field inspection.
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
 * Independently derives the public worksheet manifest and fails closed on drift.
 */
final class Worksheet_Projection_Safety_Inspector {
	public const INSPECTOR_VERSION = '1.0.0';

	/**
	 * Worksheet projection safety inspector.
	 *
	 * @var array<int,string>
	 */
	private const FORBIDDEN_KEYS = array(
		'grading',
		'grading_enabled',
		'max_points',
		'correct_answer',
		'example_answer',
		'rubric',
		'rubric_id',
		'rubric_criterion_ids',
		'teacher_note',
		'teacher_memo',
		'owner_user_id',
		'actor_user_id',
		'internal_state',
		'model_config',
		'system_prompt',
		'api_key',
		'authorization',
		'cookie',
	);

	/**
	 * Worksheet projection safety inspector.
	 *
	 * @var array<int,string>
	 */
	private const PRIVATE_ASSET_URL_KEYS = array(
		'asset_url',
		'signed_url',
		'download_url',
		'preview_url',
		'blob_url',
		'data_url',
		'poster_url',
		'src',
		'srcset',
	);

	/**
	 * Initialize worksheet projection safety inspector dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Inspect.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @param Worksheet_Preview_Projection $student Student.
	 * @param Worksheet_Preview_Projection $print Print.
	 * @return array{
	 *   ready:bool,
	 *   renderer_parity:array<string,mixed>,
	 *   private_field_scan:array<string,mixed>,
	 *   blockers:array<int,array{code:string}>,
	 *   statistics:array<string,int>
	 * }
	 */
	public function inspect(
		Canonical_Worksheet_Document $source,
		Worksheet_Preview_Projection $student,
		Worksheet_Preview_Projection $print // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.printFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
	): array {
		$parity_passed = false;
		$manifest_hash = '';
		try {
			list( $expected_document, $expected_responses ) = $this->expected_payloads( $source );
			$manifest_hash                                  = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'document'        => $expected_document,
						'response_schema' => $expected_responses,
					)
				)
			);
			$this->assert_student_projection( $source, $student, $expected_document, $expected_responses );
			$this->assert_print_projection( $source, $print, $expected_document, $expected_responses );
			$parity_passed = true;
		} catch ( InvalidArgumentException ) {
			$parity_passed = false;
		}

		$scan = array(
			'forbidden_field_count'         => 0,
			'private_asset_url_count'       => 0,
			'invalid_asset_reference_count' => 0,
		);
		$this->scan_value( $student->projection(), '', $scan );
		$this->scan_value( $print->projection(), '', $scan );
		$private_passed = 0 === array_sum( $scan );

		$blockers = array();
		if ( ! $parity_passed ) {
			$blockers[] = array( 'code' => 'worksheet_renderer_parity_failed' );
		}
		if ( ! $private_passed ) {
			$blockers[] = array( 'code' => 'worksheet_private_projection_leak' );
		}

		return array(
			'ready'              => $parity_passed && $private_passed,
			'renderer_parity'    => array(
				'status'        => $parity_passed ? 'passed' : 'blocked',
				'manifest_hash' => $manifest_hash,
			),
			'private_field_scan' => array_merge(
				array( 'status' => $private_passed ? 'passed' : 'blocked' ),
				$scan
			),
			'blockers'           => $blockers,
			'statistics'         => array_merge(
				array( 'renderer_parity_projection_count' => $parity_passed ? 2 : 0 ),
				$scan
			),
		);
	}

	/**
	 * Expected payloads.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function expected_payloads( Canonical_Worksheet_Document $source ): array {
		$teacher = $source->document();
		$json    = $this->canonical->encode( $teacher );
		if (
			! hash_equals( $source->document_json(), $json )
			|| ! hash_equals( $source->document_hash(), hash( 'sha256', $json ) )
			|| Worksheet_Document_Profile::SCHEMA_ID !== ( $teacher['schema_id'] ?? null )
			|| Worksheet_Document_Profile::SCHEMA_VERSION !== ( $teacher['schema_version'] ?? null )
			|| Worksheet_Document_Profile::EDITOR_PROFILE !== ( $teacher['editor_profile'] ?? null )
			|| ! isset( $teacher['nodes'] )
			|| ! is_array( $teacher['nodes'] )
			|| ! array_is_list( $teacher['nodes'] )
			|| 'document' !== ( $teacher['settings']['student_navigation'] ?? null )
		) {
			throw new InvalidArgumentException( 'Worksheet safety source is invalid.' );
		}

		$responses = array();
		$nodes     = $this->public_nodes( $teacher['nodes'], $responses );

		return array(
			array(
				'document_id'    => $teacher['document_id'],
				'editor_profile' => $teacher['editor_profile'],
				'locale'         => $teacher['locale'],
				'title'          => $teacher['title'],
				'navigation'     => 'document',
				'nodes'          => $nodes,
			),
			array( 'items' => $responses ),
		);
	}

	/**
	 * Public nodes.
	 *
	 * @param array<array-key,mixed>         $nodes Nodes.
	 * @param array<int,array<string,mixed>> &$responses Responses.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_nodes( array $nodes, array &$responses ): array {
		$result = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || array_is_list( $node ) ) {
				throw new InvalidArgumentException( 'Worksheet safety node is invalid.' );
			}
			if ( array_key_exists( 'text', $node ) ) {
				$text = array( 'text' => $node['text'] );
				foreach ( Worksheet_Document_Profile::TEXT_MARKS as $mark ) {
					if ( true === ( $node[ $mark ] ?? false ) ) {
						$text[ $mark ] = true;
					}
				}
				$result[] = $text;
				continue;
			}

			$type     = $node['type'] ?? null;
			$children = $node['children'] ?? null;
			if (
				! is_string( $type )
				|| ! in_array( $type, Worksheet_Document_Profile::element_node_types(), true )
				|| ! is_array( $children )
				|| ! array_is_list( $children )
			) {
				throw new InvalidArgumentException( 'Worksheet safety element is invalid.' );
			}
			$safe = array();
			foreach ( $node as $key => $value ) {
				if ( 'children' === $key || 'grading' === $key ) {
					continue;
				}
				$safe[ $key ] = $value;
			}
			if ( Worksheet_Document_Profile::RESPONSE_NODE_TYPE === $type ) {
				$response = array(
					'response_id' => $safe['response_id'] ?? null,
					'kind'        => $safe['kind'] ?? null,
					'label'       => $safe['label'] ?? null,
					'required'    => $safe['required'] ?? null,
					'position'    => count( $responses ),
				);
				foreach ( array( 'help_text', 'constraints' ) as $property ) {
					if ( array_key_exists( $property, $safe ) ) {
						$response[ $property ] = $safe[ $property ];
					}
				}
				$responses[] = $response;
			}
			$safe['children'] = $this->public_nodes( $children, $responses );
			$result[]         = $safe;
		}

		return $result;
	}

	/**
	 * Reject invalid student projection.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @param Worksheet_Preview_Projection $student Student.
	 * @param array<string,mixed>          $expected_document Expected document.
	 * @param array<string,mixed>          $expected_responses Expected responses.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_student_projection(
		Canonical_Worksheet_Document $source,
		Worksheet_Preview_Projection $student,
		array $expected_document,
		array $expected_responses
	): void {
		if ( 'student' !== $student->kind() || 'student' !== $student->profile() ) {
			throw new InvalidArgumentException( 'Worksheet student projection identity drifted.' );
		}
		$projection = $student->projection();
		$this->assert_keys( $projection, array( 'kind', 'profile', 'student_document', 'response_schema' ) );
		if ( 'student' !== $projection['kind'] || 'student' !== $projection['profile'] ) {
			throw new InvalidArgumentException( 'Worksheet student projection kind drifted.' );
		}
		$this->assert_projection_hash( $projection, $student->projection_hash() );
		$this->assert_envelope(
			$projection['student_document'],
			Worksheet_Preview_Projector::STUDENT_DOCUMENT_SCHEMA,
			Worksheet_Preview_Projector::STUDENT_DOCUMENT_VERSION,
			$source->document_hash(),
			$expected_document
		);
		$this->assert_envelope(
			$projection['response_schema'],
			Worksheet_Preview_Projector::RESPONSE_SCHEMA,
			Worksheet_Preview_Projector::RESPONSE_VERSION,
			$source->document_hash(),
			$expected_responses
		);
	}

	/**
	 * Reject invalid print projection.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @param Worksheet_Preview_Projection $print Print.
	 * @param array<string,mixed>          $expected_document Expected document.
	 * @param array<string,mixed>          $expected_responses Expected responses.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_print_projection(
		Canonical_Worksheet_Document $source,
		Worksheet_Preview_Projection $print, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.printFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		array $expected_document,
		array $expected_responses
	): void {
		if ( 'print' !== $print->kind() || ! in_array( $print->profile(), Worksheet_Preview_Projector::PRINT_PROFILES, true ) ) {
			throw new InvalidArgumentException( 'Worksheet print projection identity drifted.' );
		}
		$projection = $print->projection();
		$this->assert_keys( $projection, array( 'kind', 'profile', 'print_projection' ) );
		if ( 'print' !== $projection['kind'] || $print->profile() !== $projection['profile'] ) {
			throw new InvalidArgumentException( 'Worksheet print projection kind drifted.' );
		}
		$this->assert_projection_hash( $projection, $print->projection_hash() );
		$payload = array(
			'profile'         => $print->profile(),
			'layout'          => array(
				'page_size'   => 'A4',
				'orientation' => 'portrait',
				'print_token' => 'worksheet_a4@1',
				'font_token'  => 'worksheet_system@1',
			),
			'document'        => $expected_document,
			'response_schema' => $expected_responses,
		);
		$this->assert_envelope(
			$projection['print_projection'],
			Worksheet_Preview_Projector::PRINT_SCHEMA,
			Worksheet_Preview_Projector::PRINT_VERSION,
			$source->document_hash(),
			$payload
		);
	}

	/**
	 * Reject invalid envelope.
	 *
	 * @param mixed               $envelope Envelope.
	 * @param string              $schema Schema.
	 * @param string              $version Version.
	 * @param string              $source_hash Source hash.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_envelope( $envelope, string $schema, string $version, string $source_hash, array $payload ): void {
		if ( ! is_array( $envelope ) || array_is_list( $envelope ) ) {
			throw new InvalidArgumentException( 'Worksheet preview envelope is invalid.' );
		}
		$this->assert_keys( $envelope, array( 'schema_id', 'schema_version', 'projector_version', 'source_hash', 'payload_hash', 'payload' ) );
		$payload_hash = hash( 'sha256', $this->canonical->encode( $payload ) );
		if (
			$schema !== $envelope['schema_id']
			|| $version !== $envelope['schema_version']
			|| Worksheet_Preview_Projector::PROJECTOR_VERSION !== $envelope['projector_version']
			|| ! hash_equals( $source_hash, (string) $envelope['source_hash'] )
			|| ! hash_equals( $payload_hash, (string) $envelope['payload_hash'] )
			|| $payload !== $envelope['payload']
		) {
			throw new InvalidArgumentException( 'Worksheet preview envelope drifted.' );
		}
	}

	/**
	 * Reject invalid projection hash.
	 *
	 * @param array<string,mixed> $projection Projection.
	 * @param string              $projection_hash Projection hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_projection_hash( array $projection, string $projection_hash ): void {
		$actual_hash = hash( 'sha256', $this->canonical->encode( $projection ) );
		if ( ! hash_equals( $actual_hash, $projection_hash ) ) {
			throw new InvalidArgumentException( 'Worksheet preview projection hash drifted.' );
		}
	}

	/**
	 * Reject invalid keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet preview keys drifted.' );
		}
	}

	/**
	 * Scan value.
	 *
	 * @param mixed             $value Value.
	 * @param string            $path Path.
	 * @param array<string,int> &$scan Scan.
	 * @return void
	 */
	private function scan_value( $value, string $path, array &$scan ): void {
		if ( is_string( $value ) ) {
			$lower = strtolower( $value );
			if ( str_starts_with( $lower, 'blob:' ) || str_starts_with( $lower, 'data:' ) ) {
				++$scan['private_asset_url_count'];
			}
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $key => $item ) {
			$key_name  = is_string( $key ) ? strtolower( $key ) : '';
			$item_path = '' === $path ? $key_name : $path . '.' . $key_name;
			if ( in_array( $key_name, self::FORBIDDEN_KEYS, true ) ) {
				++$scan['forbidden_field_count'];
			}
			if ( in_array( $key_name, self::PRIVATE_ASSET_URL_KEYS, true ) ) {
				++$scan['private_asset_url_count'];
			}
			if ( in_array( $key_name, array( 'asset_id', 'poster_asset_id' ), true ) ) {
				if ( ! is_string( $item ) || ! Public_Id::is_valid( $item ) ) {
					++$scan['invalid_asset_reference_count'];
				}
				if ( is_string( $item ) && 1 === preg_match( '/^(?:https?:|blob:|data:)/i', $item ) ) {
					++$scan['private_asset_url_count'];
				}
			}
			$this->scan_value( $item, $item_path, $scan );
		}
	}
}
