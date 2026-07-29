# Angeo MCP Server for Magento 2

**Give AI agents a real API to your store — instead of letting them scrape it.**

`angeo/module-mcp-server` exposes your Magento 2 / Adobe Commerce (self-hosted)
catalog to AI agents over the [Model Context Protocol](https://modelcontextprotocol.io)
— the open standard created by Anthropic and adopted across the agentic-commerce
ecosystem (Adobe shipped an MCP server for its cloud platform at Summit 2026;
this module is the equivalent for everyone running Magento themselves).

Claude, Gemini, ChatGPT-based agents, and custom shopping assistants get live,
structured, rate-limited answers — current prices, real stock, canonical URLs —
instead of scraping stale HTML.

> **v1.0 is deliberately read-only.** Agents can search and read everything an
> anonymous shopper sees, and nothing else. Cart and checkout-handoff tools
> arrive in 1.1 as an explicit opt-in.

## Requirements

* Magento Open Source / Adobe Commerce **2.4.6+** (PHP 8.1–8.4)
* No external services: no Redis requirement, no Node sidecar, no SaaS dependency

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

`/mcp` must never be served from Varnish/LiteSpeed/FPC — a cached JSON-RPC
response is corrupt by definition. The module sends `Cache-Control: no-store`,
but front caches configured to ignore backend headers need an explicit rule.

**Varnish** (add to `vcl_recv` before the builtin):

```vcl
if (req.url ~ "^/mcp($|\?)") {
    return (pass);
}
```

**LiteSpeed** (.htaccess):

```apache
<IfModule LiteSpeed>
    RewriteRule ^mcp$ - [E=Cache-Control:no-cache]
</IfModule>
```

## Tools

| Tool | Arguments | Returns |
|---|---|---|
| `search_products` | `query`, `category_id?`, `price_min?`, `price_max?`, `page?`, `page_size?`, `sort?` | live items: sku, name, price, stock, URL, image, short description + total count |
| `get_product` | `sku` | full card: description, live price/stock, URL, image; configurable variants with per-variant option values, price, stock |
| `list_categories` | `parent_id?`, `depth?` (≤4) | active category tree with URLs and product counts |
| `get_store_info` | — | store name, currency, locale, ships-to countries, links to `llms.txt` and `/.well-known/ucp` |

Prices reflect the configured customer group (default **NOT LOGGED IN**) — agents
see exactly what an anonymous shopper sees. Disabled products, other-website
products, and invisible products are indistinguishable from absent.

## Configuration

**Stores → Configuration → Angeo → MCP Server**

| Setting | Default | Notes |
|---|---|---|
| Enable MCP Server | Yes | per store view |
| Require Bearer Token | No | see *Authentication* below |
| Log Agent Calls | Yes | JSONL in `var/log/angeo_mcp_agent.jsonl` — timestamp, tool, user agent, duration; never payloads or tokens |
| Price Customer Group | NOT LOGGED IN | which prices agents see |
| Default / Max Page Size | 10 / 50 | hard protocol cap 100 |
| Rate Limit (req/min) | 60 | per IP (+ token when present); 0 disables |

## Authentication (optional)

Public read-only mode is the default: the tools expose only storefront-visible
data. To require a token for everything:

1. **System → Extensions → Integrations → Add New Integration** — name it
   e.g. *AI Agents*, grant it only the **MCP Server Agent Access** resource.
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

Any MCP-compatible client (spec 2024-11-05 through 2025-06-18) works the same
way — the server negotiates the protocol version on `initialize`.

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

Contract details (schemas, error handling, PII rules) are documented on the
interface.

## Roadmap

* **1.1** — opt-in transactional tools: `create_cart`, `add_to_cart`,
  `get_cart`, `estimate_shipping`, and `create_checkout_url` (signed handoff
  link that opens the store's native checkout with the agent-built cart —
  discovery happens in the chat, the transaction happens on **your** site,
  you keep the customer).
* **1.2** — admin agent-traffic panel on top of the JSONL log; optional
  Elastic/OpenSearch-backed search implementation.
* Integration with [`angeo/module-ucp`](https://packagist.org/packages/angeo/module-ucp):
  the `/.well-known/ucp` profile advertises this MCP endpoint in its service
  bindings, making the store discoverable by UCP-compliant agents.

## The Angeo agentic stack

This module is part of an open-source suite that makes a Magento store legible
to AI systems end-to-end: [`module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt)
(discovery files), [`module-ucp`](https://packagist.org/packages/angeo/module-ucp)
(UCP profile), [`module-rich-data`](https://packagist.org/packages/angeo/module-rich-data)
(structured data), and this server (live agent access).

**Is your store actually visible to AI shopping agents?** Run the free scan:
<https://angeo.dev/scan> · Implementation and audits: <support@angeo.dev>

## License

MIT — see [LICENSE](./LICENSE).
