<?php
namespace GZ_AI;
defined( 'ABSPATH' ) || exit;

final class Content {
	/** Normalize human-readable metadata without changing stored content or code samples. */
	public static function plain_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( '\\r\\n', '\\n', '\\r', "\u{00a0}" ), ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	}

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
		$types = isset( $args['post_type'] ) ? array_values( array_intersect( self::types(), (array) $args['post_type'] ) ) : self::types();
		if ( ! Settings::public_enabled() || ! $types ) { return array( 'items' => array(), 'page' => 1, 'total' => 0, 'pages' => 0 ); }
		$page = max( 1, min( 10000, (int) ( $args['page'] ?? 1 ) ) );
		$query = new \WP_Query( array(
			'post_type' => $types, 'post_status' => 'publish', 'has_password' => false,
			'post__not_in' => Settings::get()['exclude_ids'], 's' => $args['query'] ?? '',
			'posts_per_page' => max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) ),
			'paged' => $page, 'orderby' => ! empty( $args['newest_first'] ) ? array( 'date' => 'DESC', 'ID' => 'DESC' ) : ( empty( $args['query'] ) ? 'ID' : 'relevance' ),
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
			'id' => $post->ID, 'title' => self::plain_text( $post->post_title ),
			'url' => get_permalink( $post ), 'type' => $post->post_type,
			'markdown_url' => Settings::get()['markdown_enabled'] ? Endpoints::markdown_url( $post ) : null,
			'excerpt' => self::plain_text( wp_trim_words( self::plain_text( strip_shortcodes( $post->post_excerpt ?: $post->post_content ) ), 45 ) ),
			'modified' => get_post_modified_time( 'c', true, $post ),
		);
	}

	public static function markdown( \WP_Post $post ): string {
		$summary = self::summary( $post );
		// Use stored content, never the_content: logged-in requests must not render member-only shortcodes.
		$html = self::expand_blocks( parse_blocks( $post->post_content ) );
		$project = self::project( $post );
		$terms = get_the_terms( $post, 'post_tag' );
		$tags = is_array( $terms ) ? array_map( static function ( $term ) { return self::plain_text( $term->name ); }, $terms ) : array();
		$metadata = array( 'title' => $summary['title'], 'date' => get_post_time( 'c', true, $post ), 'tags' => array_values( $tags ), 'project' => $project ? self::plain_text( $project->post_title ) : null );
		$text = "---\n";
		foreach ( $metadata as $key => $value ) {
			// JSON scalars and arrays are valid YAML; quoting prevents metadata from creating new keys.
			$text .= $key . ': ' . wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		}
		$text .= "---\n\n# " . Markdown::escape( $summary['title'] ) . "\n\n";
		$text .= 'Source: ' . $summary['url'] . "\n" . 'Last updated: ' . $summary['modified'] . "\n\n";
		if ( $project && $project->ID !== $post->ID ) { $text .= 'Project: [' . Markdown::escape( self::plain_text( $project->post_title ) ) . '](' . esc_url_raw( get_permalink( $project ) ) . ")\n\n"; }
		$text .= self::project_fields( $post );
		$text .= Markdown::convert( strip_shortcodes( $html ), $summary['url'] );
		return (string) apply_filters( 'gz_ai_markdown', $text, $post );
	}

	private static function project( \WP_Post $post ): ?\WP_Post {
		if ( 'project' === $post->post_type ) { return $post; }
		if ( ! in_array( $post->post_type, array( 'post', 'update' ), true ) ) { return null; }
		// Relationships are resolved through the same public-content scope as get_page.
		$id = get_post_meta( $post->ID, 'project_id', true );
		if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) { return null; }
		$project = get_post( (int) $id );
		if ( $project && 'project' === $project->post_type && self::visible( $project ) ) { return $project; }
		return null;
	}

	private static function project_fields( \WP_Post $post ): string {
		if ( ! in_array( $post->post_type, array( 'project', 'portfolio' ), true ) ) { return ''; }
		$text = '';
		// Deliberate allowlist: never enumerate arbitrary or protected post metadata.
		$fields = array( 'live_site' => 'Live site', 'client' => 'Client' );
		if ( 'project' === $post->post_type ) { $fields += array( 'production_link' => 'Website', 'repo_link' => 'Repository', 'tag_line' => 'Tagline' ); }
		foreach ( $fields as $key => $label ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( ! is_string( $value ) || '' === self::plain_text( $value ) ) { continue; }
			if ( in_array( $key, array( 'live_site', 'production_link', 'repo_link' ), true ) ) {
				$url = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
				if ( ! $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) { continue; }
				$text .= $label . ': <' . $url . ">\n\n";
			} else { $text .= $label . ': ' . Markdown::escape( self::plain_text( $value ) ) . "\n\n"; }
		}
		return $text;
	}

	private static function expand_blocks( array $blocks, array $seen = array() ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			if ( 'core/spacer' === $block['blockName'] ) { continue; }
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
			return strtr( $s['llms_custom'], array( '{{site_name}}' => get_bloginfo( 'name' ), '{{site_url}}' => home_url( '/' ), '{{description}}' => get_bloginfo( 'description' ), '{{mcp_url}}' => $s['mcp_enabled'] ? Endpoints::mcp_url() : '' ) ) . "\n";
		}
		$name = self::plain_text( get_bloginfo( 'name' ) );
		$tagline = self::plain_text( get_bloginfo( 'description' ) );
		$heading = implode( ' - ', array_filter( array( $name, $tagline ), static function ( $value ) { return '' !== $value; } ) );
		$text = '# ' . Markdown::escape( $heading ) . "\n\n> " . self::plain_text( $s['site_summary'] ?: get_bloginfo( 'description' ) ) . "\n\n";
		$text .= 'Website: ' . home_url( '/' ) . "\n\nLanguage: " . get_bloginfo( 'language' ) . "\n\n";
		if ( $s['markdown_enabled'] ) { $text .= '[Full content in Markdown](' . Endpoints::llms_full_url() . ")\n\n"; }
		if ( $s['guidance'] ) { $text .= $s['guidance'] . "\n\n"; }
		if ( $s['mcp_enabled'] ) { $text .= "## AI tools\n\n- [MCP endpoint](" . Endpoints::mcp_url() . "): Search and retrieve public site content using MCP over HTTP POST.\n\n"; }
		$custom_types = array_intersect( $s['llms_post_types'], array_keys( Settings::custom_post_types() ), self::types() );
		$limit = max( 1, min( 500, (int) $s['llms_posts_per_section'] ) );
		$truncated = false;
		foreach ( array_merge( array( 'post' ), $custom_types ) as $type ) {
			$items = array(); $page = 1;
			do {
				$result = self::query( array( 'post_type' => $type, 'per_page' => min( 50, $limit ), 'page' => $page++, 'newest_first' => true ) );
				$items = array_merge( $items, array_slice( $result['items'], 0, $limit - count( $items ) ) );
			} while ( count( $items ) < $limit && $page <= $result['pages'] );
			if ( 'post' !== $type && ! $items ) { continue; }
			$heading = 'post' === $type ? 'Content' : get_post_type_object( $type )->labels->name;
			$text .= '## ' . Markdown::escape( wp_strip_all_tags( $heading ) ) . "\n\n";
			foreach ( $items as $item ) {
				$text .= '- [' . Markdown::escape( $item['title'] ) . '](' . str_replace( array( '(', ')' ), array( '%28', '%29' ), $item['markdown_url'] ?: $item['url'] ) . '): ' . preg_replace( '/\s+/', ' ', $item['excerpt'] ) . "\n";
			}
			$truncated = $truncated || $result['total'] > count( $items );
			$text .= "\n";
		}
		if ( $truncated ) { $text .= "Additional content is available through the site search" . ( $s['mcp_enabled'] ? ' and MCP list_pages tool (when enabled)' : '' ) . ".\n"; }
		return $text;
	}

	/** Stream every visible content type; the curated index limit does not apply. */
	public static function llms_full(): \Generator {
		$s = Settings::get();
		if ( ! Settings::public_enabled() || ! $s['llms_enabled'] || ! $s['markdown_enabled'] ) { return; }
		yield self::llms();
		$types = self::types();
		if ( ! $types ) { return; }
		$page = 1;
		do {
			$query = new \WP_Query( array(
				'post_type' => $types, 'post_status' => 'publish', 'has_password' => false,
				'post__not_in' => $s['exclude_ids'], 'posts_per_page' => 50, 'paged' => $page++,
				'orderby' => 'ID', 'order' => 'ASC', 'ignore_sticky_posts' => true, 'no_found_rows' => true,
			) );
			foreach ( $query->posts as $post ) {
				if ( self::visible( $post ) ) { yield "\n\n" . self::markdown( $post ); }
			}
		} while ( count( $query->posts ) === 50 );
	}
}
