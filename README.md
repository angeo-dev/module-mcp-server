[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-mcp-server?style=flat-square)](https://packagist.org/packages/angeo/module-mcp-server)
[![License](https://img.shields.io/packagist/l/angeo/module-mcp-server?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%20--%208.4-777bb4?style=flat-square)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%2B-f26322?style=flat-square)](https://github.com/magento/magento2)

# Angeo MCP Server for Magento 2

**Give AI agents a real API to your store — instead of letting them scrape it.**

`angeo/module-mcp-server` exposes your Magento 2 / Adobe Commerce (self-hosted) catalog to AI agents over the [Model Context Protocol](https://modelcontextprotocol.io) — the open standard created by Anthropic and adopted across the agentic-commerce ecosystem. Adobe shipped an MCP server for its cloud platform at Summit 2026; this module is the equivalent for everyone running Magento themselves.

Claude, Gemini, ChatGPT-based agents, and custom shopping assistants get live, structured, rate-limited answers — current prices, real stock, canonical URLs — instead of scraping stale HTML.

> **v1.0 is read-only by design.** Agents can search and read everything an anonymous shopper sees, and nothing else. Cart and checkout tools are a separate, opt-in install: [`angeo/module-mcp-checkout`](https://github.com/angeo-dev/module-mcp-checkout).

📖 Full write-up: [angeo.dev/modules/mcp-server](https://angeo.dev/modules/mcp-server/) · [What an MCP server does for a Magento store](https://angeo.dev/magento-mcp-server/)

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [⚠️ Full-page cache bypass](#️-full-page-cache-bypass-read-this)
- [Tools](#tools)
- [Configuration](#configuration)
- [Authentication](#authentication-optional)
- [Connecting an agent](#connecting-an-agent)
- [Extending: add your own tools](#extending-add-your-own-tools)
- [FAQ](#faq)
- [Roadmap](#roadmap)
- [The Angeo agentic stack](#the-angeo-agentic-stack)

## Requirements

- Magento Open Source / Adobe Commerce **2.4.6+**
- **PHP 8.1 – 8.4**
- No external services: no Redis requirement, no Node sidecar, no SaaS dependency

## Installation

```bash
composer require angeo/module-mcp-server
bin/magento module:enable Angeo_McpServer
bin/magento setup:upgrade
bin/magento cache:flush
```

The server is **enabled read-only by default**. Verify from the CLI:

```bash
bin/magento angeo:mcp:tools
bin/magento angeo:mcp:tools search_products -a '{"query":"mug","page_size":3}'
```

…or over HTTP:

```bash
curl -s https://your-store.example/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## ⚠️ Full-page cache bypass (read this)

`/mcp` must never be served from Varnish / LiteSpeed / FPC — a cached JSON-RPC response is corrupt by definition. The module sends `Cache-Control: no-store`, but front caches configured to ignore backend headers need an explicit rule.

This is the single most common Magento-specific deployment mistake with this module.

**Varnish** — add to `vcl_recv` before the builtin:

```vcl
if (req.url ~ "^/mcp($|\?)") {
    return (pass);
}
```

**LiteSpeed** — `.htaccess`:

```apache
<IfModule LiteSpeed>
    RewriteRule ^mcp$ - [E=Cache-Control:no-cache]
</IfModule>
```

## Tools

| Tool | Arguments | Returns |
| --- | --- | --- |
| `search_products` | `query`, `category_id?`, `price_min?`, `price_max?`, `page?`, `page_size?`, `sort?` | live items: sku, name, price, stock, URL, image, short description + total count |
| `get_product` | `sku` | full card: description, live price/stock, URL, image; configurable variants with per-variant option values, price, stock |
| `list_categories` | `parent_id?`, `depth?` (≤4) | active category tree with URLs and product counts |
| `get_store_info` | — | store name, currency, locale, ships-to countries, links to `llms.txt` and `/.well-known/ucp` |

Prices reflect the configured customer group (default **NOT LOGGED IN**) — agents see exactly what an anonymous shopper sees. Disabled products, other-website products, and invisible products are indistinguishable from absent.

## Configuration

**Stores → Configuration → Angeo → MCP Server**

| Setting | Default | Notes |
| --- | --- | --- |
| Enable MCP Server | Yes | per store view |
| Require Bearer Token | No | see [Authentication](#authentication-optional) |
| Log Agent Calls | Yes | JSONL in `var/log/angeo_mcp_agent.jsonl` — timestamp, tool, user agent, duration; never payloads or tokens |
| Price Customer Group | NOT LOGGED IN | which prices agents see |
| Default / Max Page Size | 10 / 50 | hard protocol cap 100 |
| Rate Limit (req/min) | 60 | per IP (+ token when present); 0 disables |

## Authentication (optional)

Public read-only mode is the default: the tools expose only storefront-visible data. To require a token for everything:

1. **System → Extensions → Integrations → Add New Integration** — name it e.g. *AI Agents*, grant it only the **MCP Server Agent Access** resource.
2. Activate it and copy the **Access Token**.
3. Set **Require Bearer Token = Yes**.
4. Agents send `Authorization: Bearer <token>`.

Revoking the integration instantly cuts agent access.

## Connecting an agent

**Claude Desktop / Claude Code** (`.mcp.json`):

```json
{
  "mcpServers": {
    "my-store": {
      "type": "http",
      "url": "https://your-store.example/mcp"
    }
  }
}
```

Any MCP-compatible client (spec 2024-11-05 through 2025-06-18) works the same way — the server negotiates the protocol version on `initialize`.

## Extending: add your own tools

Implement `Angeo\McpServer\Api\ToolInterface` and register it in the pool:

```xml
<type name="Angeo\McpServer\Model\Tool\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="my_tool" xsi:type="object">Vendor\Module\Tool\MyTool</item>
        </argument>
    </arguments>
</type>
```

Contract details (schemas, error handling, PII rules) are documented on the interface.

## FAQ

**What can an AI agent actually do with this?**
Search products, retrieve full product cards, browse the category tree and read store information — the discovery half of a shopping conversation. Nothing that changes state. Cart and order tools require the separate [`module-mcp-checkout`](https://github.com/angeo-dev/module-mcp-checkout).

**Is it safe to expose my catalog over MCP?**
Everything the tools return is already public on your storefront; the difference is that it arrives structured instead of scraped. The read-only default and server-side rate limiting are what make it defensible — limits are enforced in PHP, not requested of the client.

**How is this different from llms.txt?**
`llms.txt` is a static file a crawler fetches; it reflects the catalog as of the last generation. MCP is a live connection an agent queries in the moment. "Is this in stock in my size, right now" cannot be answered by a file written last night. You want both.

**Which AI clients can connect?**
Any MCP client — Claude, Gemini, ChatGPT-based agents and custom assistants. That is the point of implementing a protocol rather than one vendor's API: the server is built once.

**Does it work with Hyvä / headless?**
Yes. The server is a backend JSON-RPC endpoint and is independent of the frontend theme.

**Will it slow down my storefront?**
No. `/mcp` is a separate route, rate limited server-side, and never touches page render. It must, however, bypass full-page cache — see [above](#️-full-page-cache-bypass-read-this).

**Does installing this get my store into ChatGPT?**
No. Being reachable by agents and being recommended by them are different problems. For ChatGPT Shopping specifically the authoritative input is a registered ACP product feed. Check what an engine currently sees first: [free AEO audit](https://angeo.dev/ai-magento-audit/).

## Roadmap

- **Shipped** — transactional tools moved into their own opt-in module: [`angeo/module-mcp-checkout`](https://github.com/angeo-dev/module-mcp-checkout) adds `create_cart`, `add_to_cart`, `get_cart`, `get_shipping_methods`, `set_shipping_information` and `place_order`, with server-side guardrails. Keeping writes out of this package means installing the server alone can never place an order.
- **Next** — admin agent-traffic panel on top of the JSONL log; optional Elastic/OpenSearch-backed search implementation.
- **Next** — signed checkout-handoff link (`create_checkout_url`) for merchants who prefer discovery in the chat and the transaction on their own site.
- **Integration** with [`angeo/module-ucp`](https://github.com/angeo-dev/module-ucp): the `/.well-known/ucp` profile advertises this MCP endpoint in its service bindings, making the store discoverable by UCP-compliant agents.

## The Angeo agentic stack

This module is part of an open-source suite that makes a Magento store legible to AI systems end-to-end. All MIT-licensed, no paid tier.

| Layer | Module |
| --- | --- |
| Crawler access | [`module-robots-txt-aeo`](https://github.com/angeo-dev/module-robots-txt-aeo) |
| Discovery files | [`module-llms-txt`](https://github.com/angeo-dev/module-llms-txt) |
| Structured data | [`module-rich-data`](https://github.com/angeo-dev/module-rich-data) |
| ChatGPT Shopping feed | [`module-openai-product-feed`](https://github.com/angeo-dev/module-openai-product-feed) |
| **Live agent access** | **`module-mcp-server`** ← you are here |
| Agent checkout | [`module-mcp-checkout`](https://github.com/angeo-dev/module-mcp-checkout) |
| UCP profile | [`module-ucp`](https://github.com/angeo-dev/module-ucp) · [`module-ucp-catalog`](https://github.com/angeo-dev/module-ucp-catalog) |
| Measurement | [`module-aeo-audit`](https://github.com/angeo-dev/module-aeo-audit) |

**Is your store actually visible to AI shopping agents?** Run the free scan: <https://angeo.dev/ai-magento-audit/> · All thirteen modules: <https://angeo.dev/modules/>

## Support

Issues and feature requests: [GitHub Issues](https://github.com/angeo-dev/module-mcp-server/issues).
Security reports: see [SECURITY.md](SECURITY.md).
Implementation and audits: <info@angeo.dev>

## License

MIT — see [LICENSE](LICENSE).
