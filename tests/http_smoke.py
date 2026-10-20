#!/usr/bin/env python3
"""HTTP smoke tests for a disposable WordPress server; no third-party dependencies."""
import argparse
import json
import urllib.request
import urllib.error

parser = argparse.ArgumentParser()
parser.add_argument("base", help="Local WordPress URL")
parser.add_argument("--page-id", type=int, default=2)
parser.add_argument("--slug", default="sample-page")
args = parser.parse_args()
base = args.base.rstrip("/")
checks = 0


def request(path, data=None, headers=None, method=None):
    req = urllib.request.Request(base + path, data=data, headers=headers or {}, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.headers, response.read().decode()


def expect(condition, label):
    global checks
    assert condition, label
    checks += 1
    print("PASS", label)


status, headers, text = request("/llms.txt")
expect(status == 200 and text.startswith("# "), "llms.txt serves generated Markdown")
expect("text/plain" in headers["Content-Type"], "llms.txt uses a text content type")
for path in [f"/{args.slug}/index.md", f"/{args.slug}.md", f"/wp-json/gz-ai/v1/markdown/{args.page_id}"]:
    status, headers, text = request(path)
    expect(status == 200 and text.startswith("# ") and "text/markdown" in headers["Content-Type"], "Markdown route " + path)
    expect("no-store" in headers.get("Cache-Control", ""), "Markdown is not stored in shared caches")
status, headers, text = request(f"/{args.slug}/", headers={"Accept": "text/markdown"})
expect(status == 200 and text.startswith("# ") and "text/markdown" in headers["Content-Type"], "Accept negotiation serves Markdown")
status, headers, text = request(f"/{args.slug}/", headers={"Accept": "text/markdown;q=0, text/html;q=1"})
expect(status == 200 and "text/html" in headers["Content-Type"], "Unacceptable Markdown quality does not override HTML")
status, headers, text = request(f"/{args.slug}/")
expect(status == 200 and 'type="text/markdown"' in text and 'rel="describedby"' in text and 'rel="mcp"' in text, "HTML contains discovery links")
expect("Accept" in headers.get("Vary", ""), "HTML varies by Accept")
expect(request("/missing-gz-fixture/index.md")[0] == 404, "Missing Markdown pages return 404")
expect(request("/llms.txt", method="HEAD")[2] == "", "HEAD has no response body")

mcp_headers = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream", "MCP-Protocol-Version": "2026-07-28", "Mcp-Method": "tools/list"}
body = {"jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {"_meta": {"io.modelcontextprotocol/protocolVersion": "2026-07-28", "io.modelcontextprotocol/clientCapabilities": {}}}}
status, headers, text = request("/wp-json/gz-ai/v1/mcp", json.dumps(body).encode(), mcp_headers)
expect(status == 200 and len(json.loads(text)["result"]["tools"]) == 5, "MCP tools/list works over HTTP")
expect(request("/wp-json/gz-ai/v1/mcp")[0] == 405, "MCP GET returns 405")
status, headers, text = request("/wp-json/gz-ai/v1/mcp", b"{bad-json", mcp_headers)
expect(status == 400 and json.loads(text)["error"]["code"] == -32700, "Malformed JSON retains the JSON-RPC error envelope")
status, headers, text = request("/wp-json/gz-ai/v1/mcp", b"[]", mcp_headers)
expect(status == 400 and json.loads(text)["error"]["code"] == -32600, "JSON-RPC batches are rejected")
status, headers, text = request("/wp-json/gz-ai/v1/mcp", json.dumps(body).encode(), {**mcp_headers, "Origin": "https://evil.example"})
expect(status == 403 and not headers.get("Access-Control-Allow-Origin"), "Rejected origins receive no permissive CORS headers")
status, headers, text = request("/wp-json/gz-ai/v1/mcp", headers={"Origin": base}, method="OPTIONS")
expect(status == 204 and not text and headers.get("Access-Control-Allow-Origin") == base, "Same-origin preflight succeeds without a body")
legacy = {"jsonrpc": "2.0", "method": "notifications/initialized"}
status, headers, text = request("/wp-json/gz-ai/v1/mcp", json.dumps(legacy).encode(), {**mcp_headers, "MCP-Protocol-Version": "2025-11-25"})
expect(status == 202 and not text, "MCP notifications return an empty 202")
print(f"\n{checks} HTTP checks passed.")
