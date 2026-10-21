<?php
namespace GZ_AI;
defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function boot(): void {
		add_action( 'admin_menu', static function () { add_options_page( 'AI Discoverability', 'AI Discoverability', 'manage_options', 'gz-ai', array( self::class, 'render' ) ); } );
		add_action( 'admin_init', static function () { register_setting( 'gz_ai', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( Settings::class, 'sanitize' ), 'default' => Settings::defaults() ) ); } );
		add_action( 'admin_enqueue_scripts', static function ( $hook ) {
			if ( 'settings_page_gz-ai' !== $hook ) { return; }
			wp_enqueue_style( 'gz-ai-admin', plugins_url( 'assets/admin.css', GZ_AI_FILE ), array(), GZ_AI_VERSION );
			wp_enqueue_script( 'gz-ai-admin', plugins_url( 'assets/admin.js', GZ_AI_FILE ), array(), GZ_AI_VERSION, true );
		} );
		add_filter( 'plugin_action_links_' . plugin_basename( GZ_AI_FILE ), static function ( $links ) { array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=gz-ai' ) ) . '">Settings</a>' ); return $links; } );
	}

	private static function toggle( string $key, string $title, string $description ): void {
		$s = Settings::get();
		?><label class="gz-toggle-row" for="gz-<?php echo esc_attr( $key ); ?>"><span><strong><?php echo esc_html( $title ); ?></strong><small><?php echo esc_html( $description ); ?></small></span><span class="gz-switch"><input id="gz-<?php echo esc_attr( $key ); ?>" type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( $s[ $key ] ); ?> /><span aria-hidden="true"></span></span></label><?php
	}

	private static function field( string $key, string $title, string $help, string $type = 'text' ): void {
		$s = Settings::get();
		$value = 'exclude_ids' === $key ? implode( ', ', $s[ $key ] ) : $s[ $key ];
		?><div class="gz-field"><label for="gz-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></label>
		<?php if ( 'textarea' === $type ) : ?><textarea id="gz-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" rows="<?php echo 'llms_custom' === $key ? '14' : '4'; ?>" aria-describedby="gz-help-<?php echo esc_attr( $key ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<?php else : ?><input id="gz-<?php echo esc_attr( $key ); ?>" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php if ( 'number' === $type ) { echo 'min="1" max="' . ( 'llms_posts_per_section' === $key ? '500' : ( 'rate_limit' === $key ? '600' : '60' ) ) . '"'; } ?> aria-describedby="gz-help-<?php echo esc_attr( $key ); ?>" /><?php endif; ?>
		<p id="gz-help-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $help ); ?></p></div><?php
	}

	private static function endpoint( string $label, string $url, bool $active ): void {
		?><div class="gz-endpoint"><div><span class="gz-dot <?php echo $active ? 'is-active' : ''; ?>" aria-hidden="true"></span><strong><?php echo esc_html( $label ); ?></strong><span class="gz-status"><?php echo $active ? 'Enabled' : 'Disabled'; ?></span></div><div class="gz-copy-row"><code><?php echo esc_html( $url ); ?></code><button type="button" class="gz-copy" data-copy="<?php echo esc_attr( $url ); ?>" aria-label="<?php echo esc_attr( 'Copy ' . $label . ' URL' ); ?>">Copy</button></div></div><?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = Settings::get(); $public = Settings::public_enabled();
		$llms = get_option( 'permalink_structure' ) ? home_url( '/llms.txt' ) : add_query_arg( 'gz_ai_llms', '1', home_url( '/' ) );
		?><div class="wrap gz-ai">
			<header class="gz-header"><div class="gz-brand"><span class="gz-mark" aria-hidden="true">✳</span><span>GZ / WORDPRESS TOOLS</span></div><span class="gz-version">v<?php echo esc_html( GZ_AI_VERSION ); ?></span></header>
			<div class="gz-heading"><div><span class="gz-eyebrow">A BETTER WAY TO BE FOUND</span><h1>Make your site<br><span>AI-readable.</span></h1><p>Give AI assistants a clear path to your content.<br>Your website, ready for the next way people search.</p></div><div class="gz-orbit" aria-hidden="true"><span class="gz-orbit-label gz-orbit-one">llms.txt</span><span class="gz-orbit-core">✳</span><span class="gz-orbit-label gz-orbit-two">Markdown</span><span class="gz-orbit-label gz-orbit-three">MCP</span></div></div>
			<?php if ( ! $public ) : ?><div class="notice notice-warning inline"><p>Discovery is paused because Settings → Reading discourages search engines. Change site visibility or the visibility setting below to publish these endpoints.</p></div><?php endif; ?>
			<?php if ( ! get_option( 'permalink_structure' ) ) : ?><div class="notice notice-info inline"><p>Plain permalinks are active. Query URLs work now. Enable pretty permalinks under Settings → Permalinks to serve /llms.txt and /page/index.md.</p></div><?php endif; ?>
			<?php if ( file_exists( ABSPATH . 'llms.txt' ) ) : ?><div class="notice notice-warning inline"><p>A physical llms.txt exists in the WordPress directory. Your web server may serve that file before this plugin’s customized version.</p></div><?php endif; ?>
			<form method="post" action="options.php" id="gz-settings-form"><?php settings_fields( 'gz_ai' ); ?>
			<nav class="gz-tabs" aria-label="Settings sections"><a class="is-current" href="#gz-features">Overview</a><a href="#gz-llms">llms.txt</a><a href="#gz-markdown">Markdown</a><a href="#gz-mcp">MCP server</a><a href="#gz-scope">Content & access</a></nav>
			<div class="gz-layout"><main>
			<section class="gz-card" id="gz-features"><div class="gz-section-title"><span class="gz-number">01</span><div><h2>Your discovery toolkit</h2><p>Three ways to connect your content with AI.</p></div></div>
				<?php self::toggle( 'llms_enabled', 'Publish llms.txt', 'A curated introduction and content index for AI assistants.' ); ?>
				<?php self::toggle( 'markdown_enabled', 'Serve Markdown pages', 'Clean, structured versions of public posts and pages.' ); ?>
				<?php self::toggle( 'mcp_enabled', 'Enable MCP server', 'Let assistants search, retrieve, and interact with your site.' ); ?>
			</section>
			<section class="gz-card" id="gz-llms"><div class="gz-section-title"><span class="gz-number">02</span><div><h2>A thoughtful first introduction</h2><p>Shape what assistants learn about your website.</p></div></div>
				<?php self::field( 'site_summary', 'Site summary', 'Used in the generated introduction and MCP site information. Defaults to your WordPress tagline.', 'textarea' ); ?>
				<?php self::field( 'guidance', 'Guidance for assistants', 'Add context, attribution preferences, or instructions in Markdown. These are guidance, not access controls.', 'textarea' ); ?>
				<?php self::field( 'llms_posts_per_section', 'Posts per section', 'Maximum entries in Content and in each selected custom post type section. Newest first by publication date. Default: 20. Allowed range: 1–500.', 'number' ); ?>
				<p class="gz-note">The generated index lists recent posts under “Content,” newest first by publication date. Each selected custom post type gets its own section with the same entry limit. Pages remain available through Markdown and MCP. This limit does not change a fully custom llms.txt.</p>
				<fieldset class="gz-tool-grid"><legend>Custom post types in llms.txt</legend><?php $llms_custom_types = Settings::custom_post_types(); foreach ( $llms_custom_types as $type ) : $in_scope = in_array( $type->name, Content::types(), true ); ?><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[llms_post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( $in_scope && in_array( $type->name, $s['llms_post_types'], true ) ); disabled( ! $in_scope ); ?> /><?php echo esc_html( $type->labels->name . ( $in_scope ? '' : ' — enable in Content & access first' ) ); ?></label><?php endforeach; ?></fieldset>
				<p class="gz-muted"><?php echo $llms_custom_types ? 'Choose which public custom post types get a section in the generated llms.txt. Content & access exclusions still apply. Unchecking a type here only removes it from this index.' : 'No public, publicly viewable custom post types are registered on this site.'; ?></p>
				<details class="gz-details"><summary>Use a fully custom llms.txt</summary><?php self::field( 'llms_custom', 'Custom Markdown', 'Leave blank for the generated index. Placeholders: {{site_name}}, {{site_url}}, {{description}}, {{mcp_url}}. Start with # Site name.', 'textarea' ); ?></details>
				<details class="gz-details"><summary>Preview the saved llms.txt</summary><pre class="gz-preview"><?php echo esc_html( Content::llms() ); ?></pre><p class="gz-muted">This preview reflects saved settings. Save changes to refresh it.</p></details>
			</section>
			<section class="gz-card" id="gz-markdown"><div class="gz-section-title"><span class="gz-number">03</span><div><h2>Content without the clutter</h2><p>Headings, links, lists, images, code, and tables stay readable.</p></div></div>
				<p>Public pages are available at <code>/your-page/index.md</code> (or <code>/your-page.md</code>). Clients can also request <code>Accept: text/markdown</code> on a regular page URL, or use the REST fallback:</p><code class="gz-code-block"><?php echo esc_html( rest_url( 'gz-ai/v1/markdown/123' ) ); ?></code>
				<?php self::toggle( 'head_links', 'Add discovery metadata', 'Publish llms.txt, Markdown alternate, and MCP links in your HTML head.' ); ?>
				<p class="gz-note">Stored block content is converted locally. Dynamic blocks and shortcodes that require execution are omitted. No external AI service or API key is needed.</p>
			</section>
			<section class="gz-card" id="gz-mcp"><div class="gz-section-title"><span class="gz-number">04</span><div><h2>From reading to interacting</h2><p>Choose what connected assistants can do.</p></div></div>
				<?php $short_unavailable = Endpoints::short_mcp_unavailable_reason(); $url_style = $short_unavailable ? 'standard' : $s['mcp_url_style']; ?>
				<fieldset class="gz-url-options"><legend>MCP server URL</legend>
					<label class="gz-url-choice"><input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[mcp_url_style]" value="standard" <?php checked( $url_style, 'standard' ); ?> aria-describedby="gz-standard-url-help" /><span><strong>Standard WordPress URL (recommended)</strong><code><?php echo esc_html( rest_url( 'gz-ai/v1/mcp' ) ); ?></code></span></label>
					<p id="gz-standard-url-help">The standard URL follows WordPress’s REST API structure:</p>
					<ul class="gz-url-explanation"><li><code>/wp-json/</code> — WordPress’s API entry point.</li><li><code>/gz-ai/v1/</code> — this plugin’s namespace and API version.</li><li><code>/mcp</code> — the MCP endpoint.</li></ul>
					<p class="gz-muted">This structure avoids conflicts with other plugins and uses WordPress’s built-in routing. With plain permalinks, WordPress provides a query URL instead.</p>
					<label class="gz-url-choice"><input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[mcp_url_style]" value="short" <?php checked( $url_style, 'short' ); disabled( (bool) $short_unavailable ); ?> aria-describedby="gz-short-url-help" /><span><strong>Short URL</strong><code><?php echo esc_html( home_url( '/mcp' ) ); ?></code></span></label>
					<p id="gz-short-url-help" class="gz-muted">MCP does not require a long URL. The shorter address is easier to share and uses the same server, permissions, and rate limits. It requires pretty permalinks and reserves <code>/mcp</code>; choose the standard URL if another plugin or a web-server rule already uses that path.</p>
					<?php if ( $short_unavailable ) : ?><p class="gz-note"><?php echo esc_html( $short_unavailable ); ?></p><?php endif; ?>
					<p class="gz-note">Save changes to update connection details, discovery metadata, and generated llms.txt links. The standard URL stays available for existing clients. Switching back to standard removes the short alias, so update clients using it.</p>
				</fieldset>
				<fieldset class="gz-tool-grid"><legend>Public tools</legend><?php foreach ( array( 'search' => 'Search the site', 'get_page' => 'Retrieve a page', 'list_pages' => 'Browse pages', 'site_info' => 'Read site information', 'list_terms' => 'Browse categories & tags' ) as $key => $label ) : ?><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[tools][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['tools'], true ) ); ?> /><?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset>
				<?php self::toggle( 'comments_enabled', 'Read public comments', 'Only approved comments; email addresses and IP addresses are excluded.' ); ?>
				<?php self::toggle( 'comment_submission', 'Accept comment submissions', 'Allows the submit_comment tool. Requires user consent and follows Discussion settings.' ); ?>
				<?php self::toggle( 'force_moderation', 'Hold MCP comments for review', 'Recommended. New MCP comments enter the moderation queue; spam stays blocked.' ); ?>
				<div class="gz-two-fields"><?php self::field( 'rate_limit', 'MCP requests / minute / IP', '1–600 in any 60 seconds. Includes pings and tool calls. Default: 60.', 'number' ); self::field( 'comment_rate_limit', 'Comments / minute / IP', '1–60 in any 60 seconds. Default: 5.', 'number' ); ?></div>
				<?php self::field( 'allowed_origins', 'Additional browser origins', 'One exact origin per line, e.g. https://assistant.example. Your site origin and clients without an Origin header are allowed automatically.', 'textarea' ); ?>
				<p class="gz-note">Transport: Streamable HTTP with JSON responses. Supports MCP 2026-07-28 and 2025 initialization clients. Read tools are public. Logged-in comments can use WordPress Application Passwords over HTTPS.</p>
			</section>
			<section class="gz-card" id="gz-scope"><div class="gz-section-title"><span class="gz-number">05</span><div><h2>You set the boundaries</h2><p>One content scope, applied across all discovery features.</p></div></div>
				<fieldset class="gz-tool-grid"><legend>Included public content types</legend><?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : if ( 'attachment' === $type->name ) { continue; } ?><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $s['post_types'], true ) ); ?> /><?php echo esc_html( $type->label ); ?></label><?php endforeach; ?></fieldset>
				<?php self::field( 'exclude_ids', 'Exclude specific content', 'Comma-separated post or page IDs. Exclusions apply to discovery, Markdown, MCP tools, and resources.' ); ?>
				<?php self::toggle( 'respect_search_visibility', 'Respect site search visibility', 'Pause all public endpoints when “Discourage search engines” is enabled.' ); ?>
				<?php self::toggle( 'delete_data', 'Remove settings when uninstalled', 'Keep your configuration by default. Deactivation always preserves settings.' ); ?>
				<p class="gz-note">Drafts, private content, and password-protected pages are always excluded, even for authenticated clients. For membership sites, exclude restricted page IDs or content types before enabling discovery.</p>
			</section>
			</main><aside class="gz-sidebar"><section class="gz-card gz-connections"><span class="gz-eyebrow">CONNECTION DETAILS</span><h2>Your site, connected.</h2><p>Use these addresses in your AI tools.</p>
				<?php self::endpoint( 'llms.txt', $llms, $public && $s['llms_enabled'] ); self::endpoint( 'MCP server', Endpoints::mcp_url(), $public && $s['mcp_enabled'] ); ?>
				<div class="gz-small-rule"></div><span class="gz-eyebrow">QUICK START</span><ol><li>Choose your discovery features.</li><li>Save your settings.</li><li>Add the MCP URL to a compatible client using Streamable HTTP.</li></ol><a class="gz-text-link" href="<?php echo esc_url( $llms ); ?>" target="_blank" rel="noopener">Open saved llms.txt ↗</a></section>
				<div class="gz-local"><span aria-hidden="true">↳</span><div><strong>Built to stay on your site.</strong><p>No API key. No external processing.<br>Your content, your controls.</p></div></div>
			</aside></div><footer class="gz-save-bar"><span id="gz-save-status" aria-live="polite">Settings apply site-wide.</span><button class="button button-primary" type="submit">Save changes <span aria-hidden="true">↗</span></button></footer>
			</form><div id="gz-announcement" class="screen-reader-text" aria-live="polite"></div><p class="gz-footer">GZ AI DISCOVERABILITY <span>Made for the open web.</span></p>
		</div><?php
	}
}
