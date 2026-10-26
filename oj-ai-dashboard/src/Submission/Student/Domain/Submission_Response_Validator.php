<?php
/**
 * Student autosave response validation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates all ten frozen response kinds against the student-safe P02 profile.
 */
final class Submission_Response_Validator {
	private const MAX_RESPONSES          = 500;
	private const MAX_TEXT_CHARACTERS    = 32768;
	private const MAX_DECIMAL_CHARACTERS = 100;
	private const DEFAULT_MAX_FILES      = 5;
	private const DEFAULT_MAX_FILE_BYTES = 26214400;

	/**
	 * Initialize submission response validator dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Required/minimum completeness is enforced by C26; C23 preserves valid partial drafts.
	 *
	 * @param array<string,mixed>    $profile Profile.
	 * @param array<array-key,mixed> $answers Answers.
	 * @return array{answers:array<string,mixed>,attachments:array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}>}
	 */
	public function validate( array $profile, array $answers ): array {
		$items = $this->profile_items( $profile );
		if ( array() !== $answers && array_is_list( $answers ) ) {
			$this->invalid_response();
		}
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ $item['response_id'] ] = $item;
		}
		foreach ( array_keys( $answers ) as $response_id ) {
			if ( ! is_string( $response_id ) || ! isset( $by_id[ $response_id ] ) ) {
				$this->invalid_response();
			}
		}

		$normalized  = array();
		$attachments = array();
		foreach ( $items as $item ) {
			$id = $item['response_id'];
			if ( ! array_key_exists( $id, $answers ) || null === $answers[ $id ] ) {
				continue;
			}
			$value             = $this->answer( $item, $answers[ $id ], $attachments );
			$normalized[ $id ] = $value;
		}

		return array(
			'answers'     => $normalized,
			'attachments' => $attachments,
		);
	}

	/**
	 * Validate one official response, including required and minimum constraints.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @param array<string,mixed> $answers Answers.
	 * @return array{answers:array<string,mixed>,attachments:array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}>}
	 */
	public function validate_complete( array $profile, array $answers ): array {
		$validated = $this->validate( $profile, $answers );
		foreach ( $this->profile_items( $profile ) as $item ) {
			$id      = $item['response_id'];
			$present = array_key_exists( $id, $validated['answers'] );
			if ( ! $present ) {
				if ( true === $item['required'] ) {
					$this->invalid_response();
				}
				continue;
			}

			$value       = $validated['answers'][ $id ];
			$constraints = $item['constraints'];
			switch ( $item['kind'] ) {
				case 'short_text':
				case 'long_text':
					$minimum = $this->bounded_integer( $constraints['min_chars'] ?? 0, 0, self::MAX_TEXT_CHARACTERS, false );
					if ( true === $item['required'] ) {
						$minimum = max( 1, $minimum );
					}
					if ( ! is_string( $value ) || $this->character_length( $value ) < $minimum ) {
						$this->invalid_response();
					}
					break;
				case 'multiple_choice':
					$minimum = $this->bounded_integer( $constraints['min_selected'] ?? 0, 0, count( $this->option_ids( $constraints ) ), false );
					if ( true === $item['required'] ) {
						$minimum = max( 1, $minimum );
					}
					if ( ! is_array( $value ) || count( $value ) < $minimum ) {
						$this->invalid_response();
					}
					break;
				case 'checkbox':
					if ( true === $item['required'] && true !== $value ) {
						$this->invalid_response();
					}
					break;
				case 'table':
				case 'image_upload':
				case 'file_upload':
					if ( true === $item['required'] && ( ! is_array( $value ) || array() === $value ) ) {
						$this->invalid_response();
					}
					break;
				default:
					break;
			}
		}

		return $validated;
	}

	/**
	 * Profile items.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function profile_items( array $profile ): array {
		try {
			$this->exact_keys( $profile, array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' ) );
			if (
				'oj_ai.student_response_schema' !== ( $profile['schema_id'] ?? null )
				|| '1.0.0' !== ( $profile['schema_version'] ?? null )
				|| '1.0.0' !== ( $profile['projector_version'] ?? null )
				|| ! is_string( $profile['source_hash'] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $profile['source_hash'] )
				|| ! is_array( $profile['payload'] ?? null )
				|| ! is_string( $profile['payload_hash'] ?? null )
				|| ! hash_equals( hash( 'sha256', $this->canonical->encode( $profile['payload'] ) ), $profile['payload_hash'] )
			) {
				$this->invalid_profile();
			}
			$payload = $profile['payload'];
			$this->exact_keys( $payload, array( 'items' ) );
			$items = $payload['items'] ?? null;
			if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > self::MAX_RESPONSES ) {
				$this->invalid_profile();
			}
			$result = array();
			$ids    = array();
			foreach ( $items as $position => $item ) {
				if ( ! is_array( $item ) || array_is_list( $item ) ) {
					$this->invalid_profile();
				}
				$this->allowed_keys( $item, array( 'response_id', 'kind', 'label', 'required', 'position', 'help_text', 'constraints' ) );
				foreach ( array( 'response_id', 'kind', 'label', 'required', 'position' ) as $required ) {
					if ( ! array_key_exists( $required, $item ) ) {
						$this->invalid_profile();
					}
				}
				$id   = $this->profile_public_id( $item['response_id'] );
				$kind = $item['kind'] ?? null;
				if (
					isset( $ids[ $id ] ) || $position !== ( $item['position'] ?? null )
					|| ! is_string( $kind ) || ! in_array( $kind, $this->kinds(), true )
					|| ! is_string( $item['label'] ) || '' === trim( $item['label'] )
					|| ! is_bool( $item['required'] )
					|| ( isset( $item['help_text'] ) && ! is_string( $item['help_text'] ) )
					|| ( isset( $item['constraints'] ) && ( ! is_array( $item['constraints'] ) || ( array() !== $item['constraints'] && array_is_list( $item['constraints'] ) ) ) )
				) {
					$this->invalid_profile();
				}
				$ids[ $id ]          = true;
				$item['response_id'] = $id;
				$item['constraints'] = $item['constraints'] ?? array();
				$result[]            = $item;
			}
			return $result;
		} catch ( Assignment_Failure $failure ) {
			throw $failure;
		} catch ( \Throwable ) {
			$this->invalid_profile();
		}
	}

	/**
	 * Answer.
	 *
	 * @param array<string,mixed>                                                                                        $item Item.
	 * @param mixed                                                                                                      $raw Raw.
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> &$attachments Attachments.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function answer( array $item, mixed $raw, array &$attachments ): mixed {
		$kind        = $item['kind'];
		$constraints = $item['constraints'];
		return match ( $kind ) {
			'short_text', 'long_text' => $this->text_answer( $kind, $constraints, $raw ),
			'single_choice' => $this->single_choice( $constraints, $raw ),
			'multiple_choice' => $this->multiple_choice( $constraints, $raw ),
			'checkbox' => $this->checkbox( $raw ),
			'number' => $this->number( $constraints, $raw ),
			'table' => $this->table( $constraints, $raw ),
			'image_upload', 'file_upload' => $this->uploads( $item['response_id'], $kind, $constraints, $raw, $attachments ),
			'drawing' => $this->drawing( $item['response_id'], $constraints, $raw, $attachments ),
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			default => throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED ),
		};
	}

	/**
	 * Text answer.
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $raw Raw.
	 * @return string
	 */
	private function text_answer( string $kind, array $constraints, mixed $raw ): string {
		if ( ! is_string( $raw ) || 1 !== preg_match( '//u', $raw ) ) {
			$this->invalid_response();
		}
		$value = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		if ( 'short_text' === $kind && true === ( $constraints['trim'] ?? false ) ) {
			$value = trim( $value );
		}
		$maximum = $this->bounded_integer( $constraints['max_chars'] ?? self::MAX_TEXT_CHARACTERS, 1, self::MAX_TEXT_CHARACTERS, true );
		if ( $this->character_length( $value ) > $maximum ) {
			$this->invalid_response();
		}
		return $value;
	}

	/**
	 * Single choice.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $raw Raw.
	 * @return string
	 */
	private function single_choice( array $constraints, mixed $raw ): string {
		$ids = $this->option_ids( $constraints );
		if ( ! is_string( $raw ) || ! in_array( $raw, $ids, true ) ) {
			$this->invalid_response();
		}
		return $raw;
	}

	/**
	 * Multiple choice.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $raw Raw.
	 * @return array<int,string>
	 */
	private function multiple_choice( array $constraints, mixed $raw ): array {
		$ids = $this->option_ids( $constraints );
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( array_unique( $raw, SORT_REGULAR ) ) !== count( $raw ) ) {
			$this->invalid_response();
		}
		$selected = array();
		foreach ( $raw as $id ) {
			if ( ! is_string( $id ) || ! in_array( $id, $ids, true ) ) {
				$this->invalid_response();
			}
			$selected[ $id ] = true;
		}
		$maximum = $this->bounded_integer( $constraints['max_selected'] ?? count( $ids ), 1, count( $ids ), true );
		if ( count( $selected ) > $maximum ) {
			$this->invalid_response();
		}
		return array_values( array_filter( $ids, static fn ( string $id ): bool => isset( $selected[ $id ] ) ) );
	}

	/**
	 * Checkbox.
	 *
	 * @param mixed $raw Raw.
	 * @return bool
	 */
	private function checkbox( mixed $raw ): bool {
		if ( ! is_bool( $raw ) ) {
			$this->invalid_response();
		}
		return $raw;
	}

	/**
	 * Number.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $raw Raw.
	 * @return string
	 */
	private function number( array $constraints, mixed $raw ): string {
		$value = $this->decimal( $raw );
		if ( isset( $constraints['min'] ) && $this->compare_decimals( $value, $this->decimal( $constraints['min'] ) ) < 0 ) {
			$this->invalid_response();
		}
		if ( isset( $constraints['max'] ) && $this->compare_decimals( $value, $this->decimal( $constraints['max'] ) ) > 0 ) {
			$this->invalid_response();
		}
		if ( isset( $constraints['decimal_places'] ) ) {
			$places = $this->bounded_integer( $constraints['decimal_places'], 0, 12, false );
			if ( strlen( explode( '.', $value, 2 )[1] ?? '' ) > $places ) {
				$this->invalid_response();
			}
		}
		if ( isset( $constraints['step'] ) ) {
			$step = $this->decimal( $constraints['step'] );
			if ( str_starts_with( $step, '-' ) || '0' === $step || ! $this->step_aligned( $value, isset( $constraints['min'] ) ? $this->decimal( $constraints['min'] ) : '0', $step ) ) {
				$this->invalid_response();
			}
		}
		return $value;
	}

	/**
	 * Table.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $raw Raw.
	 * @return array<string,array<string,mixed>>
	 */
	private function table( array $constraints, mixed $raw ): array {
		if ( ! is_array( $raw ) || ( array() !== $raw && array_is_list( $raw ) ) ) {
			$this->invalid_response();
		}
		$row_mode = $constraints['row_mode'] ?? null;
		$rows     = $constraints['rows'] ?? null;
		$columns  = $constraints['columns'] ?? null;
		if ( ! in_array( $row_mode, array( 'fixed', 'dynamic' ), true ) || ! is_array( $rows ) || ! array_is_list( $rows ) || ! is_array( $columns ) || ! array_is_list( $columns ) ) {
			$this->invalid_profile();
		}
		$row_ids = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$this->invalid_profile();
			}
			$row_ids[] = $this->profile_public_id( $row['row_id'] ?? null );
		}
		$column_types = array();
		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) || ! in_array( $column['cell_type'] ?? null, array( 'short_text', 'number', 'checkbox' ), true ) ) {
				$this->invalid_profile();
			}
			$column_types[ $this->profile_public_id( $column['column_id'] ?? null ) ] = $column['cell_type'];
		}
		$maximum = 'dynamic' === $row_mode ? $this->bounded_integer( $constraints['max_rows'] ?? 0, 1, 100, false ) : count( $row_ids );
		if ( count( $raw ) > $maximum ) {
			$this->invalid_response();
		}
		$result = array();
		foreach ( $raw as $row_id => $cells ) {
			if ( ! is_string( $row_id ) || ( 'fixed' === $row_mode && ! in_array( $row_id, $row_ids, true ) ) ) {
				$this->invalid_response();
			}
			if ( 'dynamic' === $row_mode ) {
				$this->response_public_id( $row_id );
			}
			if ( ! is_array( $cells ) || ( array() !== $cells && array_is_list( $cells ) ) ) {
				$this->invalid_response();
			}
			$row = array();
			foreach ( $cells as $column_id => $cell ) {
				if ( ! is_string( $column_id ) || ! isset( $column_types[ $column_id ] ) ) {
					$this->invalid_response();
				}
				$row[ $column_id ] = match ( $column_types[ $column_id ] ) {
					'short_text' => $this->text_answer( 'short_text', array(), $cell ),
					'number' => $this->number( array(), $cell ),
					'checkbox' => $this->checkbox( $cell ),
				};
			}
			$result[ $row_id ] = $row;
		}
		return $result;
	}

	/**
	 * Uploads.
	 *
	 * @param string                                                                                                     $response_id Response id.
	 * @param string                                                                                                     $kind Kind.
	 * @param array<string,mixed>                                                                                        $constraints Constraints.
	 * @param mixed                                                                                                      $raw Raw.
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> &$attachments Attachments.
	 * @return array<int,string>
	 */
	private function uploads( string $response_id, string $kind, array $constraints, mixed $raw, array &$attachments ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( array_unique( $raw, SORT_REGULAR ) ) !== count( $raw ) ) {
			$this->invalid_response();
		}
		$maximum = $this->bounded_integer( $constraints['max_files'] ?? self::DEFAULT_MAX_FILES, 1, 20, true );
		if ( count( $raw ) > $maximum ) {
			$this->invalid_response();
		}
		$mimes = $constraints['mime_types'] ?? ( 'image_upload' === $kind ? array( 'image/jpeg', 'image/png', 'image/webp' ) : array() );
		if ( ! is_array( $mimes ) || ! array_is_list( $mimes ) || array() === $mimes ) {
			$this->invalid_profile();
		}
		$max_bytes = $this->bounded_integer( $constraints['max_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES, 1, self::DEFAULT_MAX_FILE_BYTES, true );
		$result    = array();
		foreach ( $raw as $id ) {
			if ( ! is_string( $id ) ) {
				$this->invalid_response();
			}
			$this->response_public_id( $id );
			$this->add_attachment( $attachments, $id, $response_id, $kind, 'file', $mimes, $max_bytes );
			$result[] = $id;
		}
		return $result;
	}

	/**
	 * Drawing.
	 *
	 * @param string                                                                                                     $response_id Response id.
	 * @param array<string,mixed>                                                                                        $constraints Constraints.
	 * @param mixed                                                                                                      $raw Raw.
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> &$attachments Attachments.
	 * @return array{document_attachment_id:string,preview_attachment_id:string}
	 */
	private function drawing( string $response_id, array $constraints, mixed $raw, array &$attachments ): array {
		if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
			$this->invalid_response();
		}
		$this->exact_response_keys( $raw, array( 'document_attachment_id', 'preview_attachment_id' ) );
		$document_id = $raw['document_attachment_id'] ?? null;
		$preview_id  = $raw['preview_attachment_id'] ?? null;
		if ( ! is_string( $document_id ) || ! is_string( $preview_id ) || $document_id === $preview_id ) {
			$this->invalid_response();
		}
		$this->response_public_id( $document_id );
		$this->response_public_id( $preview_id );
		$preview_mime = $constraints['preview_mime_type'] ?? null;
		if ( 'image/png' !== $preview_mime ) {
			$this->invalid_profile();
		}
		$this->add_attachment( $attachments, $document_id, $response_id, 'drawing', 'document', array( 'application/json' ), self::DEFAULT_MAX_FILE_BYTES );
		$this->add_attachment( $attachments, $preview_id, $response_id, 'drawing', 'preview', array( $preview_mime ), self::DEFAULT_MAX_FILE_BYTES );
		return array(
			'document_attachment_id' => $document_id,
			'preview_attachment_id'  => $preview_id,
		);
	}

	/**
	 * Add attachment.
	 *
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> &$attachments Attachments.
	 * @param string                                                                                                     $id Id.
	 * @param string                                                                                                     $response_id Response id.
	 * @param string                                                                                                     $kind Kind.
	 * @param string                                                                                                     $role Role.
	 * @param array<int,string>                                                                                          $mimes Mimes.
	 * @param int                                                                                                        $max_bytes Max bytes.
	 * @return void
	 */
	private function add_attachment( array &$attachments, string $id, string $response_id, string $kind, string $role, array $mimes, int $max_bytes ): void {
		if ( isset( $attachments[ $id ] ) ) {
			$this->invalid_response();
		}
		$attachments[ $id ] = array(
			'response_id' => $response_id,
			'kind'        => $kind,
			'role'        => $role,
			'mime_types'  => array_values( $mimes ),
			'max_bytes'   => $max_bytes,
		);
	}

	/**
	 * Option ids.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @return array<int,string>
	 */
	private function option_ids( array $constraints ): array {
		$options = $constraints['options'] ?? null;
		if ( ! is_array( $options ) || ! array_is_list( $options ) || count( $options ) < 2 ) {
			$this->invalid_profile();
		}
		$ids = array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				$this->invalid_profile();
			}
			$id = $this->profile_public_id( $option['option_id'] ?? null );
			if ( in_array( $id, $ids, true ) ) {
				$this->invalid_profile();
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Kinds.
	 *
	 * @return array<int,string>
	 */
	private function kinds(): array {
		return array( 'short_text', 'long_text', 'single_choice', 'multiple_choice', 'checkbox', 'number', 'table', 'image_upload', 'file_upload', 'drawing' );
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function decimal( mixed $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_DECIMAL_CHARACTERS || 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', trim( $value ) ) ) {
			$this->invalid_response();
		}
		$value    = trim( $value );
		$negative = str_starts_with( $value, '-' );
		$unsigned = $negative ? substr( $value, 1 ) : $value;
		$parts    = explode( '.', $unsigned, 2 );
		$integer  = ltrim( $parts[0], '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		$result   = $integer . ( '' === $fraction ? '' : '.' . $fraction );
		return '0' === $result ? '0' : ( $negative ? '-' . $result : $result );
	}

	/**
	 * Compare decimals.
	 *
	 * @param string $left Left.
	 * @param string $right Right.
	 * @return int
	 */
	private function compare_decimals( string $left, string $right ): int {
		$left_negative  = str_starts_with( $left, '-' );
		$right_negative = str_starts_with( $right, '-' );
		if ( $left_negative !== $right_negative ) {
			return $left_negative ? -1 : 1;
		}
		$left_parts  = explode( '.', ltrim( $left, '-' ), 2 );
		$right_parts = explode( '.', ltrim( $right, '-' ), 2 );
		$comparison  = strlen( $left_parts[0] ) <=> strlen( $right_parts[0] );
		if ( 0 === $comparison ) {
			$comparison = strcmp( $left_parts[0], $right_parts[0] );
		}
		if ( 0 === $comparison ) {
			$length     = max( strlen( $left_parts[1] ?? '' ), strlen( $right_parts[1] ?? '' ) );
			$comparison = strcmp( str_pad( $left_parts[1] ?? '', $length, '0' ), str_pad( $right_parts[1] ?? '', $length, '0' ) );
		}
		$comparison = $comparison <=> 0;
		return $left_negative ? -$comparison : $comparison;
	}

	/**
	 * Step aligned.
	 *
	 * @param string $value Value.
	 * @param string $base Base.
	 * @param string $step Step.
	 * @return bool
	 */
	private function step_aligned( string $value, string $base, string $step ): bool {
		$scale                                 = max( $this->fraction_length( $value ), $this->fraction_length( $base ), $this->fraction_length( $step ) );
		list( $value_negative, $value_digits ) = $this->scaled_integer( $value, $scale );
		list( $base_negative, $base_digits )   = $this->scaled_integer( $base, $scale );
		list( , $step_digits )                 = $this->scaled_integer( $step, $scale );
		$difference                            = $value_negative === $base_negative
			? $this->subtract_unsigned_abs( $value_digits, $base_digits )
			: $this->add_unsigned( $value_digits, $base_digits );
		return '0' === $this->modulo_unsigned( $difference, $step_digits );
	}

	/**
	 * Fraction length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function fraction_length( string $value ): int {
		return strlen( explode( '.', ltrim( $value, '-' ), 2 )[1] ?? '' );
	}

	/**
	 * Scaled integer.
	 *
	 * @param string $value Value.
	 * @param int    $scale Scale.
	 * @return array{0:bool,1:string}
	 */
	private function scaled_integer( string $value, int $scale ): array {
		$negative = str_starts_with( $value, '-' );
		$parts    = explode( '.', ltrim( $value, '-' ), 2 );
		$digits   = ltrim( $parts[0] . str_pad( $parts[1] ?? '', $scale, '0' ), '0' );
		return array( $negative, '' === $digits ? '0' : $digits );
	}

	/**
	 * Compare unsigned.
	 *
	 * @param string $left Left.
	 * @param string $right Right.
	 * @return int
	 */
	private function compare_unsigned( string $left, string $right ): int {
		$left  = ltrim( $left, '0' );
		$left  = '' === $left ? '0' : $left;
		$right = ltrim( $right, '0' );
		$right = '' === $right ? '0' : $right;
		return strlen( $left ) === strlen( $right ) ? ( strcmp( $left, $right ) <=> 0 ) : ( strlen( $left ) <=> strlen( $right ) );
	}

	/**
	 * Add unsigned.
	 *
	 * @param string $left Left.
	 * @param string $right Right.
	 * @return string
	 */
	private function add_unsigned( string $left, string $right ): string {
		$carry  = 0;
		$result = '';
		for ( $li = strlen( $left ) - 1, $ri = strlen( $right ) - 1; $li >= 0 || $ri >= 0 || $carry > 0; --$li, --$ri ) {
			$sum    = ( $li >= 0 ? (int) $left[ $li ] : 0 ) + ( $ri >= 0 ? (int) $right[ $ri ] : 0 ) + $carry;
			$result = (string) ( $sum % 10 ) . $result;
			$carry  = intdiv( $sum, 10 );
		}
		$result = ltrim( $result, '0' );
		return '' === $result ? '0' : $result;
	}

	/**
	 * Subtract unsigned abs.
	 *
	 * @param string $left Left.
	 * @param string $right Right.
	 * @return string
	 */
	private function subtract_unsigned_abs( string $left, string $right ): string {
		if ( $this->compare_unsigned( $left, $right ) < 0 ) {
			return $this->subtract_unsigned( $right, $left );
		}
		return $this->subtract_unsigned( $left, $right );
	}

	/**
	 * Subtract unsigned.
	 *
	 * @param string $left Left.
	 * @param string $right Right.
	 * @return string
	 */
	private function subtract_unsigned( string $left, string $right ): string {
		$borrow = 0;
		$result = '';
		for ( $li = strlen( $left ) - 1, $ri = strlen( $right ) - 1; $li >= 0; --$li, --$ri ) {
			$digit = (int) $left[ $li ] - $borrow - ( $ri >= 0 ? (int) $right[ $ri ] : 0 );
			if ( $digit < 0 ) {
				$digit += 10;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$result = (string) $digit . $result;
		}
		$result = ltrim( $result, '0' );
		return '' === $result ? '0' : $result;
	}

	/**
	 * Modulo unsigned.
	 *
	 * @param string $dividend Dividend.
	 * @param string $divisor Divisor.
	 * @return string
	 */
	private function modulo_unsigned( string $dividend, string $divisor ): string {
		if ( '0' === $divisor ) {
			$this->invalid_response();
		}
		$remainder = '0';
		foreach ( str_split( $dividend ) as $digit ) {
			$remainder = ltrim( ( '0' === $remainder ? '' : $remainder ) . $digit, '0' );
			$remainder = '' === $remainder ? '0' : $remainder;
			while ( $this->compare_unsigned( $remainder, $divisor ) >= 0 ) {
				$remainder = $this->subtract_unsigned( $remainder, $divisor );
			}
		}
		return $remainder;
	}

	/**
	 * Profile public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function profile_public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			$this->invalid_profile();
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			$this->invalid_profile();
		}
		return $value;
	}

	/**
	 * Response public id.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function response_public_id( string $value ): void {
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			$this->invalid_response();
		}
	}

	/**
	 * Bounded integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $minimum Minimum.
	 * @param int   $maximum Maximum.
	 * @param bool  $response_failure Response failure.
	 * @return int
	 */
	private function bounded_integer( mixed $value, int $minimum, int $maximum, bool $response_failure ): int {
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
			$response_failure ? $this->invalid_response() : $this->invalid_profile();
		}
		return $value;
	}

	/**
	 * Character length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function character_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}
		$count = preg_match_all( '/./us', $value, $matches );
		return false === $count ? PHP_INT_MAX : $count;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->invalid_profile();
		}
	}

	/**
	 * Allowed keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $allowed Allowed.
	 * @return void
	 */
	private function allowed_keys( array $value, array $allowed ): void {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$this->invalid_profile();
			}
		}
	}

	/**
	 * Exact response keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 */
	private function exact_response_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->invalid_response();
		}
	}

	/**
	 * Invalid profile.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function invalid_profile(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
	}
	/**
	 * Invalid response.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function invalid_response(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
	}
}
