<?php
/**
 * Strict worksheet quality output normalizer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Worker_Failure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict worksheet quality output normalizer.
 */
final class Worksheet_Evaluation_Output_Normalizer {
	public const RESULT_SCHEMA                  = 'oj_ai.worksheet_quality_result';
	public const RESULT_VERSION                 = '1.0.0';
	public const MAXIMUM_OUTPUT_BYTES           = 524288;
	public const MAXIMUM_FINDINGS               = 100;
	public const MAXIMUM_FINDINGS_PER_DIMENSION = 20;
	public const MAXIMUM_EVIDENCE_PER_FINDING   = 3;

	private const RATINGS      = array( 'meets', 'minor_issue', 'major_issue', 'not_assessable' );
	private const SEVERITIES   = array( 'info', 'minor', 'major', 'critical' );
	private const STATUSES     = array( 'completed', 'partial', 'abstained' );
	private const ANCHOR_TYPES = array( 'worksheet', 'node', 'response', 'achievement' );

	/**
	 * Initialize worksheet evaluation output normalizer dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Normalize.
	 *
	 * @param array<string,mixed> $output Output.
	 * @param array<string,mixed> $input Input.
	 * @return Worksheet_Evaluation_Normalized_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function normalize( array $output, array $input ): Worksheet_Evaluation_Normalized_Result {
		$this->assert_output_size( $output );
		$this->assert_no_forbidden_keys( $output, '$' );
		$this->assert_exact_keys(
			$output,
			array(
				'schema_id',
				'schema_version',
				'source_hash',
				'status',
				'summary',
				'abstain_reason',
				'dimensions',
				'findings',
				'limitations',
			),
			'$'
		);

		$source_hash = $this->sha256( $input['subject']['source_hash'] ?? null, '$.input.subject.source_hash' );
		if (
			Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA !== ( $output['schema_id'] ?? null )
			|| Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION !== ( $output['schema_version'] ?? null )
			|| ! hash_equals( $source_hash, $this->sha256( $output['source_hash'] ?? null, '$.source_hash' ) )
		) {
			throw $this->schema_failure( '$.schema_id|schema_version|source_hash' );
		}

		$status = $output['status'] ?? null;
		if ( ! is_string( $status ) || ! in_array( $status, self::STATUSES, true ) ) {
			throw $this->schema_failure( '$.status' );
		}
		$summary        = $this->text( $output['summary'] ?? null, '$.summary', 3000, true );
		$abstain_reason = $this->nullable_text( $output['abstain_reason'] ?? null, '$.abstain_reason', 1500, true );
		$limitations    = $this->text_list( $output['limitations'] ?? null, '$.limitations', 20, 500 );
		if (
			( 'abstained' === $status && null === $abstain_reason )
			|| ( 'abstained' !== $status && null !== $abstain_reason )
			|| ( 'partial' === $status && array() === $limitations )
		) {
			throw $this->domain_failure( '$.status|abstain_reason|limitations' );
		}

		$dimension_order = $this->dimension_order( $input );
		$dimensions      = $this->dimensions( $output['dimensions'] ?? null, $dimension_order );
		$anchors         = $this->anchor_index( $input );
		$findings        = $this->findings( $output['findings'] ?? null, $dimension_order, $anchors );
		$this->assert_rating_consistency( $dimensions, $findings, $limitations );

		$severity_order      = array_flip( array_reverse( self::SEVERITIES ) );
		$dimension_positions = array_flip( $dimension_order );
		usort(
			$findings,
			static function ( array $left, array $right ) use ( $severity_order, $dimension_positions ): int {
				$left_anchor  = $left['evidence'][0]['anchor_type'] . ':' . $left['evidence'][0]['anchor_id'];
				$right_anchor = $right['evidence'][0]['anchor_type'] . ':' . $right['evidence'][0]['anchor_id'];
				return array(
					$severity_order[ $left['severity'] ],
					$dimension_positions[ $left['dimension_key'] ],
					$left_anchor,
					$left['title'],
					$left['finding_key'],
				) <=> array(
					$severity_order[ $right['severity'] ],
					$dimension_positions[ $right['dimension_key'] ],
					$right_anchor,
					$right['title'],
					$right['finding_key'],
				);
			}
		);

		$items = array();
		foreach ( $findings as $position => &$finding ) {
			$finding['position'] = $position;
			$evidence_payload    = array(
				'schema_id'      => 'oj_ai.worksheet_quality_finding_evidence',
				'schema_version' => '1.0.0',
				'finding_key'    => $finding['finding_key'],
				'title'          => $finding['title'],
				'rationale'      => $finding['rationale'],
				'evidence'       => $finding['evidence'],
			);
			$items[]             = array(
				'dimension_key'  => $finding['dimension_key'],
				'position'       => $position,
				'severity'       => $finding['severity'],
				'confidence'     => $finding['confidence'],
				'evidence'       => $evidence_payload,
				'recommendation' => $finding['recommendation'],
			);
		}
		unset( $finding );

		$overall_confidence  = $this->mean_confidence( array_column( $dimensions, 'confidence' ) );
		$safety_flagged      = (bool) array_filter(
			$findings,
			static fn ( array $finding ): bool => 'safety' === $finding['dimension_key']
		);
		$overall_status      = $this->overall_status( $status, $dimensions, $findings, $limitations, $safety_flagged );
		$normalized          = array(
			'schema_id'          => self::RESULT_SCHEMA,
			'schema_version'     => self::RESULT_VERSION,
			'source_hash'        => $source_hash,
			'provider_status'    => $status,
			'overall_status'     => $overall_status,
			'overall_confidence' => $overall_confidence,
			'abstained'          => 'abstained' === $status,
			'safety_flagged'     => $safety_flagged,
			'summary'            => $summary,
			'abstain_reason'     => $abstain_reason,
			'dimensions'         => $dimensions,
			'findings'           => $findings,
			'limitations'        => $limitations,
		);
		$normalized_json     = $this->encode( $normalized );
		$provider_projection = array(
			'schema_id'      => Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
			'source_hash'    => $source_hash,
			'status'         => $status,
			'summary'        => $summary,
			'abstain_reason' => $abstain_reason,
			'dimensions'     => $dimensions,
			'findings'       => array_map(
				static function ( array $finding ): array {
					unset( $finding['finding_key'], $finding['position'] );
					return $finding;
				},
				$findings
			),
			'limitations'    => $limitations,
		);

		return new Worksheet_Evaluation_Normalized_Result(
			$overall_status,
			$overall_confidence,
			$summary,
			$normalized_json,
			hash( 'sha256', $normalized_json ),
			hash( 'sha256', $this->encode( $provider_projection ) ),
			$items
		);
	}

	/**
	 * Reject invalid output size.
	 *
	 * @param array<string,mixed> $output Output.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_output_size( array $output ): void {
		try {
			$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
				$output,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
			);
		} catch ( JsonException ) {
			throw $this->schema_failure( '$ (not JSON-encodable)' );
		}
		if ( strlen( $json ) > self::MAXIMUM_OUTPUT_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Worker_Failure::because( Worksheet_Evaluation_Worker_Failure::OUTPUT_TOO_LARGE, '$ (' . strlen( $json ) . ' bytes)' );
		}
	}

	/**
	 * Dimension order.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function dimension_order( array $input ): array {
		$raw = $input['rubric']['dimensions'] ?? null;
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) < 6 || count( $raw ) > 12 ) {
			throw $this->domain_failure( '$.input.rubric.dimensions' );
		}
		$result = array();
		foreach ( $raw as $dimension ) {
			$key = is_array( $dimension ) ? ( $dimension['dimension_key'] ?? null ) : null;
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $key ) || in_array( $key, $result, true ) ) {
				throw $this->domain_failure( '$.input.rubric.dimensions[].dimension_key' );
			}
			$result[] = $key;
		}
		return $result;
	}

	/**
	 * Dimensions.
	 *
	 * @param mixed             $raw Raw.
	 * @param array<int,string> $expected Expected.
	 * @return array<int,array<string,string>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function dimensions( $raw, array $expected ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) !== count( $expected ) ) {
			$kind   = is_array( $raw ) ? ( array_is_list( $raw ) ? 'array' : 'object' ) : gettype( $raw );
			$actual = is_array( $raw ) ? (string) count( $raw ) : 'n/a';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( '$.dimensions (expected array count=' . count( $expected ) . '; received ' . $kind . ' count=' . $actual . ')' );
		}
		$mapped = array();
		foreach ( $raw as $index => $dimension ) {
			$path = '$.dimensions[' . $index . ']';
			if ( ! is_array( $dimension ) || array_is_list( $dimension ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->schema_failure( $path );
			}
			$this->assert_exact_keys( $dimension, array( 'dimension_key', 'rating', 'confidence', 'summary' ), $path );
			$key    = $dimension['dimension_key'] ?? null;
			$rating = $dimension['rating'] ?? null;
			if (
				! is_string( $key ) || ! in_array( $key, $expected, true ) || isset( $mapped[ $key ] )
				|| ! is_string( $rating ) || ! in_array( $rating, self::RATINGS, true )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path );
			}
			$mapped[ $key ] = array(
				'dimension_key' => $key,
				'rating'        => $rating,
				'confidence'    => $this->confidence( $dimension['confidence'] ?? null, $path . '.confidence' ),
				'summary'       => $this->text( $dimension['summary'] ?? null, $path . '.summary', 1000, true ),
			);
		}
		return array_map( static fn ( string $key ): array => $mapped[ $key ], $expected );
	}

	/**
	 * Findings.
	 *
	 * @param mixed                                            $raw Raw.
	 * @param array<int,string>                                $dimension_order Dimension order.
	 * @param array<string,array<string,array<string,string>>> $anchors Anchors.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function findings( $raw, array $dimension_order, array $anchors ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > self::MAXIMUM_FINDINGS ) {
			throw $this->schema_failure( '$.findings' );
		}
		$result         = array();
		$counts         = array_fill_keys( $dimension_order, 0 );
		$keys           = array();
		$evidence_count = 0;
		foreach ( $raw as $index => $finding ) {
			$path = '$.findings[' . $index . ']';
			if ( ! is_array( $finding ) || array_is_list( $finding ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->schema_failure( $path );
			}
			$this->assert_exact_keys(
				$finding,
				array( 'dimension_key', 'severity', 'title', 'rationale', 'evidence', 'recommendation', 'confidence' ),
				$path
			);
			$dimension = $finding['dimension_key'] ?? null;
			$severity  = $finding['severity'] ?? null;
			if (
				! is_string( $dimension ) || ! isset( $counts[ $dimension ] )
				|| ! is_string( $severity ) || ! in_array( $severity, self::SEVERITIES, true )
				|| ++$counts[ $dimension ] > self::MAXIMUM_FINDINGS_PER_DIMENSION
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path . '.dimension_key|severity' );
			}
			$evidence        = $this->evidence( $finding['evidence'] ?? null, $anchors, $path . '.evidence' );
			$evidence_count += count( $evidence );
			if ( $evidence_count > 300 ) {
				throw $this->domain_failure( '$.findings[].evidence (total > 300)' );
			}
			$normalized = array(
				'dimension_key'  => $dimension,
				'severity'       => $severity,
				'title'          => $this->text( $finding['title'] ?? null, $path . '.title', 160, false ),
				'rationale'      => $this->text( $finding['rationale'] ?? null, $path . '.rationale', 1500, true ),
				'evidence'       => $evidence,
				'recommendation' => $this->text( $finding['recommendation'] ?? null, $path . '.recommendation', 1500, true ),
				'confidence'     => $this->confidence( $finding['confidence'] ?? null, $path . '.confidence' ),
			);
			$key        = hash( 'sha256', $this->encode( $normalized ) );
			if ( isset( $keys[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path . ' (duplicate finding)' );
			}
			$keys[ $key ]              = true;
			$normalized['finding_key'] = $key;
			$result[]                  = $normalized;
		}
		return $result;
	}

	/**
	 * Evidence.
	 *
	 * @param mixed                                            $raw Raw.
	 * @param array<string,array<string,array<string,string>>> $anchors Anchors.
	 * @param string                                           $path Path.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evidence( $raw, array $anchors, string $path ): array {
		if (
			! is_array( $raw ) || ! array_is_list( $raw ) || array() === $raw
			|| count( $raw ) > self::MAXIMUM_EVIDENCE_PER_FINDING
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path );
		}
		$result = array();
		$seen   = array();
		foreach ( $raw as $index => $item ) {
			$item_path = $path . '[' . $index . ']';
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->schema_failure( $item_path );
			}
			$this->assert_exact_keys( $item, array( 'anchor_type', 'anchor_id', 'field', 'quote' ), $item_path );
			$type  = $item['anchor_type'] ?? null;
			$id    = $item['anchor_id'] ?? null;
			$field = $item['field'] ?? null;
			if (
				! is_string( $type ) || ! in_array( $type, self::ANCHOR_TYPES, true )
				|| ! is_string( $id ) || ! Public_Id::is_valid( $id )
				|| ! is_string( $field ) || 1 !== preg_match( '/^[a-z][a-z0-9_.]{1,63}$/D', $field )
				|| ! isset( $anchors[ $type ][ $id ][ $field ] )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $item_path . '.anchor_type|anchor_id|field' );
			}
			$quote = $this->nullable_text( $item['quote'] ?? null, $item_path . '.quote', 240, true );
			if ( null !== $quote && ! str_contains( $anchors[ $type ][ $id ][ $field ], $quote ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $item_path . '.quote (not a substring of the anchored field)' );
			}
			$normalized = array(
				'anchor_type' => $type,
				'anchor_id'   => $id,
				'field'       => $field,
				'quote'       => $quote,
			);
			$key        = $this->encode( $normalized );
			if ( isset( $seen[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $item_path . ' (duplicate evidence item)' );
			}
			$seen[ $key ] = true;
			$result[]     = $normalized;
		}
		usort( $result, static fn ( array $left, array $right ): int => array( $left['anchor_type'], $left['anchor_id'], $left['field'], $left['quote'] ) <=> array( $right['anchor_type'], $right['anchor_id'], $right['field'], $right['quote'] ) );
		return $result;
	}

	/**
	 * Anchor index.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,array<string,array<string,string>>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function anchor_index( array $input ): array {
		$payload  = $input['worksheet']['payload'] ?? null;
		$manifest = is_array( $payload ) ? ( $payload['anchor_manifest'] ?? null ) : null;
		if ( ! is_array( $payload ) || array_is_list( $payload ) || ! is_array( $manifest ) || array_is_list( $manifest ) ) {
			throw $this->domain_failure( '$.input.worksheet.payload.anchor_manifest' );
		}
		$allowed = array();
		foreach ( self::ANCHOR_TYPES as $type ) {
			$ids = $manifest[ $type ] ?? null;
			if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( '$.input.worksheet.payload.anchor_manifest.' . $type );
			}
			$allowed[ $type ] = array_fill_keys( $ids, true );
		}

		$index        = array_fill_keys( self::ANCHOR_TYPES, array() );
		$worksheet_id = $payload['worksheet_id'] ?? null;
		$title        = $payload['document']['title'] ?? null;
		if ( ! is_string( $worksheet_id ) || ! isset( $allowed['worksheet'][ $worksheet_id ] ) || ! is_string( $title ) ) {
			throw $this->domain_failure( '$.input.worksheet.payload.worksheet_id|document.title' );
		}
		$index['worksheet'][ $worksheet_id ] = array( 'title' => $title );

		$nodes = $payload['document']['nodes'] ?? null;
		if ( ! is_array( $nodes ) || ! array_is_list( $nodes ) ) {
			throw $this->domain_failure( '$.input.worksheet.payload.document.nodes' );
		}
		$this->index_nodes( $nodes, $allowed['node'], $index['node'] );

		$responses = $payload['responses'] ?? null;
		if ( ! is_array( $responses ) || ! array_is_list( $responses ) ) {
			throw $this->domain_failure( '$.input.worksheet.payload.responses' );
		}
		foreach ( $responses as $response ) {
			$id = is_array( $response ) ? ( $response['response_id'] ?? null ) : null;
			if ( ! is_string( $id ) || ! isset( $allowed['response'][ $id ] ) || isset( $index['response'][ $id ] ) ) {
				throw $this->domain_failure( '$.input.worksheet.payload.responses[].response_id' );
			}
			$fields  = $this->string_fields( $response, array( 'label', 'help_text' ) );
			$grading = $response['grading'] ?? null;
			if ( is_array( $grading ) && ! array_is_list( $grading ) ) {
				foreach ( array( 'correct_answer', 'example_answer' ) as $field ) {
					if ( is_string( $grading[ $field ] ?? null ) ) {
						$fields[ 'grading.' . $field ] = $grading[ $field ];
					}
				}
			}
			$index['response'][ $id ] = $fields;
		}

		$achievements = $payload['achievements'] ?? null;
		if ( ! is_array( $achievements ) || array_is_list( $achievements ) ) {
			throw $this->domain_failure( '$.input.worksheet.payload.achievements' );
		}
		foreach ( array( 'worksheet', 'responses' ) as $scope ) {
			$rows = $achievements[ $scope ] ?? null;
			if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( '$.input.worksheet.payload.achievements.' . $scope );
			}
			foreach ( $rows as $achievement ) {
				$id = is_array( $achievement ) ? ( $achievement['anchor_id'] ?? null ) : null;
				if ( ! is_string( $id ) || ! isset( $allowed['achievement'][ $id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->domain_failure( '$.input.worksheet.payload.achievements.' . $scope . '[].anchor_id' );
				}
				$fields = $this->string_fields( $achievement, array( 'code', 'label', 'description' ) );
				if ( isset( $index['achievement'][ $id ] ) && $index['achievement'][ $id ] !== $fields ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->domain_failure( '$.input.worksheet.payload.achievements.' . $scope . '[].fields (conflicting duplicate)' );
				}
				$index['achievement'][ $id ] = $fields;
			}
		}

		foreach ( self::ANCHOR_TYPES as $type ) {
			$actual   = array_keys( $index[ $type ] );
			$expected = array_keys( $allowed[ $type ] );
			sort( $actual, SORT_STRING );
			sort( $expected, SORT_STRING );
			if ( $actual !== $expected ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( '$.input.worksheet.payload.anchor_manifest.' . $type . ' (coverage mismatch)' );
			}
		}
		return $index;
	}

	/**
	 * Index nodes.
	 *
	 * @param array<int,mixed>                   $nodes Nodes.
	 * @param array<string,bool>                 $allowed Allowed.
	 * @param array<string,array<string,string>> &$index Index.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function index_nodes( array $nodes, array $allowed, array &$index ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || array_is_list( $node ) ) {
				throw $this->domain_failure( '$.input.worksheet.payload.document.nodes[]' );
			}
			if ( array_key_exists( 'text', $node ) ) {
				continue;
			}
			$id       = $node['node_id'] ?? null;
			$children = $node['children'] ?? null;
			if ( ! is_string( $id ) || ! isset( $allowed[ $id ] ) || isset( $index[ $id ] ) || ! is_array( $children ) || ! array_is_list( $children ) ) {
				throw $this->domain_failure( '$.input.worksheet.payload.document.nodes[].node_id|children' );
			}
			$fields = $this->string_fields( $node, array( 'label', 'help_text', 'alt', 'transcript', 'description', 'tex', 'link_host' ) );
			$text   = trim( $this->descendant_text( $children ) );
			if ( '' !== $text ) {
				$fields['text'] = $text;
			}
			$index[ $id ] = $fields;
			$this->index_nodes( $children, $allowed, $index );
		}
	}

	/**
	 * Descendant text.
	 *
	 * @param array<int,mixed> $nodes Nodes.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function descendant_text( array $nodes ): string {
		$text           = '';
		$previous_block = false;
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				throw $this->domain_failure( '$.input.worksheet.payload.document.nodes[].children (descendant)' );
			}
			if ( is_string( $node['text'] ?? null ) ) {
				$text          .= $node['text'];
				$previous_block = false;
				continue;
			}
			$children = $node['children'] ?? null;
			if ( is_array( $children ) && array_is_list( $children ) ) {
				$nested = $this->descendant_text( $children );
				if ( '' !== $nested ) {
					// Inline marks and links split leaves, not words. Only blocks add a boundary.
					$block = ! in_array( $node['type'] ?? null, array( 'a', 'equation' ), true );
					if ( '' !== $text && ( $block || $previous_block ) ) {
						$text .= "\n";
					}
					$text          .= $nested;
					$previous_block = $block;
				}
			}
		}
		return $text;
	}

	/**
	 * String fields.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param array<int,string>   $names Names.
	 * @return array<string,string>
	 */
	private function string_fields( array $source, array $names ): array {
		$result = array();
		foreach ( $names as $name ) {
			if ( is_string( $source[ $name ] ?? null ) && '' !== $source[ $name ] ) {
				$result[ $name ] = $source[ $name ];
			}
		}
		return $result;
	}

	/**
	 * Reject invalid rating consistency.
	 *
	 * @param array<int,array<string,string>> $dimensions Dimensions.
	 * @param array<int,array<string,mixed>>  $findings Findings.
	 * @param array<int,string>               $limitations Limitations.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_rating_consistency( array $dimensions, array $findings, array $limitations ): void {
		$by_dimension = array();
		foreach ( $findings as $finding ) {
			$by_dimension[ $finding['dimension_key'] ][] = $finding['severity'];
		}
		foreach ( $dimensions as $dimension ) {
			$rating     = $dimension['rating'];
			$severities = $by_dimension[ $dimension['dimension_key'] ] ?? array();
			$path       = '$.dimensions[' . $dimension['dimension_key'] . '].rating (consistency with findings/limitations)';
			if ( 'meets' === $rating && array() !== $severities ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path );
			}
			if ( 'minor_issue' === $rating && array() === array_intersect( $severities, array( 'info', 'minor' ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path );
			}
			if ( 'major_issue' === $rating && array() === array_intersect( $severities, array( 'major', 'critical' ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path );
			}
			if ( 'not_assessable' === $rating && array() === $limitations ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path );
			}
		}
	}

	/**
	 * Overall status.
	 *
	 * @param string                          $provider_status Provider status.
	 * @param array<int,array<string,string>> $dimensions Dimensions.
	 * @param array<int,array<string,mixed>>  $findings Findings.
	 * @param array<int,string>               $limitations Limitations.
	 * @param bool                            $safety_flagged Safety flagged.
	 * @return string
	 */
	private function overall_status( string $provider_status, array $dimensions, array $findings, array $limitations, bool $safety_flagged ): string {
		if ( 'abstained' === $provider_status ) {
			return 'not_assessable';
		}
		if ( $safety_flagged ) {
			return 'safety_review';
		}
		$severities = array_column( $findings, 'severity' );
		if ( array_intersect( $severities, array( 'critical', 'major' ) ) ) {
			return 'action_required';
		}
		$ratings = array_column( $dimensions, 'rating' );
		if ( count( array_filter( $ratings, static fn ( string $rating ): bool => 'not_assessable' === $rating ) ) === count( $ratings ) ) {
			return 'not_assessable';
		}
		if ( array() !== $findings || array() !== $limitations || 'partial' === $provider_status || array_diff( $ratings, array( 'meets' ) ) ) {
			return 'needs_attention';
		}
		return 'ready';
	}

	/**
	 * Mean confidence.
	 *
	 * @param array<int,string> $values Values.
	 * @return string
	 */
	private function mean_confidence( array $values ): string {
		$total = 0.0;
		foreach ( $values as $value ) {
			$total += (float) $value;
		}
		return number_format( round( $total / count( $values ), 4 ), 4, '.', '' );
	}

	/**
	 * Confidence.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function confidence( $value, string $path ): string {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path );
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || $number < 0 || $number > 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->domain_failure( $path );
		}
		return number_format( round( $number, 4 ), 4, '.', '' );
	}

	/**
	 * Text.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_newline Allow newline.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, string $path, int $maximum, bool $allow_newline ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path );
		}
		try {
			$value = $this->canonical->text( str_replace( array( "\r\n", "\r" ), "\n", trim( $value ) ) );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path );
		}
		$count = preg_match_all( '/./us', $value, $matches );
		if (
			'' === $value || false === $count || $count > $maximum
			|| ( ! $allow_newline && str_contains( $value, "\n" ) )
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path );
		}
		return $value;
	}

	/**
	 * Nullable text.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_newline Allow newline.
	 * @return string|null
	 */
	private function nullable_text( $value, string $path, int $maximum, bool $allow_newline ): ?string {
		return null === $value ? null : $this->text( $value, $path, $maximum, $allow_newline );
	}

	/**
	 * Text list.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $maximum_items Maximum items.
	 * @param int    $maximum_text Maximum text.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text_list( $value, string $path, int $maximum_items, int $maximum_text ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum_items ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path );
		}
		$result = array();
		foreach ( $value as $index => $item ) {
			$item_path = $path . '[' . $index . ']';
			$text      = $this->text( $item, $item_path, $maximum_text, true );
			if ( in_array( $text, $result, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $item_path . ' (duplicate)' );
			}
			$result[] = $text;
		}
		return $result;
	}

	/**
	 * Sha256.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value, string $path ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path );
		}
		return $value;
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @param string                 $path Input path for validation errors.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $value, array $expected, string $path ): void {
		if ( array() !== $value && array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (key set mismatch)' );
		}
	}

	/**
	 * Reject invalid no forbidden keys.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_no_forbidden_keys( $value, string $path ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		$forbidden = array(
			'chain_of_thought',
			'reasoning',
			'hidden_reasoning',
			'system_prompt',
			'prompt',
			'authorization',
			'secret',
			'api_key',
			'endpoint',
			'student_name',
			'learner_id',
			'approval',
			'publish',
			'publication_command',
			'document_patch',
			'raw_response',
		);
		foreach ( $value as $key => $item ) {
			$next = $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key );
			if ( is_string( $key ) ) {
				$normalized = strtolower( str_replace( '-', '_', $key ) );
				if ( in_array( $normalized, $forbidden, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Evaluation_Worker_Failure::because( Worksheet_Evaluation_Worker_Failure::OUTPUT_PRIVACY_INVALID, $next );
				}
			}
			$this->assert_no_forbidden_keys( $item, $next );
		}
	}

	/**
	 * Encode worksheet evaluation output normalizer.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function encode( $value ): string {
		try {
			return $this->canonical->encode( $value );
		} catch ( InvalidArgumentException ) {
			throw $this->schema_failure( '$ (not canonically encodable)' );
		}
	}

	/**
	 * Schema failure.
	 *
	 * @param string $path Path.
	 * @return Worksheet_Evaluation_Worker_Failure
	 */
	private function schema_failure( string $path = '$' ): Worksheet_Evaluation_Worker_Failure {
		return Worksheet_Evaluation_Worker_Failure::because( Worksheet_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, $path );
	}

	/**
	 * Domain failure.
	 *
	 * @param string $path Path.
	 * @return Worksheet_Evaluation_Worker_Failure
	 */
	private function domain_failure( string $path = '$' ): Worksheet_Evaluation_Worker_Failure {
		return Worksheet_Evaluation_Worker_Failure::because( Worksheet_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, $path );
	}
}
