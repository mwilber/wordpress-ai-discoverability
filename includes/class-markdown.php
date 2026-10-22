<?php
namespace GZ_AI;
defined( 'ABSPATH' ) || exit;

/** DOM conversion preserves nesting without invoking privileged shortcodes or content filters. */
final class Markdown {
	public static function convert( string $html, string $base = '' ): string {
		if ( ! class_exists( '\DOMDocument' ) ) { return trim( wp_strip_all_tags( $html ) ) . "\n"; }
		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		return trim( self::node( $body, $base ) ) . "\n";
	}

	public static function escape( string $text ): string {
		return preg_replace( '/([\\\\`*_{}\[\]<>#|])/', '\\\\$1', $text );
	}

	private static function image_alt( \DOMElement $node ): string {
		foreach ( array( 'alt', 'title' ) as $attribute ) {
			$text = Content::plain_text( $node->getAttribute( $attribute ) );
			if ( '' !== $text ) { return $text; }
		}
		if ( preg_match( '/\bwp-image-(\d+)\b/', $node->getAttribute( 'class' ), $match ) ) {
			$image = get_post( (int) $match[1] );
			// Only use metadata from public media, including its public parent when attached.
			if ( $image && 'attachment' === $image->post_type && in_array( $image->post_status, array( 'inherit', 'publish' ), true ) && ! $image->post_password && ( ! $image->post_parent || Content::visible( $image->post_parent ) ) ) {
				foreach ( array( get_post_meta( $image->ID, '_wp_attachment_image_alt', true ), $image->post_excerpt, $image->post_title ) as $value ) {
					$text = Content::plain_text( (string) $value );
					if ( '' !== $text ) { return $text; }
				}
			}
		}
		$parent = $node->parentNode;
		if ( $parent instanceof \DOMElement && 'a' === $parent->tagName ) { $parent = $parent->parentNode; }
		if ( $parent instanceof \DOMElement && 'figure' === $parent->tagName ) {
			$caption = $parent->getElementsByTagName( 'figcaption' )->item( 0 );
			if ( $caption && '' !== Content::plain_text( $caption->textContent ) ) { return Content::plain_text( $caption->textContent ); }
		}
		$path = wp_parse_url( $node->getAttribute( 'src' ), PHP_URL_PATH );
		$name = pathinfo( rawurldecode( (string) $path ), PATHINFO_FILENAME );
		$name = preg_replace( '/-\d+x\d+$/', '', $name );
		return Content::plain_text( str_replace( array( '-', '_' ), ' ', $name ) ) ?: 'Image';
	}

	private static function url( string $url, string $base ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url ) { return ''; }
		if ( str_starts_with( $url, '#' ) ) { return $url; }
		if ( ! preg_match( '~^[a-z][a-z0-9+.-]*:~i', $url ) ) {
			$url = \WP_Http::make_absolute_url( $url, $base ?: home_url( '/' ) );
		}
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) ) );
	}

	private static function node( ?\DOMNode $node, string $base ): string {
		if ( ! $node ) { return ''; }
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return self::escape( preg_replace( '/\s+/u', ' ', str_replace( array( "\u{00a0}", '&nbsp;' ), ' ', $node->nodeValue ) ) );
		}
		if ( ! $node instanceof \DOMElement ) { return ''; }
		$tag = strtolower( $node->tagName );
		if ( preg_match( '/\bwp-block-spacer\b/', $node->getAttribute( 'class' ) ) ) { return ''; }
		if ( in_array( $tag, array( 'script', 'style', 'noscript', 'iframe', 'form', 'input', 'button', 'svg', 'template' ), true ) || $node->hasAttribute( 'hidden' ) || 'true' === $node->getAttribute( 'aria-hidden' ) ) { return ''; }
		if ( 'pre' === $tag ) {
			$text = $node->textContent;
			preg_match_all( '/`+/', $text, $runs );
			$fence = str_repeat( '`', max( 3, 1 + max( array_merge( array( 0 ), array_map( 'strlen', $runs[0] ) ) ) ) );
			return "\n\n$fence\n" . rtrim( $text ) . "\n$fence\n\n";
		}
		if ( 'code' === $tag ) {
			preg_match_all( '/`+/', $node->textContent, $runs );
			$fence = str_repeat( '`', max( 1, 1 + max( array_merge( array( 0 ), array_map( 'strlen', $runs[0] ) ) ) ) );
			return $fence . ' ' . $node->textContent . ' ' . $fence;
		}
		$text = '';
		foreach ( $node->childNodes as $child ) {
			$part = self::node( $child, $base );
			if ( '' === trim( $part ) && ( '' === $text || str_ends_with( $text, "\n" ) ) ) { continue; }
			$text .= $part;
		}
		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) { return "\n\n" . str_repeat( '#', (int) $m[1] ) . ' ' . trim( $text ) . "\n\n"; }
		switch ( $tag ) {
			case 'a':
				$url = self::url( $node->getAttribute( 'href' ), $base );
				return $url ? '[' . trim( $text ) . '](' . $url . ')' : $text;
			case 'img':
				$url = self::url( $node->getAttribute( 'src' ), $base );
				return $url ? '![' . self::escape( self::image_alt( $node ) ) . '](' . $url . ')' : '';
			case 'strong': case 'b': return '**' . trim( $text ) . '**';
			case 'em': case 'i': return '*' . trim( $text ) . '*';
			case 'del': case 's': return '~~' . trim( $text ) . '~~';
			case 'br': return "  \n";
			case 'hr': return "\n\n---\n\n";
			case 'blockquote': return "\n\n> " . str_replace( "\n", "\n> ", trim( $text ) ) . "\n\n";
			case 'ul': case 'ol':
				$items = array(); $i = max( 1, (int) $node->getAttribute( 'start' ) );
				foreach ( $node->childNodes as $child ) {
					if ( $child instanceof \DOMElement && 'li' === $child->tagName ) {
						$prefix = 'ol' === $tag ? $i++ . '. ' : '- ';
						$items[] = $prefix . str_replace( "\n", "\n" . str_repeat( ' ', strlen( $prefix ) ), trim( self::node( $child, $base ) ) );
					}
				}
				return "\n\n" . implode( "\n", $items ) . "\n\n";
			case 'table':
				$rows = array(); $width = 0;
				foreach ( $node->getElementsByTagName( 'tr' ) as $tr ) {
					$cells = array();
					foreach ( $tr->childNodes as $cell ) {
						if ( $cell instanceof \DOMElement && in_array( $cell->tagName, array( 'td', 'th' ), true ) ) {
							$cells[] = preg_replace( '/\s+/', ' ', trim( self::node( $cell, $base ) ) );
						}
					}
					if ( $cells ) { $rows[] = $cells; $width = max( $width, count( $cells ) ); }
				}
				$lines = array();
				foreach ( $rows as $i => $cells ) {
					$lines[] = '| ' . implode( ' | ', array_pad( $cells, $width, '' ) ) . ' |';
					if ( 0 === $i ) { $lines[] = '| ' . implode( ' | ', array_fill( 0, $width, '---' ) ) . ' |'; }
				}
				return "\n\n" . implode( "\n", $lines ) . "\n\n";
			case 'p': case 'div': case 'section': case 'article': case 'figure': case 'figcaption': case 'dl': case 'dt': case 'dd':
				return '' === trim( $text ) ? '' : "\n\n" . trim( $text ) . "\n\n";
			default: return $text;
		}
	}
}
