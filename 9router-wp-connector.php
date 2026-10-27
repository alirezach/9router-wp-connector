<?php
/**
 * Plugin Name: 9Router WP connector
 * Description: Connects WordPress to an OpenAI-compatible 9Router server (custom base URL, API key, default model, model loading via /v1/models, connection test/debug tools and optional API request logging with time-period retention). Registers a "9Router WP connector" card on the WordPress 7.x "Settings → Connectors" screen and as a provider for the AI plugin.
 * Version:     1.0.0
 * Author:      Alireza Ch
 * Author URI:  https://github.com/alirezach
 * Plugin URI:  https://github.com/alirezach/9router-wp-connector
 * License:     GPL-2.0-or-later
 * Text Domain: nrouter-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WP AI Client provider integration (Connectors screen + AI plugin support).
require_once __DIR__ . '/includes/class-nrouter-ai-provider.php';

/* -------------------------------------------------------------------------
 * Constants / options
 * ---------------------------------------------------------------------- */

define( 'NROUTER_VERSION', '1.0.0' );
define( 'NROUTER_LOG_TABLE', 'nrouter_api_logs' );
define( 'NROUTER_REST_NAMESPACE', 'nrouter/v1' );

/**
 * Default base URL. Empty by default so the plugin is server-agnostic.
 *
 * Each site admin enters their own 9Router URL in "Settings → 9Router WP connector"
 * (e.g. https://ai.example.com, or http://127.0.0.1:PORT for a
 * local install). Sites can also pin it via the NROUTER_BASE_URL constant/env var
 * or the nrouter_default_base_url filter.
 */
function nrouter_default_base_url() {
	if ( defined( 'NROUTER_BASE_URL' ) && '' !== NROUTER_BASE_URL ) {
		return NROUTER_BASE_URL;
	}
	$env = getenv( 'NROUTER_BASE_URL' );
	if ( false !== $env && '' !== $env ) {
		return $env;
	}
	return (string) apply_filters( 'nrouter_default_base_url', '' );
}

function nrouter_get_base_url() {
	$url = trim( (string) get_option( 'nrouter_base_url', '' ) );
	if ( '' === $url ) {
		$url = nrouter_default_base_url();
	}
	return untrailingslashit( $url );
}

/**
 * Resolve the API key. Priority: PHP constant -> environment variable ->
 * plugin setting -> Connectors screen setting (both UIs share the key).
 */
function nrouter_get_api_key() {
	if ( defined( 'NROUTER_API_KEY' ) && '' !== NROUTER_API_KEY ) {
		return NROUTER_API_KEY;
	}
	$env = getenv( 'NROUTER_API_KEY' );
	if ( false !== $env && '' !== $env ) {
		return $env;
	}
	$key = (string) get_option( 'nrouter_api_key', '' );
	if ( '' !== $key ) {
		return $key;
	}
	return (string) get_option( 'connectors_ai_9router_api_key', '' );
}

function nrouter_get_model() {
	return (string) get_option( 'nrouter_model', 'exp/gpt-4.1-nano' );
}

function nrouter_get_timeout() {
	return max( 1, (int) get_option( 'nrouter_timeout', 60 ) );
}

function nrouter_logging_enabled() {
	return (bool) get_option( 'nrouter_logging', 0 );
}

/* -------------------------------------------------------------------------
 * Connectors API integration (WordPress 7.0+)
 * ---------------------------------------------------------------------- */

add_action( 'wp_connectors_init', 'nrouter_register_connector', 20 );

/**
 * Register the 9Router connector so it appears on Settings → Connectors.
 * Shares the same API-key setting name as the plugin settings screen.
 *
 * @param WP_Connector_Registry $registry Connector registry instance.
 */
function nrouter_register_connector( $registry ) {
	if ( ! $registry || ! method_exists( $registry, 'is_registered' ) ) {
		return; // Older WP without the Connectors API.
	}

	$connector = array(
		'name'        => '9Router WP connector',
		'description' => __( 'Self-hosted OpenAI-compatible AI router (9Router) with multiple model backends.', 'nrouter-ai' ),
		'type'        => 'ai_provider',
		'authentication' => array(
			'method'          => 'api_key',
			'credentials_url' => admin_url( 'options-general.php?page=nrouter-ai' ),
			'setting_name'    => 'connectors_ai_9router_api_key',
		),
		'plugin'      => array(
			'file' => plugin_basename( __FILE__ ),
			// Required by core before the connector's REST setting is registered.
			'is_active' => static function () {
				$file    = plugin_basename( __DIR__ . '/' . basename( __FILE__ ) );
				$network = function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file );
				return $network || in_array( $file, (array) get_option( 'active_plugins', array() ), true );
			},
		),
	);

	// Registry rejects duplicates: unregister -> modify -> register.
	if ( $registry->is_registered( '9router' ) ) {
		$existing  = $registry->unregister( '9router' );
		$connector = array_merge( (array) $existing, $connector );
	}

	$registry->register( '9router', $connector );
}

// Keep the same canonical setting: the Connectors screen and the plugin
// settings screen read/write the identical option.
add_filter( 'pre_update_option_nrouter_api_key', 'nrouter_sync_connector_key', 10, 2 );

function nrouter_sync_connector_key( $new_value, $old_value ) {
	if ( $new_value !== get_option( 'connectors_ai_9router_api_key' ) ) {
		update_option( 'connectors_ai_9router_api_key', $new_value, false );
	}
	return $new_value;
}

// Mirror saves made from the Connectors screen back into the plugin option.
//
// NOTE: when the AI plugin's Key Encryption experiment is enabled, the
// `connectors_ai_9router_api_key` option is stored in the secrets store and the
// wp_options row always stays empty — update_option() then sees "no change" and
// the update_option_{name} action below never fires. The bridge-safe mirror
// lives in nrouter_backfill_api_key() (runs on init and compares via reads).
add_action( 'update_option_connectors_ai_9router_api_key', 'nrouter_sync_connector_key_reverse', 10, 2 );
add_action( 'add_option_connectors_ai_9router_api_key', 'nrouter_sync_connector_key_reverse_add', 10, 2 );

function nrouter_sync_connector_key_reverse( $old_value, $new_value ) {
	if ( $new_value !== get_option( 'nrouter_api_key' ) ) {
		update_option( 'nrouter_api_key', (string) $new_value, false );
	}
}

function nrouter_sync_connector_key_reverse_add( $option, $value ) {
	nrouter_sync_connector_key_reverse( '', $value );
}

/**
 * Keep nrouter_api_key in sync with the Connectors key (bridge-safe).
 *
 * Runs on init: reads the effective Connectors key (which the AI plugin's Key
 * Encryption experiment resolves from its secrets store) and mirrors it into the
 * plugin option when they differ. Forward sync then compares the two and finds
 * them equal, so no loop occurs.
 */
function nrouter_backfill_api_key() {
	$connector_key = (string) get_option( 'connectors_ai_9router_api_key', '' );
	if ( '' === $connector_key ) {
		return;
	}
	if ( (string) get_option( 'nrouter_api_key', '' ) !== $connector_key ) {
		update_option( 'nrouter_api_key', $connector_key, false );
	}
}
add_action( 'init', 'nrouter_backfill_api_key', 15 );

/* -------------------------------------------------------------------------
 * Core API helper
 * ---------------------------------------------------------------------- */

/**
 * Send a chat-completion request to the 9Router OpenAI-compatible endpoint.
 *
 * @param array $messages OpenAI messages array.
 * @param array $args     { model, temperature, max_tokens, endpoint, headers, timeout }.
 *
 * @return array|WP_Error Array with keys: status, latency_ms, usage, body (decoded), raw.
 */
function nrouter_ai_request( $messages, $args = array() ) {
	$key = nrouter_get_api_key();

	$defaults = array(
		'model'       => nrouter_get_model(),
		'endpoint'    => '/v1/chat/completions',
		'temperature' => null,
		'max_tokens'  => null,
		'timeout'     => nrouter_get_timeout(),
		'headers'     => array(),
	);
	$args = wp_parse_args( $args, $defaults );

	$url = nrouter_get_base_url() . $args['endpoint'];

	$payload = array(
		'model'    => $args['model'],
		'messages' => $messages,
	);
	if ( null !== $args['temperature'] ) {
		$payload['temperature'] = (float) $args['temperature'];
	}
	if ( null !== $args['max_tokens'] ) {
		$payload['max_tokens'] = (int) $args['max_tokens'];
	}

	$headers = array(
		'Content-Type' => 'application/json',
	);
	if ( '' !== $key ) {
		$headers['Authorization'] = 'Bearer ' . $key;
	}
	$headers = array_merge( $headers, (array) $args['headers'] );

	$start     = microtime( true );
	$body_json = wp_json_encode( $payload );
	$response  = wp_remote_post(
		$url,
		array(
			'timeout' => $args['timeout'],
			'headers' => $headers,
			'body'    => $body_json,
		)
	);
	$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = nrouter_decode_body( $raw );

	$usage = isset( $data['usage'] ) ? $data['usage'] : null;

	return array(
		'status'     => $code,
		'latency_ms' => $latency_ms,
		'usage'      => $usage,
		'body'       => $data,
		'raw'        => $raw,
	);
}

/**
 * Decode a 9Router/OpenAI JSON body. 9Router appends a trailing SSE marker
 * ("data: [DONE]") even to non-streaming responses, so strip anything after
 * the final closing brace before decoding.
 *
 * @param string $raw Raw HTTP body.
 * @return array|null
 */
function nrouter_decode_body( $raw ) {
	$raw = trim( (string) $raw );
	$data = json_decode( $raw, true );
	if ( null !== $data ) {
		return $data;
	}
	$end = strrpos( $raw, '}' );
	if ( false !== $end ) {
		$trimmed = json_decode( substr( $raw, 0, $end + 1 ), true );
		if ( null !== $trimmed ) {
			return $trimmed;
		}
	}
	// Last resort: pull the first line/object if the proxy split lines.
	foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || 'data: [DONE]' === $line ) {
			continue;
		}
		if ( 0 === strpos( $line, 'data:' ) ) {
			$line = trim( substr( $line, 5 ) );
		}
		$decoded = json_decode( $line, true );
		if ( null !== $decoded && isset( $decoded['choices'] ) ) {
			return $decoded;
		}
	}
	return null;
}

/**
 * Convenience wrapper: returns assistant text or a WP_Error.
 */
function nrouter_ai_completion( $prompt, $args = array() ) {
	$result = nrouter_ai_request(
		array(
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		),
		$args
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( 200 !== $result['status'] ) {
		$err = isset( $result['body']['error']['message'] ) ? $result['body']['error']['message'] : substr( $result['raw'], 0, 200 );
		return new WP_Error( 'nrouter_http_' . $result['status'], $err, $result );
	}
	$text = $result['body']['choices'][0]['message']['content'] ?? '';
	if ( '' === $text ) {
		return new WP_Error( 'nrouter_empty', __( 'Empty response from 9Router.', 'nrouter-ai' ), $result );
	}
	return $text;
}

/* -------------------------------------------------------------------------
 * Logging
 * ---------------------------------------------------------------------- */

function nrouter_logs_table() {
	global $wpdb;
	return $wpdb->prefix . NROUTER_LOG_TABLE;
}

function nrouter_create_logs_table() {
	global $wpdb;
	$table = nrouter_logs_table();
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		endpoint VARCHAR(255) NOT NULL DEFAULT '',
		model VARCHAR(191) NOT NULL DEFAULT '',
		status_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
		prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
		completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
		total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
		error_text TEXT NULL,
		request_body MEDIUMTEXT NULL,
		response_body MEDIUMTEXT NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'nrouter_db_version', NROUTER_VERSION, false );
}
add_action( 'plugins_loaded', 'nrouter_maybe_upgrade_db' );

function nrouter_maybe_upgrade_db() {
	if ( get_option( 'nrouter_db_version' ) !== NROUTER_VERSION ) {
		nrouter_create_logs_table();
	}
}

register_activation_hook( __FILE__, 'nrouter_create_logs_table' );

function nrouter_log_request( $endpoint, $model, $status, $latency_ms, $usage, $request_body, $response_body ) {
	if ( ! nrouter_logging_enabled() ) {
		return;
	}
	global $wpdb;

	// Truncate so huge payloads don't blow up the table.
	$max = 20000;
	$response_text = is_string( $response_body ) ? $response_body : wp_json_encode( $response_body );
	if ( function_exists( 'mb_substr' ) ) {
		$request_body  = mb_substr( (string) $request_body, 0, $max );
		$response_body = mb_substr( (string) $response_text, 0, $max );
	} else {
		$request_body  = substr( (string) $request_body, 0, $max );
		$response_body = substr( (string) $response_text, 0, $max );
	}

	$error_text = '';
	if ( 200 !== (int) $status ) {
		$decoded    = json_decode( $response_body, true );
		$error_text = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : ( $response_body ? substr( strip_tags( $response_body ), 0, 500 ) : 'HTTP ' . $status );
	}

	$wpdb->insert(
		nrouter_logs_table(),
		array(
			'created_at'       => current_time( 'mysql', true ),
			'endpoint'         => substr( (string) $endpoint, 0, 255 ),
			'model'            => substr( (string) $model, 0, 191 ),
			'status_code'      => (int) $status,
			'latency_ms'       => (int) $latency_ms,
			'prompt_tokens'    => (int) ( $usage['prompt_tokens'] ?? 0 ),
			'completion_tokens'=> (int) ( $usage['completion_tokens'] ?? 0 ),
			'total_tokens'     => (int) ( $usage['total_tokens'] ?? 0 ),
			'error_text'       => $error_text,
			'request_body'     => $request_body,
			'response_body'    => $response_body,
		),
		array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
	);

	// Enforce time-based retention, then cap the total number of entries.
	if ( 'full' !== nrouter_get_log_retention() ) {
		nrouter_purge_old_logs();
	}

	$keep = max( 50, (int) get_option( 'nrouter_log_max', 1000 ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM " . nrouter_logs_table() . " WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM " . nrouter_logs_table() . " ORDER BY id DESC LIMIT %d ) t )", $keep ) );
}

/**
 * Universal API logging: captures EVERY outbound request to the configured
 * 9Router base URL at the WordPress HTTP layer — including traffic from the
 * WP AI Client (AI plugin features), not just the plugin's own calls.
 *
 * pre_http_request records a start time; http_api_debug consumes it and writes
 * the log row. Both hooks may fire more than once per request (transport +
 * WP_Http), so consuming the timer doubles as de-duplication.
 *
 * The plugin's own request helpers no longer call nrouter_log_request()
 * explicitly — this capture logs them too.
 */
function nrouter_http_log_start( $pre, $parsed_args, $url ) {
	if ( ! nrouter_logging_enabled() ) {
		return $pre;
	}
	$base = nrouter_get_base_url();
	if ( '' === $base || ! is_string( $url ) || 0 !== stripos( $url, $base ) ) {
		return $pre;
	}
	$key = md5( $url . '|' . (string) ( $parsed_args['body'] ?? '' ) );
	$GLOBALS['nrouter_http_timers'][ $key ] = microtime( true );
	return $pre; // Never preempt.
}
add_filter( 'pre_http_request', 'nrouter_http_log_start', 9999, 3 );

function nrouter_http_log_capture( $response, $context, $class, $args, $url ) {
	if ( ! nrouter_logging_enabled() ) {
		return;
	}
	$base = nrouter_get_base_url();
	if ( '' === $base || ! is_string( $url ) || 0 !== stripos( $url, $base ) ) {
		return;
	}
	$key = md5( $url . '|' . (string) ( $args['body'] ?? '' ) );
	if ( ! isset( $GLOBALS['nrouter_http_timers'][ $key ] ) ) {
		return; // Duplicate fire for a request already logged.
	}
	$latency_ms = (int) round( ( microtime( true ) - $GLOBALS['nrouter_http_timers'][ $key ] ) * 1000 );
	unset( $GLOBALS['nrouter_http_timers'][ $key ] );

	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( '' === $path ) {
		$path = $url;
	}

	$request_body = (string) ( $args['body'] ?? '' );
	$decoded_req  = json_decode( $request_body, true );
	$model        = is_array( $decoded_req ) ? (string) ( $decoded_req['model'] ?? '' ) : '';

	$is_error = is_wp_error( $response );
	$code     = $is_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$body     = $is_error ? $response->get_error_message() : (string) wp_remote_retrieve_body( $response );

	$usage = null;
	if ( ! $is_error && 200 === $code ) {
		// Strip 9Router's trailing "data: [DONE]" marker before decoding usage.
		$clean = json_decode( preg_replace( '/data:\s*\[DONE\]\s*$/', '', trim( $body ) ), true );
		if ( isset( $clean['usage'] ) && is_array( $clean['usage'] ) ) {
			$usage = $clean['usage'];
		}
	}

	nrouter_log_request( $path, $model, $code, $latency_ms, $usage, $request_body, $body );
}
add_action( 'http_api_debug', 'nrouter_http_log_capture', 10, 5 );

function nrouter_get_models( $force_refresh = false ) {
	$cached = get_option( 'nrouter_models', array() );
	if ( ! $force_refresh && ! empty( $cached ) ) {
		return is_array( $cached ) ? $cached : array();
	}

	$url = nrouter_get_base_url() . '/v1/models';
	$key = nrouter_get_api_key();

	$start    = microtime( true );
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			),
		)
	);
	$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'nrouter_models', $response->get_error_message() );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( 200 !== $code || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
		return new WP_Error( 'nrouter_models', sprintf( 'HTTP %d: %s', $code, substr( $raw, 0, 200 ) ?: __( 'no body', 'nrouter-ai' ) ) );
	}

	$ids = array();
	foreach ( $data['data'] as $m ) {
		if ( ! empty( $m['id'] ) ) {
			$ids[] = (string) $m['id'];
		}
	}
	sort( $ids, SORT_NATURAL | SORT_FLAG_CASE );
	update_option( 'nrouter_models', $ids, false );
	update_option( 'nrouter_models_loaded_at', current_time( 'mysql', true ), false );

	return $ids;
}

/* -------------------------------------------------------------------------
 * Aggregated usage stats by time period
 * ---------------------------------------------------------------------- */

/**
 * Supported reporting periods: label => duration string (null = all time).
 */
function nrouter_log_periods() {
	return array(
		'week'    => array(
			'label' => __( 'Week', 'nrouter-ai' ),
			'since' => '-7 days',
		),
		'month'   => array(
			'label' => __( 'Month', 'nrouter-ai' ),
			'since' => '-30 days',
		),
		'3month'  => array(
			'label' => __( '3 Months', 'nrouter-ai' ),
			'since' => '-90 days',
		),
		'6month'  => array(
			'label' => __( '6 Months', 'nrouter-ai' ),
			'since' => '-180 days',
		),
		'full'    => array(
			'label' => __( 'Full (all time)', 'nrouter-ai' ),
			'since' => null,
		),
	);
}

function nrouter_get_current_period() {
	$periods = nrouter_log_periods();
	$period  = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : 'full';
	return isset( $periods[ $period ] ) ? $period : 'full';
}

/**
 * SQL predicate fragment for a period, joined with AND by the caller.
 * created_at is stored in UTC (current_time( 'mysql', true )).
 */
function nrouter_period_since( $period ) {
	$periods = nrouter_log_periods();
	if ( ! isset( $periods[ $period ] ) || null === $periods[ $period ]['since'] ) {
		return null;
	}
	return gmdate( 'Y-m-d H:i:s', strtotime( $periods[ $period ]['since'] ) );
}

/**
 * Aggregated usage statistics for a period.
 *
 * @return array { calls, errors, success_rate, avg_latency, tokens_in, tokens_out, tokens_total, api_calls, models_calls, first, last }
 */
function nrouter_period_stats( $period ) {
	global $wpdb;
	$table  = nrouter_logs_table();
	$since  = nrouter_period_since( $period );
	$where  = $since ? $wpdb->prepare( 'WHERE created_at >= %s', $since ) : '';

	$row = $wpdb->get_row(
		"SELECT COUNT(*) AS calls,
			SUM(status_code = 200) AS ok_n,
			SUM(status_code <> 200) AS err_n,
			AVG(latency_ms) AS avg_latency,
			COALESCE(SUM(prompt_tokens), 0) AS tokens_in,
			COALESCE(SUM(completion_tokens), 0) AS tokens_out,
			COALESCE(SUM(total_tokens), 0) AS tokens_total,
			SUM(endpoint = '/v1/chat/completions') AS api_calls,
			MIN(created_at) AS first_call,
			MAX(created_at) AS last_call
		FROM {$table} {$where}"
	);

	$stats = array(
		'calls'        => (int) ( $row->calls ?? 0 ),
		'ok'           => (int) ( $row->ok_n ?? 0 ),
		'errors'       => (int) ( $row->err_n ?? 0 ),
		'success_rate' => 0,
		'avg_latency'  => $row->calls ? (int) round( (float) $row->avg_latency ) : 0,
		'tokens_in'    => (int) $row->tokens_in,
		'tokens_out'   => (int) $row->tokens_out,
		'tokens_total' => (int) $row->tokens_total,
		'api_calls'    => (int) ( $row->api_calls ?? 0 ),
		'first_call'   => $row->first_call,
		'last_call'    => $row->last_call,
	);
	$stats['success_rate'] = $stats['calls'] ? round( $stats['ok'] / $stats['calls'] * 100, 1 ) : 0;

	// Most used models in the period.
	$stats['models'] = $wpdb->get_results(
		"SELECT model, COUNT(*) AS calls, COALESCE(SUM(total_tokens), 0) AS tokens,
			AVG(latency_ms) AS avg_latency
		FROM {$table} {$where}
		GROUP BY model
		ORDER BY calls DESC
		LIMIT 10"
	);

	return $stats;
}

/* -------------------------------------------------------------------------
 * Admin UI: Settings → 9Router WP connector
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'nrouter_admin_menu' );

function nrouter_admin_menu() {
	add_options_page(
		__( '9Router WP connector', 'nrouter-ai' ),
		__( '9Router WP connector', 'nrouter-ai' ),
		'manage_options',
		'nrouter-ai',
		'nrouter_render_admin_page'
	);
}

add_action( 'admin_init', 'nrouter_register_settings' );

function nrouter_register_settings() {
	register_setting( 'nrouter_ai_group', 'nrouter_base_url', array(
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => nrouter_default_base_url(),
	) );
	register_setting( 'nrouter_ai_group', 'nrouter_api_key', array(
		'type'              => 'string',
		'sanitize_callback' => 'nrouter_sanitize_api_key',
		'default'           => '',
	) );
	register_setting( 'nrouter_ai_group', 'nrouter_model', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'exp/gpt-4.1-nano',
	) );
	register_setting( 'nrouter_ai_group', 'nrouter_timeout', array(
		'type'              => 'integer',
		'sanitize_callback' => function ( $v ) { return max( 1, (int) $v ); },
		'default'           => 60,
	) );
	register_setting( 'nrouter_ai_group', 'nrouter_logging', array(
		'type'              => 'boolean',
		'sanitize_callback' => function ( $v ) { return (bool) $v; },
		'default'           => 0,
	) );
	register_setting( 'nrouter_ai_group', 'nrouter_log_max', array(
		'type'              => 'integer',
		'sanitize_callback' => function ( $v ) { return max( 50, (int) $v ); },
		'default'           => 1000,
	) );
	register_setting( 'nrouter_ai_group', 'nrouter_log_retention', array(
		'type'              => 'string',
		'sanitize_callback' => 'nrouter_sanitize_retention',
		'default'           => 'full',
	) );
}

/**
 * Validate the retention setting ('week' | 'month' | '3month' | '6month' | 'full').
 */
function nrouter_sanitize_retention( $value ) {
	$periods = nrouter_log_periods();
	$value   = sanitize_key( (string) $value );
	return isset( $periods[ $value ] ) ? $value : 'full';
}

/**
 * Sanitize an API-key submission with "leave blank to keep" semantics.
 *
 * The Connectors screen stores the key through the AI plugin's Key Encryption
 * experiment (secrets store; the wp_options row for connectors_ai_9router_api_key
 * stays empty). Saving this settings form with an empty key field must therefore
 * never propagate an empty value — doing so would delete the stored secret and
 * disconnect every AI feature. An empty submission keeps the effective key.
 *
 * @param string $value Submitted key.
 * @return string
 */
function nrouter_sanitize_api_key( $value ) {
	$value = trim( sanitize_text_field( (string) $value ) );
	if ( '' === $value ) {
		$effective = nrouter_get_api_key();
		if ( '' !== $effective ) {
			return $effective;
		}
	}
	return $value;
}

function nrouter_get_log_retention() {
	$periods = nrouter_log_periods();
	$value   = (string) get_option( 'nrouter_log_retention', 'full' );
	return isset( $periods[ $value ] ) ? $value : 'full';
}

/**
 * Delete stored log entries older than the retention period.
 *
 * @param string|null $retention Optional retention key; defaults to the saved setting.
 * @return int Number of deleted rows. Zero when retention is 'full' or nothing matched.
 */
function nrouter_purge_old_logs( $retention = null ) {
	global $wpdb;

	$retention = null === $retention ? nrouter_get_log_retention() : nrouter_sanitize_retention( $retention );
	$since     = nrouter_period_since( $retention );

	// 'full' (all time) keeps everything.
	if ( ! $since ) {
		return 0;
	}

	return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . nrouter_logs_table() . ' WHERE created_at < %s', $since ) );
}

// Purge immediately when the retention setting is added or changed.
add_action( 'update_option_nrouter_log_retention', 'nrouter_purge_old_logs', 10, 0 );
add_action( 'add_option_nrouter_log_retention', function ( $option, $value ) {
	nrouter_purge_old_logs( $value );
}, 10, 2 );

// Keep purging once a day so the retention rule stays enforced over time.
add_action( 'nrouter_daily_purge', 'nrouter_purge_old_logs' );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'nrouter_daily_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nrouter_daily_purge' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'nrouter_daily_purge' );
} );

function nrouter_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';

	$models       = nrouter_get_models();
	$models_error = is_wp_error( $models ) ? $models->get_error_message() : '';
	if ( is_wp_error( $models ) ) {
		$models = array();
	}
	$key  = nrouter_get_api_key();
	$key_source = 'database';
	if ( defined( 'NROUTER_API_KEY' ) ) {
		$key_source = 'PHP constant (NROUTER_API_KEY)';
	} elseif ( false !== getenv( 'NROUTER_API_KEY' ) ) {
		$key_source = 'environment variable (NROUTER_API_KEY)';
	}

	$connector_ok = function_exists( 'wp_is_connector_registered' ) && wp_is_connector_registered( '9router' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '9Router WP connector', 'nrouter-ai' ); ?> <span class="nrouter-ping" id="nrouter-status-dot"></span></h1>

		<nav class="nav-tab-wrapper">
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=nrouter-ai&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'nrouter-ai' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=nrouter-ai&tab=logs' ) ); ?>" class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'API Logs', 'nrouter-ai' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=nrouter-ai&tab=debug' ) ); ?>" class="nav-tab <?php echo 'debug' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Debug', 'nrouter-ai' ); ?></a>
		</nav>

		<?php if ( ! $connector_ok && ! class_exists( 'WP_Connector_Registry' ) ) : ?>
			<div class="notice notice-info"><p>
				<?php esc_html_e( 'WordPress Connectors API not detected (needs WP 7.0+). The plugin still works, but no card will be shown on Settings → Connectors.', 'nrouter-ai' ); ?>
			</p></div>
		<?php endif; ?>

		<?php if ( 'settings' === $tab ) : ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'nrouter_ai_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nrouter_base_url"><?php esc_html_e( 'Base URL', 'nrouter-ai' ); ?></label></th>
						<td>
							<input name="nrouter_base_url" id="nrouter_base_url" type="url" class="regular-text code" placeholder="https://your-9router-server" value="<?php echo esc_attr( get_option( 'nrouter_base_url', nrouter_default_base_url() ) ); ?>" required />
							<p class="description"><?php esc_html_e( 'OpenAI-compatible endpoint of your 9Router server. Requests are sent to {base}/v1/chat/completions and {base}/v1/models.', 'nrouter-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nrouter_api_key"><?php esc_html_e( 'API Key', 'nrouter-ai' ); ?></label></th>
						<td>
							<input name="nrouter_api_key" id="nrouter_api_key" type="password" class="regular-text code" value="<?php echo esc_attr( nrouter_get_api_key() ); ?>" autocomplete="new-password" />
							<p class="description">
								<?php printf( esc_html__( 'Current key source: %s. Leave blank to keep the current key. You can also set a PHP constant or env var named %s (takes priority over this field).', 'nrouter-ai' ), '<code>' . esc_html( $key_source ) . '</code>', '<code>NROUTER_API_KEY</code>' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nrouter_model"><?php esc_html_e( 'Default model', 'nrouter-ai' ); ?></label></th>
						<td>
							<input list="nrouter-model-list" name="nrouter_model" id="nrouter_model" type="text" class="regular-text code" value="<?php echo esc_attr( get_option( 'nrouter_model', 'exp/gpt-4.1-nano' ) ); ?>" />
							<datalist id="nrouter-model-list">
								<?php foreach ( array_slice( $models, 0, 400 ) as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
							<p class="description">
								<?php printf( esc_html__( '%s models cached. Next request is routed to this model; other plugins can pass a model explicitly.', 'nrouter-ai' ), '<strong>' . count( $models ) . '</strong>' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nrouter_timeout"><?php esc_html_e( 'Timeout (seconds)', 'nrouter-ai' ); ?></label></th>
						<td><input name="nrouter_timeout" id="nrouter_timeout" type="number" min="1" step="1" class="small-text" value="<?php echo esc_attr( get_option( 'nrouter_timeout', 60 ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API request logging', 'nrouter-ai' ); ?></th>
						<td>
							<label>
								<input name="nrouter_logging" id="nrouter_logging" type="checkbox" value="1" <?php checked( nrouter_logging_enabled() ); ?> />
								<?php esc_html_e( 'Save every API call (request/response, status, latency, token usage) to the database', 'nrouter-ai' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nrouter_log_max"><?php esc_html_e( 'Keep last N log entries', 'nrouter-ai' ); ?></label></th>
						<td><input name="nrouter_log_max" id="nrouter_log_max" type="number" min="50" step="50" class="small-text" value="<?php echo esc_attr( get_option( 'nrouter_log_max', 1000 ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="nrouter_log_retention"><?php esc_html_e( 'Store logs for', 'nrouter-ai' ); ?></label></th>
						<td>
							<select name="nrouter_log_retention" id="nrouter_log_retention">
								<?php foreach ( nrouter_log_periods() as $r_key => $r_period ) : ?>
									<option value="<?php echo esc_attr( $r_key ); ?>" <?php selected( nrouter_get_log_retention(), $r_key ); ?>><?php echo esc_html( $r_period['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Log entries older than the selected period are deleted: "Week" removes any log older than 7 days, "Month" older than 30 days, etc. "Full (all time)" keeps everything. The cleanup runs immediately on save, on every new log entry, and once a day.', 'nrouter-ai' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Do it now', 'nrouter-ai' ); ?></h2>
			<p>
				<button type="button" class="button button-secondary" id="nrouter-load-models"><?php esc_html_e( 'Load models from /v1/models', 'nrouter-ai' ); ?></button>
				<button type="button" class="button button-primary" id="nrouter-test-connection"><?php esc_html_e( 'Save above first, then: Test connection', 'nrouter-ai' ); ?></button>
				<span id="nrouter-ajax-result" style="margin-left:10px;"></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'Tip: save the form first (Base URL / API Key), then use the buttons. Test sends a tiny "Say OK" completion to your default model.', 'nrouter-ai' ); ?>
			</p>

		<?php elseif ( 'logs' === $tab ) :
			global $wpdb;
			$table      = nrouter_logs_table();
			$period     = nrouter_get_current_period();
			$periods    = nrouter_log_periods();
			$since      = nrouter_period_since( $period );
			$where_sql  = $since ? $wpdb->prepare( 'WHERE created_at >= %s', $since ) : '';
			$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
			$rows       = $wpdb->get_results( "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT 100" );
			$stats      = nrouter_period_stats( $period );
			?>
			<h2><?php esc_html_e( 'API Logs', 'nrouter-ai' ); ?></h2>

			<p>
				<?php foreach ( $periods as $p_key => $p ) : ?>
					<a class="button<?php echo $p_key === $period ? ' button-primary' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'options-general.php?page=nrouter-ai&tab=logs&period=' . $p_key ) ); ?>">
						<?php echo esc_html( $p['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</p>

			<h3 style="margin-bottom:6px;">
				<?php printf( esc_html__( 'Usage — %s', 'nrouter-ai' ), esc_html( $periods[ $period ]['label'] ) ); ?>
				<?php if ( $since ) : ?>
					<span style="font-weight:400;color:#646970;"><?php echo esc_html( sprintf( '(since %s UTC)', $since ) ); ?></span>
				<?php endif; ?>
			</h3>
			<table class="widefat striped" style="max-width:980px;">
				<tbody>
					<tr>
						<td style="width:160px;"><?php esc_html_e( 'API calls', 'nrouter-ai' ); ?></td>
						<td><strong><?php echo number_format_i18n( $stats['calls'] ); ?></strong>
							<span style="color:#646970;">(<?php printf( esc_html__( '%s chat completions, %s model list calls', 'nrouter-ai' ), number_format_i18n( $stats['api_calls'] ), number_format_i18n( max( 0, $stats['calls'] - $stats['api_calls'] ) ) ); ?>)</span>
						</td>
						<td style="width:160px;"><?php esc_html_e( 'Errors', 'nrouter-ai' ); ?></td>
						<td><strong style="color:<?php echo $stats['errors'] ? '#b32d2e' : 'green'; ?>;"><?php echo number_format_i18n( $stats['errors'] ); ?></strong>
							<span style="color:#646970;">(<?php echo esc_html( $stats['success_rate'] ); ?>% success)</span>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Tokens', 'nrouter-ai' ); ?></td>
						<td>
							<strong><?php echo number_format_i18n( $stats['tokens_total'] ); ?></strong>
							<span style="color:#646970;"><?php printf( esc_html__( '(%s in / %s out)', 'nrouter-ai' ), number_format_i18n( $stats['tokens_in'] ), number_format_i18n( $stats['tokens_out'] ) ); ?></span>
							<span style="color:#646970;">~ <strong><?php echo number_format_i18n( (int) round( $stats['tokens_total'] / 1000 ) ); ?>K</strong></span>
						</td>
						<td><?php esc_html_e( 'Avg response time', 'nrouter-ai' ); ?></td>
						<td><strong><?php echo number_format_i18n( $stats['avg_latency'] ); ?> ms</strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'First call in period', 'nrouter-ai' ); ?></td>
						<td><?php echo $stats['first_call'] ? esc_html( $stats['first_call'] . ' UTC' ) : '—'; ?></td>
						<td><?php esc_html_e( 'Last call in period', 'nrouter-ai' ); ?></td>
						<td><?php echo $stats['last_call'] ? esc_html( $stats['last_call'] . ' UTC' ) : '—'; ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $stats['models'] ) ) : ?>
				<h3 style="margin:18px 0 6px;"><?php esc_html_e( 'Calls by model', 'nrouter-ai' ); ?></h3>
				<table class="widefat striped" style="max-width:980px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Model', 'nrouter-ai' ); ?></th>
							<th><?php esc_html_e( 'Calls', 'nrouter-ai' ); ?></th>
							<th><?php esc_html_e( 'Tokens', 'nrouter-ai' ); ?></th>
							<th><?php esc_html_e( 'Avg latency (ms)', 'nrouter-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stats['models'] as $m ) : ?>
							<tr>
								<td class="code"><?php echo esc_html( $m->model ?: '—' ); ?></td>
								<td><?php echo number_format_i18n( (int) $m->calls ); ?></td>
								<td><?php echo number_format_i18n( (int) $m->tokens ); ?></td>
								<td><?php echo number_format_i18n( (int) round( (float) $m->avg_latency ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:18px;">
				<button type="button" class="button" id="nrouter-refresh-logs"><?php esc_html_e( 'Refresh', 'nrouter-ai' ); ?></button>
				<button type="button" class="button button-link-delete" id="nrouter-clear-logs"><?php esc_html_e( 'Delete all logs (all time)', 'nrouter-ai' ); ?></button>
				<span id="nrouter-logs-result" style="margin-left:10px;"></span>
			</p>
			<?php if ( ! nrouter_logging_enabled() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Logging is currently OFF — nothing new is being recorded. Enable "API request logging" on the Settings tab.', 'nrouter-ai' ); ?></p></div>
			<?php endif; ?>
			<p class="description">
				<?php printf( esc_html__( '%1$s log entries in this period. Showing the newest 100.', 'nrouter-ai' ), '<strong>' . number_format_i18n( $total ) . '</strong>' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time (UTC)', 'nrouter-ai' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'nrouter-ai' ); ?></th>
						<th><?php esc_html_e( 'Model', 'nrouter-ai' ); ?></th>
						<th><?php esc_html_e( 'Status', 'nrouter-ai' ); ?></th>
						<th><?php esc_html_e( 'ms', 'nrouter-ai' ); ?></th>
						<th><?php esc_html_e( 'Tokens (in/out)', 'nrouter-ai' ); ?></th>
						<th><?php esc_html_e( 'Error', 'nrouter-ai' ); ?></th>
						<th><?php esc_html_e( 'Query', 'nrouter-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No log entries yet.', 'nrouter-ai' ); ?></td></tr>
				<?php else : foreach ( $rows as $row ) :
					// Pretty-print JSON bodies for readability.
					$pretty = static function ( $text ) {
						$text   = (string) $text;
						$clean  = preg_replace( '/data:\s*\[DONE\]\s*$/', '', trim( $text ) );
						$decode = json_decode( (string) $clean, true );
						if ( is_array( $decode ) ) {
							return wp_json_encode( $decode, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
						}
						return $text;
					};
					$req_text = $pretty( $row->request_body );
					$res_text = $pretty( $row->response_body );
					?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td class="code"><?php echo esc_html( $row->endpoint ); ?></td>
						<td class="code"><?php echo esc_html( $row->model ); ?></td>
						<td>
							<span style="color:<?php echo 200 === (int) $row->status_code ? 'green' : '#b32d2e'; ?>;font-weight:600;">
								<?php echo esc_html( $row->status_code ? $row->status_code : 'ERR' ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $row->latency_ms ); ?></td>
						<td><?php echo esc_html( $row->prompt_tokens . ' / ' . $row->completion_tokens ); ?></td>
						<td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $row->error_text ); ?>"><?php echo esc_html( $row->error_text ); ?></td>
						<td>
							<details class="nrouter-log-details">
								<summary class="button" style="cursor:pointer;"><?php esc_html_e( 'Show text', 'nrouter-ai' ); ?></summary>
								<div style="max-width:720px;">
									<p style="margin:8px 0 2px;"><strong>Request</strong></p>
									<pre style="max-height:280px;overflow:auto;background:#f6f7f7;padding:8px;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( $req_text !== '' ? $req_text : '—' ); ?></pre>
									<p style="margin:8px 0 2px;"><strong>Response</strong></p>
									<pre style="max-height:280px;overflow:auto;background:#f6f7f7;padding:8px;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( $res_text !== '' ? $res_text : '—' ); ?></pre>
								</div>
							</details>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

		<?php else : /* debug tab */ ?>
			<h2><?php esc_html_e( 'Debug', 'nrouter-ai' ); ?></h2>
			<table class="widefat striped" style="max-width:880px;">
				<tbody>
					<tr><td style="width:280px;">WordPress version</td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
					<tr><td>Connectors API (WP 7.0+)</td><td><?php echo function_exists( 'wp_is_connector_registered' ) ? '✔ available' : '✘ not available'; ?></td></tr>
					<tr><td>"9Router" connector registered</td><td><?php echo $connector_ok ? '✔ yes (visible on Settings → Connectors)' : '✘ no'; ?></td></tr>
					<tr><td>PHP version</td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
					<tr><td>cURL extension (wp_remote_request transport)</td><td><?php echo extension_loaded( 'curl' ) ? '✔ loaded' : '✘ missing'; ?></td></tr>
					<tr><td>Base URL</td><td class="code"><?php echo esc_html( nrouter_get_base_url() ); ?></td></tr>
					<tr><td>API key configured</td><td><?php echo '' !== $key ? '✔ yes (<code>' . esc_html( substr( $key, 0, 8 ) ) . '…</code>, source: ' . esc_html( $key_source ) . ')' : '✘ empty — set it on the Settings tab'; ?></td></tr>
					<tr><td>Default model</td><td class="code"><?php echo esc_html( nrouter_get_model() ); ?></td></tr>
					<tr><td>Models loaded</td><td>
						<?php
						$loaded_at = get_option( 'nrouter_models_loaded_at' );
						printf( '%d models%s', count( $models ), $loaded_at ? ' (' . esc_html( $loaded_at ) . ' UTC)' : '' );
						if ( $models_error ) {
							echo '<br /><span style="color:#b32d2e">' . esc_html( $models_error ) . '</span>';
						}
						?>
					</td></tr>
					<tr><td>Logging</td><td><?php echo nrouter_logging_enabled() ? '✔ ON — requests are saved to <code>' . esc_html( nrouter_logs_table() ) . '</code>' : 'OFF'; ?></td></tr>
					<tr><td>Log retention</td><td>
						<?php
						$retention_key = nrouter_get_log_retention();
						$retention_since = nrouter_period_since( $retention_key );
						echo esc_html( nrouter_log_periods()[ $retention_key ]['label'] );
						echo $retention_since
							? ' — entries older than <code>' . esc_html( $retention_since ) . ' UTC</code> are deleted automatically'
							: ' — nothing is deleted';
						echo ' | next daily cleanup: ' . ( wp_next_scheduled( 'nrouter_daily_purge' ) ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'nrouter_daily_purge' ) ), 'Y-m-d H:i:s' ) ) : 'not scheduled' );
						?>
					</td></tr>
					<tr><td>Log table exists</td><td>
						<?php
						global $wpdb;
						$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', nrouter_logs_table() ) );
						echo ( $found === nrouter_logs_table() ) ? '✔ yes' : '✘ no — deactivate/reactivate the plugin to create it';
						?>
					</td></tr>
					<tr><td>Outbound HTTP test (GET {base}/api/health)</td><td>
						<?php
						$h = wp_remote_get( nrouter_get_base_url() . '/api/health', array( 'timeout' => 8 ) );
						if ( is_wp_error( $h ) ) {
							echo '<span style="color:#b32d2e">✘ ' . esc_html( $h->get_error_message() ) . '</span>';
						} else {
							$hb = trim( wp_remote_retrieve_body( $h ) );
							echo '✔ HTTP ' . esc_html( wp_remote_retrieve_response_code( $h ) ) . ' <code>' . esc_html( substr( $hb, 0, 120 ) ) . '</code>';
						}
						?>
					</td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'PHP helper (for theme/plugin developers)', 'nrouter-ai' ); ?></h2>
			<p><?php esc_html_e( 'Send prompts from code with the shared Base URL / API key / default model:', 'nrouter-ai' ); ?></p>
<pre class="code" style="background:#f6f7f7;padding:12px;max-width:880px;overflow:auto;">
// Simple: returns text or WP_Error
$text = nrouter_ai_completion( 'Summarize this post: ' . $content );
echo is_wp_error( $text ) ? $text->get_error_message() : $text;

// Advanced: full OpenAI payload + raw response, custom model
$result = nrouter_ai_request(
  array( array( 'role' => 'user', 'content' => 'Hello' ) ),
  array( 'model' => 'exp/gpt-5.4-mini', 'temperature' => 0.2, 'max_tokens' => 500 )
);
if ( is_wp_error( $result ) ) { ... } else { echo $result['body']['choices'][0]['message']['content']; }
</pre>
		<?php endif; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Assets + AJAX
 * ---------------------------------------------------------------------- */

add_action( 'admin_enqueue_scripts', 'nrouter_admin_assets' );

function nrouter_admin_assets( $hook ) {
	if ( 'settings_page_nrouter-ai' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'wp-admin' );
	wp_add_inline_style( 'wp-admin', '.nrouter-ping{display:inline-block;width:10px;height:10px;border-radius:50%;background:#dcdcde;vertical-align:middle;margin-left:6px;}' );
	wp_enqueue_script( 'jquery' );
	wp_add_inline_script( 'jquery', nrouter_admin_js() );
}

function nrouter_admin_js() {
	$nonce  = wp_create_nonce( 'nrouter_admin' );
	$ajax   = admin_url( 'admin-ajax.php' );
	return <<<JS
(function($){
	var AJAX = {$ajax}, NONCE = '{$nonce}';
	function post(action, data, cb){
		$.post(AJAX, $.extend({_ajax_nonce: NONCE, action: action}, data || {}), cb, 'json')
		 .fail(function(x){ $('#nrouter-ajax-result, #nrouter-logs-result').html('<span style="color:#b32d2e">'+ (x.responseJSON && x.responseJSON.data && x.responseJSON.data.message || 'AJAX error') +'</span>'); });
	}
	// Load models
	$('#nrouter-load-models').on('click', function(){
		var btn = $(this).prop('disabled', true), out = $('#nrouter-ajax-result').text('…');
		post('nrouter_load_models', {}, function(r){
			btn.prop('disabled', false);
			out.html(r.success ? '<span style="color:green">✔ '+r.data.message+'</span>' : '<span style="color:#b32d2e">✘ '+r.data.message+'</span>');
			if (r.success) { setTimeout(function(){ location.reload(); }, 900); }
		});
	});
	// Test connection (saves nothing, just sends request with current saved options)
	$('#nrouter-test-connection').on('click', function(){
		var btn = $(this).prop('disabled', true), out = $('#nrouter-ajax-result').text('…');
		post('nrouter_test_connection', {}, function(r){
			btn.prop('disabled', false);
			var d = r.data || {};
			if (r.success) {
				out.html('<span style="color:green">✔ HTTP '+d.status+', '+d.latency_ms+' ms — '+d.reply+'</span>');
				$('#nrouter-status-dot').css('background','green');
			} else {
				out.html('<span style="color:#b32d2e">✘ '+(d.message||'failed')+(d.status?(' (HTTP '+d.status+')'):'')+'</span>');
				$('#nrouter-status-dot').css('background','#b32d2e');
			}
		});
	});
	// Logs
	$('#nrouter-clear-logs').on('click', function(){
		if (!window.confirm('Delete ALL API log entries?')) return;
		var btn = $(this).prop('disabled', true);
		post('nrouter_clear_logs', {}, function(r){
			btn.prop('disabled', false);
			$('#nrouter-logs-result').html(r.success ? '<span style="color:green">✔ '+r.data.message+'</span>' : '<span style="color:#b32d2e">✘ '+r.data.message+'</span>');
			if (r.success) { setTimeout(function(){ location.reload(); }, 700); }
		});
	});
})(jQuery);
JS;
}

add_action( 'wp_ajax_nrouter_load_models', 'nrouter_ajax_load_models' );
add_action( 'wp_ajax_nrouter_test_connection', 'nrouter_ajax_test_connection' );
add_action( 'wp_ajax_nrouter_clear_logs', 'nrouter_ajax_clear_logs' );

function nrouter_ajax_guard() {
	if ( ! check_ajax_referer( 'nrouter_admin', '_ajax_nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Bad nonce.', 'nrouter-ai' ) ), 403 );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Capability check failed.', 'nrouter-ai' ) ), 403 );
	}
}

function nrouter_ajax_load_models() {
	nrouter_ajax_guard();

	// Read the value that might have just been typed but not saved yet.
	if ( isset( $_POST['base_url'] ) && '' !== (string) $_POST['base_url'] ) {
		update_option( 'nrouter_base_url', esc_url_raw( wp_unslash( $_POST['base_url'] ) ) );
	}
	$models = nrouter_get_models( true );

	if ( is_wp_error( $models ) ) {
		wp_send_json_error( array( 'message' => $models->get_error_message() ) );
	}
	wp_send_json_success( array(
		'message' => sprintf( __( '%d models loaded from %s', 'nrouter-ai' ), count( $models ), nrouter_get_base_url() . '/v1/models' ),
		'count'   => count( $models ),
		'models'  => array_slice( $models, 0, 50 ),
	) );
}

function nrouter_ajax_test_connection() {
	nrouter_ajax_guard();

	$model = nrouter_get_model();
	$start = microtime( true );
	$res   = nrouter_ai_request(
		array( array( 'role' => 'user', 'content' => 'Say OK' ) ),
		array( 'model' => $model, 'max_tokens' => 10 )
	);

	if ( is_wp_error( $res ) ) {
		wp_send_json_error( array( 'message' => $res->get_error_message() ) );
	}

	$reply = isset( $res['body']['choices'][0]['message']['content'] )
		? trim( (string) $res['body']['choices'][0]['message']['content'] )
		: '';

	if ( 200 !== $res['status'] ) {
		$err = isset( $res['body']['error']['message'] ) ? $res['body']['error']['message'] : substr( $res['raw'], 0, 200 );
		wp_send_json_error( array(
			'message'   => $err,
			'status'    => $res['status'],
			'latency_ms'=> $res['latency_ms'],
			'raw'       => substr( $res['raw'], 0, 500 ),
		) );
	}

	wp_send_json_success( array(
		'status'        => $res['status'],
		'latency_ms'    => $res['latency_ms'],
		'model'         => $model,
		'reply'         => $reply ?: '(empty)',
		'usage'         => $res['usage'],
	) );
}

function nrouter_ajax_clear_logs() {
	nrouter_ajax_guard();
	global $wpdb;
	$table = nrouter_logs_table();
	$n     = $wpdb->query( "TRUNCATE TABLE {$table}" );
	wp_send_json_success( array( 'message' => __( 'All log entries deleted.', 'nrouter-ai' ) ) );
}

/* -------------------------------------------------------------------------
 * Optional REST endpoint (handy for headless automation)
 * POST /wp-json/nrouter/v1/completion {"prompt":"...", "model":"..."}
 * Auth: cookie + nonce, or Application Passwords (Basic Auth).
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', 'nrouter_rest_routes' );

function nrouter_rest_routes() {
	register_rest_route( NROUTER_REST_NAMESPACE, '/completion', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'args'                => array(
			'prompt' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
			'model'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			$prompt = $req->get_param( 'prompt' );
			$args   = array();
			if ( $req->get_param( 'model' ) ) {
				$args['model'] = $req->get_param( 'model' );
			}
			$text = nrouter_ai_completion( $prompt, $args );
			if ( is_wp_error( $text ) ) {
				return new WP_Error( 'nrouter_failed', $text->get_error_message(), array( 'status' => 502 ) );
			}
			return array( 'text' => $text );
		},
	) );
}
