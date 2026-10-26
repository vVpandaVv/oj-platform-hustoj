<?php
/**
 * Historical-only worksheet publication projector for legacy assignment restoration.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Worksheet_Document;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Publication_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Publication_Projector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a deterministic retired publication without inventing active configuration records.
 */
final class Legacy_Migration_Publication_Projector {
	public const PROJECTOR_VERSION     = 'legacy-migration@1.0.0';
	public const CONFIGURATION_PROFILE = 'oj_ai.legacy_migration_configuration@1.0.0';

	/**
	 * Previews.
	 *
	 * @var Worksheet_Preview_Projector
	 */
	private Worksheet_Preview_Projector $previews;
	/**
	 * Safety.
	 *
	 * @var Worksheet_Projection_Safety_Inspector
	 */
	private Worksheet_Projection_Safety_Inspector $safety;

	/**
	 * Initialize legacy migration publication projector dependencies and state.
	 *
	 * @param Canonical_Json                             $canonical Canonical JSON encoder used for stable digests.
	 * @param Worksheet_Preview_Projector|null           $previews Previews.
	 * @param Worksheet_Projection_Safety_Inspector|null $safety Safety.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		?Worksheet_Preview_Projector $previews = null,
		?Worksheet_Projection_Safety_Inspector $safety = null
	) {
		$this->previews = $previews ?? new Worksheet_Preview_Projector( $canonical );
		$this->safety   = $safety ?? new Worksheet_Projection_Safety_Inspector( $canonical );
	}

	/**
	 * Project legacy migration publication projector.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @param string                       $revision_public_id Revision public id.
	 * @return Worksheet_Publication_Projection
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project(
		Canonical_Worksheet_Document $source,
		string $revision_public_id
	): Worksheet_Publication_Projection {
		if ( ! Public_Id::is_valid( $revision_public_id ) ) {
			throw new InvalidArgumentException( 'Legacy migration publication revision ID is invalid.' );
		}

		$student    = $this->previews->student( $source );
		$print      = $this->previews->print( $source, 'student_blank' );
		$inspection = $this->safety->inspect( $source, $student, $print );
		if ( true !== ( $inspection['ready'] ) || array() !== ( $inspection['blockers'] ) ) {
			throw new InvalidArgumentException( 'Legacy migration publication safety inspection failed.' );
		}

		$student_projection = $student->projection();
		$print_projection   = $print->projection();
		$student_document   = $this->object( $student_projection['student_document'] ?? null, 'student document' );
		$response_schema    = $this->object( $student_projection['response_schema'] ?? null, 'response schema' );
		$print_envelope     = $this->object( $print_projection['print_projection'] ?? null, 'print projection' );
		$grading_schema     = $this->grading_envelope( $source, $response_schema, $revision_public_id );
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
			'manifest_version'     => Worksheet_Publication_Projector::MANIFEST_VERSION,
			'profiles'             => $manifest_profiles,
			'source_revision_hash' => $source->document_hash(),
		);

		return new Worksheet_Publication_Projection(
			$profiles,
			$manifest,
			Worksheet_Publication_Projector::MANIFEST_VERSION,
			hash( 'sha256', $this->canonical->encode( $manifest ) )
		);
	}

	/**
	 * Grading envelope.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @param array<string,mixed>          $response_schema Response schema.
	 * @param string                       $revision_public_id Revision public id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function grading_envelope(
		Canonical_Worksheet_Document $source,
		array $response_schema,
		string $revision_public_id
	): array {
		$items   = $this->response_items( $response_schema );
		$grading = array();
		$this->collect_grading( $source->document()['nodes'] ?? null, $grading );
		if ( array_keys( $items ) !== array_keys( $grading ) ) {
			throw new InvalidArgumentException( 'Legacy migration publication response identity drifted.' );
		}

		$criteria    = array();
		$total_units = 0;
		foreach ( $items as $response_id => $item ) {
			$rule = $grading[ $response_id ];
			if ( true !== ( $rule['grading_enabled'] ?? false ) ) {
				continue;
			}
			$points       = $this->decimal_units( $rule['max_points'] ?? null, false );
			$total_units += $points;
			$criteria[]   = array(
				'criterion_id'   => $response_id,
				'label'          => $this->label( $item['label'] ?? null ),
				'maximum_points' => $this->decimal_string( $points ),
				'position'       => count( $criteria ),
				'response_id'    => $response_id,
				'scope'          => 'response',
			);
		}

		$total_points  = $this->decimal_string( $total_units );
		$rubric_source = array(
			'criteria'             => $criteria,
			'profile'              => self::CONFIGURATION_PROFILE,
			'source_revision_hash' => $source->document_hash(),
			'total_points'         => $total_points,
		);
		$rubric_hash   = hash( 'sha256', $this->canonical->encode( $rubric_source ) );
		$bindings      = array();
		foreach ( $criteria as $criterion ) {
			$response_id = (string) $criterion['response_id'];
			$rule        = $grading[ $response_id ];
			$binding     = array(
				'binding_key'    => $this->binding_key( $source->document_hash(), $rubric_hash, $response_id ),
				'criterion_id'   => $criterion['criterion_id'],
				'label'          => $criterion['label'],
				'maximum_points' => $criterion['maximum_points'],
				'position'       => $criterion['position'],
				'response_id'    => $response_id,
				'scope'          => 'response',
			);
			foreach ( array( 'correct_answer', 'example_answer' ) as $answer ) {
				if ( array_key_exists( $answer, $rule ) ) {
					$binding[ $answer ] = $rule[ $answer ];
				}
			}
			$bindings[] = $binding;
		}

		$payload = array(
			'bindings'    => $bindings,
			'policy'      => array(
				'content_hash' => $this->configuration_hash( 'policy', $source->document_hash() ),
				'revision_id'  => $revision_public_id,
			),
			'review_rule' => array(
				'content_hash'              => $this->configuration_hash( 'review_rule', $source->document_hash() ),
				'explicit_release_required' => true,
				'review_required'           => true,
				'revision_id'               => $revision_public_id,
			),
			'rubric'      => array(
				'content_hash' => $rubric_hash,
				'revision_id'  => $revision_public_id,
				'total_points' => $total_points,
			),
		);

		return $this->envelope(
			Worksheet_Publication_Projector::GRADING_SCHEMA,
			Worksheet_Publication_Projector::GRADING_VERSION,
			$source->document_hash(),
			$payload,
			self::PROJECTOR_VERSION
		);
	}

	/**
	 * Response items.
	 *
	 * @param array<string,mixed> $response_schema Response schema.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response_items( array $response_schema ): array {
		$payload = $this->object( $response_schema['payload'] ?? null, 'response payload' );
		$items   = $payload['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new InvalidArgumentException( 'Legacy migration response schema is invalid.' );
		}
		$result = array();
		foreach ( $items as $item ) {
			$item        = $this->object( $item, 'response item' );
			$response_id = $this->public_id( $item['response_id'] ?? null );
			if ( isset( $result[ $response_id ] ) ) {
				throw new InvalidArgumentException( 'Legacy migration response is duplicated.' );
			}
			$result[ $response_id ] = $item;
		}
		return $result;
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
			throw new InvalidArgumentException( 'Legacy migration worksheet nodes are invalid.' );
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || array_is_list( $node ) ) {
				throw new InvalidArgumentException( 'Legacy migration worksheet node is invalid.' );
			}
			if ( array_key_exists( 'text', $node ) ) {
				continue;
			}
			if ( Worksheet_Document_Profile::RESPONSE_NODE_TYPE === ( $node['type'] ?? null ) ) {
				$response_id = $this->public_id( $node['response_id'] ?? null );
				if ( isset( $grading[ $response_id ] ) ) {
					throw new InvalidArgumentException( 'Legacy migration grading response is duplicated.' );
				}
				$rule                    = $node['grading'] ?? array( 'grading_enabled' => false );
				$grading[ $response_id ] = $this->object( $rule, 'grading rule' );
			}
			if ( array_key_exists( 'children', $node ) ) {
				$this->collect_grading( $node['children'], $grading );
			}
		}
	}

	/**
	 * Configuration hash.
	 *
	 * @param string $kind Kind.
	 * @param string $source_hash Source hash.
	 * @return string
	 */
	private function configuration_hash( string $kind, string $source_hash ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'kind'                 => $kind,
					'profile'              => self::CONFIGURATION_PROFILE,
					'source_revision_hash' => $source_hash,
				)
			)
		);
	}

	/**
	 * Binding key.
	 *
	 * @param string $source_hash Source hash.
	 * @param string $rubric_hash Rubric hash.
	 * @param string $response_id Response id.
	 * @return string
	 */
	private function binding_key( string $source_hash, string $rubric_hash, string $response_id ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'criterion_id'         => $response_id,
					'response_id'          => $response_id,
					'rubric_hash'          => $rubric_hash,
					'scope'                => 'response',
					'source_revision_hash' => $source_hash,
				)
			)
		);
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
		$expected = array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' );
		$actual   = array_keys( $profile );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		$payload = $profile['payload'] ?? null;
		if (
			$actual !== $expected || ! is_array( $payload ) || array_is_list( $payload )
			|| ! hash_equals( $source_hash, (string) ( $profile['source_hash'] ?? '' ) )
			|| ! hash_equals( hash( 'sha256', $this->canonical->encode( $payload ) ), (string) ( $profile['payload_hash'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Legacy migration publication envelope is invalid.' );
		}
	}

	/**
	 * Envelope.
	 *
	 * @param string              $schema Schema.
	 * @param string              $version Version.
	 * @param string              $source_hash Source hash.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param string              $projector Projector.
	 * @return array<string,mixed>
	 */
	private function envelope( string $schema, string $version, string $source_hash, array $payload, string $projector ): array {
		return array(
			'payload'           => $payload,
			'payload_hash'      => hash( 'sha256', $this->canonical->encode( $payload ) ),
			'projector_version' => $projector,
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
			throw new InvalidArgumentException( 'Legacy migration ' . $label . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		return $value;
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Legacy migration response ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Label.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function label( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 255 ) {
			throw new InvalidArgumentException( 'Legacy migration grading label is invalid.' );
		}
		return $this->canonical->text( $value );
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
			throw new InvalidArgumentException( 'Legacy migration publication decimal is invalid.' );
		}
		$units = (int) $matches[1] * 10000 + (int) str_pad( $matches[2] ?? '', 4, '0' );
		if ( ! $allow_zero && $units < 1 ) {
			throw new InvalidArgumentException( 'Legacy migration publication points must be positive.' );
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
