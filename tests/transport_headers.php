<?php
/** Cache and redirect regressions; run only in a disposable WordPress installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'GZ_AI_TEST_ALLOW' ) ) {
	exit( "Set GZ_AI_TEST_ALLOW=1 and use wp eval-file in a disposable installation.\n" );
}
use GZ_AI\Endpoints;
use GZ_AI\Settings;

$original_server = $_SERVER;
$original_query = $GLOBALS['wp_query'];
$original_home = get_option( 'home' );
$original_settings = get_option( Settings::OPTION );
$fixture = 0;
$GLOBALS['gz_transport_checks'] = 0;
function check_transport( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	++$GLOBALS['gz_transport_checks']; echo "PASS $label\n";
}
try {
	$stale = array( 'cache-control' => 'public, max-age=172800', 'Cache-Control' => 'no-store', 'expires' => 'Wed, 01 Jan 2031 00:00:00 GMT', 'ETag' => 'fixture', 'last-modified' => 'fixture', 'PRAGMA' => 'public', 'Retry-After' => '23', 'X-RateLimit-Remaining' => '0' );
	foreach ( array( 200, 202, 204, 400, 403, 404, 405, 413, 415, 429 ) as $status ) {
		$request = new WP_REST_Request( 'POST', '/gz-ai/v1/mcp' );
		$response = new WP_REST_Response( array( 'fixture' => true ), $status, $stale );
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$headers = $response->get_headers();
		check_transport( $headers === array_merge( array( 'Retry-After' => '23', 'X-RateLimit-Remaining' => '0' ), Endpoints::cache_headers() ) && $status === $response->get_status() && array( 'fixture' => true ) === $response->get_data(), "MCP HTTP $status has one unambiguous cache policy and retains response data" );
	}
	foreach ( array( '/gz-ai/v1/mcp/', '/gz-ai/v1/markdown/2', '/gz-ai/v1/markdown/2/' ) as $route ) {
		$response = apply_filters( 'rest_post_dispatch', new WP_REST_Response( null, 404, $stale ), rest_get_server(), new WP_REST_Request( 'GET', $route ) );
		check_transport( Endpoints::EXPIRES === $response->get_headers()['Expires'] && ! isset( $response->get_headers()['cache-control'] ), "$route errors receive the same expired cache policy" );
	}
	foreach ( array( '/wp/v2/posts', '/gz-ai/v1/mcp-other', '/gz-ai/v1/markdown-extra/2' ) as $route ) {
		$response = apply_filters( 'rest_post_dispatch', new WP_REST_Response( null, 200, $stale ), rest_get_server(), new WP_REST_Request( 'GET', $route ) );
		check_transport( $stale === $response->get_headers(), "$route caching is left to its owner" );
	}

	$fixture = wp_insert_post( array( 'post_title' => 'MCP App Update fixture', 'post_name' => 'mcp-app-update-transport-fixture', 'post_status' => 'publish', 'post_type' => 'post' ) );
	$GLOBALS['wp_query'] = new WP_Query();
	$GLOBALS['wp_query']->set_404();
	set_query_var( 'name', 'mcp' );
	$_SERVER['REQUEST_URI'] = '/unrelated-missing-url';
	check_transport( is_string( redirect_guess_404_permalink() ), 'A published MCP-prefixed post reproduces WordPress slug guessing on ordinary missing URLs' );
	$path = wp_parse_url( home_url( '/.well-known/mcp' ), PHP_URL_PATH );
	foreach ( array( $path, $path . '/', $path . '?probe=1' ) as $uri ) {
		$_SERVER['REQUEST_URI'] = $uri;
		check_transport( false === redirect_guess_404_permalink() && false === apply_filters( 'redirect_canonical', get_permalink( $fixture ), home_url( $uri ) ), "$uri never guesses a post redirect" );
	}
	foreach ( array( $path . '-other', '/.well-known/oauth-authorization-server', '/ordinary-missing-page' ) as $uri ) {
		$_SERVER['REQUEST_URI'] = $uri;
		check_transport( true === apply_filters( 'do_redirect_guess_404_permalink', true ) && 'fixture' === apply_filters( 'redirect_canonical', 'fixture' ), "$uri retains normal WordPress redirect behavior" );
	}
	update_option( 'home', untrailingslashit( $original_home ) . '/subdirectory' );
	$_SERVER['REQUEST_URI'] = wp_parse_url( home_url( '/.well-known/mcp' ), PHP_URL_PATH );
	check_transport( Endpoints::is_mcp_discovery_path(), 'Redirect guard supports WordPress installed below a subdirectory' );
	$s = Settings::defaults(); $s['mcp_enabled'] = false; update_option( Settings::OPTION, $s );
	check_transport( false === redirect_guess_404_permalink(), 'Disabling MCP does not reintroduce the misleading redirect' );
	echo "\n" . $GLOBALS['gz_transport_checks'] . " transport checks passed.\n";
} finally {
	$_SERVER = $original_server;
	$GLOBALS['wp_query'] = $original_query;
	update_option( 'home', $original_home );
	update_option( Settings::OPTION, $original_settings );
	if ( $fixture ) { wp_delete_post( $fixture, true ); }
}
