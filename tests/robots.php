<?php
/** Run with GZ_AI_TEST_ALLOW=1 wp eval-file in a disposable WordPress installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'GZ_AI_TEST_ALLOW' ) ) {
	exit( "Set GZ_AI_TEST_ALLOW=1 and use wp eval-file in a disposable installation.\n" );
}

use GZ_AI\Endpoints;
use GZ_AI\Settings;

$original_settings = get_option( Settings::OPTION );
$original_public = get_option( 'blog_public' );
$original_permalinks = get_option( 'permalink_structure' );
$sitemaps = wp_sitemaps_get_server();
$original_sitemap_hook = has_filter( 'robots_txt', array( $sitemaps, 'add_robots' ) );
$other_plugin = static function ( $text ) { return $text . "Disallow: /other-plugin-private/\n"; };
$GLOBALS['gz_robots_checks'] = 0;
function check_robots( bool $condition, string $description ): void {
	if ( ! $condition ) { throw new RuntimeException( $description ); }
	++$GLOBALS['gz_robots_checks']; echo "PASS $description\n";
}
try {
	global $wp_rewrite;
	update_option( 'blog_public', '1' );
	update_option( 'permalink_structure', '/%postname%/' );
	$wp_rewrite->init();
	$s = Settings::defaults(); update_option( Settings::OPTION, $s );
	add_filter( 'wp_sitemaps_enabled', '__return_true' );
	add_filter( 'robots_txt', array( $sitemaps, 'add_robots' ), 0, 2 );
	add_filter( 'robots_txt', $other_plugin, 10 );
	ob_start(); do_robots(); $generated = ob_get_clean();
	check_robots( str_contains( $generated, 'Disallow: ' . wp_parse_url( admin_url(), PHP_URL_PATH ) ) && str_contains( $generated, 'Allow: ' . wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ) ), 'WordPress crawler rules remain in generated robots.txt' );
	check_robots( str_contains( $generated, "Disallow: /other-plugin-private/\n" ), 'Other plugin rules remain in generated robots.txt' );
	check_robots( str_contains( $generated, '# llms.txt: ' . Endpoints::llms_url() ), 'Generated robots.txt advertises llms.txt' );
	check_robots( str_contains( $generated, '# MCP endpoint: ' . rest_url( 'gz-ai/v1/mcp' ) ), 'Generated robots.txt advertises the direct MCP endpoint' );
	check_robots( 1 === substr_count( $generated, 'Sitemap:' ), 'Core sitemap is not duplicated' );
	check_robots( $generated === Endpoints::robots( $generated, true ), 'Repeated filtering does not duplicate discovery hints or sitemap' );

	$base = "User-agent: *\r\nDisallow: /private/\r\nAllow: /private/public";
	$robots = Endpoints::robots( $base, true );
	check_robots( str_starts_with( $robots, $base . "\n" ), 'Existing text is preserved byte for byte, including a missing final newline' );
	check_robots( str_contains( $robots, 'Sitemap: ' . $sitemaps->index->get_index_url() ), 'An enabled core sitemap is appended when missing' );
	$seo = $base . "\n  sItEmAp: https://example.test/seo-index.xml\nSitemap: https://example.test/news.xml\n";
	$robots = Endpoints::robots( $seo, true );
	check_robots( str_starts_with( $robots, $seo ) && ! str_contains( $robots, $sitemaps->index->get_index_url() ), 'Existing SEO sitemap entries are preserved without adding a competing sitemap' );

	$s['mcp_url_style'] = 'short'; update_option( Settings::OPTION, $s );
	check_robots( str_contains( Endpoints::robots( $base, true ), '# MCP endpoint: ' . home_url( '/mcp' ) ), 'Short MCP preference is reflected in robots.txt' );
	update_option( 'permalink_structure', '' );
	$wp_rewrite->init();
	$robots = Endpoints::robots( $base, true );
	check_robots( str_contains( $robots, '# MCP endpoint: ' . rest_url( 'gz-ai/v1/mcp' ) ) && str_contains( $robots, '# llms.txt: ' . Endpoints::llms_url() ) && str_contains( $robots, '?rest_route=' ) && str_contains( $robots, '?gz_ai_llms=1' ), 'Plain permalinks advertise working query URLs' );
	check_robots( str_contains( $robots, 'Sitemap: ' . $sitemaps->index->get_index_url() ) && str_contains( $robots, '?sitemap=index' ), 'Plain permalinks use the WordPress sitemap query URL' );

	$s['llms_enabled'] = false; update_option( Settings::OPTION, $s );
	$robots = Endpoints::robots( $base, true );
	check_robots( ! str_contains( $robots, '# llms.txt:' ) && str_contains( $robots, '# MCP endpoint:' ), 'MCP discovery works independently of llms.txt' );
	$s['llms_enabled'] = true; $s['mcp_enabled'] = false; update_option( Settings::OPTION, $s );
	$robots = Endpoints::robots( $base, true );
	check_robots( str_contains( $robots, '# llms.txt:' ) && ! str_contains( $robots, '# MCP endpoint:' ), 'Disabled MCP is not advertised' );
	$s['llms_enabled'] = false; update_option( Settings::OPTION, $s );
	$robots = Endpoints::robots( $base, true );
	check_robots( ! str_contains( $robots, '# AI discovery' ) && str_contains( $robots, 'Sitemap:' ), 'Sitemap discovery is independent of AI feature toggles' );
	check_robots( $seo === Endpoints::robots( $seo, true ), 'No additions leave existing output unchanged' );

	$s = Settings::defaults(); update_option( Settings::OPTION, $s );
	check_robots( $base === Endpoints::robots( $base, false ), 'Non-public robots.txt output is left untouched' );
	update_option( 'blog_public', '0' );
	check_robots( $base === Endpoints::robots( $base, true ), 'Search visibility settings suppress discovery additions' );
	$s['respect_search_visibility'] = false; update_option( Settings::OPTION, $s );
	check_robots( $base === Endpoints::robots( $base, false ), 'AI visibility override never overrides the robots public flag' );
	update_option( 'blog_public', '1' );

	remove_filter( 'wp_sitemaps_enabled', '__return_true' );
	add_filter( 'wp_sitemaps_enabled', '__return_false' );
	$robots = Endpoints::robots( $base, true );
	check_robots( ! str_contains( $robots, 'Sitemap:' ) && str_contains( $robots, '# MCP endpoint:' ), 'Disabled core sitemap is not advertised while AI discovery remains available' );
	check_robots( str_starts_with( Endpoints::robots( $seo, true ), $seo ), 'SEO sitemap entries survive when core sitemaps are disabled' );

	remove_filter( 'robots_txt', array( Endpoints::class, 'robots' ), 100 );
	check_robots( ! str_contains( apply_filters( 'robots_txt', $base, true ), '# AI discovery' ), 'Removing the plugin filter removes its additions without a stored file' );
	echo "\n" . $GLOBALS['gz_robots_checks'] . " robots.txt checks passed.\n";
} finally {
	remove_filter( 'robots_txt', $other_plugin, 10 );
	remove_filter( 'wp_sitemaps_enabled', '__return_true' );
	remove_filter( 'wp_sitemaps_enabled', '__return_false' );
	remove_filter( 'robots_txt', array( $sitemaps, 'add_robots' ), 0 );
	if ( false !== $original_sitemap_hook ) { add_filter( 'robots_txt', array( $sitemaps, 'add_robots' ), $original_sitemap_hook, 2 ); }
	add_filter( 'robots_txt', array( Endpoints::class, 'robots' ), 100, 2 );
	update_option( 'blog_public', $original_public );
	update_option( 'permalink_structure', $original_permalinks );
	$wp_rewrite->init();
	update_option( Settings::OPTION, $original_settings );
	Endpoints::rewrites(); flush_rewrite_rules( false );
}
