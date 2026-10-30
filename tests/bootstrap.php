<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader and defines the few WordPress functions the
 * pure-PHP classes touch, so unit tests run without a WordPress installation.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ODDS_AGGREGATOR_URL' ) ) {
	define( 'ODDS_AGGREGATOR_URL', 'https://example.test/wp-content/plugins/odds-aggregator/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub: returns the filtered value unchanged.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Extra arguments.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub: no-op.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Arguments.
	 */
	function do_action( string $hook_name, ...$args ): void {}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub: returns the string unchanged.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub backed by an in-memory store.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Value returned when unset.
	 * @return mixed
	 */
	function get_option( string $name, $fallback = false ) {
		return $GLOBALS['odds_aggregator_test_options'][ $name ] ?? $fallback;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub backed by an in-memory store.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value to store.
	 * @param bool   $autoload Ignored.
	 * @return bool
	 */
	function update_option( string $name, $value, bool $autoload = true ): bool {
		$GLOBALS['odds_aggregator_test_options'][ $name ] = $value;

		return true;
	}
}

$GLOBALS['odds_aggregator_test_options'] = array();

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub: lowercases and strips anything outside a-z0-9_-.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub: strips tags and trims.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function sanitize_text_field( string $value ): string {
		return trim( wp_strip_all_tags_stub( $value ) );
	}

	/**
	 * Helper for the sanitize_text_field stub.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function wp_strip_all_tags_stub( string $value ): string {
		return (string) preg_replace( '/<[^>]*>/', '', $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub: accepts only http and https URLs.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	function esc_url_raw( string $url ): string {
		return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}

$GLOBALS['odds_aggregator_test_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stub backed by an in-memory store.
	 *
	 * @param string $name Transient name.
	 * @return mixed False when unset.
	 */
	function get_transient( string $name ) {
		return $GLOBALS['odds_aggregator_test_transients'][ $name ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub backed by an in-memory store; expiry is ignored.
	 *
	 * @param string $name       Transient name.
	 * @param mixed  $value      Value to store.
	 * @param int    $expiration Ignored.
	 * @return bool
	 */
	function set_transient( string $name, $value, int $expiration = 0 ): bool {
		$GLOBALS['odds_aggregator_test_transients'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub backed by an in-memory store.
	 *
	 * @param string $name Transient name.
	 * @return bool
	 */
	function delete_transient( string $name ): bool {
		unset( $GLOBALS['odds_aggregator_test_transients'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub: appends url-encoded arguments, as WordPress does.
	 *
	 * Encoding matters here - a provider that pre-encodes a value would send a
	 * literal "%2C" instead of a comma, and a test should be able to see that.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @param string              $url  Base URL.
	 * @return string
	 */
	function add_query_arg( array $args, string $url = '' ): string {
		$pairs = array();

		foreach ( $args as $name => $value ) {
			$pairs[] = urlencode( (string) $name ) . '=' . urlencode( (string) $value );
		}

		if ( array() === $pairs ) {
			return $url;
		}

		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', $pairs );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub: a fixed site URL, used when building the outbound User-Agent.
	 *
	 * @param string $path Appended path.
	 * @return string
	 */
	function home_url( string $path = '/' ): string {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Stub: the low end, so retry jitter never sleeps in tests.
	 *
	 * @param int $min Lower bound.
	 * @param int $max Upper bound.
	 * @return int
	 */
	function wp_rand( int $min = 0, int $max = 0 ): int {
		return $min;
	}
}

$GLOBALS['odds_aggregator_test_http'] = array(
	'queue' => array(),
	'calls' => 0,
	'urls'  => array(),
);

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Stub: pops a canned response and records the call.
	 *
	 * The call counter is what lets a test assert that one upstream request
	 * served every event rather than one request per event.
	 *
	 * @param string              $url  Requested URL.
	 * @param array<string,mixed> $args Request arguments.
	 * @return array<string,mixed>
	 */
	function wp_remote_get( string $url, array $args = array() ) {
		++$GLOBALS['odds_aggregator_test_http']['calls'];
		$GLOBALS['odds_aggregator_test_http']['urls'][] = $url;

		$queued = array_shift( $GLOBALS['odds_aggregator_test_http']['queue'] );

		if ( ! is_array( $queued ) ) {
			$queued = array(
				'code' => 500,
				'body' => '',
			);
		}

		return array(
			'response' => array( 'code' => $queued['code'] ?? 200 ),
			'body'     => $queued['body'] ?? '',
			'headers'  => $queued['headers'] ?? array(),
		);
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub: the transport stub never returns a WP_Error.
	 *
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Stub reader.
	 *
	 * @param mixed $response Response array.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Stub reader.
	 *
	 * @param mixed $response Response array.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * Stub reader.
	 *
	 * @param mixed  $response Response array.
	 * @param string $name     Header name.
	 * @return string
	 */
	function wp_remote_retrieve_header( $response, string $name ): string {
		return is_array( $response ) ? (string) ( $response['headers'][ $name ] ?? '' ) : '';
	}
}

$GLOBALS['odds_aggregator_test_settings_errors'] = array();

if ( ! function_exists( 'add_settings_error' ) ) {
	/**
	 * Stub: collects notices so a test can read them back.
	 *
	 * @param string $setting Option name.
	 * @param string $code    Notice code.
	 * @param string $message Notice text.
	 * @param string $type    Notice type.
	 */
	function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
		$GLOBALS['odds_aggregator_test_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub: non-negative integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub: escapes for HTML text.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Stub: escapes for an attribute value.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub: accepts only http and https URLs.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	function esc_url( string $url ): string {
		return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Stub: translate then escape.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Stub: translate then escape for an attribute.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'wp_kses_data' ) ) {
	/**
	 * Stub: passes the value through.
	 *
	 * @param string $data Raw markup.
	 * @return string
	 */
	function wp_kses_data( string $data ): string {
		return $data;
	}
}

if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	/**
	 * Stub: the class attribute core would emit for a dynamic block.
	 *
	 * @param array<string,string> $extra Extra attributes.
	 * @return string
	 */
	function get_block_wrapper_attributes( array $extra = array() ): string {
		$classes = trim( 'wp-block-odds-aggregator-odds-table ' . ( $extra['class'] ?? '' ) );

		return sprintf( 'class="%s"', esc_attr( $classes ) );
	}
}

if ( ! function_exists( 'human_time_diff' ) ) {
	/**
	 * Stub: a fixed readable difference.
	 *
	 * @param int $from Earlier timestamp.
	 * @param int $to   Later timestamp.
	 * @return string
	 */
	function human_time_diff( int $from, int $to = 0 ): string {
		return '1 min';
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Stub: formats in UTC, since tests set no site timezone.
	 *
	 * @param string   $format    Date format.
	 * @param int|null $timestamp Unix timestamp.
	 * @return string
	 */
	function wp_date( string $format, ?int $timestamp = null ): string {
		return gmdate( $format, $timestamp ?? time() );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub: tests render as a visitor unless a test says otherwise.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return (bool) ( $GLOBALS['odds_aggregator_test_can'] ?? false );
	}
}

$GLOBALS['odds_aggregator_test_can'] = false;

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	/**
	 * Stub reader.
	 *
	 * @param mixed $response Response array.
	 * @return array<string,string>
	 */
	function wp_remote_retrieve_headers( $response ): array {
		return is_array( $response ) ? (array) ( $response['headers'] ?? array() ) : array();
	}
}
