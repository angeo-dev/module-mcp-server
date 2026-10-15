# Angeo MCP Server for Magento 2

[![CI](https://github.com/angeo-dev/module-mcp-server/actions/workflows/ci.yml/badge.svg)](https://github.com/angeo-dev/module-mcp-server/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-mcp-server)](https://packagist.org/packages/angeo/module-mcp-server)
[![Downloads](https://img.shields.io/packagist/dt/angeo/module-mcp-server)](https://packagist.org/packages/angeo/module-mcp-server)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%E2%80%93%208.5-777bb4)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%20%E2%80%93%202.4.9-f26322)](composer.json)
[![Mage-OS Directory](https://img.shields.io/badge/Mage--OS-Extension%20Directory-2d7d9a)](https://directory.mage-os.org/packages/angeo/module-mcp-server/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**Give AI agents a real API to your store — instead of letting them scrape it.**

`angeo/module-mcp-server` exposes your Magento 2 / Adobe Commerce (self-hosted)
catalog to AI agents over the [Model Context Protocol](https://modelcontextprotocol.io)
— the open standard created by Anthropic and adopted across the agentic-commerce
ecosystem (Adobe shipped an MCP server for its cloud platform at Summit 2026;
this module is the equivalent for everyone running Magento themselves).

Claude, Gemini, ChatGPT-based agents, and custom shopping assistants get live,
structured, rate-limited answers — current prices, real stock, canonical URLs —
instead of scraping stale HTML.

> **Read-only by default.** The four built-in tools show what an anonymous
> shopper already sees, and nothing else. Writing tools come from separate,
> opt-in modules:
>
> * [`angeo/module-mcp-checkout`](https://github.com/angeo-dev/module-mcp-checkout) —
>   guest cart and checkout with server-side limits and pay-by-link.
> * [`angeo/module-mcp-reorder`](https://github.com/angeo-dev/module-mcp-reorder) —
>   for a signed-in buyer: their orders, reorder and quick order into their own
>   cart (B2B). It never places an order.

**Protocol:** MCP **2026-07-28** (stateless, no handshake) and every earlier
revision back to 2024-11-05, on the same `/mcp` endpoint. See
[Protocol versions](#protocol-versions).

## Requirements

* Magento Open Source / Adobe Commerce **2.4.6+** (PHP 8.1–8.5)
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
if (req.url ~ "^/mcp($|/|\?)") {
    return (pass);
}
```

**LiteSpeed** (.htaccess):

```apache
<IfModule LiteSpeed>
    RewriteRule ^mcp(/.*)?$ - [E=Cache-Control:no-cache]
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
| Accept Customer Tokens | No | 2.3.0 — lets tools act for a signed-in shopper; see *Authentication* |
| Accept Storefront Customer Tokens (testing) | No | 2.4.0 — ordinary Magento customer tokens at `/mcp`; never in production |
| MCP Token Lifetime (min) | 60 | 2.4.0 — account linking; 5 – 1440 |
| Allowed Browser Origins | — | 2.3.0 — extra `Origin` values allowed; the store's own URLs always are |
| Strict MCP Headers | Yes | 2.3.0 — reject 2026-07-28 requests without the required headers |
| Tool List Cache Hint (s) | 300 | 2.3.0 — `ttlMs` sent with `tools/list` and `prompts/list` |

The call log also records the caller kind (`anonymous`, `integration`,
`customer`) and the protocol era since 2.3.0. Never an id or a token.

CLI test as a signed-in customer (needs shell access, no token):

```bash
bin/magento angeo:mcp:tools list_my_orders -c buyer@example.com
```

## Getting the agent to actually use your store

A connector that works is not the same as a connector that gets picked. A model
chooses between your tools and its own general product search *before* it calls
anything, using only three things: the `instructions` you send on connect, the
tool names, and the tool descriptions. Everything below shapes those three.

Since 1.2.0 the defaults do the right thing with no configuration:

| Setting | Default | What it does |
|---|---|---|
| `agent/store_label` | store view name | The name a model matches "check Acme" against. Falls back to the website name when the store view is still called something generic. |
| `agent/instructions` | *(empty — generated)* | Built from the store name and the tools actually installed, so it stays true when you add or remove the checkout module. |
| `agent/anchor_descriptions` | Yes | Appends "Applies to the {store} store only." to every tool description. |
| `agent/tool_titles` | Yes | Generates `title` annotations ("Search Acme products") for the client's permission screen. |

**Set `agent/store_label` to the name customers know you by.** It is the single
highest-leverage field here. "Default Store View" gives a model nothing to
recognise; "Acme Outdoor" gives it something a shopper will actually type.

### Conversation starters

Since 1.3.0 the server also exposes MCP **prompts** — ready-made requests the
client offers the shopper after connecting, with the store name already in
them:

| Prompt | What the shopper gets |
|---|---|
| Browse *store* | Categories and a sense of the range |
| Find something in *store* | A live catalog search for what they type |
| About *store* | Shipping, currency, policies |
| Buy from *store* | Find, cart, shipping, checkout — only when the checkout module is installed |

A prompt is an offer, not an action: the shopper picks it. No MCP server can
start a conversation turn on its own, and clients surface prompts to the user
rather than to the model, so the model will not suggest one unprompted. What
this removes is the guessing — the shopper no longer has to find a phrasing
that beats the client's own product search.

Register your own by implementing `Angeo\McpServer\Api\PromptInterface` and
adding it to `PromptRegistry` in `di.xml`, exactly as with tools.

### What this will and will not do

It will not win a cold, unanchored shopping request. "Find me a grey backpack",
with no reference to any shop, will often still go to a general product search
— and reasonably so: one store should not win a question about the whole
market. Prompts that reliably reach a connector are the ones a general search
cannot answer:

- anything about cart state — "what's in my cart", "add two of these", "how
  much is shipping to Rotterdam"
- anything about the merchant — "what's the return policy", "do you ship to NL"
- anything deictic once the connector is enabled — "what do you have in stock",
  "show me the categories here"
- any follow-up after a first successful call, since the model then has
  evidence the tools are useful

A good demo starts with `list_categories` or `get_store_info` and moves to
products from there. Once the context is established, even a vague product
request goes to the catalog.

## Authentication (optional)

Public read-only mode is the default: the tools expose only storefront-visible
data. To require a token for everything:

1. **System → Extensions → Integrations → Add New Integration** — name it
   e.g. *AI Agents*, grant it only the **MCP Server Agent Access** resource.
2. Activate it and copy the **Access Token**.
3. Set **Require Bearer Token = Yes**.
4. Agents send `Authorization: Bearer <token>`.

Revoking the integration instantly cuts agent access. Only an Integration
token passes this check. Customer and admin tokens do not (fixed in 2.3.0 —
see the changelog).

### Signed-in customers (2.3.0)

Some tools act for one shopper: their orders, their cart. They need to know
who the shopper is. Turn on **Security → Accept Customer Tokens**.

| Setup | Headers | Token |
|---|---|---|
| **Production:** an MCP connector acts for the shopper | `Authorization: Bearer <integration token>` **and** `X-Customer-Token: amc_…` | MCP-only token from [account linking](#account-linking-240) |
| **Testing only:** Claude Code or the MCP Inspector talks to the store directly | `Authorization: Bearer <customer token>` | ordinary Magento customer token; needs **Accept Storefront Customer Tokens (testing)** = Yes |

Why two kinds? An ordinary Magento customer token was issued for the store's
REST and GraphQL APIs. It can change addresses or read every order there. The
MCP specification says a server **must not** accept tokens that were not
issued for it, and a connector should hold no more power than it needs. An
`amc_` token works at `/mcp` only, only next to the Integration token of the
connector it was issued to, and stops the moment the shopper disconnects.

An `X-Customer-Token` that does not verify gives HTTP 401. It never falls back
to anonymous.

Tools read the result through `Angeo\McpServer\Api\CallerContextInterface`.
They never take a customer id from tool arguments.

A customer token alone does **not** satisfy *Require Bearer Token*. That
setting still means "only approved agent platforms".

### Account linking (2.4.0)

Customer tokens live one hour by default, and Magento has no refresh token
for customers. So a connector cannot simply keep one. Account linking solves
this without the connector ever seeing a password:

1. The connector sends the shopper's browser to **`GET /mcp/link`** with
   `redirect_uri`, `state`, `code_challenge` and `code_challenge_method=S256`.
2. The shopper signs in **on your store** (normal login page) and approves on
   a page that names the connector and says what it can and cannot do.
3. The store redirects to `redirect_uri?code=…&state=…`. The code works once,
   for two minutes, and only with the PKCE verifier.
4. The connector calls **`POST /mcp/link/exchange`** with its Integration
   token and `{code, code_verifier, redirect_uri}`. It gets a long-lived
   **grant**, a first MCP-only token (`amc_…`), `expires_in` and a masked
   email.
5. When the token expires (*MCP Token Lifetime*, 60 minutes by default), the
   connector calls **`POST /mcp/link/token`** with `{grant}` and gets a fresh
   one.
6. It sends the token as `X-Customer-Token` next to its Integration token.

| Endpoint | Body | Answer |
|---|---|---|
| `POST /mcp/link/exchange` | `code`, `code_verifier`, `redirect_uri` | `grant`, `customer_token` (`amc_…`), `expires_in`, `customer_label` |
| `POST /mcp/link/token` | `grant` | `customer_token` (`amc_…`), `expires_in` |
| `POST /mcp/link/revoke` | `grant` | `{"ok": true}` (always) |

All three need `Authorization: Bearer <integration token>`. Errors are
`invalid_client` (401), `invalid_request` / `invalid_grant` (400) and
`slow_down` (429). They never say which check failed.

What protects the shopper:

* The password is typed only on your store.
* Only SHA-256 hashes of codes and grants are stored.
* A grant is bound to the Integration that redeemed it; another integration
  cannot use a leaked grant.
* `redirect_uri` must match the configured list exactly. A bad link shows an
  error on your store and never redirects.
* The connector never holds a Magento customer token: `amc_` tokens work at
  `/mcp` only, next to the Integration that holds the grant.
* Shoppers see and disconnect their assistants under **My Account → AI
  assistants**. Disconnecting stops the grant **and its tokens at once**.
* The approval page cannot be framed (`frame-ancestors 'none'`,
  `X-Frame-Options: DENY`).
* Locked accounts, deleted accounts and accounts of another website (with
  per-website accounts) get no tokens.

Set it up under **Account Linking**: enable it, paste the connector's redirect
URI (for mcp.angeo.dev: `https://mcp.angeo.dev/authorize/linked`) and the
name shoppers should see. *Accept Customer Tokens* must be on too.

### Browser origins

Browsers send an `Origin` header. Unknown origins get HTTP 403. This protects
against DNS rebinding, as the MCP transport requires. The store's own URLs
are always allowed. AI platforms connect server-to-server without `Origin`,
so they are not affected. To use a browser tool such as the MCP Inspector,
add its origin under **Security → Allowed Browser Origins**
(e.g. `http://localhost:6274`).

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

Any MCP client works the same way, old or new. See below.

## Protocol versions

| Revision | How the version is chosen |
|---|---|
| **2026-07-28** | No handshake. Each request names its version in `_meta` and in the `MCP-Protocol-Version` header. `server/discover` returns versions, capabilities and instructions. |
| 2025-11-25, 2025-06-18, 2025-03-26, 2024-11-05 | `initialize` handshake, as before. |

What 2026-07-28 changes on the wire, and what this server does:

* **Headers must match the body.** `MCP-Protocol-Version`, `Mcp-Method` and
  `Mcp-Name` must agree with the JSON body. A mismatch gives HTTP 400 with
  error `-32020`. This stops a gateway from seeing one tool while the server
  runs another. Missing headers are also rejected while
  **Protocol → Strict MCP Headers** is *Yes* (the default). Set *No* only for
  a proxy that drops them.
* **Unknown version** gives HTTP 400 with error `-32022` and the list of
  supported versions.
* **Unknown method** gives HTTP 404 with `-32601` (older clients still get 200).
* **Results** carry `resultType` and `_meta.serverInfo`. `tools/list` and
  `prompts/list` carry `ttlMs` and `cacheScope`. The scope is `private`
  when the caller is authenticated, because the tool list can depend on who
  is asking.
* **GET and DELETE** on `/mcp` give 405. The endpoint is POST only.

Older clients see no change.

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

* Admin agent-traffic panel on top of the JSONL log (the log has carried the
  caller kind and protocol era since 2.3.0).
* Optional Elastic/OpenSearch-backed search implementation.
* Integration with [`angeo/module-ucp`](https://packagist.org/packages/angeo/module-ucp):
  the `/.well-known/ucp` profile advertises this MCP endpoint in its service
  bindings, making the store discoverable by UCP-compliant agents.

Cart and checkout tools, once planned here as 1.1, shipped as
[`angeo/module-mcp-checkout`](https://github.com/angeo-dev/module-mcp-checkout).

## The Angeo agentic stack

This module is part of an open-source suite that makes a Magento store legible
to AI systems end-to-end: [`module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt)
(discovery files), [`module-ucp`](https://packagist.org/packages/angeo/module-ucp)
(UCP profile), [`module-rich-data`](https://packagist.org/packages/angeo/module-rich-data)
(structured data), this server (live agent access), and the opt-in
[`module-mcp-checkout`](https://packagist.org/packages/angeo/module-mcp-checkout)
and [`module-mcp-reorder`](https://packagist.org/packages/angeo/module-mcp-reorder)
tools.

**Is your store actually visible to AI shopping agents?** Run the free scan:
<https://angeo.dev/scan> · Implementation and audits: <support@angeo.dev>

## "Add to Claude" button

A Magento widget that lets a shopper connect this store to Claude in one
click. Place it from **Content → Widgets** into the footer, a sidebar or any
CMS page — no template edits.

Set the connector first, in **Stores → Configuration → Angeo → MCP Server →
Claude Connector**:

| Field | Meaning |
|---|---|
| Connector URL | The HTTPS MCP endpoint shoppers connect to, issued by your MCP provider — e.g. `https://mcp.example.com/t/your-store/mcp` |
| Connector Name | Shown in Claude's connector list. Defaults to the store name |

> **This is not your store's own `/mcp` path.** That endpoint has no OAuth and
> cannot be added to Claude directly. The URL here comes from whatever service
> fronts your MCP server with an authorization layer.

There is no default URL, and the button renders nothing until a valid HTTPS
value is set — so installing the module on a store that has not been onboarded
changes nothing on the storefront.

The link opens Claude's add-connector dialog with the URL and name prefilled.
The shopper reviews and approves it there; the link itself grants no access.

## License

MIT — see [LICENSE](./LICENSE).
