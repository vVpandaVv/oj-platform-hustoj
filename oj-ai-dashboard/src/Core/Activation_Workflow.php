<?php
/**
 * Activation schema workflow.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Core;

use Closure;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Installer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the schema and stores the code version only after verified success.
 */
final class Activation_Workflow {
	/**
	 * Installer.
	 *
	 * @var Schema_Installer_Port_Interface
	 */
	private Schema_Installer_Port_Interface $installer;
	/**
	 * Version writer.
	 *
	 * @var Closure
	 */
	private Closure $version_writer;
	/**
	 * Configuration bootstrap.
	 *
	 * @var Closure|null
	 */
	private ?Closure $configuration_bootstrap;

	/**
	 * Initialize activation workflow dependencies and state.
	 *
	 * @param Schema_Installer_Port_Interface $installer Installer.
	 * @param Closure                         $version_writer Version writer.
	 * @param Closure|null                    $configuration_bootstrap Configuration bootstrap.
	 */
	public function __construct(
		Schema_Installer_Port_Interface $installer,
		Closure $version_writer,
		?Closure $configuration_bootstrap = null
	) {
		$this->installer               = $installer;
		$this->version_writer          = $version_writer;
		$this->configuration_bootstrap = $configuration_bootstrap;
	}

	/**
	 * Run activation workflow.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @param string $code_version Code version.
	 * @return array{code:string,message:string,correlation_id:string,diagnostic_code?:string}|null
	 */
	public function run( int $actor_user_id, string $correlation_id, string $code_version ): ?array {
		try {
			$result = $this->installer->install( $actor_user_id, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem(
				'schema_installation_failed',
				'활동지 데이터 공간을 준비하지 못했습니다. 다시 시도해 주세요.',
				$correlation_id
			);
		}

		if ( ! $result->succeeded() ) {
			return $this->schema_problem( $result );
		}

		if ( null !== $this->configuration_bootstrap ) {
			try {
				$bootstrapped = ( $this->configuration_bootstrap )( $actor_user_id, $correlation_id );
			} catch ( Throwable ) {
				$bootstrapped = false;
			}
			if ( true !== $bootstrapped ) {
				return $this->problem(
					'assessment_configuration_bootstrap_failed',
					'기본 평가 설정을 준비하지 못했습니다. 다시 시도해 주세요.',
					$correlation_id
				);
			}
		}

		try {
			$stored = ( $this->version_writer )( $code_version );
		} catch ( Throwable ) {
			$stored = false;
		}
		if ( true !== $stored ) {
			return $this->problem(
				'addon_version_option_write_failed',
				'설치 정보를 기록하지 못했습니다. 다시 시도해 주세요.',
				$correlation_id
			);
		}

		return null;
	}

	/**
	 * Schema problem.
	 *
	 * @param Schema_Installation_Result $result Result.
	 * @return array{code:string,message:string,correlation_id:string}
	 */
	private function schema_problem( Schema_Installation_Result $result ): array {
		$code            = 1 === preg_match( '/^[a-z][a-z0-9_]{2,99}$/', $result->code() )
			? $result->code()
			: 'schema_installation_failed';
		$diagnostic_code = $this->diagnostic_code( $result );

		if ( Schema_Installation_Result::LOCK_UNAVAILABLE === $result->status() || 'schema_lock_unavailable' === $code ) {
			return $this->problem(
				$code,
				'설치 작업이 진행 중입니다. 잠시 후 다시 시도해 주세요.',
				$result->correlation_id(),
				$diagnostic_code
			);
		}

		if ( 'schema_owner_conflict' === $code ) {
			return $this->problem(
				$code,
				'기존 데이터 확인이 필요합니다.',
				$result->correlation_id(),
				$diagnostic_code
			);
		}

		if (
			Schema_Installation_Result::REPAIR_REQUIRED === $result->status()
			|| Schema_Installation_Result::BLOCKED === $result->status()
		) {
			return $this->problem(
				$code,
				'데이터 구조를 확인해야 합니다. 운영자 복구를 진행해 주세요.',
				$result->correlation_id(),
				$diagnostic_code
			);
		}

		return $this->problem(
			$code,
			'활동지 데이터 공간을 준비하지 못했습니다. 다시 시도해 주세요.',
			$result->correlation_id(),
			$diagnostic_code
		);
	}

	/**
	 * Problem.
	 *
	 * @param string      $code Code.
	 * @param string      $message Message.
	 * @param string      $correlation_id Request correlation identifier for diagnostics.
	 * @param string|null $diagnostic_code Diagnostic code.
	 * @return array{code:string,message:string,correlation_id:string,diagnostic_code?:string}
	 */
	private function problem(
		string $code,
		string $message,
		string $correlation_id,
		?string $diagnostic_code = null
	): array {
		$problem = array(
			'code'           => $code,
			'message'        => $message,
			'correlation_id' => $correlation_id,
		);
		if ( null !== $diagnostic_code ) {
			$problem['diagnostic_code'] = $diagnostic_code;
		}

		return $problem;
	}

	/**
	 * Diagnostic code.
	 *
	 * @param Schema_Installation_Result $result Result.
	 * @return string|null
	 */
	private function diagnostic_code( Schema_Installation_Result $result ): ?string {
		$fallback = null;
		foreach ( $result->issues() as $issue ) {
			$candidate = $issue['code'] ?? null;
			if ( ! is_string( $candidate ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/', $candidate ) ) {
				continue;
			}
			$fallback ??= $candidate;
			if ( ! in_array( $candidate, array( 'partial_schema', 'schema_preflight_blocked' ), true ) ) {
				return $candidate;
			}
		}

		return $fallback;
	}
}
