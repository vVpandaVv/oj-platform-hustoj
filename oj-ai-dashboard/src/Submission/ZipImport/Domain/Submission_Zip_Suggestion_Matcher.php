<?php
/**
 * Submission zip suggestion matcher.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces review-only exact filename suggestions; it never confirms a mapping.
 */
final class Submission_Zip_Suggestion_Matcher {
	/**
	 * Suggest.
	 *
	 * @param string                         $relative_path Relative path.
	 * @param array<int,string>              $compatible_response_ids Compatible response ids.
	 * @param array<int,array<string,mixed>> $targets Targets.
	 * @return array{learner:?array<string,mixed>,response:?array<string,mixed>}
	 */
	public function suggest( string $relative_path, array $compatible_response_ids, array $targets ): array {
		$tokens          = $this->path_tokens( $relative_path );
		$learner_matches = array();
		foreach ( $targets as $target ) {
			if ( 'active' !== ( $target['state'] ?? null ) || ! is_string( $target['display_name'] ?? null ) ) {
				continue;
			}
			if ( in_array( $this->fold( $target['display_name'] ), $tokens, true ) ) {
				$learner_matches[] = $target;
			}
		}
		$learner = null;
		if ( 1 === count( $learner_matches ) ) {
			$learner = array(
				'learner_user_id' => (int) $learner_matches[0]['learner_user_id'],
				'method'          => 'exact_path_token',
				'target_id'       => (string) $learner_matches[0]['target_id'],
			);
		}

		$path_response_ids = array();
		if ( preg_match_all( '/(?<![0-9A-HJKMNP-TV-Z])([0-9A-HJKMNP-TV-Z]{26})(?![0-9A-HJKMNP-TV-Z])/i', $relative_path, $matches ) ) {
			foreach ( $matches[1] as $candidate ) {
				$candidate = strtoupper( $candidate );
				if ( in_array( $candidate, $compatible_response_ids, true ) ) {
					$path_response_ids[ $candidate ] = true;
				}
			}
		}
		$response = null;
		if ( 1 === count( $path_response_ids ) ) {
			$response = array(
				'method'      => 'exact_path_token',
				'response_id' => (string) array_key_first( $path_response_ids ),
			);
		} elseif ( 1 === count( $compatible_response_ids ) ) {
			$response = array(
				'method'      => 'single_compatible',
				'response_id' => $compatible_response_ids[0],
			);
		}
		return array(
			'learner'  => $learner,
			'response' => $response,
		);
	}

	/**
	 * Path tokens.
	 *
	 * @param string $path Path.
	 * @return array<int,string>
	 */
	private function path_tokens( string $path ): array {
		$tokens                             = array();
		$segments                           = explode( '/', $path );
		$segments[ count( $segments ) - 1 ] = (string) pathinfo( $segments[ count( $segments ) - 1 ], PATHINFO_FILENAME );
		foreach ( $segments as $segment ) {
			$folded = $this->fold( trim( $segment ) );
			if ( '' !== $folded ) {
				$tokens[ $folded ] = true;
			}
			$parts = preg_split( '/[\s_.-]+/u', $segment, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $parts ) ) {
				foreach ( $parts as $part ) {
					$tokens[ $this->fold( $part ) ] = true;
				}
			}
		}
		return array_keys( $tokens );
	}

	/**
	 * Fold.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function fold( string $value ): string {
		$value = trim( $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
