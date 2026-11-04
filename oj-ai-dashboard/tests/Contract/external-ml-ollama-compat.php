<?php
/**
 * Synthetic native Ollama HTTP contract; no network or real student records.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/external-ml-assessment.php';

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiHttpResponse;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiHttpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiStructuredOutput;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai\AiHttpJson;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai\OllamaClient;
use OJAI\Dashboard\ExternalAssessment\Assessment_Gateway_V1;
use OJAI\Dashboard\ExternalAssessment\Assessment_Profile;

/** In-memory wire implementation that rejects complex native schemas. */
final class ML_Ollama_Compat_Transport implements AiHttpTransport {
	/**
	 * Set the synthetic model output.
	 *
	 * @param array $output Normalized fixture result.
	 */
	public function __construct( public array $output ) {}
	/**
	 * Captured synthetic requests only.
	 *
	 * @var array
	 */
	public array $bodies = array();
	/**
	 * Simulate context overflow independently from schema support.
	 *
	 * @var bool
	 */
	public bool $context_overflow = false;

	/**
	 * Retain the exact wire body and return a deterministic synthetic response.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $url Endpoint.
	 * @param array       $headers Synthetic authentication headers.
	 * @param string|null $body JSON body.
	 * @param int         $connectTimeoutSeconds Connection timeout.
	 * @param int         $timeoutSeconds Response timeout.
	 * @param array       $expectedResolvedIps Fixed test addresses.
	 * @return AiHttpResponse
	 * @throws \Throwable On invalid fixture inputs.
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $connectTimeoutSeconds, int $timeoutSeconds, array $expectedResolvedIps ): AiHttpResponse {
		ml_ai_assert( 'POST' === $method && 'https://fixture.invalid/api/chat' === $url, 'Only the fake native chat endpoint is used.' );
		$wire           = json_decode( $body, true, 30, JSON_THROW_ON_ERROR );
		$this->bodies[] = $wire;
		if ( $this->context_overflow ) {
			return new AiHttpResponse( 400, array(), '{"error":"input length exceeds context length"}', 3 );
		}
		if ( array( 'type' => 'object' ) !== $wire['format'] ) {
			return new AiHttpResponse( 400, array(), '{"error":"fixture schema rejection"}', 3 );
		}
		return new AiHttpResponse(
			200,
			array(),
			Assessment_Profile::encode(
				array(
					'done'        => true,
					'done_reason' => 'stop',
					'message'     => array( 'content' => Assessment_Profile::encode( $this->output ) ),
				)
			),
			3
		);
	}
}

$transport      = new ML_Ollama_Compat_Transport( $output );
$client         = new OllamaClient( new AiHttpJson( $transport ) );
$native_profile = new AiProviderProfile( 'fixture', 'Synthetic native Ollama', 'ollama', 'https://fixture.invalid', 'x_api_key', 'not-a-secret', 'fixture-model', 'fixture-revision', array( Assessment_Profile::TASK ), true, 60, array( '127.0.0.1' ), str_repeat( 'f', 64 ) );
$native_store   = new ML_Assessment_Memory_Store();
$native_gateway = new Assessment_Gateway_V1(
	$native_store,
	static fn(): array => array( Assessment_Profile::SOURCE => $source ),
	static fn(): array => $route,
	static function ( array $run, array $input ) use ( $client, $native_profile, $route ): array {
		try {
			$reply = $client->generateStructured(
				$native_profile,
				array(
					array(
						'role'    => 'user',
						'content' => Assessment_Profile::encode( $input ),
					),
				),
				4000,
				AiStructuredOutput::fromInput( $input ),
				array( 'num_ctx' => 32768 )
			);
			return array(
				'success'             => true,
				'output'              => $reply,
				'routing_fingerprint' => $route['fingerprint'],
			);
		} catch ( AiProviderException $error ) {
			// The real public API may expose only a coarser uncertain outcome.
			return array(
				'success'    => false,
				'error_code' => $error->safeCode(),
			);
		}
	},
	static function (): void {}
);
$native_gateway->enqueue( Assessment_Profile::SOURCE, $id, 7, $consent );
$native_gateway->run_once();
ml_ai_assert( 'ml_ai_provider_invalid_request' === $native_store->runs[1]['error_code'] && 1 === count( $transport->bodies ), 'HTTP 400 does not cause an automatic second request.' );
$native_gateway->enqueue( Assessment_Profile::SOURCE, $id, 7, $consent + array( 'outputMode' => 'json_compat' ) );
$native_gateway->run_once();
ml_ai_assert( 'ready' === $native_store->runs[2]['state'] && 2 === count( $transport->bodies ), 'Explicit compatibility request may recover a schema-specific rejection.' );
foreach ( $transport->bodies as $wire ) {
	ml_ai_assert( false === $wire['truncate'] && false === $wire['shift'], 'Both modes preserve whole-context handling.' );
	ml_ai_assert( 32768 === $wire['options']['num_ctx'], 'Configured context budget is never silently reduced.' );
	ml_ai_assert( str_contains( $wire['messages'][1]['content'], Assessment_Profile::encode( $source->data['evidence']['portfolio'] ) ), 'Complete synthetic student evidence reaches each mode.' );
}
$transport->context_overflow                 = true;
$source->data['assignment']['instructions'] .= ' context fixture';
$native_gateway->enqueue( Assessment_Profile::SOURCE, $id, 7, $consent + array( 'outputMode' => 'json_compat' ) );
$native_gateway->run_once();
ml_ai_assert( 'ml_ai_provider_context_too_small' === $native_store->runs[3]['error_code'] && null === $native_store->outputs[3], 'Compatibility must fail on known context overflow rather than return a truncated evaluation.' );
$transport->context_overflow                 = false;
$transport->output['criteria'][0]['score']   = 10000;
$source->data['assignment']['instructions'] .= ' invalid score fixture';
$native_gateway->enqueue( Assessment_Profile::SOURCE, $id, 7, $consent + array( 'outputMode' => 'json_compat' ) );
$native_gateway->run_once();
ml_ai_assert( 'ml_ai_invalid_output' === $native_store->runs[4]['error_code'] && null === $native_store->outputs[4], 'Simplified generation never relaxes the real rubric score validator.' );
echo "ML native Ollama synthetic wire: explicit retry, simplified schema, intact evidence, whole-context guard and overflow rejection passed.\n";
