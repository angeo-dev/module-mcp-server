# Changelog

All notable changes to `angeo/module-mcp-server` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/) and the
project adheres to [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-07-03

Initial release: read-only MCP server for Magento 2 / Adobe Commerce
(self-hosted). MCP spec revision 2025-06-18 (with 2025-03-26 / 2024-11-05
version negotiation), Streamable HTTP transport in single request/response
mode at `POST /mcp`.

### Added

* **Protocol layer** — `initialize`, `tools/list`, `tools/call`, `ping`,
  notification handling; JSON-RPC batching rejected per spec; tool failures
  returned as `isError` tool results (agents can recover), unexpected
  exceptions masked (no internals leak).
* **Tools**: `search_products` (keyword / category / price-range / paging /
  sorting via the repository layer — website, status, and visibility
  respected), `get_product` (full card incl. configurable variants with live
  per-variant price and stock), `list_categories` (active tree with URLs and
  product counts), `get_store_info` (identity, currency, ships-to, links to
  llms.txt and /.well-known/ucp surfaces).
* **ToolInterface SPI** — third-party modules add tools via a di.xml pool
  item; see the interface docblock for the contract.
* **Security**: pure-PHP atomic file-based rate limiter (per IP + token,
  fail-open, hostile-key safe), optional Bearer auth against Magento
  Integration tokens with a dedicated `Angeo_McpServer::agent_access` ACL
  resource, 64 KB body cap, `Cache-Control: no-store` +
  `X-Content-Type-Options: nosniff` + `X-Robots-Tag: noindex` on every
  response.
* **Agent call log** — one JSON line per `tools/call` in
  `var/log/angeo_mcp_agent.jsonl` (never payloads or tokens); the raw feed
  for agent-traffic analytics.
* **CLI**: `bin/magento angeo:mcp:tools` to list tools or invoke one with
  JSON arguments — verify the server without wiring up an agent.
* Unit tests for the protocol layer and rate limiter.

### Known limitations (by design, see README roadmap)

* Read-only: cart/checkout tools ship in 1.1 as an explicit opt-in.
* Search is repository/LIKE-based; an Elastic/OpenSearch-backed
  implementation can replace `SearchProductsTool` via di preference.
* Rate limiter is per-node on multi-server setups.
