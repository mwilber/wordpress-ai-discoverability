<?php
namespace GZ_AI;
defined( 'ABSPATH' ) || exit;

final class Content {
	public static function types(): array {
		$types = array_intersect( Settings::get()['post_types'], array_keys( get_post_types( array( 'public' => true ) ) ) );
		return array_values( array_filter( $types, static function ( $type ) {
			return 'attachment' !== $type && is_post_type_viewable( get_post_type_object( $type ) );
		} ) );
	}

	public static function visible( $post ): bool {
		$post = get_post( $post );
		return Settings::public_enabled() && $post instanceof \WP_Post
			&& 'publish' === $post->post_status && '' === $post->post_password
			&& is_post_publicly_viewable( $post ) && in_array( $post->post_type, self::types(), true )
			&& ! in_array( $post->ID, Settings::get()['exclude_ids'], true );
	}

	public static function find( $id = 0, string $url = '' ) {
		if ( $url ) {
			// Resolve local URLs only. Never fetch arbitrary URLs from a client.
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return new \WP_Error( 'not_found', 'Public page not found.' );
			}
			$id = url_to_postid( $url );
		}
		if ( (int) $id < 1 ) { return new \WP_Error( 'not_found', 'Public page not found.' ); }
		$post = get_post( (int) $id );
		return $post && self::visible( $post ) ? $post : new \WP_Error( 'not_found', 'Public page not found.' );
	}

	public static function query( array $args = array() ): array {
		if ( ! Settings::public_enabled() || ! self::types() ) { return array( 'items' => array(), 'page' => 1, 'total' => 0, 'pages' => 0 ); }
		$page = max( 1, min( 10000, (int) ( $args['page'] ?? 1 ) ) );
		$query = new \WP_Query( array(
			'post_type' => self::types(), 'post_status' => 'publish', 'has_password' => false,
			'post__not_in' => Settings::get()['exclude_ids'], 's' => $args['query'] ?? '',
			'posts_per_page' => max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) ),
			'paged' => $page, 'orderby' => empty( $args['query'] ) ? 'ID' : 'relevance',
			'order' => 'ASC', 'ignore_sticky_posts' => true,
		) );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( self::visible( $post ) ) { $items[] = self::summary( $post ); }
		}
		return array( 'items' => $items, 'page' => $page, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
	}

	public static function summary( \WP_Post $post ): array {
		return array(
			'id' => $post->ID, 'title' => html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ),
			'url' => get_permalink( $post ), 'type' => $post->post_type,
			'markdown_url' => Settings::get()['markdown_enabled'] ? Endpoints::markdown_url( $post ) : null,
			'excerpt' => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_excerpt ?: $post->post_content ) ), 45 ),
			'modified' => get_post_modified_time( 'c', true, $post ),
		);
	}

	public static function markdown( \WP_Post $post ): string {
		$summary = self::summary( $post );
		// Use stored content, never the_content: logged-in requests must not render member-only shortcodes.
		$html = self::expand_blocks( parse_blocks( $post->post_content ) );
		$text = '# ' . Markdown::escape( $summary['title'] ) . "\n\n";
		$text .= 'Source: ' . $summary['url'] . "\n" . 'Last updated: ' . $summary['modified'] . "\n\n";
		$text .= Markdown::convert( strip_shortcodes( $html ), $summary['url'] );
		return (string) apply_filters( 'gz_ai_markdown', $text, $post );
	}

	private static function expand_blocks( array $blocks, array $seen = array() ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
				$id = (int) $block['attrs']['ref'];
				$reusable = get_post( $id );
				if ( ! in_array( $id, $seen, true ) && count( $seen ) < 10 && $reusable && 'wp_block' === $reusable->post_type && 'publish' === $reusable->post_status && ! $reusable->post_password ) {
					$html .= self::expand_blocks( parse_blocks( $reusable->post_content ), array_merge( $seen, array( $id ) ) );
				}
			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$i = 0;
				foreach ( $block['innerContent'] as $chunk ) {
					$html .= null === $chunk ? self::expand_blocks( array( $block['innerBlocks'][ $i++ ] ), $seen ) : $chunk;
				}
			} else { $html .= $block['innerHTML']; }
		}
		return $html;
	}

	public static function llms(): string {
		$s = Settings::get();
		if ( '' !== trim( $s['llms_custom'] ) ) {
			return strtr( $s['llms_custom'], array( '{{site_name}}' => get_bloginfo( 'name' ), '{{site_url}}' => home_url( '/' ), '{{description}}' => get_bloginfo( 'description' ), '{{mcp_url}}' => $s['mcp_enabled'] ? rest_url( 'gz-ai/v1/mcp' ) : '' ) ) . "\n";
		}
		$text = '# ' . Markdown::escape( get_bloginfo( 'name' ) ) . "\n\n> " . str_replace( "\n", "\n> ", $s['site_summary'] ?: get_bloginfo( 'description' ) ) . "\n\n";
		$text .= 'Website: ' . home_url( '/' ) . "\n\nLanguage: " . get_bloginfo( 'language' ) . "\n\n";
		if ( $s['guidance'] ) { $text .= $s['guidance'] . "\n\n"; }
		if ( $s['mcp_enabled'] ) { $text .= "## AI tools\n\n- [MCP endpoint](" . rest_url( 'gz-ai/v1/mcp' ) . "): Search and retrieve public site content using MCP over HTTP POST.\n\n"; }
		$text .= "## Content\n\n";
		$count = 0; $page = 1;
		do {
			$result = self::query( array( 'page' => $page++, 'per_page' => min( 50, $s['llms_limit'] - $count ) ) );
			foreach ( $result['items'] as $item ) {
				$text .= '- [' . Markdown::escape( $item['title'] ) . '](' . str_replace( array( '(', ')' ), array( '%28', '%29' ), $item['markdown_url'] ?: $item['url'] ) . '): ' . preg_replace( '/\s+/', ' ', $item['excerpt'] ) . "\n";
				++$count;
			}
		} while ( $count < $s['llms_limit'] && $page <= $result['pages'] );
		if ( $result['total'] > $count ) { $text .= "\nAdditional content is available through the site search" . ( $s['mcp_enabled'] ? ' and MCP list_pages tool (when enabled)' : '' ) . ".\n"; }
		return $text;
	}
}
