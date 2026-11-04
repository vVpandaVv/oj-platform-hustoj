<?php
/**
 * Versioned, server-only assessment API. No direct public REST endpoint.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\ExternalAssessment;

use Closure;
use Throwable;

/** Assessment Gateway V1 boundary. */
final class Assessment_Gateway_V1 {
	/**
	 * Sources state.
	 *
	 * @var Closure
	 */
	private Closure $sources;
	/**
	 * Routing state.
	 *
	 * @var Closure
	 */
	private Closure $routing;
	/**
	 * Execution state.
	 *
	 * @var Closure
	 */
	private Closure $execution;
	/**
	 * Wakeup state.
	 *
	 * @var Closure
	 */
	private Closure $wakeup;

	/**
	 * Connect the owned persistence and execution dependencies.
	 *
	 * @param Assessment_Store $store store.
	 * @param callable         $sources sources.
	 * @param callable         $routing routing.
	 * @param callable         $execution execution.
	 * @param callable         $wakeup wakeup.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private Assessment_Store $store, callable $sources, callable $routing, callable $execution, callable $wakeup ) {
		$this->sources   = Closure::fromCallable( $sources );
		$this->routing   = Closure::fromCallable( $routing );
		$this->execution = Closure::fromCallable( $execution );
		$this->wakeup    = Closure::fromCallable( $wakeup );
	}

	/**
	 * Return the stable server-side bridge version.
	 *
	 * @return string
	 */
	public function version(): string {
		return '1.0'; }

	/**
	 * Advertise additive input support without breaking existing v1 consumers.
	 *
	 * @return string[]
	 */
	public function contracts(): array {
		return array( 'oj_ml_ai_evidence.v1', Common_Assessment_Profile::EVIDENCE );
	}

	/**
	 * Read current authorized status without exposing stale or unapproved content.
	 *
	 * @param string $source source.
	 * @param string $source_id source id.
	 * @param int    $actor actor.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function status( string $source, string $source_id, int $actor ): array {
		try {
			$profile  = Assessment_Profile::for_source( $source );
			$snapshot = $this->snapshot( $source, $source_id, $actor );
			$route    = ( $this->routing )( $profile::TASK );
			$run      = $this->store->latest( $source, $source_id );
			$result   = null;
			if ( null !== $run ) {
				$result                = array_intersect_key( $run, array_flip( array( 'run_id', 'state', 'input_hash', 'source_sha256', 'created_at', 'error_code' ) ) );
				$result['output_mode'] = $run['output_mode'] ?? 'structured';
				if ( Assessment_Profile::SOURCE !== $source && str_starts_with( $result['error_code'], 'ml_ai_' ) ) {
					$result['error_code'] = self::code( $source, substr( $result['error_code'], 6 ) );
				}
				if ( ! hash_equals( $run['input_hash'], $profile::digest( $snapshot, $result['output_mode'] ) ) ) {
					$result['state']      = 'stale';
					$result['error_code'] = self::code( $source, 'source_changed' );
				} elseif ( is_array( $route ) && ! hash_equals( $run['routing_fingerprint'], $route['fingerprint'] ) ) {
					$result['state']      = 'stale';
					$result['error_code'] = self::code( $source, 'consent_changed' );
				} elseif ( in_array( $run['state'], array( 'ready', 'needs_review' ), true ) ) {
					$content = $this->store->result( $run );
					if ( null === $content ) {
						$result['state']      = 'unavailable';
						$result['error_code'] = self::code( $source, 'result_unavailable' );
					} else {
						$result += $content;
					}
				}
			}
			return array(
				'available'           => is_array( $route ),
				'code'                => is_array( $route ) ? '' : self::code( $source, 'routing_required' ),
				'consent_fingerprint' => is_array( $route ) ? $route['fingerprint'] : '',
				'output_modes'        => Assessment_Profile::SOURCE === $source ? array( 'structured', 'json_compat' ) : array( 'structured' ),
				'result'              => $result,
			);
		} catch ( Throwable ) {
			return self::failure( self::code( $source, 'source_unavailable' ) );
		}
	}

	/**
	 * Read the same authorized projection used by the integrated ML screen.
	 *
	 * @param string $source source.
	 * @param string $source_id source id.
	 * @param int    $actor actor.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get( string $source, string $source_id, int $actor ): array {
		return $this->status( $source, $source_id, $actor );
	}

	/**
	 * Queue one approved snapshot without duplicating a pending or completed run.
	 *
	 * @param string $source source.
	 * @param string $source_id source id.
	 * @param int    $actor actor.
	 * @param array  $consent consent.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( string $source, string $source_id, int $actor, array $consent ): array {
		try {
			$profile  = Assessment_Profile::for_source( $source );
			$snapshot = $this->snapshot( $source, $source_id, $actor );
			$route    = ( $this->routing )( $profile::TASK );
			if ( ! is_array( $route ) ) {
				return self::failure( self::code( $source, 'routing_required' ) ); }
			if ( true !== ( $consent['approved'] ?? null ) || ! is_string( $consent['routingFingerprint'] ?? null )
				|| ! hash_equals( $route['fingerprint'], $consent['routingFingerprint'] ) ) {
				return self::failure( self::code( $source, 'consent_required' ) );
			}
			$mode = $consent['outputMode'] ?? 'structured';
			if ( ! is_string( $mode ) || ! in_array( $mode, array( 'structured', 'json_compat' ), true ) || ( Assessment_Profile::SOURCE !== $source && 'structured' !== $mode ) ) {
				return self::failure( self::code( $source, 'invalid_output_mode' ) );
			}
			$this->store->enqueue(
				array(
					'source'              => $source,
					'source_id'           => $source_id,
					'actor_id'            => $actor,
					'source_sha256'       => $snapshot['source_sha256'],
					'input_hash'          => $profile::digest( $snapshot, $mode ),
					'output_mode'         => $mode,
					'routing_fingerprint' => $route['fingerprint'],
					'consent_version'     => Assessment_Profile::SOURCE === $source ? 'ml_external_ai.v1' : 'external_assessment_ai.v1',
					'consented_at'        => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			( $this->wakeup )();
			return $this->status( $source, $source_id, $actor );
		} catch ( Throwable ) {
			return self::failure( self::code( $source, 'enqueue_failed' ) );
		}
	}

	/**
	 * Execute one leased snapshot and retain only validated private results.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function run_once(): void {
		$run = null;
		try {
			$run = $this->store->claim();
			if ( null === $run ) {
				return; }
			$profile  = Assessment_Profile::for_source( $run['source'] );
			$mode     = $run['output_mode'] ?? 'structured';
			$snapshot = $this->snapshot( $run['source'], $run['source_id'], $run['actor_id'] );
			if ( ! hash_equals( $run['input_hash'], $profile::digest( $snapshot, $mode ) ) ) {
				$this->store->finish( $run, 'stale', null, self::code( $run['source'], 'source_changed' ) );
				return;
			}
			$route = ( $this->routing )( $profile::TASK );
			if ( ! is_array( $route ) || ! hash_equals( $run['routing_fingerprint'], $route['fingerprint'] ) ) {
				$this->store->finish( $run, 'failed', null, self::code( $run['source'], 'consent_changed' ) );
				return;
			}
			$reply = ( $this->execution )( $run, $profile::request_input( $snapshot, $mode ) );
			if ( ! is_array( $reply ) || true !== ( $reply['success'] ?? null ) || ! is_array( $reply['output'] ?? null ) ) {
				$suffix = Assessment_Profile::SOURCE === $run['source'] ? self::provider_failure( $reply ) : 'provider_failed';
				$this->store->finish( $run, 'failed', null, self::code( $run['source'], $suffix ) );
				return;
			}
			if ( ! hash_equals( $run['routing_fingerprint'], (string) ( $reply['routing_fingerprint'] ?? '' ) ) ) {
				$this->store->finish( $run, 'failed', null, self::code( $run['source'], 'consent_changed' ) );
				return;
			}
			try {
				$output = $profile::output( $reply['output'], $snapshot );
			} catch ( \InvalidArgumentException ) {
				// Distinguish malformed AI output from abstention or storage failure,
				// without persisting provider text, exception messages or student data.
				$suffix = Assessment_Profile::SOURCE === $run['source'] ? 'invalid_output' : 'review_required';
				$this->store->finish( $run, 'failed', null, self::code( $run['source'], $suffix ) );
				return;
			}
			// A teacher can lose access or the rubric can change while the model runs.
			$current = $this->snapshot( $run['source'], $run['source_id'], $run['actor_id'] );
			if ( ! hash_equals( $run['input_hash'], $profile::digest( $current, $mode ) ) ) {
				$this->store->finish( $run, 'stale', null, self::code( $run['source'], 'source_changed' ) );
				return;
			}
			$this->store->finish( $run, $output['needs_review'] ? 'needs_review' : 'ready', $output );
		} catch ( Throwable ) {
			if ( null !== $run ) {
				try {
					$this->store->finish( $run, 'failed', null, self::code( $run['source'], 'review_required' ) );
					// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Durable lease records interruption without leaking private content.
				} catch ( Throwable ) {
					/* Lease expiry retains the failure for manual retry. */
				}
			}
		} finally {
			$this->store->release();
		}
	}

	/**
	 * Reauthorize the source and validate its bounded evidence contract.
	 *
	 * @param string $source source.
	 * @param string $source_id source id.
	 * @param int    $actor actor.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot( string $source, string $source_id, int $actor ): array {
		$pattern = Assessment_Profile::SOURCE === $source ? '/^[a-f0-9-]{36}$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D';
		if ( $actor < 1 || 1 !== preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $source ) || 1 !== preg_match( $pattern, $source_id ) ) {
			throw new \InvalidArgumentException( 'assessment_source_unavailable' );
		}
		$sources  = ( $this->sources )();
		$provider = is_array( $sources ) ? ( $sources[ $source ] ?? null ) : null;
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'version' ) || '1.0' !== $provider->version() || ! method_exists( $provider, 'snapshot' ) ) {
			throw new \InvalidArgumentException( 'assessment_source_unavailable' );
		}
		$profile = Assessment_Profile::for_source( $source );
		return $profile::snapshot( $provider->snapshot( $source_id, $actor ), $source_id );
	}

	/**
	 * Preserve legacy ML codes while exposing source-neutral common errors.
	 *
	 * @param string $source Source key.
	 * @param string $suffix Stable error suffix.
	 * @return string
	 */
	private static function code( string $source, string $suffix ): string {
		return ( Assessment_Profile::SOURCE === $source ? 'ml_ai_' : 'assessment_' ) . $suffix;
	}

	/**
	 * Preserve only fixed public failure categories, never provider messages.
	 *
	 * @param mixed $reply Execution result from the public platform gateway.
	 * @return string
	 */
	private static function provider_failure( mixed $reply ): string {
		$code = is_array( $reply ) ? ( $reply['error_code'] ?? null ) : null;
		return match ( $code ) {
			'invalid_request' => 'provider_invalid_request',
			'authentication_failed' => 'provider_authentication_failed',
			'model_not_found' => 'provider_model_not_found',
			'timeout' => 'provider_timeout',
			'rate_limited' => 'provider_rate_limited',
			'provider_unavailable' => 'provider_unavailable',
			'context_too_small' => 'provider_context_too_small',
			'output_truncated' => 'provider_output_truncated',
			'invalid_response' => 'provider_invalid_response',
			'addon_ai_request_pending' => 'provider_request_pending',
			'addon_ai_outcome_uncertain' => 'provider_outcome_uncertain',
			'addon_ai_task_unroutable' => 'routing_required',
			default => 'provider_failed',
		};
	}

	/**
	 * Return a stable, content-free failure envelope.
	 *
	 * @param string $code code.
	 * @return array
	 */
	private static function failure( string $code ): array {
		return array(
			'available'           => false,
			'code'                => $code,
			'consent_fingerprint' => '',
			'result'              => null,
		);
	}
}
