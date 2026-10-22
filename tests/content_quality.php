<?php
/** Run only in a disposable WordPress installation. See README.md. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'GZ_AI_TEST_ALLOW' ) ) {
	exit( "Set GZ_AI_TEST_ALLOW=1 and run with wp eval-file in a disposable installation.\n" );
}

use GZ_AI\Content;
use GZ_AI\Endpoints;
use GZ_AI\Markdown;
use GZ_AI\Settings;

$GLOBALS['gz_quality_checks'] = 0;
function quality_expect( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	++$GLOBALS['gz_quality_checks']; echo "PASS $label\n";
}
function quality_info(): array {
	$request = new WP_REST_Request( 'POST', '/gz-ai/v1/mcp' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'accept', 'application/json, text/event-stream' );
	$request->set_header( 'mcp-protocol-version', '2025-11-25' );
	$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'site_info', 'arguments' => (object) array() ) ) ) );
	return (array) rest_do_request( $request )->get_data()['result']->structuredContent;
}
$original = get_option( Settings::OPTION );
$options = array();
foreach ( array( 'blog_public', 'blogname', 'blogdescription', 'permalink_structure' ) as $key ) { $options[ $key ] = get_option( $key ); }
$ids = array(); $tag_id = 0;
try {
	update_option( 'blog_public', '1' );
	update_option( 'blogname', 'GreenZeta' );
	update_option( 'blogdescription', 'Making websites for fun and profit!' );
	foreach ( array( 'project', 'portfolio', 'update' ) as $type ) { register_post_type( $type, array( 'public' => true, 'taxonomies' => array( 'post_tag' ) ) ); }
	$s = Settings::defaults(); $s['post_types'] = array( 'post', 'page', 'project', 'portfolio', 'update' ); $s['rate_limit'] = 600;
	update_option( Settings::OPTION, $s );
	quality_expect( str_starts_with( Content::llms(), "# GreenZeta - Making websites for fun and profit!\n" ), 'llms.txt includes the unescaped tagline in its H1' );
	update_option( 'blogdescription', '' );
	quality_expect( str_starts_with( Content::llms(), "# GreenZeta\n" ), 'An empty tagline leaves no trailing separator' );
	update_option( 'blogname', '' );
	quality_expect( str_starts_with( Content::llms(), "# \n" ), 'Missing WordPress values remain blank without hardcoded branding' );
	update_option( 'blogdescription', 'Only a tagline' );
	quality_expect( str_starts_with( Content::llms(), "# Only a tagline\n" ), 'A tagline without a site title has no leading separator' );
	update_option( 'blogname', 'GreenZeta' );
	update_option( 'blogdescription', $options['blogdescription'] );
	$s['site_summary'] = "Software\r\nby Matthew\\r\\nWilber &amp; team"; update_option( Settings::OPTION, $s );
	quality_expect( 'Software by Matthew Wilber & team' === quality_info()['description'], 'MCP descriptions normalize real and literal CRLF and decode entities' );
	$id = wp_insert_post( array( 'post_title' => 'GreenZeta — Matthew Wilber', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<h1>GreenZeta — Matthew Wilber</h1><!-- wp:spacer --><div class="wp-block-spacer">&nbsp;</div><!-- /wp:spacer --><p>&nbsp;</p><p>&amp;nbsp;</p><p>Make a comic strip!</p><img src="/images/comic-strip-800x600.png" alt="" />', 'post_excerpt' => str_repeat( 'word ', 60 ) ) ); $ids[] = $id;
	$markdown = Content::markdown( get_post( $id ) );
	quality_expect( str_starts_with( $markdown, "---\ntitle: \"GreenZeta — Matthew Wilber\"\n" ) && str_contains( $markdown, "\n# GreenZeta — Matthew Wilber\n" ), 'Front matter and H1 retain the full name' );
	quality_expect( str_contains( $markdown, 'date: "' ) && str_contains( $markdown, 'tags: []' ) && str_contains( $markdown, 'project: null' ), 'Every Markdown document includes date, tags and project keys' );
	quality_expect( ! str_contains( $markdown, '&nbsp;' ) && ! str_contains( $markdown, "\u{00a0}" ) && ! preg_match( '/^ +$/m', $markdown ), 'Spacer blocks and blank nonbreaking-space paragraphs disappear' );
	$spaced = Markdown::convert( "<p>Before</p>\n\n<div class=\"wp-block-spacer\">&nbsp;</div>\n\n<p>After <b>one</b> <b>two</b></p>" );
	quality_expect( ! preg_match( '/^ +$/m', $spaced ) && str_contains( $spaced, '**one** **two**' ), 'Formatted HTML has no whitespace-only spacer lines and keeps inline spacing' );
	quality_expect( str_contains( $markdown, 'comic strip!' ) && ! str_contains( $markdown, 'strip\\!' ), 'Ordinary exclamation marks are not escaped' );
	quality_expect( str_contains( $markdown, '![comic strip](' ), 'Unlabeled images use a readable filename fallback' );
	quality_expect( str_contains( Content::summary( get_post( $id ) )['excerpt'], '…' ) && ! str_contains( Content::summary( get_post( $id ) )['excerpt'], '&hellip' ), 'Trimmed excerpts decode WordPress ellipsis entities' );
	$converted = Markdown::convert( '<p>A &amp; B &hellip; &eacute;</p><pre>&nbsp; literal &amp;nbsp; strip!</pre><img src="/image.png" alt="An existing label" /><figure><img src="/img.png" alt="" /><figcaption>A useful caption</figcaption></figure>', home_url( '/' ) );
	quality_expect( str_contains( $converted, 'A & B … é' ) && str_contains( $converted, '![An existing label]' ) && str_contains( $converted, '![A useful caption]' ), 'Entities, explicit image labels and figure captions survive conversion' );
	quality_expect( str_contains( $converted, "\u{00a0} literal &nbsp; strip!" ), 'Code preserves literal whitespace and entities' );
	$project = wp_insert_post( array( 'post_title' => 'Project: "Alpha"', 'post_type' => 'project', 'post_status' => 'publish', 'post_content' => 'Full project body.' ) ); $ids[] = $project;
	foreach ( array( 'production_link' => 'https://example.test/live', 'repo_link' => 'https://example.test/repo', 'live_site' => 'https://example.test/legacy', 'client' => 'Example &amp; Co', 'tag_line' => 'Make things!', '_private_token' => 'DO_NOT_EXPOSE' ) as $key => $value ) { update_post_meta( $project, $key, $value ); }
	$term = wp_insert_term( 'Tools &amp; Art ' . wp_generate_uuid4(), 'post_tag' ); $tag_id = $term['term_id'];
	wp_set_object_terms( $project, array( $tag_id ), 'post_tag' );
	$markdown = Content::markdown( get_post( $project ) );
	quality_expect( str_contains( $markdown, 'Website: <https://example.test/live>' ) && str_contains( $markdown, 'Repository: <https://example.test/repo>' ) && str_contains( $markdown, 'Live site: <https://example.test/legacy>' ), 'Project documents include live and repository URLs' );
	quality_expect( str_contains( $markdown, 'Client: Example & Co' ) && str_contains( $markdown, 'Tagline: Make things!' ) && ! str_contains( $markdown, 'DO_NOT_EXPOSE' ), 'Only allowlisted project metadata is included and cleaned' );
	preg_match( '/^project: (.+)$/m', $markdown, $match );
	quality_expect( 'Project: "Alpha"' === json_decode( $match[1], true ) && str_contains( $markdown, 'Tools & Art' ), 'Front matter quotes punctuation safely and includes decoded tags and project name' );
	$portfolio = wp_insert_post( array( 'post_title' => 'Portfolio fixture', 'post_type' => 'portfolio', 'post_status' => 'publish' ) ); $ids[] = $portfolio;
	foreach ( array( 'client' => 'Portfolio client', 'live_site' => 'https://example.test/portfolio', 'repo_link' => 'https://example.test/unused-repo', 'production_link' => 'https://example.test/unused-site', 'tag_line' => 'UNUSED_TAGLINE' ) as $key => $value ) { update_post_meta( $portfolio, $key, $value ); }
	$portfolio_md = Content::markdown( get_post( $portfolio ) );
	quality_expect( str_contains( $portfolio_md, 'Client: Portfolio client' ) && str_contains( $portfolio_md, 'Live site: <https://example.test/portfolio>' ) && ! str_contains( $portfolio_md, 'unused-' ) && ! str_contains( $portfolio_md, 'UNUSED_TAGLINE' ), 'Portfolio exports only its client and live-site fields' );
	update_post_meta( $project, 'repo_link', 'javascript:alert(1)' );
	quality_expect( ! str_contains( Content::markdown( get_post( $project ) ), 'javascript:' ), 'Unsafe project URLs are omitted' );
	$related = wp_insert_post( array( 'post_title' => 'Linked post', 'post_type' => 'post', 'post_status' => 'publish' ) ); $ids[] = $related;
	update_post_meta( $related, 'project_id', $project );
	quality_expect( str_contains( Content::markdown( get_post( $related ) ), 'Project: [' ), 'Posts link a public project using project_id' );
	$update = wp_insert_post( array( 'post_title' => 'Linked update', 'post_type' => 'update', 'post_status' => 'publish' ) ); $ids[] = $update;
	update_post_meta( $update, 'project_id', $project );
	quality_expect( str_contains( Content::markdown( get_post( $update ) ), 'Project: [' ), 'Updates link a public project using project_id' );
	update_post_meta( $id, 'project_id', $project );
	quality_expect( str_contains( Content::markdown( get_post( $id ) ), 'project: null' ), 'Page content does not acquire an unsupported project relationship' );
	update_post_meta( $update, 'project_id', array( $project ) );
	quality_expect( str_contains( Content::markdown( get_post( $update ) ), 'project: null' ), 'Project relationships require one ID and reject arrays' );
	wp_update_post( array( 'ID' => $project, 'post_status' => 'private' ) );
	quality_expect( str_contains( Content::markdown( get_post( $related ) ), 'project: null' ) && ! str_contains( Content::markdown( get_post( $related ) ), 'Project: [' ), 'Private project relationships do not leak titles or links' );
	wp_update_post( array( 'ID' => $project, 'post_status' => 'publish' ) );
	$attachment = wp_insert_post( array( 'post_title' => 'Media title', 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $id ) ); $ids[] = $attachment;
	update_post_meta( $attachment, '_wp_attachment_image_alt', 'Stored media description' );
	quality_expect( str_contains( Markdown::convert( '<img class="wp-image-' . $attachment . '" src="/image.png" alt="" />' ), '![Stored media description]' ), 'Images use the public media library alt description when available' );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) );
	quality_expect( ! str_contains( Markdown::convert( '<img class="wp-image-' . $attachment . '" src="/image.png" alt="" />' ), 'Stored media description' ), 'Images do not disclose metadata from private parent content' );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	foreach ( array( 'draft', 'private', 'password', 'excluded' ) as $kind ) {
		$hidden = wp_insert_post( array( 'post_title' => 'HIDDEN_QUALITY_' . $kind, 'post_type' => 'project', 'post_status' => in_array( $kind, array( 'draft', 'private' ), true ) ? $kind : 'publish', 'post_password' => 'password' === $kind ? 'secret' : '' ) ); $ids[] = $hidden;
		if ( 'excluded' === $kind ) { $s['exclude_ids'][] = $hidden; }
	}
	$s['llms_posts_per_section'] = 1; update_option( Settings::OPTION, $s );
	// More than a batch verifies the full document doesn't stop at the curated index or query limit.
	for ( $i = 0; $i < 52; ++$i ) { $ids[] = wp_insert_post( array( 'post_title' => 'FULL_BATCH_' . $i, 'post_type' => 'update', 'post_status' => 'publish', 'post_content' => 'Unique full body ' . $i ) ); }
	$full = implode( '', iterator_to_array( Content::llms_full() ) );
	quality_expect( str_contains( $full, 'Unique full body 0' ) && str_contains( $full, 'Unique full body 51' ) && str_contains( $full, 'Full project body.' ) && str_contains( $full, '# Portfolio fixture' ), 'llms-full.txt spans batches and all enabled types regardless of index selection and limit' );
	quality_expect( ! str_contains( $full, 'HIDDEN_QUALITY_' ) && ! str_contains( $full, 'Media title' ), 'The full document excludes restricted content and attachments' );
	$s['markdown_enabled'] = false; update_option( Settings::OPTION, $s );
	quality_expect( array() === iterator_to_array( Content::llms_full() ) && null === quality_info()['llms_full_url'], 'Disabling Markdown also disables and stops advertising the full document' );
	$s['markdown_enabled'] = true; $s['llms_enabled'] = false; update_option( Settings::OPTION, $s );
	quality_expect( array() === iterator_to_array( Content::llms_full() ), 'Disabling llms.txt disables the full document' );
	$s['llms_enabled'] = true; update_option( Settings::OPTION, $s ); update_option( 'blog_public', '0' );
	quality_expect( array() === iterator_to_array( Content::llms_full() ), 'Site privacy disables the full document' );
	update_option( 'blog_public', '1' ); update_option( 'permalink_structure', '' );
	quality_expect( str_contains( Endpoints::llms_full_url(), 'gz_ai_llms=full' ), 'Plain permalinks have a full-document query URL' );
	$s['llms_custom'] = '# Custom introduction'; update_option( Settings::OPTION, $s );
	quality_expect( "# Custom introduction\n" === Content::llms(), 'Custom llms.txt is preserved exactly' );
	echo "\n" . $GLOBALS['gz_quality_checks'] . " content quality checks passed.\n";
} finally {
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	if ( $tag_id ) { wp_delete_term( $tag_id, 'post_tag' ); }
	foreach ( array( 'project', 'portfolio', 'update' ) as $type ) { unregister_post_type( $type ); }
	update_option( Settings::OPTION, $original );
	foreach ( $options as $key => $value ) { update_option( $key, $value ); }
}
