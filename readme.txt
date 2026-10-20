=== GZ AI Discoverability ===
Contributors: gz
Tags: ai, llms-txt, markdown, mcp, discoverability
Requires at least: 6.6
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a customizable llms.txt, serve public pages as Markdown, and connect AI assistants with public WordPress features over MCP.

== Description ==

GZ AI Discoverability provides a responsive settings page with feature toggles, an editable llms.txt and saved preview, Markdown conversion, and a public MCP server.

Tools include search, page retrieval, content browsing, categories and tags, site metadata, and optional approved-comment reading and moderated comment submission. No API key or external AI processing is required.

Only selected published public content is exposed. Private, draft, scheduled, password-protected, and excluded content is unavailable. Membership-restricted content must be excluded explicitly. Shortcodes and dynamic content callbacks are not executed.

MCP supports stateless Streamable HTTP JSON responses, protocol 2026-07-28, and 2025 initialization clients. Comment submission is disabled by default and preserves WordPress Discussion settings and moderation.

See the included README.md for full transport details, integration limits, caching guidance, and developer verification.

== Installation ==

1. Upload and activate the plugin.
2. Open Settings > AI Discoverability.
3. Select features and content scope, then save.
4. Copy the MCP server URL into a compatible Streamable HTTP client.

Enable pretty permalinks for /llms.txt and .md paths. Query and REST fallback URLs work with plain permalinks.

== Frequently Asked Questions ==

= Does this improve search ranking? =

The plugin makes your public content easier for compatible assistants to discover and read. Crawling, citations, ranking, and adherence to llms.txt guidance are controlled by each client.

= Does it send data to an AI provider? =

No. Content conversion and tools execute on your WordPress site.

= Can assistants publish comments immediately? =

Submission must be enabled separately. Holding MCP comments for review is enabled by default. WordPress comment rules and spam filters apply.

= Will every page builder render in Markdown? =

Stored HTML and block content are supported. Content available only through dynamic callbacks, metadata, JavaScript, or shortcodes requires a trusted site-specific adapter.

== Changelog ==

= 1.0.1 =
* Choose a standard or short MCP URL with inline explanations, automatic discovery updates, and path-conflict checks.

= 1.0.0 =
* Initial release: llms.txt, Markdown, public MCP tools/resources, and configurable admin interface.
