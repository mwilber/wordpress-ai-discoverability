<?php
namespace GZ_AI;
defined( 'ABSPATH' ) || exit;

final class Endpoints {
	public static function boot(): void {
		add_action( 'init', array( self::class, 'rewrites' ) );
		add_filter( 'query_vars', static function ( $vars ) { return array_merge( $vars, array( 'gz_ai_llms', 'gz_ai_markdown' ) ); } );
		add_action( 'template_redirect', array( self::class, 'serve' ), 0 );
		add_action( 'wp_head', array( self::class, 'head' ), 2 );
		add_filter( 'wp_headers', array( self::class, 'headers' ) );
		add_filter( 'robots_txt', static function ( $text, $public ) {
			return $public && Settings::get()['llms_enabled'] && Settings::public_enabled() ? $text . "\n# AI discovery\n# " . Endpoints::llms_url() . "\n" : $text;
		}, 10, 2 );
		add_action( 'rest_api_init', static function () {
			register_rest_route( 'gz-ai/v1', '/markdown/(?P<id>\d+)', array(
				'methods' => 'GET', 'permission_callback' => '__return_true',
				'callback' => static function ( $request ) {
					$post = Content::find( (int) $request['id'] );
					if ( ! Settings::get()['markdown_enabled'] || is_wp_error( $post ) ) { return new \WP_Error( 'not_found', 'Public page not found.', array( 'status' => 404 ) ); }
					return new \WP_REST_Response( Content::markdown( $post ), 200, array( 'Content-Type' => 'text/markdown; charset=UTF-8', 'X-GZ-AI-Raw' => 'markdown', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store' ) );
				},
			) );
		} );
		add_filter( 'rest_pre_serve_request', static function ( $served, $result, $request ) {
			if ( $result instanceof \WP_HTTP_Response && isset( $result->get_headers()['X-GZ-AI-Raw'] ) ) {
				if ( 202 !== $result->get_status() && 'HEAD' !== $request->get_method() ) {
					echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw Markdown, not HTML.
				}
				return true;
			}
			return $served;
		}, 10, 3 );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^llms\.txt$', 'index.php?gz_ai_llms=1', 'top' );
		add_rewrite_rule( '^(.+?)\.md/?$', 'index.php?gz_ai_markdown=$matches[1]', 'top' );
	}

	public static function markdown_url( \WP_Post $post ): string {
		if ( ! get_option( 'permalink_structure' ) ) {
			$args = 'page' === $post->post_type ? array( 'page_id' => $post->ID ) : array( 'p' => $post->ID, 'post_type' => $post->post_type );
			return add_query_arg( $args + array( 'gz_ai_markdown' => '1' ), home_url( '/' ) );
		}
		$url = get_permalink( $post );
		return str_ends_with( $url, '/' ) ? $url . 'index.md' : $url . '.md';
	}

	public static function llms_url(): string {
		return get_option( 'permalink_structure' ) ? home_url( '/llms.txt' ) : add_query_arg( 'gz_ai_llms', '1', home_url( '/' ) );
	}

	public static function serve(): void {
		$llms = get_query_var( 'gz_ai_llms' );
		$md = get_query_var( 'gz_ai_markdown' );
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		$negotiated = ! $md && is_singular() && self::prefers_markdown( $accept );
		if ( ! $llms && ! $md && ! $negotiated ) { return; }
		if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) {
			status_header( 405 ); header( 'Allow: GET, HEAD' ); header( 'Content-Type: text/plain; charset=UTF-8' ); exit;
		}
		$s = Settings::get();
		$text = null;
		if ( $llms && $s['llms_enabled'] && Settings::public_enabled() ) { $text = Content::llms(); }
		if ( ! $llms && $s['markdown_enabled'] && Settings::public_enabled() ) {
			if ( $md && '1' !== $md ) {
				$path = 'index' === $md ? '/' : preg_replace( '~/index$~', '/', (string) $md );
				$url = home_url( '/' . ltrim( $path, '/' ) );
				if ( '/' === $path || '' === $path ) { $post = get_post( (int) get_option( 'page_on_front' ) ); }
				else { $post = Content::find( 0, $url ); }
			} elseif ( '1' === (string) $md ) {
				$post = Content::find( (int) get_query_var( 'p' ) ?: (int) get_query_var( 'page_id' ) );
			} else { $post = get_queried_object(); }
			if ( $post instanceof \WP_Post && Content::visible( $post ) ) { $text = Content::markdown( $post ); }
		}
		status_header( null === $text ? 404 : 200 );
		header( 'Content-Type: ' . ( $llms ? 'text/plain' : 'text/markdown' ) . '; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Vary: Accept', false );
		nocache_headers();
		if ( $s['llms_enabled'] && Settings::public_enabled() ) { header( 'Link: <' . Endpoints::llms_url() . '>; rel="describedby"', false ); }
		if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			echo null === $text ? 'Not found.' : $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text response.
		}
		exit;
	}

	private static function prefers_markdown( string $accept ): bool {
		$markdown = 0.0; $html = 0.0;
		foreach ( explode( ',', strtolower( $accept ) ) as $entry ) {
			$parts = array_map( 'trim', explode( ';', $entry ) );
			$q = 1.0;
			foreach ( array_slice( $parts, 1 ) as $parameter ) {
				if ( str_starts_with( $parameter, 'q=' ) ) { $q = max( 0.0, min( 1.0, (float) substr( $parameter, 2 ) ) ); }
			}
			if ( 'text/markdown' === $parts[0] ) { $markdown = $q; }
			if ( in_array( $parts[0], array( 'text/html', 'application/xhtml+xml' ), true ) ) { $html = max( $html, $q ); }
		}
		return $markdown > 0 && $markdown >= $html;
	}

	private static function links(): array {
		$s = Settings::get(); $links = array();
		if ( ! $s['head_links'] || ! Settings::public_enabled() ) { return $links; }
		if ( $s['llms_enabled'] ) { $links[] = array( 'url' => Endpoints::llms_url(), 'rel' => 'describedby', 'type' => 'text/plain' ); }
		if ( $s['markdown_enabled'] && is_singular() && Content::visible( get_queried_object() ) ) { $links[] = array( 'url' => self::markdown_url( get_queried_object() ), 'rel' => 'alternate', 'type' => 'text/markdown' ); }
		if ( $s['mcp_enabled'] ) { $links[] = array( 'url' => rest_url( 'gz-ai/v1/mcp' ), 'rel' => 'mcp', 'type' => 'application/json' ); }
		return $links;
	}

	public static function head(): void {
		foreach ( self::links() as $link ) { printf( '<link rel="%s" type="%s" href="%s" />' . "\n", esc_attr( $link['rel'] ), esc_attr( $link['type'] ), esc_url( $link['url'] ) ); }
	}

	public static function headers( array $headers ): array {
		// Vary even on HTML so an upstream cache cannot mix HTML and negotiated Markdown.
		$headers['Vary'] = isset( $headers['Vary'] ) ? $headers['Vary'] . ', Accept' : 'Accept';
		return $headers;
	}
}
