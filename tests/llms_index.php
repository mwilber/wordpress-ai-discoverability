<?php
/** Run with GZ_AI_TEST_ALLOW=1 wp eval-file in a disposable WordPress installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'GZ_AI_TEST_ALLOW' ) ) {
	exit( "Use GZ_AI_TEST_ALLOW=1 and a disposable WordPress installation.\n" );
}
use GZ_AI\Settings;
use GZ_AI\Content;

$original = get_option( Settings::OPTION );
$original_public = get_option( 'blog_public' );
$ids = array();
$GLOBALS['gz_llms_checks'] = 0;
function llms_expect( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	++$GLOBALS['gz_llms_checks']; echo "PASS $label\n";
}
function llms_section( string $document, string $heading ): string {
	preg_match( '/^## ' . preg_quote( $heading, '/' ) . '\R(.*?)(?=^## |\z)/ms', $document, $matches );
	return $matches[1] ?? '';
}
function llms_titles( string $section ): array {
	preg_match_all( '/^- \[([^\]]+)\]/m', $section, $matches );
	return $matches[1];
}
try {
	register_post_type( 'gz_books', array( 'public' => true, 'label' => 'Books', 'rewrite' => array( 'slug' => 'gz-books' ) ) );
	register_post_type( 'gz_projects', array( 'public' => true, 'label' => 'Projects' ) );
	register_post_type( 'gz_secret', array( 'public' => false, 'label' => 'Secret' ) );
	register_post_type( 'gz_unviewable', array( 'public' => true, 'publicly_queryable' => false ) );
	$s = Settings::defaults();
	$s['exclude_ids'] = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
	$s['post_types'] = array( 'post', 'page', 'gz_books', 'gz_projects' );
	$s['llms_limit'] = 500; // Existing installations must receive the new 20-entry limit too.
	update_option( Settings::OPTION, $s ); update_option( 'blog_public', '1' );
	$base = time() - HOUR_IN_SECONDS;
	foreach ( array( 'post' => 'Article', 'gz_books' => 'Book', 'gz_projects' => 'Project' ) as $type => $label ) {
		for ( $i = 0; $i < ( 'gz_projects' === $type ? 2 : 25 ); ++$i ) {
			// Create newer content first so sorting by ID would produce the wrong answer.
			$date = gmdate( 'Y-m-d H:i:s', 'gz_projects' === $type ? $base : $base - $i * MINUTE_IN_SECONDS );
			$ids[] = wp_insert_post( array( 'post_title' => sprintf( '%s %02d', $label, $i ), 'post_type' => $type, 'post_status' => 'publish', 'post_date' => $date, 'post_date_gmt' => $date ) );
		}
	}
	$ids[] = wp_insert_post( array( 'post_title' => 'Page outside the posts index', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$index = Content::llms();
	$expected_articles = array_map( static function ( $i ) { return sprintf( 'Article %02d', $i ); }, range( 0, 19 ) );
	llms_expect( $expected_articles === llms_titles( llms_section( $index, 'Content' ) ), 'Content lists exactly the 20 newest posts by publication date' );
	llms_expect( ! str_contains( $index, 'Article 20' ) && ! str_contains( $index, 'Article 24' ), 'Older posts are omitted even with a saved legacy link limit' );
	llms_expect( ! str_contains( $index, 'Page outside the posts index' ), 'Pages do not consume the 20-post allowance' );
	llms_expect( ! str_contains( $index, '## Books' ), 'Custom types are opt-in for llms.txt' );
	$s['llms_post_types'] = array( 'gz_books', 'gz_projects' ); update_option( Settings::OPTION, $s );
	$index = Content::llms();
	$expected_books = array_map( static function ( $i ) { return sprintf( 'Book %02d', $i ); }, range( 0, 19 ) );
	llms_expect( $expected_books === llms_titles( llms_section( $index, 'Books' ) ), 'Each selected custom type has a named section and its own 20 newest entries' );
	llms_expect( $expected_articles === llms_titles( llms_section( $index, 'Content' ) ), 'Custom type entries do not replace standard posts' );
	llms_expect( strpos( $index, '## Content' ) < strpos( $index, '## Books' ) && strpos( $index, '## Books' ) < strpos( $index, '## Projects' ), 'Custom sections follow Content in the selected order' );
	llms_expect( array( 'Project 01', 'Project 00' ) === llms_titles( llms_section( $index, 'Projects' ) ), 'Equal publication dates have deterministic newest-ID-first ordering' );
	$s['llms_posts_per_section'] = 3; update_option( Settings::OPTION, $s );
	$index = Content::llms();
	llms_expect( array_slice( $expected_articles, 0, 3 ) === llms_titles( llms_section( $index, 'Content' ) ) && array_slice( $expected_books, 0, 3 ) === llms_titles( llms_section( $index, 'Books' ) ), 'The configured limit applies to standard and custom post sections' );
	foreach ( array( 'post' => 'Article', 'gz_books' => 'Book' ) as $type => $label ) {
		for ( $i = 25; $i < 55; ++$i ) {
			$date = gmdate( 'Y-m-d H:i:s', $base - $i * MINUTE_IN_SECONDS );
			$ids[] = wp_insert_post( array( 'post_title' => sprintf( '%s %02d', $label, $i ), 'post_type' => $type, 'post_status' => 'publish', 'post_date' => $date, 'post_date_gmt' => $date ) );
		}
	}
	$s['llms_posts_per_section'] = 53; update_option( Settings::OPTION, $s );
	$index = Content::llms();
	llms_expect( array_map( static function ( $i ) { return sprintf( 'Article %02d', $i ); }, range( 0, 52 ) ) === llms_titles( llms_section( $index, 'Content' ) ), 'Limits above 50 paginate without gaps, duplicates or reordered posts' );
	llms_expect( 53 === count( llms_titles( llms_section( $index, 'Books' ) ) ) && ! str_contains( $index, 'Book 53' ), 'Large limits apply independently to custom sections' );
	$legacy = $s; unset( $legacy['llms_posts_per_section'] ); update_option( Settings::OPTION, $legacy );
	llms_expect( 20 === Settings::get()['llms_posts_per_section'] && 20 === count( llms_titles( llms_section( Content::llms(), 'Content' ) ) ), 'Existing installations without the new setting default to 20' );
	foreach ( array( -1 => 1, 9999 => 500, 'invalid' => 20 ) as $value => $expected ) {
		$input = $s; $input['llms_posts_per_section'] = $value;
		llms_expect( $expected === Settings::sanitize( $input )['llms_posts_per_section'], 'Entry limit is sanitized safely for ' . $value );
	}
	$s['llms_posts_per_section'] = 20; update_option( Settings::OPTION, $s );
	foreach ( array( 'draft', 'private', 'protected', 'excluded' ) as $hidden ) {
		$id = wp_insert_post( array( 'post_title' => 'Hidden Book ' . $hidden, 'post_type' => 'gz_books', 'post_status' => in_array( $hidden, array( 'draft', 'private' ), true ) ? $hidden : 'publish', 'post_password' => 'protected' === $hidden ? 'password' : '' ) );
		$ids[] = $id;
		if ( 'excluded' === $hidden ) { $s['exclude_ids'][] = $id; }
	}
	update_option( Settings::OPTION, $s );
	llms_expect( ! str_contains( Content::llms(), 'Hidden Book' ), 'Custom sections retain private, password, draft and explicit exclusion protections' );
	$s['llms_post_types'] = array( 'gz_projects' ); update_option( Settings::OPTION, $s );
	llms_expect( ! str_contains( Content::llms(), '## Books' ) && Content::visible( $ids[25] ), 'Deselecting a custom section leaves Markdown and MCP content scope intact' );
	$s['llms_post_types'] = array( 'gz_books', 'gz_projects', 'gz_secret', 'gz_unviewable', 'attachment', 'post', 'page', 'missing' );
	$s['exclude_ids'] = implode( ',', $s['exclude_ids'] );
	$clean = Settings::sanitize( $s );
	llms_expect( array( 'gz_books', 'gz_projects' ) === $clean['llms_post_types'], 'Admin selection accepts only registered, public, viewable custom types' );
	$s['exclude_ids'] = wp_parse_id_list( $s['exclude_ids'] );
	$s['post_types'] = array( 'post', 'page' ); update_option( Settings::OPTION, $s );
	llms_expect( ! str_contains( Content::llms(), '## Books' ), 'The shared content access scope cannot be bypassed by an llms.txt selection' );
	$s['llms_custom'] = '# My custom index'; update_option( Settings::OPTION, $s );
	llms_expect( "# My custom index\n" === Content::llms(), 'A fully custom llms.txt remains unchanged' );
	echo "\n" . $GLOBALS['gz_llms_checks'] . " llms.txt index checks passed.\n";
} finally {
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	foreach ( array( 'gz_books', 'gz_projects', 'gz_secret', 'gz_unviewable' ) as $type ) { unregister_post_type( $type ); }
	update_option( Settings::OPTION, $original ); update_option( 'blog_public', $original_public );
}
