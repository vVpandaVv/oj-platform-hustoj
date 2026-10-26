<?php
/**
 * Converts frozen 0.1.2 answers into the current student response profile.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects only answer data; attachment bytes remain deferred to D3-D7.
 */
final class Legacy_Migration_Submission_Response_Projector {
	private const RESPONSE_ALIASES = array(
		'short'          => 'short_text',
		'short_answer'   => 'short_text',
		'text'           => 'short_text',
		'textarea'       => 'long_text',
		'long_answer'    => 'long_text',
		'essay'          => 'long_text',
		'select'         => 'single_choice',
		'radio'          => 'single_choice',
		'choice'         => 'single_choice',
		'single_select'  => 'single_choice',
		'checkbox_group' => 'multiple_choice',
		'checklist'      => 'multiple_choice',
		'multi_select'   => 'multiple_choice',
		'image'          => 'image_upload',
		'file'           => 'file_upload',
		'paint'          => 'drawing',
	);

	/**
	 * Validator.
	 *
	 * @var Submission_Response_Validator
	 */
	private Submission_Response_Validator $validator;

	/**
	 * Initialize legacy migration submission response projector dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
		$this->validator = new Submission_Response_Validator( $canonical );
	}

	/**
	 * Project legacy migration submission response projector.
	 *
	 * @param array<string,mixed>                                                              $profile Profile.
	 * @param array<string,mixed>                                                              $content Content.
	 * @param array<string,array{response_id:string,kind:string,question:array<string,mixed>}> $bindings Bindings.
	 * @return array{answers:array<string,mixed>,complete:bool,deferred_attachment:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project( array $profile, array $content, array $bindings ): array {
		$items = $this->profile_items( $profile );
		$this->assert_bindings( $items, $bindings );
		$engine = $content['engine'] ?? null;
		if ( 'zip_upload_submission' === $engine ) {
			$this->exact_keys( $content, array( 'answers', 'engine', 'files', 'version', 'zip_upload' ) );
			if (
				1 !== ( $content['version'] ?? null )
				|| ! is_array( $content['answers'] ?? null ) || array() !== $content['answers']
				|| ! is_array( $content['files'] ?? null ) || ! array_is_list( $content['files'] )
				|| ! is_array( $content['zip_upload'] ?? null ) || ( array() !== $content['zip_upload'] && array_is_list( $content['zip_upload'] ) )
			) {
				throw new InvalidArgumentException( 'Legacy ZIP response is invalid.' );
			}
			try {
				$this->validator->validate( $profile, array() );
			} catch ( Assignment_Failure $failure ) {
				throw new InvalidArgumentException( 'Current response profile is invalid.', 0, $failure ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
			}
			return array(
				'answers'             => array(),
				'complete'            => false,
				'deferred_attachment' => true,
			);
		}
		if ( 'worksheet_student_answers' !== $engine ) {
			throw new InvalidArgumentException( 'Legacy response engine is unsupported.' );
		}
		$this->exact_keys( $content, array( 'answers', 'engine', 'version' ) );
		if ( 1 !== ( $content['version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Legacy response version is invalid.' );
		}
		$source_answers = $content['answers'] ?? null;
		if ( ! is_array( $source_answers ) || ( array() !== $source_answers && array_is_list( $source_answers ) ) ) {
			throw new InvalidArgumentException( 'Legacy response answers are invalid.' );
		}

		$answers  = array();
		$deferred = false;
		foreach ( $source_answers as $legacy_question_id => $answer ) {
			$legacy_question_id = (string) $legacy_question_id;
			$binding            = $bindings[ $legacy_question_id ] ?? null;
			if ( ! is_array( $binding ) || ! is_array( $answer ) || array_is_list( $answer ) ) {
				throw new InvalidArgumentException( 'Legacy response question is invalid.' );
			}
			$this->exact_keys( $answer, array( 'block_id', 'question_id', 'type', 'value' ) );
			if ( ! hash_equals( $legacy_question_id, (string) ( $answer['question_id'] ?? '' ) ) ) {
				throw new InvalidArgumentException( 'Legacy response question identity drifted.' );
			}
			$kind = $this->kind( $answer['type'] ?? null );
			if ( ! hash_equals( $binding['kind'], $kind ) ) {
				throw new InvalidArgumentException( 'Legacy response kind drifted.' );
			}
			$response_id = $binding['response_id'];
			$item        = $items[ $response_id ];
			$value       = $answer['value'];
			if ( null === $value ) {
				continue;
			}
			if ( in_array( $kind, array( 'image_upload', 'file_upload', 'drawing' ), true ) ) {
				$deferred = $this->attachment_value_present( $kind, $value ) || $deferred;
				continue;
			}
			$answers[ $response_id ] = $this->value( $kind, $value, $binding['question'], $item['constraints'] );
		}

		try {
			$validated = $this->validator->validate( $profile, $answers );
		} catch ( Assignment_Failure $failure ) {
			throw new InvalidArgumentException( 'Legacy response failed current validation.', 0, $failure ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		$complete = false;
		if ( ! $deferred ) {
			try {
				$this->validator->validate_complete( $profile, $validated['answers'] );
				$complete = true;
			} catch ( Assignment_Failure ) {
				$complete = false;
			}
		}
		return array(
			'answers'             => $validated['answers'],
			'complete'            => $complete,
			'deferred_attachment' => $deferred,
		);
	}

	/**
	 * Profile items.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function profile_items( array $profile ): array {
		$payload = $profile['payload'] ?? null;
		$items   = is_array( $payload ) ? ( $payload['items'] ?? null ) : null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new InvalidArgumentException( 'Current response profile is invalid.' );
		}
		$result = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Current response item is invalid.' );
			}
			$response_id = $item['response_id'] ?? null;
			$kind        = $item['kind'] ?? null;
			if ( ! is_string( $response_id ) || ! is_string( $kind ) || isset( $result[ $response_id ] ) ) {
				throw new InvalidArgumentException( 'Current response identity is invalid.' );
			}
			$item['constraints']    = $item['constraints'] ?? array();
			$result[ $response_id ] = $item;
		}
		return $result;
	}

	/**
	 * Reject invalid bindings.
	 *
	 * @param array<string,array<string,mixed>> $items Items.
	 * @param array<array-key,mixed>            $bindings Bindings.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_bindings( array $items, array $bindings ): void {
		$seen = array();
		foreach ( $bindings as $legacy_id => $binding ) {
			if ( ! is_array( $binding ) ) {
				throw new InvalidArgumentException( 'Legacy response binding is invalid.' );
			}
			$response_id = $binding['response_id'] ?? null;
			$kind        = $binding['kind'] ?? null;
			if (
				! is_string( $legacy_id ) || '' === $legacy_id
				|| ! is_string( $response_id ) || ! isset( $items[ $response_id ] ) || isset( $seen[ $response_id ] )
				|| ! is_string( $kind ) || $kind !== ( $items[ $response_id ]['kind'] ?? null )
				|| ! is_array( $binding['question'] ?? null ) || array_is_list( $binding['question'] )
			) {
				throw new InvalidArgumentException( 'Legacy response binding is invalid.' );
			}
			$seen[ $response_id ] = true;
		}
		if ( count( $seen ) !== count( $items ) ) {
			throw new InvalidArgumentException( 'Legacy response binding inventory drifted.' );
		}
	}

	/**
	 * Value.
	 *
	 * @param string              $kind Kind.
	 * @param mixed               $value Value.
	 * @param array<string,mixed> $source_question Source question.
	 * @param array<string,mixed> $constraints Constraints.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function value( string $kind, mixed $value, array $source_question, array $constraints ): mixed {
		return match ( $kind ) {
			'short_text', 'long_text', 'checkbox' => $value,
			'number' => $this->number( $value ),
			'single_choice' => $this->single_choice( $value, $constraints ),
			'multiple_choice' => $this->multiple_choice( $value, $constraints ),
			'table' => $this->table( $value, $source_question, $constraints ),
			default => throw new InvalidArgumentException( 'Legacy response kind is unsupported.' ),
		};
	}

	/**
	 * Single choice.
	 *
	 * @param mixed               $value Value.
	 * @param array<string,mixed> $constraints Constraints.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function single_choice( mixed $value, array $constraints ): string {
		$key     = is_string( $value ) || is_int( $value ) ? (string) $value : '';
		$options = $this->choice_map( $constraints );
		if ( '' === $key || ! isset( $options[ $key ] ) ) {
			throw new InvalidArgumentException( 'Legacy single-choice value is invalid.' );
		}
		return $options[ $key ];
	}

	/**
	 * Multiple choice.
	 *
	 * @param mixed               $value Value.
	 * @param array<string,mixed> $constraints Constraints.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function multiple_choice( mixed $value, array $constraints ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Legacy multiple-choice value is invalid.' );
		}
		$options = $this->choice_map( $constraints );
		$result  = array();
		foreach ( $value as $entry ) {
			$key = is_string( $entry ) || is_int( $entry ) ? (string) $entry : '';
			if ( '' === $key || ! isset( $options[ $key ] ) || isset( $result[ $options[ $key ] ] ) ) {
				throw new InvalidArgumentException( 'Legacy multiple-choice value is invalid.' );
			}
			$result[ $options[ $key ] ] = true;
		}
		$ordered = array();
		foreach ( $options as $option_id ) {
			if ( isset( $result[ $option_id ] ) ) {
				$ordered[] = $option_id;
			}
		}
		return $ordered;
	}

	/**
	 * Choice map.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function choice_map( array $constraints ): array {
		$options = $constraints['options'] ?? null;
		if ( ! is_array( $options ) || ! array_is_list( $options ) ) {
			throw new InvalidArgumentException( 'Current choice constraints are invalid.' );
		}
		$result = array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || ! is_string( $option['value'] ?? null ) || ! is_string( $option['option_id'] ?? null ) || isset( $result[ $option['value'] ] ) ) {
				throw new InvalidArgumentException( 'Current choice option is invalid.' );
			}
			$result[ $option['value'] ] = $option['option_id'];
		}
		return $result;
	}

	/**
	 * Table.
	 *
	 * @param mixed               $value Value.
	 * @param array<string,mixed> $source_question Source question.
	 * @param array<string,mixed> $constraints Constraints.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function table( mixed $value, array $source_question, array $constraints ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Legacy table response is invalid.' );
		}
		$source         = $source_question['table'] ?? null;
		$source_rows    = is_array( $source ) ? ( $source['rows'] ?? null ) : null;
		$source_columns = is_array( $source ) ? ( $source['columns'] ?? null ) : null;
		$target_rows    = $constraints['rows'] ?? null;
		$target_columns = $constraints['columns'] ?? null;
		if (
			! is_array( $source_rows ) || ! array_is_list( $source_rows )
			|| ! is_array( $source_columns ) || ! array_is_list( $source_columns )
			|| ! is_array( $target_rows ) || ! array_is_list( $target_rows )
			|| ! is_array( $target_columns ) || ! array_is_list( $target_columns )
			|| count( $source_rows ) !== count( $target_rows ) || count( $source_columns ) !== count( $target_columns )
		) {
			throw new InvalidArgumentException( 'Legacy table axis drifted.' );
		}
		$row_map = array();
		foreach ( $source_rows as $index => $row ) {
			$key = is_array( $row ) ? ( $row['key'] ?? null ) : null;
			$id  = is_array( $target_rows[ $index ] ) ? ( $target_rows[ $index ]['row_id'] ?? null ) : null;
			if ( ! is_string( $key ) || '' === $key || ! is_string( $id ) || isset( $row_map[ $key ] ) ) {
				throw new InvalidArgumentException( 'Legacy table row axis is invalid.' );
			}
			$row_map[ $key ] = $id;
		}
		$column_map = array();
		foreach ( $source_columns as $index => $column ) {
			$key       = is_array( $column ) ? ( $column['key'] ?? null ) : null;
			$target    = $target_columns[ $index ] ?? null;
			$id        = is_array( $target ) ? ( $target['column_id'] ?? null ) : null;
			$cell_type = is_array( $target ) ? ( $target['cell_type'] ?? null ) : null;
			if ( ! is_string( $key ) || '' === $key || ! is_string( $id ) || ! is_string( $cell_type ) || isset( $column_map[ $key ] ) ) {
				throw new InvalidArgumentException( 'Legacy table column axis is invalid.' );
			}
			$column_map[ $key ] = array(
				'id'        => $id,
				'cell_type' => $cell_type,
			);
		}
		$result = array();
		foreach ( $value as $source_row_key => $cells ) {
			$source_row_key = (string) $source_row_key;
			if ( ! isset( $row_map[ $source_row_key ] ) || ! is_array( $cells ) || ( array() !== $cells && array_is_list( $cells ) ) ) {
				throw new InvalidArgumentException( 'Legacy table row value is invalid.' );
			}
			$row = array();
			foreach ( $cells as $source_column_key => $cell ) {
				$source_column_key = (string) $source_column_key;
				$column            = $column_map[ $source_column_key ] ?? null;
				if ( ! is_array( $column ) ) {
					throw new InvalidArgumentException( 'Legacy table cell axis is invalid.' );
				}
				$row[ $column['id'] ] = 'number' === $column['cell_type'] ? $this->number( $cell ) : $cell;
			}
			$result[ $row_map[ $source_row_key ] ] = $row;
		}
		return $result;
	}

	/**
	 * Number.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function number( mixed $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', trim( $value ) ) ) {
			throw new InvalidArgumentException( 'Legacy numeric response is invalid.' );
		}
		return trim( $value );
	}

	/**
	 * Attachment value present.
	 *
	 * @param string $kind Kind.
	 * @param mixed  $value Value.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_value_present( string $kind, mixed $value ): bool {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Legacy attachment response is invalid.' );
		}
		$key = 'drawing' === $kind ? 'drawing' : 'files';
		$this->exact_keys( $value, array( $key ) );
		$payload = $value[ $key ];
		if ( 'drawing' === $kind ) {
			return null !== $payload && array() !== $payload;
		}
		if ( ! is_array( $payload ) || ! array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Legacy upload response is invalid.' );
		}
		return array() !== $payload;
	}

	/**
	 * Kind.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function kind( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Legacy response kind is invalid.' );
		}
		$value = strtolower( trim( $value ) );
		return self::RESPONSE_ALIASES[ $value ] ?? $value;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $keys Keys.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $keys ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		if ( $actual !== $keys ) {
			throw new InvalidArgumentException( 'Legacy response fields are invalid.' );
		}
	}
}
