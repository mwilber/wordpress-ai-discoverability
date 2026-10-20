<?php
/** URL selection regression checks; run only in a disposable WordPress installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'GZ_AI_TEST_ALLOW' ) ) {
	exit( "Set GZ_AI_TEST_ALLOW=1 and use wp eval-file in a disposable installation.\n" );
}
use GZ_AI\Settings;
use GZ_AI\Endpoints;
use GZ_AI\Content;

if ( ! function_exists( 'add_settings_error' ) ) { require_once ABSPATH . 'wp-admin/includes/template.php'; }
$original_settings = get_option( Settings::OPTION );
$original_permalinks = get_option( 'permalink_structure' );
$fixture = 0;
$GLOBALS['gz_url_checks'] = 0;
function check_mcp_url( bool $condition, string $description ): void {
	if ( ! $condition ) { throw new RuntimeException( $description ); }
	++$GLOBALS['gz_url_checks']; echo "PASS $description\n";
}
try {
	update_option( 'permalink_structure', '/%postname%/' );
	$s = Settings::defaults(); update_option( Settings::OPTION, $s );
	check_mcp_url( Endpoints::mcp_url() === rest_url( 'gz-ai/v1/mcp' ), 'Existing installations default to the standard URL' );
	$s['mcp_url_style'] = 'short'; update_option( Settings::OPTION, $s );
	check_mcp_url( Endpoints::mcp_url() === home_url( '/mcp' ), 'Short preference advertises the site-relative /mcp address' );
	$rules = get_option( 'rewrite_rules' );
	check_mcp_url( ( $rules[ Endpoints::MCP_ALIAS_RULE ] ?? '' ) === Endpoints::MCP_ALIAS_TARGET, 'Saving short preference installs the alias without a manual permalink save' );
	check_mcp_url( str_contains( Content::llms(), '[MCP endpoint](' . home_url( '/mcp' ) . ')' ), 'Generated llms.txt advertises the selected URL' );
	$s['llms_custom'] = '# Site' . "\n" . '{{mcp_url}}'; update_option( Settings::OPTION, $s );
	check_mcp_url( str_contains( Content::llms(), home_url( '/mcp' ) ), 'Custom llms.txt MCP placeholder uses the selected URL' );
	$s['mcp_url_style'] = 'standard'; update_option( Settings::OPTION, $s );
	check_mcp_url( ! isset( get_option( 'rewrite_rules' )[ Endpoints::MCP_ALIAS_RULE ] ), 'Saving standard preference removes the short alias' );
	$s['mcp_url_style'] = 'unexpected';
	check_mcp_url( 'standard' === Settings::sanitize( $s )['mcp_url_style'], 'Unknown preferences fall back to standard' );
	$s['mcp_url_style'] = 'short'; update_option( Settings::OPTION, $s );
	update_option( 'permalink_structure', '' );
	check_mcp_url( Endpoints::mcp_url() === rest_url( 'gz-ai/v1/mcp' ), 'Plain permalinks safely advertise the standard query URL' );
	check_mcp_url( 'standard' === Settings::sanitize( $s )['mcp_url_style'], 'Short preference cannot be saved with plain permalinks' );
	update_option( 'permalink_structure', '/%postname%/' );
	$s['mcp_url_style'] = 'standard'; update_option( Settings::OPTION, $s );
	$fixture = wp_insert_post( array( 'post_title' => 'MCP URL collision fixture', 'post_name' => 'mcp', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$s['mcp_url_style'] = 'short';
	check_mcp_url( 'standard' === Settings::sanitize( $s )['mcp_url_style'], 'An existing /mcp page blocks the short alias' );
	check_mcp_url( str_contains( Endpoints::short_mcp_unavailable_reason(), 'Existing WordPress content' ), 'Path conflicts include an actionable explanation' );
	echo "\n" . $GLOBALS['gz_url_checks'] . " MCP URL checks passed.\n";
} finally {
	if ( $fixture ) { wp_delete_post( $fixture, true ); }
	update_option( 'permalink_structure', $original_permalinks );
	update_option( Settings::OPTION, $original_settings );
	Endpoints::rewrites(); flush_rewrite_rules( false );
}
