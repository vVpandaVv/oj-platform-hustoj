<?php
/**
 * Approve legacy migration.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Mapping_Decision_Validator_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Preview_Token_Verifier_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approve legacy migration.
 */
final class Approve_Legacy_Migration {
	public const PREVIEW_PROFILE          = 'oj_ai.legacy_migration_preview@1.0.0';
	public const PREVIEW_SCHEMA           = 'oj_ai.legacy_migration_preview';
	public const PREVIEW_DATABASE_VERSION = 1;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize legacy migration dependencies and state.
	 *
	 * @param Legacy_Source_Inspector_Port_Interface                $sources Sources.
	 * @param Legacy_Preview_Token_Verifier_Port_Interface          $tokens Tokens.
	 * @param Legacy_Migration_Site_Port_Interface                  $sites Sites.
	 * @param Legacy_Migration_Approval_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                   $ids Ids.
	 * @param Canonical_Json                                        $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                         $clock Clock.
	 * @param Legacy_Mapping_Decision_Validator_Port_Interface|null $mapping_validator Mapping validator.
	 */
	public function __construct(
		private Legacy_Source_Inspector_Port_Interface $sources,
		private Legacy_Preview_Token_Verifier_Port_Interface $tokens,
		private Legacy_Migration_Site_Port_Interface $sites,
		private Legacy_Migration_Approval_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		private ?Legacy_Mapping_Decision_Validator_Port_Interface $mapping_validator = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute legacy migration.
	 *
	 * @param Approve_Legacy_Migration_Command $command Command.
	 * @return Approve_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Approve_Legacy_Migration_Command $command ): Approve_Legacy_Migration_Result {
		try {
			$token   = $this->tokens->verify( $command->preview_token() );
			$site_id = $this->sites->site_id();
			if (
				! hash_equals( $site_id, $token['site'] )
				|| ! hash_equals( $command->idempotency_key_hash(), $token['idempotency_key_hash'] )
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::TOKEN_SCOPE_MISMATCH );
			}

			$source = $this->sources->inspect( $command->source_profile() );
			if ( $source->profile() !== $command->source_profile() ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
			}
			$plans        = new Legacy_Migration_Plan( $this->canonical );
			$preview_plan = $plans->build( $site_id, $source );
			$plans->assert_valid( $preview_plan );
			if (
				! hash_equals( $source->source_fingerprint(), $token['source_fingerprint'] )
				|| ! hash_equals( (string) $preview_plan['plan_hash'], $token['plan_hash'] )
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
			}
			try {
				$plan = $plans->build( $site_id, $source, $command->mapping_decisions() );
				$plans->assert_executable( $plan );
			} catch ( InvalidArgumentException $error ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED, $error );
			}
			if ( array() !== $plan['mapping_manifest'] ) {
				if ( ! $this->mapping_validator instanceof Legacy_Mapping_Decision_Validator_Port_Interface ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
				}
				$this->mapping_validator->validate(
					$command->actor_user_id(),
					$plan['mapping_manifest'],
					$plan['mapping_decisions']
				);
			}

			$preview         = array(
				'profile' => self::PREVIEW_PROFILE,
				'state'   => 'approved',
				'plan'    => $plan,
			);
			$preview_json    = $this->canonical->encode( $preview );
			$approved_at_utc = $this->now();
			$source_kind     = strstr( $source->profile(), '@', true );
			if ( ! is_string( $source_kind ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{2,63}$/D', $source_kind ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
			}

			return $this->repository->approve(
				array(
					'actor_user_id'       => $command->actor_user_id(),
					'audit_public_id'     => $this->id(),
					'command_id'          => $this->id(),
					'command_storage_key' => $command->idempotency_storage_key(),
					'correlation_id'      => $command->correlation_id(),
					'outbox_public_id'    => $this->id(),
					'preview_json'        => $preview_json,
					'preview_hash'        => hash( 'sha256', $preview_json ),
					'preview_token_hash'  => $command->preview_token_hash(),
					'request_digest'      => $command->request_digest(),
					'run_public_id'       => $this->id(),
					'source_fingerprint'  => $source->source_fingerprint(),
					'source_kind'         => $source_kind,
					'source_profile'      => $source->profile(),
					'source_version'      => $source->source_version(),
					'plan_hash'           => (string) $plan['plan_hash'],
					'item_total'          => (int) $plan['counts']['migration_candidates'],
					'approved_at_utc'     => $approved_at_utc,
				)
			);
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::TOKEN_INVALID, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
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
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
