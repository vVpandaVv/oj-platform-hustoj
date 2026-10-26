<?php
/**
 * Deterministic P01, P02, P03 and P05 publication projector.
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
 * Deterministic P01, P02, P03 and P05 publication projector.
 */
final class Worksheet_Publication_Projector {
	public const PROJECTOR_VERSION         = '1.0.0';
	public const GRADING_PROJECTOR_VERSION = '1.1.0';
	public const MANIFEST_VERSION          = '1.0.0';
	public const GRADING_SCHEMA            = 'oj_ai.grading_schema';
	public const GRADING_VERSION           = '1.1.0';

	/**
	 * Initialize worksheet publication projector dependencies and state.
	 *
	 * @param Canonical_Json                        $canonical Canonical JSON encoder used for stable digests.
	 * @param Worksheet_Preview_Projector           $previews Previews.
	 * @param Worksheet_Projection_Safety_Inspector $safety Safety.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Worksheet_Preview_Projector $previews,
		private Worksheet_Projection_Safety_Inspector $safety
	) {
	}

	/**
	 * Project worksheet publication projector.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @param array<string,mixed>          $configuration Configuration.
	 * @return Worksheet_Publication_Projection
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project(
		Canonical_Worksheet_Document $source,
		array $configuration
	): Worksheet_Publication_Projection {
		$student    = $this->previews->student( $source );
		$print      = $this->previews->print( $source, 'student_blank' );
		$inspection = $this->safety->inspect( $source, $student, $print );
		if ( true !== ( $inspection['ready'] ) || array() !== ( $inspection['blockers'] ) ) {
			throw new InvalidArgumentException( 'Worksheet publication safety inspection failed.' );
		}

		$student_projection = $student->projection();
		$print_projection   = $print->projection();
		$student_document   = $this->object( $student_projection['student_document'] ?? null, 'student document' );
		$response_schema    = $this->object( $student_projection['response_schema'] ?? null, 'response schema' );
		$print_envelope     = $this->object( $print_projection['print_projection'] ?? null, 'print projection' );
		$grading_schema     = $this->grading_envelope( $source, $response_schema, $configuration );
		$profiles           = array(
			'grading_schema'   => $grading_schema,
			'print_projection' => $print_envelope,
			'response_schema'  => $response_schema,
			'student_document' => $student_document,
		);

		$manifest_profiles = array();
		foreach ( $profiles as $name => $profile ) {
			$this->assert_envelope( $profile, $source->document_hash() );
			$manifest_profiles[ $name ] = array(
				'payload_hash'      => $profile['payload_hash'],
				'projector_version' => $profile['projector_version'],
				'schema_id'         => $profile['schema_id'],
				'schema_version'    => $profile['schema_version'],
			);
		}
		$manifest = array(
			'manifest_version'     => self::MANIFEST_VERSION,
			'profiles'             => $manifest_profiles,
			'source_revision_hash' => $source->document_hash(),
		);

		return new Worksheet_Publication_Projection(
			$profiles,
			$manifest,
			self::MANIFEST_VERSION,
			hash( 'sha256', $this->canonical->encode( $manifest ) )
		);
	}

	/**
	 * Grading envelope.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @param array<string,mixed>          $response_schema Response schema.
	 * @param array<string,mixed>          $configuration Configuration.
	 * @return array<string,mixed>
	 */
	private function grading_envelope(
		Canonical_Worksheet_Document $source,
		array $response_schema,
		array $configuration
	): array {
		$this->assert_keys( $configuration, array( 'guard', 'policy', 'review_rule', 'rubric' ) );
		$policy = $this->pin( $configuration['policy'] ?? null, 'policy', true );
		$rubric = $this->rubric( $configuration['rubric'] ?? null );
		Worksheet_Preparation::assert_ready( $source->document(), $rubric );
		$review         = $this->review_rule( $configuration['review_rule'] ?? null );
		$response_ids   = $this->response_ids( $response_schema );
		$binding_result = $this->bindings( $source->document(), $response_ids, $rubric, $source->document_hash() );
		$payload        = array(
			'bindings'    => $binding_result['bindings'],
			'policy'      => array(
				'content_hash' => $policy['content_hash'],
				'revision_id'  => $policy['revision_id'],
			),
			'review_rule' => array(
				'content_hash'              => $review['content_hash'],
				'explicit_release_required' => true,
				'review_required'           => true,
				'revision_id'               => $review['revision_id'],
			),
			'rubric'      => array(
				'content_hash' => $rubric['content_hash'],
				'revision_id'  => $rubric['revision_id'],
				'total_points' => $binding_result['total_points'],
			),
		);

		return $this->envelope(
			self::GRADING_SCHEMA,
			self::GRADING_VERSION,
			$source->document_hash(),
			$payload,
			self::GRADING_PROJECTOR_VERSION
		);
	}

	/**
	 * Pin.
	 *
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @param bool   $subject Subject.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pin( $value, string $label, bool $subject = false ): array {
		$value = $this->object( $value, $label );
		$keys  = $subject ? array( 'content_hash', 'revision_id', 'subject_type' ) : array( 'content_hash', 'revision_id' );
		$this->assert_keys( $value, $keys );
		$this->public_id( $value['revision_id'] ?? null, $label . ' revision' );
		$this->hash( $value['content_hash'] ?? null, $label . ' hash' );
		if ( $subject && 'student_submission' !== ( $value['subject_type'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet publication policy subject is invalid.' );
		}
		return $value;
	}

	/**
	 * Review rule.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_rule( $value ): array {
		$value = $this->object( $value, 'review rule' );
		$this->assert_keys( $value, array( 'content_hash', 'explicit_release_required', 'review_required', 'revision_id' ) );
		$this->public_id( $value['revision_id'] ?? null, 'review rule revision' );
		$this->hash( $value['content_hash'] ?? null, 'review rule hash' );
		if ( true !== ( $value['review_required'] ?? null ) || true !== ( $value['explicit_release_required'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet publication review rule is unsafe.' );
		}
		return $value;
	}

	/**
	 * Rubric.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rubric( $value ): array {
		$value = $this->object( $value, 'rubric' );
		$this->assert_keys( $value, array( 'content_hash', 'criteria', 'revision_id', 'total_points' ) );
		$this->public_id( $value['revision_id'] ?? null, 'rubric revision' );
		$this->hash( $value['content_hash'] ?? null, 'rubric hash' );
		$criteria = $value['criteria'] ?? null;
		if ( ! is_array( $criteria ) || ! array_is_list( $criteria ) ) {
			throw new InvalidArgumentException( 'Worksheet publication rubric criteria are invalid.' );
		}
		$normalized = array();
		$ids        = array();
		$total      = 0;
		foreach ( $criteria as $index => $criterion ) {
			$criterion       = $this->object( $criterion, 'criterion' );
			$has_levels      = array_key_exists( 'levels', $criterion );
			$has_description = array_key_exists( 'description', $criterion );
			$keys            = array( 'criterion_id', 'label', 'maximum_points', 'position', 'scope' );
			if ( $has_levels ) {
				$keys[] = 'levels';
			}
			if ( $has_description ) {
				$keys[] = 'description';
			}
			$this->assert_keys( $criterion, $keys );
			$id    = $this->public_id( $criterion['criterion_id'] ?? null, 'criterion ID' );
			$scope = $criterion['scope'] ?? null;
			if ( isset( $ids[ $id ] ) || ! in_array( $scope, array( 'response', 'submission' ), true ) ) {
				throw new InvalidArgumentException( 'Worksheet publication criterion identity is invalid.' );
			}
			$ids[ $id ] = true;
			if ( ! is_string( $criterion['label'] ?? null ) || '' === trim( $criterion['label'] ) || $index !== ( $criterion['position'] ?? null ) ) {
				throw new InvalidArgumentException( 'Worksheet publication criterion metadata is invalid.' );
			}
			if ( $has_description ) {
				$description = is_string( $criterion['description'] ?? null ) ? trim( $criterion['description'] ) : '';
				$length      = preg_match_all( '/./us', $description, $matches );
				if ( '' === $description || false === $length || $length > 1000 ) {
					throw new InvalidArgumentException( 'Worksheet publication criterion description is invalid.' );
				}
				$criterion['description'] = $description;
			}
			$points                      = $this->decimal_units( $criterion['maximum_points'] ?? null, false );
			$total                      += $points;
			$criterion['maximum_points'] = $this->decimal_string( $points );
			if ( $has_levels ) {
				$criterion['levels'] = $this->criterion_levels( $criterion['levels'] ?? null, $points );
			}
			$normalized[] = $criterion;
		}
		$expected_total = $this->decimal_units( $value['total_points'] ?? null, true );
		if ( $total !== $expected_total ) {
			throw new InvalidArgumentException( 'Worksheet publication rubric total is invalid.' );
		}
		$value['criteria']     = $normalized;
		$value['total_points'] = $this->decimal_string( $expected_total );
		return $value;
	}

	/**
	 * Criterion levels.
	 *
	 * @param mixed $raw Raw.
	 * @param int   $maximum_units Maximum units.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function criterion_levels( $raw, int $maximum_units ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) < 2 || count( $raw ) > 10 ) {
			throw new InvalidArgumentException( 'Worksheet publication criterion levels are invalid.' );
		}
		$result      = array();
		$seen_ids    = array();
		$seen_points = array();
		$max_units   = 0;
		foreach ( $raw as $position => $level ) {
			$level = $this->object( $level, 'criterion level' );
			$this->assert_keys( $level, array( 'label', 'level_key', 'points', 'position' ) );
			$id = $this->public_id( $level['level_key'] ?? null, 'criterion level key' );
			if ( isset( $seen_ids[ $id ] ) || $position !== ( $level['position'] ?? null ) ) {
				throw new InvalidArgumentException( 'Worksheet publication criterion level identity is invalid.' );
			}
			if ( ! is_string( $level['label'] ?? null ) || '' === trim( $level['label'] ) ) {
				throw new InvalidArgumentException( 'Worksheet publication criterion level label is invalid.' );
			}
			$units = $this->decimal_units( $level['points'] ?? null, true );
			if ( isset( $seen_points[ $units ] ) ) {
				throw new InvalidArgumentException( 'Worksheet publication criterion level points must be unique.' );
			}
			$seen_ids[ $id ]       = true;
			$seen_points[ $units ] = true;
			$max_units             = max( $max_units, $units );
			$result[]              = array(
				'label'     => $level['label'],
				'level_key' => $id,
				'points'    => $this->decimal_string( $units ),
				'position'  => $position,
			);
		}
		if ( $max_units !== $maximum_units ) {
			throw new InvalidArgumentException( 'Worksheet publication criterion levels do not match its maximum points.' );
		}
		return $result;
	}

	/**
	 * Bindings.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param array<int,string>   $response_ids Response ids.
	 * @param array<string,mixed> $rubric Rubric.
	 * @param string              $source_revision_hash Source revision hash.
	 * @return array{bindings:array<int,array<string,mixed>>,total_points:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bindings( array $document, array $response_ids, array $rubric, string $source_revision_hash ): array {
		$grading_by_response = array();
		$this->collect_grading( $document['nodes'] ?? null, $grading_by_response );
		$actual_response_ids = array_keys( $grading_by_response );
		sort( $actual_response_ids, SORT_STRING );
		$expected_response_ids = $response_ids;
		sort( $expected_response_ids, SORT_STRING );
		if ( $actual_response_ids !== $expected_response_ids ) {
			throw new InvalidArgumentException( 'Worksheet publication response identity drifted.' );
		}

		$settings = $document['settings'] ?? null;
		if ( ! is_array( $settings ) || ! array_key_exists( 'assessment_bindings', $settings ) ) {
			$bindings = $this->legacy_bindings( $grading_by_response, $rubric, $source_revision_hash );
			$total    = 0;
			foreach ( $bindings as $binding ) {
				$total += $this->decimal_units( $binding['maximum_points'], false );
			}
			return array(
				'bindings'     => $bindings,
				'total_points' => $this->decimal_string( $total ),
			);
		}

		$raw = $settings['assessment_bindings'];
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > Worksheet_Document_Profile::MAX_ASSESSMENT_BINDINGS ) {
			throw new InvalidArgumentException( 'Worksheet publication assessment bindings are invalid.' );
		}
		$criteria = array();
		foreach ( $rubric['criteria'] as $criterion ) {
			$criteria[ $criterion['criterion_id'] ] = $criterion;
		}
		$response_lookup = array_fill_keys( $response_ids, true );
		$used            = array();
		$bindings        = array();
		$total           = 0;
		foreach ( $raw as $position => $binding ) {
			$binding = $this->object( $binding, 'assessment binding' );
			$this->assert_keys( $binding, array( 'criterion_id', 'scope', 'response_id' ) );
			$criterion_id = $this->public_id( $binding['criterion_id'] ?? null, 'assessment criterion ID' );
			$scope        = $binding['scope'] ?? null;
			if ( isset( $used[ $criterion_id ] ) || ! isset( $criteria[ $criterion_id ] ) || ! in_array( $scope, array( 'worksheet', 'response' ), true ) ) {
				throw new InvalidArgumentException( 'Worksheet publication assessment binding is invalid.' );
			}
			$response_id     = null;
			$effective_scope = 'submission';
			$grading         = array();
			if ( 'response' === $scope ) {
				$response_id = $this->public_id( $binding['response_id'] ?? null, 'assessment response ID' );
				if ( ! isset( $response_lookup[ $response_id ] ) || ! isset( $grading_by_response[ $response_id ] ) ) {
					throw new InvalidArgumentException( 'Worksheet publication assessment response is invalid.' );
				}
				$effective_scope = 'response';
				$grading         = $grading_by_response[ $response_id ];
			} elseif ( null !== ( $binding['response_id'] ?? null ) ) {
				throw new InvalidArgumentException( 'Worksheet publication worksheet binding target is invalid.' );
			}
			$criterion = $criteria[ $criterion_id ];
			$points    = $this->decimal_units( $criterion['maximum_points'], false );
			$total    += $points;
			$item      = array(
				'binding_key'    => $this->binding_key( $source_revision_hash, $rubric['content_hash'], $effective_scope, $response_id, $criterion_id ),
				'criterion_id'   => $criterion_id,
				'label'          => $criterion['label'],
				'maximum_points' => $criterion['maximum_points'],
				'position'       => $position,
				'response_id'    => $response_id,
				'scope'          => $effective_scope,
			);
			if ( array_key_exists( 'levels', $criterion ) ) {
				$item['levels'] = $criterion['levels'];
			}
			foreach ( array( 'correct_answer', 'example_answer' ) as $answer ) {
				if ( array_key_exists( $answer, $grading ) ) {
					$item[ $answer ] = $grading[ $answer ];
				}
			}
			$used[ $criterion_id ] = true;
			$bindings[]            = $item;
		}
		return array(
			'bindings'     => $bindings,
			'total_points' => $this->decimal_string( $total ),
		);
	}

	/**
	 * Legacy bindings.
	 *
	 * @param array<string,array<string,mixed>> $grading_by_response Grading by response.
	 * @param array<string,mixed>               $rubric Rubric.
	 * @param string                            $source_revision_hash Source revision hash.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function legacy_bindings( array $grading_by_response, array $rubric, string $source_revision_hash ): array {
		$criteria = array();
		foreach ( $rubric['criteria'] as $criterion ) {
			$criteria[ $criterion['criterion_id'] ] = $criterion;
		}
		$by_position = array();
		$used        = array();
		foreach ( $grading_by_response as $response_id => $grading ) {
			$enabled       = true === ( $grading['grading_enabled'] ?? false );
			$criterion_ids = $grading['rubric_criterion_ids'] ?? array();
			if ( ! is_array( $criterion_ids ) || ! array_is_list( $criterion_ids ) ) {
				throw new InvalidArgumentException( 'Worksheet publication grading criteria are invalid.' );
			}
			if ( ! $enabled ) {
				if ( array() !== $criterion_ids ) {
					throw new InvalidArgumentException( 'Disabled grading cannot bind rubric criteria.' );
				}
				continue;
			}
			if ( array() === $criterion_ids ) {
				throw new InvalidArgumentException( 'Enabled grading requires rubric criteria.' );
			}
			$response_total = 0;
			foreach ( $criterion_ids as $criterion_id ) {
				if ( ! is_string( $criterion_id ) || ! isset( $criteria[ $criterion_id ] ) || isset( $used[ $criterion_id ] ) ) {
					throw new InvalidArgumentException( 'Worksheet publication grading criterion is invalid.' );
				}
				$criterion = $criteria[ $criterion_id ];
				if ( 'response' !== $criterion['scope'] ) {
					throw new InvalidArgumentException( 'Worksheet publication criterion scope is invalid.' );
				}
				$used[ $criterion_id ] = true;
				$points                = $this->decimal_units( $criterion['maximum_points'], false );
				$response_total       += $points;
				$binding               = array(
					'binding_key'    => $this->binding_key( $source_revision_hash, $rubric['content_hash'], 'response', $response_id, $criterion_id ),
					'criterion_id'   => $criterion_id,
					'label'          => $criterion['label'],
					'maximum_points' => $criterion['maximum_points'],
					'position'       => 0,
					'response_id'    => $response_id,
					'scope'          => 'response',
				);
				if ( array_key_exists( 'levels', $criterion ) ) {
					$binding['levels'] = $criterion['levels'];
				}
				foreach ( array( 'correct_answer', 'example_answer' ) as $answer ) {
					if ( array_key_exists( $answer, $grading ) ) {
						$binding[ $answer ] = $grading[ $answer ];
					}
				}
				$by_position[ (int) $criterion['position'] ] = $binding;
			}
			if ( $response_total !== $this->decimal_units( $grading['max_points'] ?? null, true ) ) {
				throw new InvalidArgumentException( 'Worksheet publication response points are invalid.' );
			}
		}
		foreach ( $criteria as $criterion_id => $criterion ) {
			if ( isset( $used[ $criterion_id ] ) ) {
				continue;
			}
			if ( 'submission' !== $criterion['scope'] ) {
				throw new InvalidArgumentException( 'Worksheet publication rubric criterion is unbound.' );
			}
			$used[ $criterion_id ] = true;
			$submission_binding    = array(
				'binding_key'    => $this->binding_key( $source_revision_hash, $rubric['content_hash'], 'submission', null, $criterion_id ),
				'criterion_id'   => $criterion_id,
				'label'          => $criterion['label'],
				'maximum_points' => $criterion['maximum_points'],
				'position'       => 0,
				'response_id'    => null,
				'scope'          => 'submission',
			);
			if ( array_key_exists( 'levels', $criterion ) ) {
				$submission_binding['levels'] = $criterion['levels'];
			}
			$by_position[ (int) $criterion['position'] ] = $submission_binding;
		}
		ksort( $by_position, SORT_NUMERIC );
		$bindings = array_values( $by_position );
		foreach ( $bindings as $position => &$binding ) {
			$binding['position'] = $position;
		}
		unset( $binding );
		if ( count( $bindings ) !== count( $criteria ) ) {
			throw new InvalidArgumentException( 'Worksheet publication binding coverage is invalid.' );
		}
		return $bindings;
	}
	/**
	 * Binding key.
	 *
	 * @param string      $source_revision_hash Source revision hash.
	 * @param string      $rubric_hash Rubric hash.
	 * @param string      $scope Scope.
	 * @param string|null $response_id Response id.
	 * @param string      $criterion_id Criterion id.
	 * @return string
	 */
	private function binding_key(
		string $source_revision_hash,
		string $rubric_hash,
		string $scope,
		?string $response_id,
		string $criterion_id
	): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'criterion_id'         => $criterion_id,
					'response_id'          => $response_id,
					'rubric_hash'          => $rubric_hash,
					'scope'                => $scope,
					'source_revision_hash' => $source_revision_hash,
				)
			)
		);
	}

	/**
	 * Collect grading.
	 *
	 * @param mixed                             $nodes Nodes.
	 * @param array<string,array<string,mixed>> &$grading Grading.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function collect_grading( $nodes, array &$grading ): void {
		if ( ! is_array( $nodes ) || ! array_is_list( $nodes ) ) {
			throw new InvalidArgumentException( 'Worksheet publication nodes are invalid.' );
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || array_is_list( $node ) ) {
				throw new InvalidArgumentException( 'Worksheet publication node is invalid.' );
			}
			if ( array_key_exists( 'text', $node ) ) {
				continue;
			}
			if ( Worksheet_Document_Profile::RESPONSE_NODE_TYPE === ( $node['type'] ?? null ) ) {
				$response_id = $this->public_id( $node['response_id'] ?? null, 'response ID' );
				if ( isset( $grading[ $response_id ] ) ) {
					throw new InvalidArgumentException( 'Worksheet publication response is duplicated.' );
				}
				$value                   = $node['grading'] ?? array(
					'grading_enabled'      => false,
					'rubric_criterion_ids' => array(),
				);
				$grading[ $response_id ] = $this->object( $value, 'grading' );
			}
			if ( array_key_exists( 'children', $node ) ) {
				$this->collect_grading( $node['children'], $grading );
			}
		}
	}

	/**
	 * Response ids.
	 *
	 * @param array<string,mixed> $response_schema Response schema.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response_ids( array $response_schema ): array {
		$payload = $this->object( $response_schema['payload'] ?? null, 'response payload' );
		$items   = $payload['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new InvalidArgumentException( 'Worksheet publication response items are invalid.' );
		}
		$ids = array();
		foreach ( $items as $item ) {
			$item = $this->object( $item, 'response item' );
			$id   = $this->public_id( $item['response_id'] ?? null, 'response item ID' );
			if ( isset( $ids[ $id ] ) ) {
				throw new InvalidArgumentException( 'Worksheet publication response item is duplicated.' );
			}
			$ids[ $id ] = true;
		}
		return array_keys( $ids );
	}

	/**
	 * Reject invalid envelope.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @param string              $source_hash Source hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_envelope( array $profile, string $source_hash ): void {
		$this->assert_keys( $profile, array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' ) );
		$payload = $this->object( $profile['payload'] ?? null, 'profile payload' );
		if (
			! is_string( $profile['schema_id'] ?? null )
			|| ! is_string( $profile['schema_version'] ?? null )
			|| ! is_string( $profile['projector_version'] ?? null )
			|| ! hash_equals( $source_hash, (string) ( $profile['source_hash'] ?? '' ) )
			|| ! hash_equals( hash( 'sha256', $this->canonical->encode( $payload ) ), (string) ( $profile['payload_hash'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Worksheet publication envelope is invalid.' );
		}
	}

	/**
	 * Envelope.
	 *
	 * @param string              $schema Schema.
	 * @param string              $version Version.
	 * @param string              $source_hash Source hash.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param string              $projector_version Projector version.
	 * @return array<string,mixed>
	 */
	private function envelope(
		string $schema,
		string $version,
		string $source_hash,
		array $payload,
		string $projector_version = self::PROJECTOR_VERSION
	): array {
		return array(
			'payload'           => $payload,
			'payload_hash'      => hash( 'sha256', $this->canonical->encode( $payload ) ),
			'projector_version' => $projector_version,
			'schema_id'         => $schema,
			'schema_version'    => $version,
			'source_hash'       => $source_hash,
		);
	}

	/**
	 * Object.
	 *
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function object( $value, string $label ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet publication ' . $label . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		return $value;
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
			throw new InvalidArgumentException( 'Worksheet publication object keys are invalid.' );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value, string $label ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet publication ' . $label . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Hash.
	 *
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( $value, string $label ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet publication ' . $label . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		return $value;
	}

	/**
	 * Decimal units.
	 *
	 * @param mixed $value Value.
	 * @param bool  $allow_zero Allow zero.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_units( $value, bool $allow_zero ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Worksheet publication decimal is invalid.' );
		}
		$units = (int) $matches[1] * 10000 + (int) str_pad( $matches[2] ?? '', 4, '0' );
		if ( ! $allow_zero && $units < 1 ) {
			throw new InvalidArgumentException( 'Worksheet publication points must be positive.' );
		}
		return $units;
	}

	/**
	 * Decimal string.
	 *
	 * @param int $units Units.
	 * @return string
	 */
	private function decimal_string( int $units ): string {
		$whole    = intdiv( $units, 10000 );
		$fraction = rtrim( str_pad( (string) ( $units % 10000 ), 4, '0', STR_PAD_LEFT ), '0' );
		return '' === $fraction ? (string) $whole : $whole . '.' . $fraction;
	}
}
