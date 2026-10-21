<?php
/** Rolling-window regression checks; run only in a disposable WordPress installation. */
namespace GZ_AI {
	// Control only the plugin clock so boundary/expiry checks need no wall-clock waits.
	function time(): int { return $GLOBALS['gz_rate_test_time'] ?? \time(); }
}
namespace {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'GZ_AI_TEST_ALLOW' ) ) {
		exit( "Set GZ_AI_TEST_ALLOW=1 and use wp eval-file in a disposable installation.\n" );
	}
	use GZ_AI\Settings;
	use GZ_AI\Rate_Limiter;

	$original_settings = get_option( Settings::OPTION );
	$original_public = get_option( 'blog_public' );
	$original_server = $_SERVER;
	$GLOBALS['checks'] = 0;
	global $wpdb;
	function clear_rate_fixtures(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'gz_ai_rate_' ) . '%' ) );
	}
	function check_rate( bool $condition, string $label ): void {
		if ( ! $condition ) { throw new RuntimeException( $label ); }
		++$GLOBALS['checks']; echo "PASS $label\n";
	}
	function rate_rpc( string $method = 'ping', array $params = array(), string $http_method = 'POST' ): WP_REST_Response {
		$request = new WP_REST_Request( $http_method, '/gz-ai/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'accept', 'application/json, text/event-stream' );
		$request->set_header( 'mcp-protocol-version', '2025-11-25' );
		$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params ) ) );
		return rest_do_request( $request );
	}
	try {
		clear_rate_fixtures();
		update_option( 'blog_public', '1' );
		// Exercise the same sanitization and storage as the admin settings form.
		$s = Settings::sanitize( Settings::defaults() );
		update_option( Settings::OPTION, $s );
		check_rate( 60 === Settings::get()['rate_limit'], 'Admin rate setting persists as 60' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );
		$burst_ip = $_SERVER['REMOTE_ADDR'];
		$base = (int) floor( time() / 60 ) * 60 + 50;
		$statuses = array(); $responses = array();
		for ( $i = 0; $i < 70; ++$i ) {
			$GLOBALS['gz_rate_test_time'] = $base + intdiv( $i * 21, 69 );
			$responses[] = rate_rpc();
			$statuses[] = end( $responses )->get_status();
		}
		check_rate( 60 === count( array_filter( $statuses, static function ( $status ) { return 200 === $status; } ) ) && 10 === count( array_filter( $statuses, static function ( $status ) { return 429 === $status; } ) ), '70 pings across a clock-minute boundary allow 60 and reject 10' );
		check_rate( '60' === $responses[0]->get_headers()['X-RateLimit-Limit'] && '59' === $responses[0]->get_headers()['X-RateLimit-Remaining'], 'Successful ping reports the configured limit and remaining budget' );
		check_rate( '0' === $responses[59]->get_headers()['X-RateLimit-Remaining'], 'The 60th request exhausts the budget' );
		$headers = $responses[69]->get_headers();
		check_rate( '0' === $headers['X-RateLimit-Remaining'] && (string) ( $base + 60 ) === $headers['X-RateLimit-Reset'] && '39' === $headers['Retry-After'], '429 reports the actual next available request time' );
		check_rate( 429 === rate_rpc( 'tools/call', array( 'name' => 'site_info' ) )->get_status(), 'Tool calls share the exhausted ping budget' );
		check_rate( 204 === rate_rpc( 'ping', array(), 'OPTIONS' )->get_status(), 'CORS preflight is allowed after exhaustion' );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		check_rate( 429 === rate_rpc()->get_status(), 'Spoofing a forwarded IP cannot bypass the budget' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		check_rate( 200 === rate_rpc( 'tools/call', array( 'name' => 'site_info' ) )->get_status(), 'A different remote IP has an independent budget' );
		// Restore the original burst IP, then advance to its oldest request's expiry.
		$_SERVER['REMOTE_ADDR'] = $burst_ip;
		$GLOBALS['gz_rate_test_time'] = $base + 59;
		check_rate( 429 === rate_rpc()->get_status(), 'The burst remains limited for the full rolling interval' );
		$GLOBALS['gz_rate_test_time'] = $base + 60;
		$recovered = rate_rpc();
		check_rate( 200 === $recovered->get_status() && '3' === $recovered->get_headers()['X-RateLimit-Remaining'] && (string) ( $base + 61 ) === $recovered->get_headers()['X-RateLimit-Reset'], 'Only the four oldest requests expire at 60 seconds' );
		check_rate( 200 === rate_rpc()->get_status() && 200 === rate_rpc()->get_status() && 200 === rate_rpc()->get_status() && 429 === rate_rpc()->get_status(), 'Replenished slots cannot be reused within the rolling interval' );
		$GLOBALS['gz_rate_test_time'] = $base + 81;
		check_rate( '55' === rate_rpc()->get_headers()['X-RateLimit-Remaining'], 'Rejected requests do not extend the lockout' );
		$_SERVER['REMOTE_ADDR'] = $original_server['REMOTE_ADDR'] ?? '203.0.113.3';
		$s['rate_limit'] = 2; update_option( Settings::OPTION, $s );
		check_rate( 200 === rate_rpc()->get_status() && 200 === rate_rpc( 'tools/call', array( 'name' => 'site_info' ) )->get_status() && 429 === rate_rpc()->get_status(), 'A saved limit change applies to both pings and tool calls' );
		check_rate( ! Rate_Limiter::consume( 'comments', 2 )['limited'] && ! Rate_Limiter::consume( 'comments', 2 )['limited'] && Rate_Limiter::consume( 'comments', 2 )['limited'], 'Comment submissions have a separate rolling budget' );
		$GLOBALS['gz_rate_test_time'] += 60;
		check_rate( ! Rate_Limiter::consume( 'comments', 2 )['limited'], 'Comment allowance recovers after expiration' );
		Rate_Limiter::consume( 'storage_failure', 2 );
		$fail_storage = static function ( $sql ) {
			return str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, Rate_Limiter::PREFIX ) ? 'GZ deliberate storage failure' : $sql;
		};
		$previous_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_storage );
		try {
			check_rate( Rate_Limiter::consume( 'storage_failure', 2 )['limited'], 'Database write failure denies the request' );
		} finally {
			remove_filter( 'query', $fail_storage );
			$wpdb->suppress_errors( $previous_errors );
		}
		check_rate( ! Rate_Limiter::consume( 'storage_failure', 2 )['limited'] && Rate_Limiter::consume( 'storage_failure', 2 )['limited'], 'A failed write does not corrupt the saved allowance' );
		$now = $GLOBALS['gz_rate_test_time'];
		$GLOBALS['gz_rate_test_time'] -= 60;
		Rate_Limiter::consume( 'cleanup_expired', 2 );
		$GLOBALS['gz_rate_test_time'] = $now;
		Rate_Limiter::consume( 'cleanup_active', 2 );
		do_action( 'gz_ai_cleanup_rates' );
		$key = static function ( $bucket ) { return Rate_Limiter::PREFIX . hash_hmac( 'sha256', $bucket . '|' . $_SERVER['REMOTE_ADDR'], wp_salt( 'nonce' ) ); };
		check_rate( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key( 'cleanup_expired' ) ) ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key( 'cleanup_active' ) ) ), 'Cron removes expired rolling logs and preserves active budgets' );
		echo "\n" . $GLOBALS['checks'] . " rate-limit checks passed.\n";
	} finally {
		unset( $GLOBALS['gz_rate_test_time'] );
		$_SERVER = $original_server;
		update_option( Settings::OPTION, $original_settings );
		update_option( 'blog_public', $original_public );
		clear_rate_fixtures();
	}
}
