# GZ AI Discoverability

A self-contained WordPress plugin that gives AI assistants a useful introduction to your site, readable Markdown versions of public content, and MCP tools for public WordPress interactions. No API keys, external processing, Composer dependencies, or build step are required to run it.

## Install

1. Upload `dist/gz-ai-discoverability-1.0.6.zip` under **Plugins → Add Plugin → Upload Plugin** and activate it. Alternatively, copy this directory to `wp-content/plugins/gz-ai-discoverability`.
2. Open **Settings → AI Discoverability**.
3. Choose content types, exclusions, and features; customize the introduction; then save.
4. Add the displayed MCP endpoint to a compatible client using **Streamable HTTP**.

Requires WordPress 6.6+ and PHP 8.1+. Tested against **WordPress 7.1.3 / PHP 8.4.11**, with WordPress’s SQLite integration in a disposable local installation. PHP DOM provides full HTML-to-Markdown conversion; a plain-text fallback is used if DOM is unavailable. No production site has been modified.

## Discovery files and metadata

- `/llms.txt` is generated from your site name, description, language, custom guidance, and MCP endpoint. Its **Content** section lists recent standard posts, ordered by publication date from newest to oldest, with ID descending to break ties. **Posts per section** under the llms.txt settings controls the limit for every section (default **20**, range **1–500**). Existing installations without this setting default to 20; the old total link-limit setting is not reused.
- Under **Settings → AI Discoverability → llms.txt**, select custom post types to include. Each selected public type receives a separate section using its display name (for example, **Products**), containing up to the configured number of its newest entries. Empty sections are omitted. Types must first be enabled in **Content & access**; that shared access scope and excluded IDs always apply. Unchecking a type in llms.txt does not remove it from Markdown or MCP.
- Pages remain accessible through Markdown and MCP and do not consume the per-section allowance. Older entries are available through MCP pagination. Fully custom llms.txt content is left unchanged by these generated-index settings.
- Replace the generated document entirely with custom Markdown. Supported placeholders: `{{site_name}}`, `{{site_url}}`, `{{description}}`, and `{{mcp_url}}`. The MCP placeholder is blank when MCP is disabled.
- HTML `<link>` metadata advertises `rel="describedby"` for llms.txt, `rel="alternate" type="text/markdown"` for a page’s Markdown, and a `rel="mcp"` discovery hint. The MCP relation is an additional hint, not a universal client standard.
- Markdown responses link back to llms.txt through an HTTP `Link` header. The plugin appends labeled comments for enabled llms.txt and MCP endpoints to WordPress’s generated robots.txt using the `robots_txt` filter. Existing crawler rules and sitemap entries are preserved. If no `Sitemap:` line exists and WordPress’s sitemap service is enabled, its index URL is appended. Disabled endpoints and non-public sites receive no discovery additions.
- The admin provides a preview of the **saved** document and copyable connection URLs.

Pretty permalinks must be enabled for `/llms.txt` and `.md` paths. On plain permalinks, `/?gz_ai_llms=1` and `/?page_id=123&gz_ai_markdown=1` (or `p=123` for posts) work, as do REST URLs using `?rest_route=`. The admin shows the correct URLs for the current configuration. A physical `llms.txt` file or a web-server rule can take precedence over WordPress; the plugin does not overwrite physical files.

The plugin does not create or edit a physical robots.txt. A physical file, CDN response, or web-server rule that bypasses WordPress must be updated separately; purge cached robots.txt after upgrading. The llms.txt and MCP comments are discovery hints, not standardized robots directives, and do not change crawler permissions. The plugin advertises its direct MCP URL and does not publish a discovery document at `/.well-known/mcp`. WordPress canonical redirects and 404 slug guessing are disabled for that exact unsupported path (including a trailing slash or query string), so a missing document stays HTTP 404 instead of redirecting to a similarly named post. Other paths retain normal WordPress redirect behavior. No permalink refresh is needed for this guard.

## Markdown

For a page at `/about/`, both `/about/index.md` and `/about.md` are supported. For a permalink ending in a file extension, append `.md`, for example `/article.html.md`. A static front page has `/index.md`. The posts archive is not a singular page; use MCP `list_pages` to browse its entries.

Additional access paths:

```text
GET /about/                    Accept: text/markdown
GET /wp-json/gz-ai/v1/markdown/123
```

Conversion preserves headings, links, images, emphasis, nested lists, quotations, code, tables, and published reusable blocks. Relative links become absolute. The source URL and modification time accompany the content.

Stored content is converted without running `the_content`, dynamic block callbacks, or shortcodes. This avoids executing content with the requesting user’s privileges. Content rendered only by dynamic blocks, page-builder metadata, JavaScript, or shortcodes needs a trusted adapter via the `gz_ai_markdown` filter. Theme headers, navigation, and footers are intentionally outside singular page content.

The plugin sends `Vary: Accept` on normal HTML responses. MCP, Markdown, and llms.txt responses send `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private`, `Pragma: no-cache`, and an expired `Expires` timestamp. Plugin REST responses normalize conflicting PHP cache headers and remove ETag/Last-Modified validators before serving, including error, notification, and preflight responses. Other plugins' REST cache policies are left unchanged. Supplying `Expires` prevents [Apache mod_expires](https://httpd.apache.org/docs/2.4/mod/mod_expires.html) from appending its default lifetime; setting only `no-store` can otherwise leave an additional two-day `max-age`/`Expires` on some hosts.

If a CDN bypasses WordPress or a server rule overwrites headers after PHP, its configuration must also exclude the MCP addresses from caching. Exclude `/wp-json/` and the short `/mcp` URL from a blanket two-day expiry policy, and cover query-style `rest_route` URLs on sites using plain permalinks. Configure CDN variation on `Accept`, bypass `.md` and discovery routes, and purge old cached responses/redirects after upgrading. The plugin does not edit Apache or CDN configuration.

## MCP

Choose the advertised MCP URL under **Settings → AI Discoverability → MCP server**:

- **Standard WordPress URL (default):** `/wp-json/gz-ai/v1/mcp`. `/wp-json/` is WordPress’s API entry point; `/gz-ai/v1/` is this plugin’s namespace and API version; `/mcp` identifies the endpoint. This structure avoids plugin conflicts and uses WordPress’s built-in routing. Plain permalinks use WordPress’s query URL instead.
- **Short URL:** `/mcp` under your site’s home URL. MCP does not require a long URL; this alias uses the same WordPress REST handler, authentication, origin validation, and rate limits. It requires pretty permalinks and reserves the `/mcp` path. Existing WordPress content conflicts block selection; use the standard URL if another plugin or a web-server rule owns the path.

Saving updates connection details, HTML discovery metadata, generated llms.txt links, the custom `{{mcp_url}}` placeholder, and `site_info`. The standard URL remains available when short is selected, preserving existing client connections. Switching back to standard removes the short alias; update clients using `/mcp`. Rewrite rules refresh automatically when this preference changes. Copy the full saved URL from Settings.

Supports MCP **2026-07-28** with per-request metadata and `server/discover`, plus initialization clients speaking **2025-11-25**, **2025-06-18**, or **2025-03-26**. Uses stateless Streamable HTTP with JSON responses. GET/DELETE return 405; no legacy HTTP+SSE endpoint, session IDs, subscriptions, prompts, or server-initiated calls are implemented. Notifications supported by initialization clients receive an empty 202 response.

| Tool | Purpose | Default |
| --- | --- | --- |
| `search` | Search published content, with bounded pagination | On |
| `get_page` | Retrieve a public page by ID or canonical URL as Markdown | On |
| `list_pages` | Browse public posts, pages, and selected public custom types | On |
| `site_info` | Site metadata, language, search URL, feeds, discovery URLs | On |
| `list_terms` | Public categories/tags with counts limited to the content scope | On |
| `get_comments` | Approved comments without email or IP addresses | Off |
| `submit_comment` | Submit a comment or reply after user consent | Off |

MCP resources also enumerate and retrieve public pages, with paginated `resources/list` and `resources/read`. Resources use the same content scope and remain available independently of individual tool toggles.

Example modern discovery request:

```sh
curl 'https://example.com/wp-json/gz-ai/v1/mcp' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: server/discover' \
  --data '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'
```

For modern `tools/call`, supply `Mcp-Method: tools/call` and `Mcp-Name: search` matching the body. Each request must carry protocol metadata. Tool arguments are validated against the published schemas. Page numbers are limited to 10,000 and page sizes to 50; requests are limited to 64 KB.

## Access and comments

Only published, publicly viewable posts in selected public content types are exposed. Private posts, drafts, scheduled posts, password-protected posts, attachments, and excluded IDs are unavailable even to authenticated administrators. By default, enabling **Discourage search engines** under Settings → Reading pauses discovery endpoints. Administrators can turn that behavior off explicitly.

Membership plugins and custom access-control systems must be configured through excluded IDs/types or a trusted site-specific integration before discovery is enabled. A plugin that hides otherwise published content through a content filter cannot be inferred automatically. Per-post SEO noindex metadata is not an access-control rule and is not automatically imported. Custom llms.txt text is administrator-authored and may contain links outside the generated content scope.

Reads are deliberately public. Comment submission is separately opt-in and requires `consent: true`, author details, a public target, and an approved same-page parent when replying. WordPress enforces comments-open, required email/name, login requirements, duplicate/flood checks, and spam filters through `wp_handle_comment_submission`. Holding MCP comments for moderation is on by default, and spam/trash decisions are retained. No arbitrary post creation, user management, uploads, or administrative actions are exposed.

Sites requiring logged-in commenters can use existing **WordPress Application Passwords over HTTPS**. This does not grant private-content access. The plugin does not implement an OAuth authorization server. A client's `consent` argument records its claim of consent; the client must obtain actual human approval before calling the write tool.

Origins are checked before processing MCP requests. Browser clients must use the site origin or an exact origin listed by the administrator. Clients without an Origin header are allowed. Arbitrary CORS reflection is removed for this route. IP limits use `REMOTE_ADDR`, not untrusted forwarding headers; behind a trusted proxy, configure the web server to supply the correct client IP. This is an application-level limit; use web-server controls for network-level abuse.

MCP POST requests share one rolling **60-second** budget per IP across the standard and short URLs. All methods count, including `ping`, `initialize`, `tools/call`, notifications, and invalid POST bodies. OPTIONS preflight, unsupported HTTP methods, disabled endpoints, and rejected origins do not consume this budget. The default is 60 requests; the 61st request within the rolling interval receives HTTP **429**. Counts persist directly in the WordPress database and updates are atomic across workers, with no persistent object cache required. Database failures deny requests. Comment submissions have an additional, separate rolling budget (default 5).

Every counted POST response includes `X-RateLimit-Limit` (the saved setting), `X-RateLimit-Remaining`, and `X-RateLimit-Reset` (Unix seconds when allowance next replenishes). HTTP 429 also includes `Retry-After` in seconds. Allowed browser clients can read these headers through CORS. Rejected requests do not extend the lockout. Version 1.0.5 replaces the previous clock-minute buckets, which could allow a short burst above the configured limit when it crossed a minute boundary.

## Data and lifecycle

The plugin stores one per-site settings option and short-lived request timestamps keyed by HMAC-hashed IP/bucket identifiers; raw client IPs are not stored by the limiter. It does not send content to external services or maintain analytics. Comments use WordPress’s normal storage and privacy behavior. WP-Cron cleans expired rate logs and legacy counters; activation/deactivation and uninstall clear them. Deactivation preserves settings. Uninstall removes settings only if the admin opts in. On multisite, configure each site separately; network-wide lifecycle behavior has not been certified.

## Development and verification

Run in a **disposable WordPress installation** with the plugin activated:

```sh
GZ_AI_TEST_ALLOW=1 wp eval-file /absolute/path/to/gz-wp-ai/tests/integration.php --path=/path/to/test-wordpress
GZ_AI_TEST_ALLOW=1 wp eval-file /absolute/path/to/gz-wp-ai/tests/mcp_rate_limit.php --path=/path/to/test-wordpress
GZ_AI_TEST_ALLOW=1 wp eval-file /absolute/path/to/gz-wp-ai/tests/transport_headers.php --path=/path/to/test-wordpress
python3 tests/http_smoke.py http://127.0.0.1:8891 --page-id 2 --slug sample-page
GZ_AI_TEST_ALLOW=1 wp eval-file /absolute/path/to/gz-wp-ai/tests/mcp_urls.php --path=/path/to/test-wordpress
GZ_AI_TEST_ALLOW=1 wp eval-file /absolute/path/to/gz-wp-ai/tests/llms_index.php --path=/path/to/test-wordpress
GZ_AI_TEST_ALLOW=1 wp eval-file /absolute/path/to/gz-wp-ai/tests/robots.php --path=/path/to/test-wordpress
# With the short URL selected:
python3 tests/http_smoke.py http://127.0.0.1:8891 --mcp-path /mcp
python3 scripts/build.py
```

The integration suite creates and deletes fixtures and temporarily changes site/discussion/plugin settings. It suppresses test mail and restores settings afterward; never run it against a live site. The HTTP suite assumes pretty permalinks, default five tools, and the WordPress sample page unless overridden. It performs public reads/protocol probes and sends no comments.

To reproduce the rate-limit probe, first allow 60 seconds without MCP traffic from your IP. Confirm the saved limit is 60, then run:

```sh
python3 tests/http_rate_limit.py https://example.com --mcp-path /mcp
# Concurrent ping/tool calls across both URLs (short URL must be enabled):
python3 tests/http_rate_limit.py https://example.com --mcp-path /mcp --mcp-path /wp-json/gz-ai/v1/mcp --method mixed --workers 8 --delay 0
```

Each probe uses a fresh budget; wait 60 seconds between runs. The default probe sends 70 pings with 0.3 seconds between them and expects 60 successes and 10 HTTP 429s, with rate-limit headers. It only performs public reads. `--limit` accepts a different saved setting. Other clients sharing your IP also consume this budget.

Verification performed for 1.0.6: PHP lint; 54 real-WordPress integration checks; 19 rolling-rate checks; 25 cache-header/redirect regression checks; 22 robots.txt checks; 20 llms.txt index checks; 11 URL-selection checks; and 34 HTTP checks against each MCP address. An Apache 2.4 proxy with `ExpiresDefault "access plus 2 days"` reproduced the old conflicting headers; 13 Apache/HTTP checks verified the corrected policy on both MCP URLs, the query URL, success/error/empty responses, and a large Markdown body, plus non-redirecting well-known URLs while an MCP-prefixed post existed. A concurrent probe through Apache with eight clients across both URLs and both ping/tool methods returned exactly 60 successes and 10 HTTP 429s with consistent cache headers. PHP tests also covered case-insensitive duplicate header removal, unaffected third-party routes, subdirectory sites, and disabled MCP. Earlier releases passed six checks for plain permalinks, a static front page, and activation lifecycle, plus browser verification of settings layout and submission. A local MySQL binary crashes during initialization, so MySQL/MariaDB runtime testing remains outstanding; local runtime checks use WordPress’s SQLite integration.

## References

- [WordPress release archive](https://wordpress.org/download/releases/)
- [llms.txt proposal](https://llmstxt.org/)
- [MCP Streamable HTTP](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http)
- [WordPress comment submission API](https://developer.wordpress.org/reference/functions/wp_handle_comment_submission/)

License: GPL-2.0-or-later.
