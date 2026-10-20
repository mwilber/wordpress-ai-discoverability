<?php
/** Run only in a disposable WordPress installation. See README.md. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'GZ_AI_TEST_ALLOW' ) ) {
	exit( "Set GZ_AI_TEST_ALLOW=1 and run with wp eval-file in a disposable installation.\n" );
}

use GZ_AI\Settings;
use GZ_AI\Content;
use GZ_AI\Markdown;
use GZ_AI\MCP;

$GLOBALS['gz_ai_checks'] = 0;
function expect( $condition, string $label ): void {
	++$GLOBALS['gz_ai_checks'];
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	echo "PASS $label\n";
}
function rpc( string $method, $params = null, string $version = '2026-07-28', array $headers = array(), $id = 1 ): WP_REST_Response {
	$params = (object) ( $params ?? array() );
	if ( '2026-07-28' === $version ) {
		$params->_meta = (object) array( 'io.modelcontextprotocol/protocolVersion' => $version, 'io.modelcontextprotocol/clientCapabilities' => (object) array() );
	}
	$request = new WP_REST_Request( 'POST', '/gz-ai/v1/mcp' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'accept', 'application/json, text/event-stream' );
	$request->set_header( 'mcp-protocol-version', $version );
	$request->set_header( 'mcp-method', $method );
	if ( 'tools/call' === $method ) { $request->set_header( 'mcp-name', $params->name ); }
	if ( 'resources/read' === $method ) { $request->set_header( 'mcp-name', $params->uri ); }
	foreach ( $headers as $key => $value ) { $request->set_header( $key, $value ); }
	$body = array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => $params );
	if ( null !== $id ) { $body['id'] = $id; }
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
}
function tool( string $name, array $args = array() ): array {
	return rpc( 'tools/call', array( 'name' => $name, 'arguments' => (object) $args ) )->get_data();
}

$original = get_option( Settings::OPTION );
$original_public = get_option( 'blog_public' );
$original_discussion = array( get_option( 'require_name_email' ), get_option( 'comment_registration' ) );
$original_user = get_current_user_id();
add_filter( 'pre_wp_mail', '__return_true' );
$ids = array();
$term_ids = array();
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'gz_ai_rate_' ) . '%' ) );
try {
	$s = Settings::defaults(); $s['rate_limit'] = 600;
	update_option( Settings::OPTION, $s ); update_option( 'blog_public', '1' );
	foreach ( array( 'publish', 'draft', 'private', 'future' ) as $status ) {
		$ids[ $status ] = wp_insert_post( array( 'post_title' => 'GZ Fixture ' . $status, 'post_type' => 'page', 'post_status' => $status, 'post_date' => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : current_time( 'mysql' ), 'post_content' => '<h2>Useful heading</h2><p>Hello <strong>world</strong>.</p><ul><li>First</li><li>Second</li></ul><p>[gz_secret]</p>', 'comment_status' => 'open' ) );
	}
	$ids['password'] = wp_insert_post( array( 'post_title' => 'GZ protected', 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => 'secret', 'post_content' => 'Hidden password content' ) );
	$ids['excluded'] = wp_insert_post( array( 'post_title' => 'GZ excluded', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$s['exclude_ids'] = array( $ids['excluded'] ); update_option( Settings::OPTION, $s );
	add_shortcode( 'gz_secret', static function () { return 'SECRET_SHORTCODE_OUTPUT'; } );
	expect( Content::visible( $ids['publish'] ), 'Published public page is visible' );
	foreach ( array( 'draft', 'private', 'future', 'password', 'excluded' ) as $status ) {
		expect( ! Content::visible( $ids[ $status ] ), "$status is excluded" );
		expect( is_wp_error( Content::find( $ids[ $status ] ) ), "$status cannot be retrieved" );
	}
	wp_set_current_user( 1 );
	expect( is_wp_error( Content::find( $ids['private'] ) ) && is_wp_error( Content::find( $ids['password'] ) ), 'Authenticated administrators cannot bypass public scope' );
	wp_set_current_user( 0 );
	register_post_type( 'gz_unviewable', array( 'public' => true, 'publicly_queryable' => false ) );
	$s['post_types'][] = 'gz_unviewable'; update_option( Settings::OPTION, $s );
	expect( ! in_array( 'gz_unviewable', Content::types(), true ), 'Unviewable public custom types are excluded from queries and term counts' );
	unregister_post_type( 'gz_unviewable' );
	expect( is_wp_error( Content::find( 0, home_url( '/missing-fixture' ) ) ), 'Unknown URL never falls back to global post' );
	$markdown = Content::markdown( get_post( $ids['publish'] ) );
	expect( str_contains( $markdown, '## Useful heading' ) && str_contains( $markdown, '**world**' ) && str_contains( $markdown, '- First' ), 'Markdown preserves headings, emphasis and lists' );
	expect( ! str_contains( $markdown, 'SECRET_SHORTCODE_OUTPUT' ), 'Privileged shortcodes are not executed' );
	$converted = Markdown::convert( '<p><a href="/about/">About</a><a href="javascript:alert(1)">Bad</a></p><script>secret</script><pre>a\nb```c</pre><table><tr><th>A</th></tr><tr><td>B</td></tr></table>', home_url( '/page/' ) );
	expect( str_contains( $converted, home_url( '/about/' ) ) && ! str_contains( $converted, 'javascript:' ) && ! str_contains( $converted, 'secret' ), 'Markdown resolves relative links and strips unsafe HTML' );
	expect( str_contains( $converted, '````' ) && str_contains( $converted, '| --- |' ), 'Code fences and tables survive conversion' );
	$llms = Content::llms();
	expect( str_starts_with( $llms, '# ' ) && str_contains( $llms, 'GZ Fixture publish' ) && ! str_contains( $llms, 'GZ protected' ) && ! str_contains( $llms, 'GZ excluded' ), 'Generated llms.txt follows content scope' );
	$s['llms_custom'] = '# {{site_name}}' . "\n" . '{{site_url}} {{mcp_url}}'; update_option( Settings::OPTION, $s );
	expect( str_contains( Content::llms(), rest_url( 'gz-ai/v1/mcp' ) ), 'Custom llms.txt expands placeholders' );
	$s['llms_custom'] = ''; update_option( Settings::OPTION, $s );
	$discover = rpc( 'server/discover' )->get_data();
	expect( isset( $discover['result']->supportedVersions ) && 'complete' === $discover['result']->resultType, 'Modern MCP discovery succeeds' );
	$list = rpc( 'tools/list' )->get_data();
	expect( count( $list['result']->tools ) === 5, 'Default MCP tools are discoverable' );
	$search = tool( 'search', array( 'query' => 'GZ Fixture' ) );
	expect( 1 === count( $search['result']->structuredContent->items ), 'Search excludes private, draft, future and protected pages' );
	expect( isset( tool( 'get_page', array( 'id' => $ids['password'] ) )['result']->isError ), 'MCP cannot retrieve protected content' );
	$get = tool( 'get_page', array( 'id' => $ids['publish'] ) );
	expect( str_contains( $get['result']->structuredContent->markdown, '**world**' ), 'MCP page retrieval returns Markdown' );
	expect( isset( tool( 'search', array( 'query' => array( 'bad' ) ) )['error'] ), 'Invalid tool argument types are rejected' );
	expect( isset( tool( 'search', array( 'query' => 'ok', 'per_page' => 1000 ) )['error'] ), 'Pagination is bounded' );
	expect( isset( tool( 'site_info', array( 'unexpected' => true ) )['error'] ), 'Additional properties are rejected' );
	expect( 403 === rpc( 'tools/list', null, '2026-07-28', array( 'origin' => 'https://untrusted.example' ) )->get_status(), 'Foreign origins are rejected' );
	expect( -32020 === rpc( 'tools/list', null, '2026-07-28', array( 'mcp-method' => 'tools/call' ) )->get_data()['error']['code'], 'Modern header mismatch is rejected' );
	expect( -32022 === rpc( 'tools/list', null, '2099-01-01' )->get_data()['error']['code'], 'Unsupported protocol advertises supported versions' );
	$legacy = rpc( 'initialize', array( 'protocolVersion' => '2025-11-25', 'clientInfo' => (object) array( 'name' => 'test', 'version' => '1' ), 'capabilities' => (object) array() ), '2025-11-25' )->get_data();
	expect( '2025-11-25' === $legacy['result']->protocolVersion, 'Legacy initialization negotiates requested version' );
	expect( 202 === rpc( 'notifications/initialized', null, '2025-11-25', array(), null )->get_status(), 'Legacy initialized notification accepted with 202' );
	expect( isset( rpc( 'resources/list' )->get_data()['result']->resources ), 'MCP resources enumerate public pages' );
	expect( isset( rpc( 'resources/list', array( 'cursor' => 'invalid!' ) )->get_data()['error'] ), 'Invalid resource cursors are rejected' );
	expect( isset( rpc( 'resources/read', array( 'uri' => get_permalink( $ids['publish'] ) ) )->get_data()['result']->contents ), 'MCP resources can retrieve Markdown' );
	$public_term = wp_insert_term( 'GZ Public ' . wp_generate_uuid4(), 'category' );
	$hidden_term = wp_insert_term( 'GZ Hidden ' . wp_generate_uuid4(), 'category' );
	$term_ids = array( $public_term['term_id'], $hidden_term['term_id'] );
	wp_set_object_terms( $ids['publish'], array( $term_ids[0] ), 'category' );
	foreach ( array( 'private', 'password', 'excluded' ) as $status ) { wp_set_object_terms( $ids[ $status ], array( $term_ids[1] ), 'category' ); }
	$terms = tool( 'list_terms', array( 'taxonomy' => 'category', 'per_page' => 50 ) );
	$term_items = $terms['result']->structuredContent->items;
	$listed_ids = array_column( $term_items, 'id' );
	expect( in_array( $term_ids[0], $listed_ids, true ) && ! in_array( $term_ids[1], $listed_ids, true ), 'Term browsing excludes terms attached only to hidden content' );
	expect( get_bloginfo( 'name' ) === tool( 'site_info' )['result']->structuredContent->name, 'Site information tool returns configured metadata' );
	$s['markdown_enabled'] = false; update_option( Settings::OPTION, $s );
	expect( 404 === rest_do_request( new WP_REST_Request( 'GET', '/gz-ai/v1/markdown/' . $ids['publish'] ) )->get_status(), 'Disabled Markdown endpoint returns 404' );
	$s['markdown_enabled'] = true; update_option( Settings::OPTION, $s );
	$slot = (int) floor( time() / 60 );
	$expired_key = 'gz_ai_rate_' . sprintf( '%012d', $slot - 5 ) . '_fixture';
	$current_key = 'gz_ai_rate_' . sprintf( '%012d', $slot ) . '_fixture';
	add_option( $expired_key, 1, '', false ); add_option( $current_key, 1, '', false );
	do_action( 'gz_ai_cleanup_rates' );
	// Direct reads avoid stale options cache after deliberate SQL cleanup.
	expect( ! $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $expired_key ) ) && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $current_key ) ), 'Rate cleanup removes expired counters while preserving active limits' );
	$s['tools'] = array( 'site_info' ); update_option( Settings::OPTION, $s );
	expect( isset( tool( 'search', array( 'query' => 'fixture' ) )['error'] ), 'Disabled tools cannot be invoked directly' );
	$s['comments_enabled'] = true; $s['comment_submission'] = true; update_option( Settings::OPTION, $s );
	update_option( 'require_name_email', 1 ); update_option( 'comment_registration', 0 ); wp_set_current_user( 0 );
	$comment = array( 'post_id' => $ids['publish'], 'author' => 'Fixture Reader', 'email' => 'fixture@example.test', 'content' => 'A useful public comment ' . wp_generate_uuid4(), 'consent' => true );
	$submission = tool( 'submit_comment', $comment );
	expect( 'pending' === ( $submission['result']->structuredContent->status ?? '' ), 'Comment submission is held for moderation' );
	$comment_id = $submission['result']->structuredContent->id;
	expect( '0' === get_comment( $comment_id )->comment_approved, 'MCP comment is actually unapproved in the database' );
	$read = tool( 'get_comments', array( 'post_id' => $ids['publish'] ) );
	expect( array() === $read['result']->structuredContent->items, 'Unapproved comments are never exposed' );
	wp_set_comment_status( $comment_id, 'approve' );
	$read = tool( 'get_comments', array( 'post_id' => $ids['publish'] ) );
	expect( ! str_contains( wp_json_encode( $read ), 'fixture@example.test' ) && 1 === count( $read['result']->structuredContent->items ), 'Approved comments omit email addresses' );
	$comment['consent'] = false;
	expect( isset( tool( 'submit_comment', $comment )['error'] ), 'Comment consent is enforced in the schema' );
	$comment['consent'] = true; $comment['parent'] = $comment_id; $comment['post_id'] = $ids['excluded'];
	expect( isset( tool( 'submit_comment', $comment )['result']->isError ), 'Comments cannot target excluded pages' );
	update_option( 'comment_registration', 1 ); $comment['post_id'] = $ids['publish']; unset( $comment['parent'] );
	expect( isset( tool( 'submit_comment', $comment )['result']->isError ), 'WordPress login requirement is preserved' );
	update_option( 'comment_registration', 0 );
	wp_update_comment( array( 'comment_ID' => $comment_id, 'comment_date' => gmdate( 'Y-m-d H:i:s', time() - 120 ), 'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ) );
	$spam_filter = static function () { return 'spam'; };
	add_filter( 'pre_comment_approved', $spam_filter, 10 );
	$comment['content'] = 'Spam fixture ' . wp_generate_uuid4();
	$spam = tool( 'submit_comment', $comment );
	remove_filter( 'pre_comment_approved', $spam_filter, 10 );
	$spam_id = $spam['result']->structuredContent->id ?? 0;
	expect( $spam_id && 'spam' === get_comment( $spam_id )->comment_approved, 'Forced moderation preserves anti-spam decisions' );
	update_option( 'blog_public', '0' );
	expect( ! Content::visible( $ids['publish'] ) && 404 === rpc( 'tools/list' )->get_status(), 'Discourage search engines pauses discovery and MCP' );
	$s['respect_search_visibility'] = false; update_option( Settings::OPTION, $s );
	expect( Content::visible( $ids['publish'] ), 'Administrator can explicitly override site search visibility' );
	update_option( 'blog_public', '1' );
	$s['rate_limit'] = 1; update_option( Settings::OPTION, $s );
	expect( 429 === rpc( 'tools/list' )->get_status(), 'MCP request limit returns HTTP 429' );
	$s['mcp_enabled'] = false; update_option( Settings::OPTION, $s );
	expect( 404 === rpc( 'tools/list' )->get_status(), 'Disabled MCP server is unavailable' );
	$sanitized = Settings::sanitize( array( 'post_types' => array( 'page', 'attachment', 'made_up' ), 'rate_limit' => 10000, 'llms_custom' => '<script>bad</script># Header', 'allowed_origins' => "https://ok.example\nhttps://bad.example/path\n*", 'exclude_ids' => '1,2,2,-3' ) );
	expect( array( 'page' ) === $sanitized['post_types'] && 600 === $sanitized['rate_limit'] && 'https://ok.example' === $sanitized['allowed_origins'], 'Settings restrict content types, bounds and browser origins' );
	echo "\n" . $GLOBALS['gz_ai_checks'] . ' integration checks passed on WordPress ' . get_bloginfo( 'version' ) . ".\n";
} finally {
	foreach ( $ids as $post_id ) { wp_delete_post( $post_id, true ); }
	foreach ( $term_ids as $term_id ) { wp_delete_term( $term_id, 'category' ); }
	update_option( Settings::OPTION, $original ); update_option( 'blog_public', $original_public );
	update_option( 'require_name_email', $original_discussion[0] ); update_option( 'comment_registration', $original_discussion[1] );
	wp_set_current_user( $original_user );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'gz_ai_rate_' ) . '%' ) );
}
