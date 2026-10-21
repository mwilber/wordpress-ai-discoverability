#!/usr/bin/env python3
"""Probe a fresh MCP budget over HTTP; no comments or settings are written."""
import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
import json
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("base", help="WordPress site URL")
parser.add_argument("--mcp-path", action="append", help="Repeat to alternate standard/short URLs")
parser.add_argument("--limit", type=int, default=60, help="Saved MCP requests/minute setting")
parser.add_argument("--workers", type=int, default=1)
parser.add_argument("--method", choices=("ping", "tools/call", "mixed"), default="ping")
parser.add_argument("--delay", type=float, default=0.3, help="Seconds between requests per worker")
args = parser.parse_args()
if not 1 <= args.limit <= 600 or args.workers < 1 or args.delay < 0:
    parser.error("Use a limit of 1–600, positive workers, and a nonnegative delay")
paths = args.mcp_path or ["/wp-json/gz-ai/v1/mcp"]


def probe(index):
    if args.delay:
        time.sleep(args.delay)
    # Exercise both method paths and verify that they consume the same budget.
    method = ("tools/call" if index % 2 else "ping") if args.method == "mixed" else args.method
    body = {"jsonrpc": "2.0", "id": index, "method": method}
    if method == "tools/call":
        body["params"] = {"name": "site_info", "arguments": {}}
    request = urllib.request.Request(
        args.base.rstrip("/") + paths[index % len(paths)],
        data=json.dumps(body).encode(),
        headers={"Content-Type": "application/json",
                 "Accept": "application/json, text/event-stream",
                 "MCP-Protocol-Version": "2025-11-25"},
    )
    try:
        response = urllib.request.urlopen(request, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        data = json.load(response)
        assert response.status in (200, 429), (response.status, data)
        assert response.headers["X-RateLimit-Limit"] == str(args.limit), "Saved limit/header mismatch"
        remaining = int(response.headers["X-RateLimit-Remaining"])
        assert 0 <= remaining < args.limit, remaining
        assert int(response.headers["X-RateLimit-Reset"]) > 0
        assert "no-store" in response.headers.get("Cache-Control", "")
        if response.status == 429:
            assert remaining == 0 and 1 <= int(response.headers["Retry-After"]) <= 60
            assert "error" in data
        else:
            assert "result" in data and not data["result"].get("isError")
        return response.status


started = time.monotonic()
with ThreadPoolExecutor(max_workers=args.workers) as executor:
    statuses = Counter(executor.map(probe, range(args.limit + 10)))
elapsed = time.monotonic() - started
print(f"{dict(statuses)} in {elapsed:.2f}s ({args.workers} workers)")
assert elapsed < 60, "The probe spanned more than one rolling interval; shorten --delay"
assert statuses == {200: args.limit, 429: 10}, "Expected a fresh budget; wait 60 seconds without MCP requests and retry"
print(f"PASS {args.method} budget, persistence between HTTP requests, 429s, and rate-limit headers")
