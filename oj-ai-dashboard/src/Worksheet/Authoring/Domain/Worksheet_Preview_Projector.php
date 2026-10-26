<?php
/**
 * Student-safe and print worksheet preview projector.
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
 * Builds new allowlisted trees; teacher-only fields are never copied.
 */
final class Worksheet_Preview_Projector {
	public const PROJECTOR_VERSION        = '1.0.0';
	public const STUDENT_DOCUMENT_SCHEMA  = 'oj_ai.student_document';
	public const STUDENT_DOCUMENT_VERSION = '1.0.0';
	public const RESPONSE_SCHEMA          = 'oj_ai.student_response_schema';
	public const RESPONSE_VERSION         = '1.0.0';
	public const PRINT_SCHEMA             = 'oj_ai.worksheet_print';
	public const PRINT_VERSION            = '1.0.0';
	public const PRINT_PROFILES           = array( 'student_blank', 'teacher_blank' );

	/**
	 * Initialize worksheet preview projector dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Student.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @return Worksheet_Preview_Projection
	 */
	public function student( Canonical_Worksheet_Document $source ): Worksheet_Preview_Projection {
		list( $document, $responses ) = $this->student_payloads( $source );
		$source_hash                  = $source->document_hash();
		$projection                   = array(
			'kind'             => 'student',
			'profile'          => 'student',
			'student_document' => $this->envelope(
				self::STUDENT_DOCUMENT_SCHEMA,
				self::STUDENT_DOCUMENT_VERSION,
				$source_hash,
				$document
			),
			'response_schema'  => $this->envelope(
				self::RESPONSE_SCHEMA,
				self::RESPONSE_VERSION,
				$source_hash,
				$responses
			),
		);

		return new Worksheet_Preview_Projection(
			'student',
			'student',
			$projection,
			hash( 'sha256', $this->canonical->encode( $projection ) )
		);
	}

	/**
	 * Print.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @param string                       $profile Profile.
	 * @return Worksheet_Preview_Projection
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function print( Canonical_Worksheet_Document $source, string $profile ): Worksheet_Preview_Projection {
		if ( ! in_array( $profile, self::PRINT_PROFILES, true ) ) {
			throw new InvalidArgumentException( 'Worksheet print profile is invalid.' );
		}
		list( $document, $responses ) = $this->student_payloads( $source );
		$teacher_document             = $source->document();
		$settings                     = $teacher_document['settings'] ?? null;
		if ( ! is_array( $settings ) || 'worksheet_a4@1' !== ( $settings['print_profile'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet print settings are invalid.' );
		}
		$payload     = array(
			'profile'         => $profile,
			'layout'          => array(
				'page_size'   => 'A4',
				'orientation' => 'portrait',
				'print_token' => 'worksheet_a4@1',
				'font_token'  => 'worksheet_system@1',
			),
			'document'        => $document,
			'response_schema' => $responses,
		);
		$source_hash = $source->document_hash();
		$projection  = array(
			'kind'             => 'print',
			'profile'          => $profile,
			'print_projection' => $this->envelope(
				self::PRINT_SCHEMA,
				self::PRINT_VERSION,
				$source_hash,
				$payload
			),
		);

		return new Worksheet_Preview_Projection(
			'print',
			$profile,
			$projection,
			hash( 'sha256', $this->canonical->encode( $projection ) )
		);
	}

	/**
	 * Student payloads.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function student_payloads( Canonical_Worksheet_Document $source ): array {
		$teacher   = $this->assert_source( $source );
		$responses = array();
		$nodes     = $this->student_nodes( $teacher['nodes'], $responses );
		$settings  = $teacher['settings'] ?? null;
		if ( ! is_array( $settings ) || 'document' !== ( $settings['student_navigation'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet student settings are invalid.' );
		}

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
	 * Reject invalid source.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source( Canonical_Worksheet_Document $source ): array {
		$document = $source->document();
		try {
			$json = $this->canonical->encode( $document );
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Worksheet preview source is invalid.' );
		}
		$expected_keys = array( 'schema_id', 'schema_version', 'editor_profile', 'document_id', 'locale', 'title', 'nodes', 'settings' );
		$actual_keys   = array_keys( $document );
		sort( $expected_keys, SORT_STRING );
		sort( $actual_keys, SORT_STRING );
		if (
			$actual_keys !== $expected_keys
			|| ! hash_equals( $source->document_json(), $json )
			|| ! hash_equals( $source->document_hash(), hash( 'sha256', $json ) )
			|| Worksheet_Document_Profile::SCHEMA_ID !== $document['schema_id']
			|| Worksheet_Document_Profile::SCHEMA_VERSION !== $document['schema_version']
			|| Worksheet_Document_Profile::EDITOR_PROFILE !== $document['editor_profile']
			|| ! is_array( $document['nodes'] )
			|| ! array_is_list( $document['nodes'] )
		) {
			throw new InvalidArgumentException( 'Worksheet preview source is invalid.' );
		}

		return $document;
	}

	/**
	 * Student nodes.
	 *
	 * @param array<array-key,mixed>         $nodes Nodes.
	 * @param array<int,array<string,mixed>> &$responses Responses.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function student_nodes( array $nodes, array &$responses ): array {
		$result = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || array_is_list( $node ) ) {
				throw new InvalidArgumentException( 'Worksheet preview node is invalid.' );
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

			$type = $node['type'] ?? null;
			if ( ! is_string( $type ) || ! in_array( $type, Worksheet_Document_Profile::element_node_types(), true ) ) {
				throw new InvalidArgumentException( 'Worksheet preview node type is invalid.' );
			}
			$safe = array(
				'type'    => $type,
				'node_id' => $node['node_id'] ?? null,
			);
			foreach ( $this->public_node_properties( $type ) as $property ) {
				if ( array_key_exists( $property, $node ) ) {
					$safe[ $property ] = $node[ $property ];
				}
			}
			if ( Worksheet_Document_Profile::RESPONSE_NODE_TYPE === $type ) {
				if ( isset( $safe['constraints'] ) ) {
					$safe['constraints'] = $this->public_constraints( (string) $safe['kind'], $safe['constraints'] );
				}
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
			$children = $node['children'] ?? null;
			if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
				throw new InvalidArgumentException( 'Worksheet preview children are invalid.' );
			}
			$safe['children'] = $this->student_nodes( $children, $responses );
			$result[]         = $safe;
		}

		return $result;
	}

	/**
	 * Public node properties.
	 *
	 * @param string $type Type.
	 * @return array<int,string>
	 */
	private function public_node_properties( string $type ): array {
		return array(
			'p'              => array( 'align' ),
			'h1'             => array( 'align' ),
			'h2'             => array( 'align' ),
			'h3'             => array( 'align' ),
			'ol'             => array( 'start' ),
			'a'              => array( 'url', 'label' ),
			'table'          => array( 'header', 'column_widths', 'width_percent', 'column_ratios' ),
			'tr'             => array( 'row_id' ),
			'td'             => array( 'column_id' ),
			'th'             => array( 'column_id' ),
			'img'            => array( 'asset_id', 'alt', 'width' ),
			'audio'          => array( 'asset_id', 'transcript' ),
			'video'          => array( 'asset_id', 'transcript', 'poster_asset_id' ),
			'file'           => array( 'asset_id', 'label', 'description' ),
			'equation'       => array( 'tex', 'display' ),
			'code_block'     => array( 'language' ),
			'callout'        => array( 'tone', 'icon' ),
			'oj_ai_drawing'  => array( 'asset_id', 'alt', 'aspect_ratio', 'stroke_schema_version' ),
			'oj_ai_response' => array( 'response_id', 'kind', 'label', 'required', 'help_text', 'constraints' ),
		)[ $type ] ?? array();
	}

	/**
	 * Public constraints.
	 *
	 * @param string $kind Kind.
	 * @param mixed  $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_constraints( string $kind, $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Worksheet response constraints are invalid.' );
		}
		$allowed = array(
			'short_text'      => array( 'min_chars', 'max_chars', 'trim' ),
			'long_text'       => array( 'min_chars', 'max_chars' ),
			'single_choice'   => array( 'options' ),
			'multiple_choice' => array( 'options', 'min_selected', 'max_selected' ),
			'checkbox'        => array(),
			'number'          => array( 'min', 'max', 'step', 'unit', 'decimal_places' ),
			'table'           => array( 'row_mode', 'rows', 'columns', 'min_rows', 'max_rows' ),
			'image_upload'    => array( 'mime_types', 'max_files', 'max_bytes', 'alt_required' ),
			'file_upload'     => array( 'mime_types', 'max_files', 'max_bytes' ),
			'drawing'         => array( 'stroke_schema_version', 'preview_mime_type', 'aspect_ratio', 'max_strokes' ),
		)[ $kind ] ?? null;
		if ( null === $allowed ) {
			throw new InvalidArgumentException( 'Worksheet response kind is invalid.' );
		}
		$result = array();
		foreach ( $allowed as $property ) {
			if ( ! array_key_exists( $property, $value ) ) {
				continue;
			}
			if ( 'options' === $property ) {
				$result[ $property ] = $this->public_object_list( $value[ $property ], array( 'option_id', 'value', 'label' ) );
			} elseif ( 'rows' === $property ) {
				$result[ $property ] = $this->public_object_list( $value[ $property ], array( 'row_id', 'label' ) );
			} elseif ( 'columns' === $property ) {
				$result[ $property ] = $this->public_object_list( $value[ $property ], array( 'column_id', 'label', 'cell_type' ) );
			} elseif ( 'mime_types' === $property ) {
				if ( ! is_array( $value[ $property ] ) || ! array_is_list( $value[ $property ] ) ) {
					throw new InvalidArgumentException( 'Worksheet response MIME list is invalid.' );
				}
				$result[ $property ] = $value[ $property ];
			} else {
				$result[ $property ] = $value[ $property ];
			}
		}

		return $result;
	}

	/**
	 * Public object list.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $allowed Allowed.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_object_list( $value, array $allowed ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet response object list is invalid.' );
		}
		$result = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) || array_is_list( $entry ) ) {
				throw new InvalidArgumentException( 'Worksheet response object is invalid.' );
			}
			$item = array();
			foreach ( $allowed as $property ) {
				if ( array_key_exists( $property, $entry ) ) {
					$item[ $property ] = $entry[ $property ];
				}
			}
			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Envelope.
	 *
	 * @param string              $schema Schema.
	 * @param string              $version Version.
	 * @param string              $source_hash Source hash.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @return array<string,mixed>
	 */
	private function envelope( string $schema, string $version, string $source_hash, array $payload ): array {
		$payload_json = $this->canonical->encode( $payload );

		return array(
			'schema_id'         => $schema,
			'schema_version'    => $version,
			'projector_version' => self::PROJECTOR_VERSION,
			'source_hash'       => $source_hash,
			'payload_hash'      => hash( 'sha256', $payload_json ),
			'payload'           => $payload,
		);
	}
}
