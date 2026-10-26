<?php
/**
 * Shared activation and runtime-upgrade coordinator.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Core;

use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;
use OJAI\Dashboard\Assessment\Configuration\Infrastructure\WordPress_Assessment_Configuration_Repository;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Installation_Runner_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the same verified installation workflow for activation and ZIP upgrades.
 */
final class Installation_Manager {
	/**
	 * Run an upgrade only when the stored add-on version differs from the code.
	 *
	 * @return array{code:string,message:string,correlation_id:string,diagnostic_code?:string}|null
	 */
	public static function run_pending_upgrade(): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$installed_version = trim( (string) get_option( 'oj_ai_dashboard_version', '' ) );
		$current_version   = (string) OJ_AI_DASHBOARD_VERSION;
		if ( $installed_version === $current_version ) {
			return null;
		}
		if (
			'' !== $installed_version
			&& 1 !== preg_match( '/^[0-9]+(?:\\.[0-9]+){1,3}(?:-[0-9A-Za-z.-]+)?$/D', $installed_version )
		) {
			return self::problem(
				'addon_version_state_invalid',
				'저장된 애드온 버전 상태를 확인할 수 없습니다.',
				self::correlation_id( 'upgrade' )
			);
		}
		if ( '' !== $installed_version && version_compare( $installed_version, $current_version, '>' ) ) {
			return self::problem(
				'addon_version_rollback_blocked',
				'설치된 애드온 데이터가 현재 코드보다 새 버전입니다.',
				self::correlation_id( 'upgrade' )
			);
		}

		return self::run( 'upgrade' );
	}

	/**
	 * Run schema verification, configuration bootstrap, then version storage.
	 *
	 * @param string $operation Operation.
	 * @return array{code:string,message:string,correlation_id:string,diagnostic_code?:string}|null
	 */
	public static function run( string $operation ): ?array {
		$correlation_id = self::correlation_id( $operation );
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return self::problem(
				'schema_database_unavailable',
				'데이터베이스 연결을 확인할 수 없습니다.',
				$correlation_id
			);
		}

		try {
			$installer = WordPress_Schema_Installation_Runner_Factory::create( $wpdb );
		} catch ( Throwable ) {
			return self::problem(
				'schema_database_adapter_unavailable',
				'데이터베이스 준비 상태를 확인할 수 없습니다.',
				$correlation_id
			);
		}

		$workflow = new Activation_Workflow(
			$installer,
			static function ( $version ) {
				update_option( 'oj_ai_dashboard_version', (string) $version, false );

				return (string) get_option( 'oj_ai_dashboard_version', '' ) === (string) $version;
			},
			static function ( int $actor_user_id, string $bootstrap_correlation_id ) use ( $wpdb ): bool {
				$canonical  = new Canonical_Json();
				$profile    = new Assessment_Configuration_Profile( $canonical );
				$repository = new WordPress_Assessment_Configuration_Repository(
					$canonical,
					new Public_Id_Generator(),
					$profile,
					$wpdb
				);
				$repository->seed_defaults( $actor_user_id, $bootstrap_correlation_id );
				$ids      = new Public_Id_Generator();
				$projects = new \OJAI\Dashboard\Assessment\Project\Infrastructure\WordPress_Project_Repository(
					$canonical,
					$ids,
					new \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration( $canonical, $ids ),
					$wpdb
				);
				$projects->migrate_unlinked();
				return true;
			}
		);

		return $workflow->run(
			function_exists( 'get_current_user_id' ) ? max( 0, (int) get_current_user_id() ) : 0,
			$correlation_id,
			(string) OJ_AI_DASHBOARD_VERSION
		);
	}

	/**
	 * Build a stable, non-secret installation correlation ID.
	 *
	 * @param string $operation Operation.
	 * @return string
	 */
	private static function correlation_id( string $operation ): string {
		$operation = in_array( $operation, array( 'activation', 'upgrade' ), true ) ? $operation : 'installation';
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$candidate = $operation . ':' . (string) wp_generate_uuid4();
			if ( 1 === preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', $candidate ) ) {
				return $candidate;
			}
		}

		try {
			return $operation . ':' . bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			return $operation . ':' . uniqid( '', true );
		}
	}

	/**
	 * Problem.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return array{code:string,message:string,correlation_id:string}
	 */
	private static function problem( string $code, string $message, string $correlation_id ): array {
		return array(
			'code'           => $code,
			'message'        => $message,
			'correlation_id' => $correlation_id,
		);
	}
}
