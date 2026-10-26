<?php
/**
 * Event-driven execution of the add-on's durable AI evaluation queue.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns scheduling only; existing workers retain claim, consent and commit policy. */
final class WordPress_Evaluation_Queue_Dispatcher implements Module_Interface {
	public const ACTION  = 'oj_ai_dashboard_run_evaluation';
	private const OPTION = 'oj_ai_dashboard_evaluation_wakeup';
	/**
	 * Registered subject workers.
	 *
	 * @var array<string,callable>
	 */
	private array $workers = array();

	/**
	 * Use the add-on's WordPress connection for dispatch coordination.
	 *
	 * @param object $database WordPress database adapter.
	 */
	public function __construct( private object $database ) {
	}

	/** Build the production adapter. */
	public static function from_globals(): self {
		return new self( $GLOBALS['wpdb'] ?? new \stdClass() );
	}

	/** Register token-authenticated internal endpoints. */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Bind a domain worker without moving its business policy.
	 *
	 * @param string   $key Subject or operation key.
	 * @param callable $worker Existing run-once application service.
	 */
	public function register_worker( string $key, callable $worker ): void {
		$this->workers[ $key ] = $worker;
	}

	/** Best effort only: a dispatch failure never invalidates committed input. */
	public function wake(): void { // phpcs:ignore OJAI.Commenting.ThrowsContract.Missing -- All dispatch exceptions are contained here; durable enqueue must remain successful.
		$ticket = null;
		try {
			if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'admin_url' ) ) {
				return;
			}
			if ( '1' !== (string) $this->scalar( 'SELECT IS_FREE_LOCK(%s)', $this->lock_name( 'run' ) ) ) {
				return;
			}
			$ticket = $this->issue();
			if ( null === $ticket ) {
				return;
			}
			$response = wp_remote_post(
				admin_url( 'admin-post.php' ),
				array(
					'blocking'    => false,
					'timeout'     => 1,
					'redirection' => 0,
					'sslverify'   => true,
					'body'        => array(
						'action' => self::ACTION,
						'ticket' => $ticket,
					),
				)
			);
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
				throw new RuntimeException( 'Evaluation loopback unavailable.' );
			}
		} catch ( Throwable ) {
			if ( null !== $ticket ) {
				try {
					$this->consume( $ticket );
				} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Failed signal cleanup is recovered by ticket expiry.
					// Expiry and the durable cron sweep recover a lost signal.
				}
			}
			$this->report_failure();
		}
	}

	/** Validate a one-use signal before starting any queue work. */
	public function handle(): void {
		try {
			$ticket = $_POST['ticket'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- consume() strictly validates 64 hex bytes and atomically consumes the one-use token; this is not a user form.
			$method = $_SERVER['REQUEST_METHOD'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Exact POST comparison, never stored or rendered.
			if ( 'POST' !== $method || ! is_string( $ticket ) || ! $this->consume( $ticket ) ) {
				status_header( 403 );
				return;
			}
			ignore_user_abort( true );
			$this->run();
			status_header( 204 );
		} catch ( Throwable ) {
			$this->report_failure();
			status_header( 503 );
		}
	}

	/**
	 * Run one long AI call, then signal the next eligible run.
	 */
	public function run(): void { // phpcs:ignore OJAI.Commenting.ThrowsContract.Missing -- Worker and coordination errors are contained for cron recovery.
		$locked  = false;
		$healthy = false;
		try {
			$lock_result = $this->scalar( 'SELECT GET_LOCK(%s, 0)', $this->lock_name( 'run' ) );
			if ( null === $lock_result ) {
				throw new RuntimeException( 'Evaluation lock unavailable.' );
			}
			$locked = '1' === (string) $lock_result;
			if ( ! $locked ) {
				return;
			}
			$next = $this->next();
			if ( null === $next ) {
				$healthy = true;
			} else {
				$key = 'worksheet_evaluation' === $next['subject_type'] ? 'worksheet' : (string) $next['operation_key'];
				if ( ! isset( $this->workers[ $key ] ) ) {
					throw new RuntimeException( 'Evaluation worker unavailable.' );
				}
				( $this->workers[ $key ] )();
				// A corrupt/stale head must not create an unlimited HTTP dispatch loop.
				$after   = $this->next();
				$healthy = null === $after || $next['id'] !== $after['id'] || $next['lock_version'] !== $after['lock_version'];
			}
		} catch ( Throwable ) {
			$this->report_failure();
		} finally {
			if ( $locked ) {
				try {
					$this->scalar( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( 'run' ) );
				} catch ( Throwable ) {
					$healthy = false;
					$this->report_failure();
				}
			}
		}
		if ( $healthy ) {
			try {
				if ( null !== $this->next() ) {
					$this->wake();
				}
			} catch ( Throwable ) {
				$this->report_failure();
			}
		}
	}

	/**
	 * Read the oldest claimable run using the domain repositories' eligibility.
	 *
	 * @return array<string,mixed>|null
	 * @throws RuntimeException If queue storage is unavailable.
	 */
	private function next(): ?array {
		$sql = "SELECT r.id, r.lock_version, r.subject_type, r.operation_key
			FROM oj_ai_evaluation_runs r
			WHERE ((r.state IN ('queued','retry_wait') AND r.available_at_utc <= UTC_TIMESTAMP())
			 OR (r.state IN ('leased','running') AND r.lease_expires_at_utc IS NOT NULL AND r.lease_expires_at_utc <= UTC_TIMESTAMP()))
			AND ((r.subject_type = 'worksheet_evaluation' AND EXISTS (
			 SELECT 1 FROM oj_ai_worksheet_evaluations e INNER JOIN oj_ai_worksheet_evaluation_inputs i ON i.worksheet_evaluation_id = e.id
			 WHERE e.public_id = r.evaluation_public_id))
			 OR (r.subject_type = 'submission_evaluation' AND EXISTS (
			 SELECT 1 FROM oj_ai_submission_evaluations e WHERE e.public_id = r.evaluation_public_id
			 AND ((r.operation_key = 'score' AND e.state IN ('queued','running') AND EXISTS (
			  SELECT 1 FROM oj_ai_submission_evaluation_inputs i WHERE i.submission_evaluation_id = e.id))
			  OR (r.operation_key IN ('feedback_draft','special_note_draft') AND e.state IN ('reviewed','review_required'))))))
			ORDER BY r.available_at_utc ASC, r.id ASC LIMIT 1";
		$row = $this->database->get_row( $sql, 'ARRAY_A' );
		if ( '' !== ( $this->database->last_error ?? '' ) ) {
			throw new RuntimeException( 'Evaluation queue unavailable.' );
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Coalesce signals while holding a short database lock.
	 *
	 * @throws Throwable If signal persistence or randomness fails.
	 */
	private function issue(): ?string {
		$name = $this->lock_name( 'signal' );
		if ( '1' !== (string) $this->scalar( 'SELECT GET_LOCK(%s, 0)', $name ) ) {
			return null;
		}
		try {
			$state = json_decode( (string) $this->stored(), true );
			if ( is_array( $state ) && (int) ( $state['expires'] ?? 0 ) >= time() ) {
				return null;
			}
			$ticket  = bin2hex( random_bytes( 32 ) );
			$value   = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fail closed with JSON_THROW_ON_ERROR instead of encoding recovery.
				array(
					'hash'    => hash( 'sha256', $ticket ),
					'expires' => time() + 60,
				),
				JSON_THROW_ON_ERROR
			);
			$written = $this->database->query(
				$this->database->prepare(
					"INSERT INTO {$this->options_table()} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
					self::OPTION,
					$value
				)
			);
			if ( false === $written ) {
				throw new RuntimeException( 'Evaluation signal unavailable.' );
			}
			return $ticket;
		} finally {
			$this->scalar( 'SELECT RELEASE_LOCK(%s)', $name );
		}
	}

	/**
	 * Consume only the matching unexpired ticket with an atomic delete.
	 *
	 * @param string $ticket Untrusted random token.
	 */
	private function consume( string $ticket ): bool {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $ticket ) ) {
			return false;
		}
		$value = $this->stored();
		$state = is_string( $value ) ? json_decode( $value, true ) : null;
		if ( ! is_array( $state ) || (int) ( $state['expires'] ?? 0 ) < time()
			|| ! is_string( $state['hash'] ?? null ) || ! hash_equals( $state['hash'], hash( 'sha256', $ticket ) ) ) {
			return false;
		}
		return 1 === $this->database->query(
			$this->database->prepare(
				"DELETE FROM {$this->options_table()} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$value
			)
		);
	}

	/** Read signal state directly, without a possibly stale options cache. */
	private function stored(): mixed {
		return $this->scalar( "SELECT option_value FROM {$this->options_table()} WHERE option_name = %s", self::OPTION );
	}

	/**
	 * Execute a prepared scalar query and detect storage failures.
	 *
	 * @param string $sql Fixed SQL template.
	 * @param string $argument Bound value.
	 * @throws RuntimeException If storage is unavailable.
	 */
	private function scalar( string $sql, string $argument ): mixed {
		$value = $this->database->get_var( $this->database->prepare( $sql, $argument ) );
		if ( '' !== ( $this->database->last_error ?? '' ) ) {
			throw new RuntimeException( 'Evaluation dispatch state unavailable.' );
		}
		return $value;
	}

	/**
	 * Validate the WordPress-owned options table identifier.
	 *
	 * @throws RuntimeException If the configured identifier is invalid.
	 */
	private function options_table(): string {
		$table = (string) ( $this->database->options ?? '' );
		if ( 1 !== preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'WordPress options unavailable.' );
		}
		return $table;
	}

	/**
	 * Scope advisory locks to the database and WordPress site.
	 *
	 * @param string $purpose Fixed lock purpose.
	 */
	private function lock_name( string $purpose ): string {
		return 'oj_ai_eval_' . $purpose . '_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->options_table() ), 0, 40 );
	}

	/**
	 * Announce a contained dispatch failure without any AI input or token details.
	 *
	 * Runtime code must not write to the PHP error log; durable cron recovery
	 * remains scheduled, and site operators may observe this action instead.
	 */
	private function report_failure(): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'oj_ai_dashboard_evaluation_dispatch_unavailable' );
		}
	}
}
