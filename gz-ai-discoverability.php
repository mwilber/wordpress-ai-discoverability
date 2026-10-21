<?php
/**
 * Plugin Name: GZ AI Discoverability
 * Description: Give AI assistants a curated llms.txt, clean Markdown, and a public-facing MCP server.
 * Version: 1.0.3
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Tested up to: 7.1.3
 * Author: GZ
 * License: GPL-2.0-or-later
 * Text Domain: gz-ai-discoverability
 */

defined( 'ABSPATH' ) || exit;
define( 'GZ_AI_VERSION', '1.0.3' );
define( 'GZ_AI_FILE', __FILE__ );
foreach ( array( 'settings', 'markdown', 'content', 'endpoints', 'mcp', 'admin' ) as $gz_ai_module ) {
	require_once __DIR__ . '/includes/class-' . $gz_ai_module . '.php';
}
add_action( 'plugins_loaded', static function () {
	GZ_AI\Endpoints::boot();
	GZ_AI\MCP::boot();
	if ( is_admin() ) {
		GZ_AI\Admin::boot();
	}
} );
register_activation_hook( __FILE__, static function () {
	add_option( GZ_AI\Settings::OPTION, GZ_AI\Settings::defaults(), '', false );
	GZ_AI\Endpoints::rewrites();
	flush_rewrite_rules( false );
	wp_clear_scheduled_hook( 'gz_ai_cleanup_rates' );
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'gz_ai_rate_' ) . '%' ) );
} );
register_deactivation_hook( __FILE__, static function () {
	// Remove our rules before flushing; init has already registered them this request.
	GZ_AI\Endpoints::remove_mcp_alias();
	global $wp_rewrite;
	$wp_rewrite->extra_rules_top = array_filter( $wp_rewrite->extra_rules_top, static function ( $target ) {
		return ! str_contains( $target, 'gz_ai_' );
	} );
	flush_rewrite_rules( false );
	wp_clear_scheduled_hook( 'gz_ai_cleanup_rates' );
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'gz_ai_rate_' ) . '%' ) );
} );
