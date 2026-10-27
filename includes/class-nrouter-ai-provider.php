<?php
/**
 * 9Router provider implementation for the WP AI Client (AiClient).
 *
 * Registers a custom "9router" provider with the default provider registry so
 * the WordPress 7.x Connectors screen and the AI plugin treat it as a first
 * class OpenAI-compatible provider, using the Base URL + API key configured in
 * this plugin's settings.
 *
 * @package nrouter-ai
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
	return;
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Strip 9Router's trailing "data: [DONE]" SSE marker before parsing.
 *
 * @param Response $response Raw response.
 * @return Response
 */
function nrouter_ai_clean_response( Response $response ): Response {
	$body = null === $response->getBody() ? '' : (string) $response->getBody();
	if ( '' !== $body && 1 === preg_match( '/data:\s*\[DONE\]\s*$/', trim( $body ) ) ) {
		$end = strrpos( $body, '}' );
		if ( false !== $end ) {
			return new Response( $response->getStatusCode(), $response->getHeaders(), substr( $body, 0, $end + 1 ) );
		}
	}
	return $response;
}

/**
 * Reorder model metadata so the plugin's "Default model" option comes first.
 *
 * The WP AI Client PromptBuilder, when a prompt specifies no model preference,
 * falls back to the first candidate model returned by the provider's model
 * directory. Putting the configured default first makes the "Default model"
 * setting on the 9Router settings page authoritative for all AI features.
 *
 * @param list<ModelMetadata> $list Parsed model metadata.
 * @return list<ModelMetadata>
 */
function nrouter_ai_preferred_models_first( array $list ) {
	$preferred = '';
	if ( function_exists( 'nrouter_get_model' ) ) {
		$preferred = trim( (string) nrouter_get_model() );
	}
	if ( '' === $preferred || 2 > count( $list ) ) {
		return $list;
	}

	$default  = null;
	$priority = array();
	foreach ( $list as $model ) {
		if ( null === $default && $model->getId() === $preferred ) {
			$default = $model;
			continue;
		}
		$priority[] = $model;
	}

	// Fuzzy match: for a short name like "glm", also prefer "xxx/glm…" entries.
	if ( null === $default ) {
		foreach ( $list as $model ) {
			if ( 1 === preg_match( '#(^|/)' . preg_quote( $preferred, '#' ) . '(/|$)#i', $model->getId() ) ) {
				$default = $model;
				break;
			}
		}
	}

	if ( null === $default ) {
		return $list;
	}
	array_unshift( $priority, $default );
	return $priority;
}

/**
 * Request options for 9Router calls.
 *
 * The WP AI Client HTTP adapter falls back to WordPress' 5-second default
 * timeout when no explicit timeout is given, which is far too short for
 * chat completions — LLM generation commonly takes 10–90+ seconds.
 *
 * @return RequestOptions
 */
function nrouter_ai_request_options(): RequestOptions {
	static $options = null;
	if ( null === $options ) {
		$options = new RequestOptions();
		$options->setTimeout( (float) apply_filters( 'nrouter_ai_request_timeout', 120.0 ) );
		$options->setConnectTimeout( 10.0 );
	}
	return $options;
}

if ( ! class_exists( 'NRouter_Ai_Text_Model' ) ) :

	/**
	 * OpenAI-compatible text generation model pointing at the 9Router endpoint.
	 */
	class NRouter_Ai_Text_Model extends AbstractOpenAiCompatibleTextGenerationModel {

		/**
		 * {@inheritDoc}
		 */
		protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
			return new Request( $method, NRouter_Ai_Provider::url( '/v1/' . ltrim( $path, '/' ) ), $headers, $data, nrouter_ai_request_options() );
		}

		/**
		 * {@inheritDoc}
		 */
		protected function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
			return parent::parseResponseToGenerativeAiResult( nrouter_ai_clean_response( $response ) );
		}
	}

endif;

if ( ! class_exists( 'NRouter_Ai_Models_Directory' ) ) :

	/**
	 * Model metadata directory fetching /v1/models from 9Router.
	 */
	class NRouter_Ai_Models_Directory extends AbstractOpenAiCompatibleModelMetadataDirectory {

		/**
		 * {@inheritDoc}
		 */
		protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
			return new Request( $method, NRouter_Ai_Provider::url( '/v1/' . ltrim( $path, '/' ) ), $headers, $data, nrouter_ai_request_options() );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Same as the parent, but strips 9Router's trailing "data: [DONE]" marker
		 * before parsing (it breaks json_decode downstream).
		 */
		protected function sendListModelsRequest(): array {
			$http_transporter = $this->getHttpTransporter();
			$request          = $this->createRequest( HttpMethodEnum::GET(), 'models' );
			$request          = $this->getRequestAuthentication()->authenticateRequest( $request );
			$response         = $http_transporter->send( $request );
			$response         = nrouter_ai_clean_response( $response );
			$this->throwIfNotSuccessful( $response );

			$model_metadata_map = array();
			foreach ( $this->parseResponseToModelMetadataList( $response ) as $model_metadata ) {
				$model_metadata_map[ $model_metadata->getId() ] = $model_metadata;
			}
			return $model_metadata_map;
		}

		/**
		 * {@inheritDoc}
		 */
		protected function parseResponseToModelMetadataList( Response $response ): array {
			$data = $response->getData();
			if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
				return array();
			}

			$list = array();
			foreach ( $data['data'] as $model ) {
				$id = is_array( $model ) ? (string) ( $model['id'] ?? '' ) : '';
				if ( '' === $id ) {
					continue;
				}

				$capabilities = array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() );

				$raw_caps = is_array( $model ) ? ( $model['capabilities'] ?? array() ) : array();
				if ( ! empty( $raw_caps['imageOutput'] ) || 1 === preg_match( '/(dall-e|imagen|image(-|_)gen|flux|midjourney|gpt-image)/i', $id ) ) {
					$capabilities[] = CapabilityEnum::imageGeneration();
				}
				if ( 1 === preg_match( '/(embedding|embed)/i', $id ) ) {
					$capabilities[] = CapabilityEnum::embeddingGeneration();
				}

			/*
			 * Declare the standard OpenAI-compatible options so the WP AI
			 * Client model matcher accepts prompts using them. `null` means
			 * any value is accepted; the /chat/completions endpoint handles
			 * or safely ignores these parameters.
			 */
			$supported_options = array(
				new SupportedOption( OptionEnum::inputModalities() ),
				new SupportedOption( OptionEnum::outputModalities() ),
				new SupportedOption( OptionEnum::systemInstruction() ),
				new SupportedOption( OptionEnum::candidateCount() ),
				new SupportedOption( OptionEnum::maxTokens() ),
				new SupportedOption( OptionEnum::temperature() ),
				new SupportedOption( OptionEnum::topP() ),
			);

			$list[] = new ModelMetadata( $id, $id, $capabilities, $supported_options );
			}

			return nrouter_ai_preferred_models_first( $list );
		}
	}

endif;

if ( ! class_exists( 'NRouter_Ai_Provider_Availability' ) ) :

	/**
	 * Availability check that forwards transporter/auth to the shared model
	 * metadata directory (ListModelsApiBasedProviderAvailability does not
	 * implement the With* injection interfaces, so core's registry wiring
	 * would never reach its wrapped directory).
	 */
	class NRouter_Ai_Provider_Availability implements ProviderAvailabilityInterface, \WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface, \WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface {

		/**
		 * {@inheritDoc}
		 */
		public function setHttpTransporter( \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter ): void {
			NRouter_Ai_Provider::modelMetadataDirectory()->setHttpTransporter( $http_transporter );
		}

		/**
		 * {@inheritDoc}
		 */
		public function setRequestAuthentication( \WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface $request_authentication ): void {
			NRouter_Ai_Provider::modelMetadataDirectory()->setRequestAuthentication( $request_authentication );
		}

		/**
		 * {@inheritDoc}
		 */
		public function getHttpTransporter(): \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
			return NRouter_Ai_Provider::modelMetadataDirectory()->getHttpTransporter();
		}

		/**
		 * {@inheritDoc}
		 */
		public function getRequestAuthentication(): \WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface {
			return NRouter_Ai_Provider::modelMetadataDirectory()->getRequestAuthentication();
		}

		/**
		 * {@inheritDoc}
		 */
		public function isConfigured(): bool {
			try {
				NRouter_Ai_Provider::modelMetadataDirectory()->listModelMetadata();
				return true;
			} catch ( \Throwable $e ) {
				return false;
			}
		}
	}

endif;

if ( ! class_exists( 'NRouter_Ai_Provider' ) ) :

	/**
	 * The 9Router AI provider (OpenAI-compatible, self-hosted).
	 */
	class NRouter_Ai_Provider extends AbstractApiProvider {

		/**
		 * {@inheritDoc}
		 */
		protected static function baseUrl(): string {
			return untrailingslashit( nrouter_get_base_url() );
		}

		/**
		 * {@inheritDoc}
		 */
		protected static function createProviderMetadata(): ProviderMetadata {
			return new ProviderMetadata(
				'9router',
				__( '9Router', 'nrouter-ai' ),
				ProviderTypeEnum::SERVER(),
				null,
				RequestAuthenticationMethod::apiKey(),
				__( 'Self-hosted OpenAI-compatible AI router (9Router) with multiple model backends.', 'nrouter-ai' ),
				null
			);
		}

		/**
		 * {@inheritDoc}
		 */
		protected static function createProviderAvailability(): ProviderAvailabilityInterface {
			return new NRouter_Ai_Provider_Availability();
		}

		/**
		 * {@inheritDoc}
		 */
		protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
			return new NRouter_Ai_Models_Directory();
		}

		/**
		 * {@inheritDoc}
		 */
		protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
			return new NRouter_Ai_Text_Model( $model_metadata, $provider_metadata );
		}
	}

endif;

/**
 * Register the 9Router provider + authentication with the WP AI Client.
 *
 * Runs early on init (before core's init-20 hook that passes stored connector
 * keys to the AI client).
 */
function nrouter_ai_register_provider() {
	if ( ! class_exists( 'WordPress\AiClient\AiClient' ) || ! class_exists( 'NRouter_Ai_Provider' ) || ! function_exists( 'nrouter_get_api_key' ) ) {
		return;
	}

	try {
		$registry = AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( '9router' ) ) {
			$registry->registerProvider( 'NRouter_Ai_Provider' );
		}

		$key = nrouter_get_api_key();
		if ( '' !== $key ) {
			$registry->setProviderRequestAuthentication( '9router', new ApiKeyRequestAuthentication( $key ) );
		}
	} catch ( Exception $e ) {
		wp_trigger_error( 'nrouter_ai_register_provider', $e->getMessage() );
	}
}

add_action( 'init', 'nrouter_ai_register_provider', 5 );

/**
 * Allow requests WordPress would consider unsafe, but only to the configured
 * 9Router base URL.
 *
 * The WP AI Client sends its requests through wp_safe_remote_request(), which
 * rejects loopback/private URLs by default. A 9Router on the same machine
 * (e.g. http://127.0.0.1:PORT) would be blocked — this unblocks exactly one
 * URL, the configured base URL, leaving all other safety checks intact.
 *
 * @param array  $args HTTP request arguments.
 * @param string $url  Request URL.
 * @return array
 */
function nrouter_allow_local_http( $args, $url ) {
	$base = nrouter_get_base_url();
	if ( '' !== $base && is_string( $url ) && 0 === stripos( $url, $base ) ) {
		$args['reject_unsafe_urls'] = false;
		// Safety net: WordPress' 5s default aborts LLM completions mid-run.
		if ( ! isset( $args['timeout'] ) || (int) $args['timeout'] < 60 ) {
			$args['timeout'] = (int) apply_filters( 'nrouter_ai_request_timeout', 120 );
		}
	}
	return $args;
}
add_filter( 'http_request_args', 'nrouter_allow_local_http', 10, 2 );
