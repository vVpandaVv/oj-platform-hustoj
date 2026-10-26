<?php
/**
 * Approve purge.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Preview_Token_Verifier_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approve purge.
 */
final class Approve_Purge {
	public const PREVIEW_SCHEMA              = 'oj_ai.purge_preview';
	public const PREVIEW_DATABASE_VERSION    = 1;
	private const BACKUP_MAXIMUM_AGE_SECONDS = 86400;
	private const APPROVAL_TTL_SECONDS       = 1800;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize purge dependencies and state.
	 *
	 * @param Purge_Preview_Builder                       $previews Previews.
	 * @param Purge_Preview_Token_Verifier_Port_Interface $tokens Tokens.
	 * @param Purge_Site_Port_Interface                   $sites Sites.
	 * @param Purge_Approval_Repository_Port_Interface    $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                         $ids Ids.
	 * @param Canonical_Json                              $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                               $clock Clock.
	 */
	public function __construct(
		private Purge_Preview_Builder $previews,
		private Purge_Preview_Token_Verifier_Port_Interface $tokens,
		private Purge_Site_Port_Interface $sites,
		private Purge_Approval_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute purge.
	 *
	 * @param Approve_Purge_Command $command Command.
	 * @return Approve_Purge_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Approve_Purge_Command $command ): Approve_Purge_Result {
		try {
			$token   = $this->tokens->verify( $command->preview_token() );
			$site_id = $this->sites->site_id();
			if (
				! hash_equals( $site_id, $token['site'] )
				|| ! hash_equals( $command->idempotency_key_hash(), $token['idempotency_key_hash'] )
			) {
				throw Purge_Failure::because( Purge_Failure::TOKEN_SCOPE_MISMATCH );
			}

			$approved   = $this->now();
			$backup_age = $approved->getTimestamp() - $command->backup_verified_at_utc()->getTimestamp();
			if ( $backup_age < 0 || $backup_age > self::BACKUP_MAXIMUM_AGE_SECONDS ) {
				throw Purge_Failure::because( Purge_Failure::BACKUP_INVALID );
			}

			$preview = $this->previews->build( $command->scope_type() );
			if ( (int) ( $preview['private_files']['legal_hold_count'] ?? -1 ) > 0 ) {
				throw Purge_Failure::because( Purge_Failure::LEGAL_HOLD_BLOCKED );
			}
			if (
				! hash_equals( (string) $preview['scope_hash'], $token['scope_fingerprint'] )
				|| ! hash_equals( (string) $preview['preview_hash'], $token['preview_hash'] )
			) {
				throw Purge_Failure::because( Purge_Failure::PREVIEW_STALE );
			}

			$preview_without_hash = $preview;
			unset( $preview_without_hash['preview_hash'] );
			$preview_hash = hash( 'sha256', $this->canonical->encode( $preview_without_hash ) );
			if ( ! hash_equals( (string) $preview['preview_hash'], $preview_hash ) ) {
				throw Purge_Failure::because( Purge_Failure::PREVIEW_STALE );
			}
			$preview_json      = $this->canonical->encode( $preview );
			$preview_json_hash = hash( 'sha256', $preview_json );
			$approved_at_utc   = $approved->format( 'Y-m-d H:i:s' );
			$expires_at_utc    = $approved->modify( '+' . self::APPROVAL_TTL_SECONDS . ' seconds' )->format( 'Y-m-d H:i:s' );

			return $this->repository->approve(
				array(
					'actor_user_id'            => $command->actor_user_id(),
					'approval_expires_at_utc'  => $expires_at_utc,
					'approved_at_utc'          => $approved_at_utc,
					'audit_public_id'          => $this->id(),
					'backup_evidence_digest'   => $command->backup_evidence_digest(),
					'backup_verified_at_utc'   => $command->backup_verified_at_utc()->format( 'Y-m-d H:i:s' ),
					'command_id'               => $this->id(),
					'command_storage_key'      => $command->idempotency_storage_key(),
					'confirmation_phrase_hash' => $command->confirmation_phrase_hash(),
					'correlation_id'           => $command->correlation_id(),
					'job_public_id'            => $this->id(),
					'outbox_public_id'         => $this->id(),
					'preview_hash'             => $preview_hash,
					'preview_json'             => $preview_json,
					'preview_json_hash'        => $preview_json_hash,
					'preview_token_hash'       => $command->preview_token_hash(),
					'request_digest'           => $command->request_digest(),
					'scope_hash'               => (string) $preview['scope_hash'],
					'scope_type'               => $command->scope_type(),
				)
			);
		} catch ( Purge_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::APPROVAL_INVALID, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	private function id(): string {
		return $this->ids->create()->value();
	}

	/**
	 * Now.
	 *
	 * @return DateTimeImmutable
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): DateTimeImmutable {
		$value = ( $this->clock )();
		if ( ! $value instanceof DateTimeImmutable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $value->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}
