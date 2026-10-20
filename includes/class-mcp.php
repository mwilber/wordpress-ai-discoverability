<?php
namespace GZ_AI;
defined( 'ABSPATH' ) || exit;

/** A stateless, JSON-response Streamable HTTP server for public WordPress features. */
final class MCP {
	const VERSIONS = array( '2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26' );

	public static function boot(): void {
		add_action( 'rest_api_init', static function () {
			register_rest_route( 'gz-ai/v1', '/mcp', array(
				'methods' => 'GET, POST, DELETE, OPTIONS', 'permission_callback' => '__return_true',
				'callback' => array( self::class, 'handle' ),
			) );
		} );
		// Validate JSON ourselves before REST's argument parser can replace the JSON-RPC envelope.
		add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
			return '/gz-ai/v1/mcp' === $request->get_route() ? self::handle( $request ) : $result;
		}, 10, 3 );
		// Core reflects arbitrary origins. This public route instead uses an explicit origin allowlist.
		add_filter( 'rest_pre_serve_request', static function ( $served, $result, $request ) {
			if ( '/gz-ai/v1/mcp' === $request->get_route() ) {
				header_remove( 'Access-Control-Allow-Origin' );
				header_remove( 'Access-Control-Allow-Credentials' );
				$origin = $request->get_header( 'origin' );
				if ( $origin && self::origin_allowed( $origin ) ) {
					header( 'Access-Control-Allow-Origin: ' . $origin );
					header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
					header( 'Access-Control-Allow-Headers: Content-Type, Accept, MCP-Protocol-Version, Mcp-Method, Mcp-Name, X-WP-Nonce, Authorization' );
					header( 'Vary: Origin', false );
				}
			}
			return $served;
		}, 20, 3 );
	}

	private static function origin_allowed( string $origin ): bool {
		$p = wp_parse_url( home_url() );
		$home = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		return in_array( $origin, array_merge( array( $home ), preg_split( '/\R/', Settings::get()['allowed_origins'] ) ), true );
	}

	private static function response( $data, int $status = 200 ): \WP_REST_Response {
		$headers = array( 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff' );
		if ( 202 === $status || 204 === $status ) { $headers['X-GZ-AI-Raw'] = 'empty'; }
		return new \WP_REST_Response( $data, $status, $headers );
	}

	private static function error( $id, int $code, string $message, int $status = 400, ?array $data = null ): \WP_REST_Response {
		$error = array( 'code' => $code, 'message' => $message );
		if ( null !== $data ) { $error['data'] = $data; }
		return self::response( array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => $error ), $status );
	}

	/** Atomic per-minute counter, without persisting client IP addresses. Proxy headers are not trusted. */
	private static function limited( string $bucket, int $limit ): bool {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$slot = (int) floor( time() / 60 );
		$key = 'gz_ai_rate_' . sprintf( '%012d', $slot ) . '_' . hash_hmac( 'sha256', $bucket . '|' . $ip, wp_salt( 'nonce' ) );
		// INSERT/UPDATE is atomic across PHP workers; transients alone can race on comment writes.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1", $key
		) );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		if ( ! wp_next_scheduled( 'gz_ai_cleanup_rates' ) ) { wp_schedule_single_event( time() + 120, 'gz_ai_cleanup_rates' ); }
		// Fail closed if the counter could not be stored.
		return $count < 1 || $count > $limit;
	}

	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$s = Settings::get();
		if ( ! $s['mcp_enabled'] || ! Settings::public_enabled() ) { return self::error( null, -32601, 'MCP is unavailable.', 404 ); }
		$origin = $request->get_header( 'origin' );
		if ( $origin && ! self::origin_allowed( $origin ) ) { return self::error( null, -32600, 'Origin is not allowed.', 403 ); }
		if ( 'OPTIONS' === $request->get_method() ) { return self::response( null, 204 ); }
		if ( 'POST' !== $request->get_method() ) {
			$response = self::error( null, -32600, 'Use HTTP POST for MCP requests.', 405 );
			$response->header( 'Allow', 'POST, OPTIONS' );
			return $response;
		}
		if ( self::limited( 'requests', $s['rate_limit'] ) ) {
			$response = self::error( null, -32600, 'Rate limit exceeded. Try again in one minute.', 429 );
			$response->header( 'Retry-After', '60' ); return $response;
		}
		if ( strlen( $request->get_body() ) > 65536 ) { return self::error( null, -32600, 'Request exceeds 64 KB.', 413 ); }
		if ( ! preg_match( '~^application/json(?:\s*;|$)~i', $request->get_header( 'content-type' ) ) ) { return self::error( null, -32600, 'Content-Type must be application/json.', 415 ); }
		$accept = $request->get_header( 'accept' );
		if ( ! str_contains( $accept, 'application/json' ) || ! str_contains( $accept, 'text/event-stream' ) ) { return self::error( null, -32600, 'Accept must include application/json and text/event-stream.', 406 ); }
		$body = json_decode( $request->get_body() );
		if ( JSON_ERROR_NONE !== json_last_error() ) { return self::error( null, -32700, 'Invalid JSON.' ); }
		if ( ! is_object( $body ) || ( $body->jsonrpc ?? '' ) !== '2.0' || ! is_string( $body->method ?? null ) || ( property_exists( $body, 'params' ) && ! is_object( $body->params ) ) ) { return self::error( null, -32600, 'Expected one JSON-RPC 2.0 request.' ); }
		$has_id = property_exists( $body, 'id' );
		$id = $has_id ? $body->id : null;
		if ( $has_id && ! is_int( $id ) && ! is_string( $id ) ) { return self::error( null, -32600, 'Request id must be a string or integer.' ); }
		$method = $body->method;
		$params = isset( $body->params ) ? (array) $body->params : array();
		$meta = isset( $params['_meta'] ) && is_object( $params['_meta'] ) ? (array) $params['_meta'] : array();
		$version = $request->get_header( 'mcp-protocol-version' );
		if ( 'initialize' === $method ) {
			if ( ! $has_id || ! is_string( $params['protocolVersion'] ?? null ) || ! is_object( $params['capabilities'] ?? null ) || ! is_object( $params['clientInfo'] ?? null ) ) { return self::error( $id, -32602, 'Initialization requires protocolVersion, capabilities and clientInfo.' ); }
			$version = in_array( $params['protocolVersion'], array_slice( self::VERSIONS, 1 ), true ) ? $params['protocolVersion'] : '2025-11-25';
		} else {
			$version = $version ?: '2025-03-26';
			if ( ! in_array( $version, self::VERSIONS, true ) ) { return self::error( $id, -32022, 'Unsupported protocol version.', 400, array( 'supported' => self::VERSIONS, 'requested' => $version ) ); }
		}
		$modern = '2026-07-28' === $version;
		if ( $modern ) {
			if ( ! isset( $meta['io.modelcontextprotocol/protocolVersion'] ) || ! is_object( $meta['io.modelcontextprotocol/clientCapabilities'] ?? null ) ) { return self::error( $id, -32602, 'Required per-request protocol metadata is missing.' ); }
			if ( $version !== $meta['io.modelcontextprotocol/protocolVersion'] || $request->get_header( 'mcp-method' ) !== $method ) { return self::error( $id, -32020, 'Required MCP headers are missing or do not match the body.' ); }
			if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
				$name = $request->get_header( 'mcp-name' );
				if ( preg_match( '/^=\?base64\?(.+)\?=$/', $name, $match ) ) { $name = base64_decode( $match[1], true ); }
				$expected = 'resources/read' === $method ? ( $params['uri'] ?? null ) : ( $params['name'] ?? null );
				if ( ! is_string( $expected ) || $name !== $expected ) { return self::error( $id, -32020, 'Mcp-Name does not match the body.' ); }
			}
		} elseif ( isset( $meta['io.modelcontextprotocol/protocolVersion'] ) ) {
			return self::error( $id, -32020, 'Protocol metadata and HTTP header do not match.' );
		}
		if ( ! $has_id ) {
			if ( ! $modern && in_array( $method, array( 'notifications/initialized', 'notifications/cancelled' ), true ) ) { return self::response( null, 202 ); }
			return self::error( null, -32600, 'Unsupported notification.' );
		}
		$info = array( 'name' => 'gz-ai-discoverability', 'title' => get_bloginfo( 'name' ), 'version' => GZ_AI_VERSION );
		$capabilities = array( 'tools' => (object) array(), 'resources' => (object) array() );
		$result = array();
		switch ( $method ) {
			case 'initialize': $result = array( 'protocolVersion' => $version, 'serverInfo' => $info, 'capabilities' => $capabilities, 'instructions' => 'Access public WordPress content. Ask for explicit user consent before submitting a comment.' ); break;
			case 'server/discover':
				$result = array( 'supportedVersions' => self::VERSIONS, 'capabilities' => $capabilities, 'instructions' => 'Search and read public WordPress content. Obtain user consent before comment submission.' ); break;
			case 'ping': $result = array(); break;
			case 'tools/list': $result = array( 'tools' => self::tools() ); break;
			case 'tools/call':
				if ( ! is_string( $params['name'] ?? null ) || ( isset( $params['arguments'] ) && ! is_object( $params['arguments'] ) ) ) { return self::error( $id, -32602, 'Expected tool name and arguments object.' ); }
				$definition = null;
				foreach ( self::tools() as $tool ) { if ( $tool['name'] === $params['name'] ) { $definition = $tool; break; } }
				if ( ! $definition ) { return self::error( $id, -32602, 'Unknown or disabled tool.' ); }
				$args = isset( $params['arguments'] ) ? (array) $params['arguments'] : array();
				$schema = $definition['inputSchema'];
				$schema['properties'] = (array) $schema['properties'];
				$valid = rest_validate_value_from_schema( (object) $args, $schema, 'arguments' );
				if ( is_wp_error( $valid ) ) { return self::error( $id, -32602, $valid->get_error_message() ); }
				$data = self::call( $params['name'], $args );
				$result = is_wp_error( $data ) ? array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => $data->get_error_message() ) ) ) : array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ), 'structuredContent' => (object) $data );
				break;
			case 'resources/list':
				$page = self::cursor_page( $params['cursor'] ?? null );
				if ( ! $page ) { return self::error( $id, -32602, 'Invalid cursor.' ); }
				$pages = Content::query( array( 'page' => $page ) ); $resources = array();
				foreach ( $pages['items'] as $item ) { $resources[] = array( 'uri' => $item['url'], 'name' => $item['title'], 'mimeType' => 'text/markdown' ); }
				$result = array( 'resources' => $resources );
				if ( $page < $pages['pages'] ) { $result['nextCursor'] = base64_encode( (string) ( $page + 1 ) ); } break;
			case 'resources/templates/list': $result = array( 'resourceTemplates' => array() ); break;
			case 'resources/read':
				if ( ! is_string( $params['uri'] ?? null ) ) { return self::error( $id, -32602, 'Expected a public page URI.' ); }
				$post = Content::find( 0, $params['uri'] );
				if ( is_wp_error( $post ) ) { return self::error( $id, -32602, $post->get_error_message() ); }
				$result = array( 'contents' => array( array( 'uri' => get_permalink( $post ), 'mimeType' => 'text/markdown', 'text' => Content::markdown( $post ) ) ) ); break;
			default: return self::error( $id, -32601, 'Method not found.', $modern ? 404 : 200 );
		}
		if ( $modern ) { $result['resultType'] = 'complete'; $result['_meta'] = array( 'io.modelcontextprotocol/serverInfo' => $info ); }
		return self::response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result ) );
	}

	private static function cursor_page( $cursor ): int {
		if ( null === $cursor ) { return 1; }
		if ( ! is_string( $cursor ) ) { return 0; }
		$page = base64_decode( $cursor, true );
		return is_string( $page ) && ctype_digit( $page ) && (int) $page > 0 && (int) $page <= 10000 ? (int) $page : 0;
	}

	public static function tools(): array {
		$s = Settings::get();
		$integer = array( 'type' => 'integer', 'minimum' => 1 );
		$pagination = array( 'page' => $integer + array( 'maximum' => 10000 ), 'per_page' => $integer + array( 'maximum' => 50 ) );
		$definitions = array(
			'search' => array( 'Search public site content.', array_merge( array( 'query' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ) ), $pagination ), array( 'query' ) ),
			'get_page' => array( 'Retrieve a public page as Markdown. Provide id or canonical url.', array( 'id' => $integer, 'url' => array( 'type' => 'string', 'format' => 'uri', 'maxLength' => 2048 ) ), array() ),
			'list_pages' => array( 'Browse published public content with pagination.', $pagination, array() ),
			'site_info' => array( 'Get public site metadata, feeds, search URL and discovery endpoints.', array(), array() ),
			'list_terms' => array( 'List public categories or tags with published content.', array_merge( array( 'taxonomy' => array( 'type' => 'string', 'enum' => array( 'category', 'post_tag' ) ) ), $pagination ), array() ),
		);
		$enabled = $s['tools'];
		if ( $s['comments_enabled'] ) { $enabled[] = 'get_comments'; $definitions['get_comments'] = array( 'Read approved comments on a public page. Email and IP addresses are never returned.', array_merge( array( 'post_id' => $integer ), $pagination ), array( 'post_id' ) ); }
		if ( $s['comment_submission'] ) {
			$enabled[] = 'submit_comment';
			$definitions['submit_comment'] = array( 'Submit a comment only after the user explicitly approves its text and author details. WordPress moderation and anti-spam apply.', array(
				'post_id' => $integer, 'author' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
				'email' => array( 'type' => 'string', 'format' => 'email', 'maxLength' => 100 ),
				'content' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 10000 ),
				'parent' => array( 'type' => 'integer', 'minimum' => 0 ), 'consent' => array( 'type' => 'boolean', 'enum' => array( true ) ),
			), array( 'post_id', 'author', 'content', 'consent' ) );
		}
		$tools = array();
		foreach ( $definitions as $name => $def ) {
			if ( ! in_array( $name, $enabled, true ) ) { continue; }
			$tools[] = array( 'name' => $name, 'description' => $def[0], 'inputSchema' => array( 'type' => 'object', 'properties' => (object) $def[1], 'required' => $def[2], 'additionalProperties' => false ), 'annotations' => array( 'readOnlyHint' => 'submit_comment' !== $name, 'destructiveHint' => false, 'idempotentHint' => 'submit_comment' !== $name, 'openWorldHint' => false ) );
		}
		usort( $tools, static function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		return $tools;
	}

	private static function call( string $name, array $args ) {
		switch ( $name ) {
			case 'search': case 'list_pages': return Content::query( $args );
			case 'get_page':
				if ( empty( $args['id'] ) && empty( $args['url'] ) ) { return new \WP_Error( 'missing_page', 'Provide a page id or url.' ); }
				$post = Content::find( $args['id'] ?? 0, $args['url'] ?? '' );
				return is_wp_error( $post ) ? $post : Content::summary( $post ) + array( 'markdown' => Content::markdown( $post ), 'comments_open' => comments_open( $post->ID ) );
			case 'site_info':
				$s = Settings::get();
				return array( 'name' => get_bloginfo( 'name' ), 'description' => $s['site_summary'] ?: get_bloginfo( 'description' ), 'url' => home_url( '/' ), 'language' => get_bloginfo( 'language' ), 'feed' => get_feed_link(), 'search_url' => home_url( '/?s={query}' ), 'llms_url' => $s['llms_enabled'] ? Endpoints::llms_url() : null, 'mcp_url' => rest_url( 'gz-ai/v1/mcp' ), 'content_types' => Content::types() );
			case 'list_terms':
				$taxonomy = $args['taxonomy'] ?? 'category'; $page = $args['page'] ?? 1; $limit = $args['per_page'] ?? 20;
				// Restrict counts to content in the discovery scope, not private/excluded posts.
				global $wpdb;
				$types = Content::types();
				if ( ! $types ) { return array( 'items' => array(), 'page' => $page ); }
				$excluded = array_merge( array( 0 ), Settings::get()['exclude_ids'] );
				$sql = "SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS public_count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tt.taxonomy=%s AND p.post_status='publish' AND p.post_password='' AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND p.ID NOT IN (' . implode( ',', array_fill( 0, count( $excluded ), '%d' ) ) . ') GROUP BY t.term_id, t.name, t.slug ORDER BY t.term_id ASC LIMIT %d OFFSET %d';
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $taxonomy ), $types, $excluded, array( $limit, ( $page - 1 ) * $limit ) ) ) );
				$items = array();
				foreach ( $rows as $row ) { $items[] = array( 'id' => (int) $row->term_id, 'name' => $row->name, 'slug' => $row->slug, 'count' => (int) $row->public_count, 'url' => get_term_link( (int) $row->term_id, $taxonomy ) ); }
				return array( 'items' => $items, 'page' => $page );
			case 'get_comments': case 'submit_comment':
				$post = Content::find( $args['post_id'] );
				if ( is_wp_error( $post ) ) { return $post; }
				if ( 'submit_comment' === $name ) { return self::comment( $post, $args ); }
				$page = $args['page'] ?? 1; $limit = $args['per_page'] ?? 20;
				$comments = get_comments( array( 'post_id' => $post->ID, 'status' => 'approve', 'type' => 'comment', 'number' => $limit, 'offset' => ( $page - 1 ) * $limit, 'orderby' => 'comment_ID', 'order' => 'ASC' ) );
				$items = array();
				foreach ( $comments as $comment ) { $items[] = array( 'id' => (int) $comment->comment_ID, 'parent' => (int) $comment->comment_parent, 'author' => $comment->comment_author, 'content' => Markdown::convert( $comment->comment_content, get_permalink( $post ) ), 'date' => $comment->comment_date_gmt ); }
				return array( 'items' => $items, 'page' => $page );
		}
		return new \WP_Error( 'unknown_tool', 'Unknown tool.' );
	}

	private static function comment( \WP_Post $post, array $args ) {
		$s = Settings::get();
		if ( true !== ( $args['consent'] ?? false ) ) { return new \WP_Error( 'consent', 'Explicit user consent is required.' ); }
		if ( self::limited( 'comments', $s['comment_rate_limit'] ) ) { return new \WP_Error( 'rate_limit', 'Comment rate limit exceeded. Try again in one minute.' ); }
		if ( ! empty( $args['parent'] ) ) {
			$parent = get_comment( $args['parent'] );
			if ( ! $parent || (int) $parent->comment_post_ID !== $post->ID || '1' !== (string) $parent->comment_approved ) { return new \WP_Error( 'invalid_parent', 'Reply target must be an approved comment on this page.' ); }
		}
		$moderate = static function ( $approved ) { return in_array( $approved, array( 'spam', 'trash' ), true ) || is_wp_error( $approved ) ? $approved : 0; };
		if ( $s['force_moderation'] ) { add_filter( 'pre_comment_approved', $moderate, PHP_INT_MAX ); }
		try {
			$result = wp_handle_comment_submission( array( 'comment_post_ID' => $post->ID, 'author' => sanitize_text_field( $args['author'] ), 'email' => $args['email'] ?? '', 'comment' => wp_kses_post( $args['content'] ), 'comment_parent' => $args['parent'] ?? 0 ) );
		} finally {
			if ( $s['force_moderation'] ) { remove_filter( 'pre_comment_approved', $moderate, PHP_INT_MAX ); }
		}
		if ( is_wp_error( $result ) ) { return $result; }
		return array( 'id' => (int) $result->comment_ID, 'status' => '1' === (string) $result->comment_approved ? 'approved' : 'pending', 'message' => 'Comment received. Publication depends on site moderation.' );
	}
}

add_action( 'gz_ai_cleanup_rates', static function () {
	global $wpdb;
	// All counters have a maximum useful life of one minute; delayed cleanup is safe.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING(option_name, 12, 12) AS UNSIGNED) < %d", $wpdb->esc_like( 'gz_ai_rate_' ) . '%', (int) floor( time() / 60 ) - 1 ) );
} );
