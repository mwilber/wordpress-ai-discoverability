#!/usr/bin/env python3
"""HTTP smoke tests for a disposable WordPress server; no third-party dependencies."""
import argparse
import json
import urllib.request
import urllib.error
from email.utils import parsedate_to_datetime
import time

parser = argparse.ArgumentParser()
parser.add_argument("base", help="Local WordPress URL")
parser.add_argument("--page-id", type=int, default=2)
parser.add_argument("--slug", default="sample-page")
parser.add_argument("--mcp-path", default="/wp-json/gz-ai/v1/mcp")
args = parser.parse_args()
base = args.base.rstrip("/")
checks = 0
mcp_path = args.mcp_path


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


def expect_no_cache(headers, label):
    policies = headers.get_all("Cache-Control", [])
    expires = headers.get_all("Expires", [])
    expect(len(policies) == 1 and "no-store" in policies[0] and "max-age=0" in policies[0]
           and "172800" not in policies[0] and len(expires) == 1
           and parsedate_to_datetime(expires[0]).timestamp() < time.time()
           and "ETag" not in headers and "Last-Modified" not in headers, label)


status, headers, text = request("/llms.txt")
expect(status == 200 and text.startswith("# "), "llms.txt serves generated Markdown")
expect("text/plain" in headers["Content-Type"], "llms.txt uses a text content type")
expect_no_cache(headers, "llms.txt has one non-cacheable policy and an expired timestamp")
for path in [f"/{args.slug}/index.md", f"/{args.slug}.md", f"/wp-json/gz-ai/v1/markdown/{args.page_id}"]:
    status, headers, text = request(path)
    expect(status == 200 and text.startswith("# ") and "text/markdown" in headers["Content-Type"], "Markdown route " + path)
    expect_no_cache(headers, "Markdown has one non-cacheable policy and an expired timestamp")
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
status, headers, text = request(mcp_path, json.dumps(body).encode(), mcp_headers)
expect(status == 200 and len(json.loads(text)["result"]["tools"]) == 5, "MCP tools/list works over HTTP")
expect_no_cache(headers, "Successful MCP response has no conflicting cache lifetime")
expect(int(headers["X-RateLimit-Limit"]) >= 1 and int(headers["X-RateLimit-Remaining"]) >= 0 and int(headers["X-RateLimit-Reset"]) > 0, "Successful MCP responses include rate-limit headers")
status, headers, text = request(mcp_path)
expect(status == 405, "MCP GET returns 405")
expect_no_cache(headers, "MCP 405 has no conflicting cache lifetime")
status, headers, text = request(mcp_path, b"{bad-json", mcp_headers)
expect(status == 400 and json.loads(text)["error"]["code"] == -32700, "Malformed JSON retains the JSON-RPC error envelope")
expect_no_cache(headers, "MCP parse errors have no conflicting cache lifetime")
expect("X-RateLimit-Remaining" in headers, "Invalid POST requests consume budget and include rate-limit headers")
status, headers, text = request(mcp_path, b"[]", mcp_headers)
expect(status == 400 and json.loads(text)["error"]["code"] == -32600, "JSON-RPC batches are rejected")
status, headers, text = request(mcp_path, json.dumps(body).encode(), {**mcp_headers, "Origin": "https://evil.example"})
expect(status == 403 and not headers.get("Access-Control-Allow-Origin"), "Rejected origins receive no permissive CORS headers")
expect_no_cache(headers, "MCP rejected origins have no conflicting cache lifetime")
status, headers, text = request(mcp_path, headers={"Origin": base}, method="OPTIONS")
expect(status == 204 and not text and headers.get("Access-Control-Allow-Origin") == base, "Same-origin preflight succeeds without a body")
expect_no_cache(headers, "MCP preflight has no conflicting cache lifetime")
expect("X-RateLimit-Remaining" in headers.get("Access-Control-Expose-Headers", "") and "Retry-After" in headers.get("Access-Control-Expose-Headers", ""), "Browser clients can read rate-limit headers")
legacy = {"jsonrpc": "2.0", "method": "notifications/initialized"}
status, headers, text = request(mcp_path, json.dumps(legacy).encode(), {**mcp_headers, "MCP-Protocol-Version": "2025-11-25"})
expect(status == 202 and not text, "MCP notifications return an empty 202")
expect_no_cache(headers, "MCP notifications have no conflicting cache lifetime")

class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


for path in ("/.well-known/mcp", "/.well-known/mcp/", "/.well-known/mcp?probe=1"):
    try:
        response = urllib.request.build_opener(NoRedirects).open(base + path, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        expect(response.status == 404 and not response.headers.get("Location"), path + " stays 404 without a guessed redirect")
print(f"\n{checks} HTTP checks passed.")
