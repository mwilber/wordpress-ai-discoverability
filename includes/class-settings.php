<?php
namespace GZ_AI;
defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION = 'gz_ai_settings';

	public static function defaults(): array {
		return array(
			'llms_enabled' => true, 'markdown_enabled' => true, 'mcp_enabled' => true,
			'head_links' => true, 'respect_search_visibility' => true,
			'comments_enabled' => false, 'comment_submission' => false, 'force_moderation' => true,
			'tools' => array( 'search', 'get_page', 'list_pages', 'site_info', 'list_terms' ),
			'post_types' => array( 'post', 'page' ), 'exclude_ids' => array(),
			'site_summary' => '', 'guidance' => '', 'llms_custom' => '',
			'llms_limit' => 100, 'rate_limit' => 60, 'comment_rate_limit' => 5,
			'allowed_origins' => '', 'delete_data' => false, 'mcp_url_style' => 'standard',
		);
	}

	public static function get(): array {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function public_enabled(): bool {
		$s = self::get();
		return ! $s['respect_search_visibility'] || '1' === (string) get_option( 'blog_public' );
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out = self::defaults();
		$out['mcp_url_style'] = 'short' === ( $input['mcp_url_style'] ?? '' ) ? 'short' : 'standard';
		if ( 'short' === $out['mcp_url_style'] && Endpoints::short_mcp_unavailable_reason() ) {
			$out['mcp_url_style'] = 'standard';
			add_settings_error( self::OPTION, 'gz_ai_mcp_url', Endpoints::short_mcp_unavailable_reason() . ' The standard WordPress URL has been selected.', 'warning' );
		}
		foreach ( $out as $key => $value ) {
			if ( is_bool( $value ) ) {
				$out[ $key ] = ! empty( $input[ $key ] );
			}
		}
		foreach ( array( 'site_summary', 'guidance', 'llms_custom' ) as $key ) {
			$out[ $key ] = sanitize_textarea_field( is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '' );
		}
		$out['post_types'] = array_values( array_intersect( (array) ( $input['post_types'] ?? array() ), array_keys( get_post_types( array( 'public' => true ) ) ) ) );
		$out['post_types'] = array_values( array_diff( $out['post_types'], array( 'attachment' ) ) );
		$out['tools'] = array_values( array_intersect( (array) ( $input['tools'] ?? array() ), self::defaults()['tools'] ) );
		$out['exclude_ids'] = wp_parse_id_list( is_string( $input['exclude_ids'] ?? null ) ? $input['exclude_ids'] : '' );
		foreach ( array( 'llms_limit' => 500, 'rate_limit' => 600, 'comment_rate_limit' => 60 ) as $key => $max ) {
			$out[ $key ] = max( 1, min( $max, absint( $input[ $key ] ?? $out[ $key ] ) ) );
		}
		$origins = array();
		foreach ( preg_split( '/\R/', is_string( $input['allowed_origins'] ?? null ) ? $input['allowed_origins'] : '' ) as $origin ) {
			$origin = trim( $origin );
			$p = wp_parse_url( $origin );
			if ( $origin && $p && in_array( $p['scheme'] ?? '', array( 'http', 'https' ), true ) && ! empty( $p['host'] ) && empty( $p['user'] ) && empty( $p['pass'] ) && empty( $p['query'] ) && empty( $p['fragment'] ) && empty( $p['path'] ) ) {
				$origins[] = esc_url_raw( $origin );
			}
		}
		$out['allowed_origins'] = implode( "\n", array_unique( $origins ) );
		return $out;
	}
}
