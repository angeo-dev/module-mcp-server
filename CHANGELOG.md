# Changelog

All notable changes to `angeo/module-mcp-server` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/) and the
project adheres to [Semantic Versioning](https://semver.org/).

---

## [2.1.0] - 2026-09-05

A tool-contract release. Nothing in the handler layer changed; what changed is
everything a model and a Connector Directory reviewer actually read.

### Fixed

- **Reported server version.** `Config::MODULE_VERSION` and
  `McpServer::SERVER_VERSION` were still `1.3.0` at the 2.0.0 tag, so
  `initialize` announced a version that had never been published and
  `get_store_info` echoed it back. Both now track the package version.

### Changed

- **Tool descriptions no longer argue against other tools.** Three of the four
  descriptions told the model to prefer this connector over a web search, and
  `buildInstructions` repeated the argument in a paragraph of its own.
  Directory review treats "prefer my tool over unrelated tools" as a
  prompt-injection pattern, so the whole class of phrasing is gone.

  What replaces it is the boundary between *these* tools: `search_products`
  says to call it when no sku is known, `get_product` says it needs an exact
  sku and to search first without one. That distinction is what actually
  reduces selection variance — the connector never won a cold "find me a grey
  backpack" and was never going to. One store should not answer a question
  about the whole market.

- **`served_by` carries no marketing.** The field used to return an angeo.dev
  URL with UTM parameters, in a result the model reads. Promotional copy in a
  tool result is a review rejection, and `serverInfo` already carries the same
  name and version through the channel meant for it. The key stays, factual.

- **`title` is published at the top level** of each tool descriptor, where the
  current tool model puts it and where review expects it, in addition to
  `annotations.title` for older clients.

- **`tools/list` is ordered by tool name.** Insertion order followed `di.xml`
  merge order, which shifts when a sibling module such as `mcp-checkout` is
  installed or removed. Clients cache the catalog and hosts cache the prompt
  containing it, so the same authorization must produce the same bytes.

### Added

- **Data fence around tool results.** Product names, descriptions and category
  names are merchant-editable content this server does not control, and they
  went to the model as bare prose. The text channel of every result is now
  wrapped in `<store_data>` tags, with a notice in `instructions` saying that
  an instruction found inside them is something to report, never something to
  follow. A literal closing tag in a payload is neutralised, so the fence
  cannot be torn open from a product description. `structuredContent` is left
  unfenced: it is parsed, not read.

  The pattern is taken from Anthropic's Claude Commerce Agents blueprint
  (Apache 2.0, released 2026-09-02), whose reference storefront server fences
  every catalog, review, policy and order result the same way.

- **`Test\Unit\Model\Tool\ToolContractTest`** — six checks across all four
  tools: name length and character set, banned steering and promotional
  phrases, description substance, a description or constraint on every input
  field, coherent safety annotations, and unique names. Tools are built with
  `newInstanceWithoutConstructor()`, so the check needs no Magento wiring and
  always runs.

  This is the test that would have caught the descriptions above. A handler
  bug shows up the first time you call the tool; a description that fails
  review ships silently and costs a submission cycle.

## [2.0.0] - 2026-08-31

First release since 1.0.1. Versions 1.1.0, 1.2.0, 1.2.1 and 1.3.0 were built
but never published to Packagist, and they are folded into this release rather
than tagged retroactively — their entries below are kept for the record.

**On the version number:** nothing here breaks a 1.0.1 install.
`Api\ToolInterface` is unchanged byte for byte, so third-party tools keep
working; `di.xml` and `config.xml` only add. The major bump reflects the size
of the jump from what is actually published, not a compatibility break.

### Added

- **"Add to Claude" button** (`Block\ClaudeButton`, `etc/widget.xml`,
  `view/frontend/templates/claude-button.phtml`). A Magento widget a merchant
  places from Content → Widgets — footer, sidebar or CMS page — that opens
  Claude's add-connector dialog with the store's connector URL and name
  prefilled. The shopper still reviews and approves; the link prefills a form
  and grants nothing.

  This existed on the widget branch and was never merged into the 1.1–1.3
  line, which is why it appears here rather than at 1.2.0 where its docblock
  says it was written.

- **Claude Connector settings** — `Stores → Configuration → Angeo → MCP Server
  → Claude Connector`: `Connector URL` and `Connector Name`, exposed on
  `Model\Config` as `getConnectorUrl()`, `getConnectorLabel()` and
  `isConnectorConfigured()`.

  There is deliberately **no default URL**. The endpoint is issued by whichever
  MCP provider the merchant uses, so a shipped default would point every store
  at someone else's connector. Empty or non-HTTPS means the button renders
  nothing, which makes installing the module on a store that has not been
  onboarded harmless.

  The field comment states plainly that this is *not* the store's own `/mcp`
  path — that endpoint has no OAuth and cannot be added to Claude directly.
  It is the single point merchants get wrong first.

- **`SECURITY.md`** — vulnerability reporting policy, also missing from the
  1.1–1.3 line.

### Fixed

- **CHANGELOG entries were out of order.** In 1.3.0 the headings ran
  1.2.0 → 1.3.0 → 1.1.0 → 1.0.0; the 1.3.0 entry had been inserted below
  1.2.0. Now strictly reverse-chronological.
- **1.2.1 had no entry of its own** — its fixes were recorded as a
  `### Fixed (1.2.1)` subsection inside 1.2.0, so the version was invisible to
  anyone reading the file. Split out.

## [1.3.0] - 2026-08-20

### Added
- **MCP `prompts` capability.** Four store-anchored conversation starters —
  Browse, Find something, About, and (with the checkout module) Buy — surfaced
  in the client's prompt picker with the store's own name already in the text.

  This is the answer to "can the connector catch the first request without the
  shopper naming the store". It cannot do so automatically: an MCP server
  responds to requests and cannot start a conversation turn or inject a
  message. What it can do is supply the phrasing, so the shopper picks a
  starter instead of guessing wording that competes with the client's built-in
  product search.

  Note that prompts are surfaced to the USER, not to the model — the model will
  not suggest one on its own.

- `Angeo\McpServer\Api\PromptInterface` and `PromptRegistry`: register a
  prompt from any module the same way a tool is registered. Built-in prompts
  are `StorePrompt` virtual types configured entirely in `di.xml`, so adding
  one needs no PHP.

- The `prompts` capability is advertised only when at least one prompt is
  available, and the Buy starter appears only when `place_order` exists —
  offering checkout on a read-only store is a promise the tools cannot keep.

## [1.2.1] - 2026-08-16

Split out of the 1.2.0 entry in 2.0.0. These fixes shipped as 1.2.1 but
were only ever recorded as a subsection inside 1.2.0, so the version had
no entry of its own.

### Fixed
- Generated titles and the description anchor no longer duplicate the word
  "store" or pick an article. "Get Angeo Demo Store store information",
  "Create a Angeo Demo Store cart" and "Applies to the Angeo Demo Store store
  only." all read as typos, and a description that reads as a typo makes both
  models and reviewers trust the rest of it less. Titles are now article-free,
  and the anchor detects a label that already names itself a shop.

  **Changing tool titles resets a client's saved per-tool permissions** — the
  client treats a retitled tool as a new one and falls back to "needs
  approval". That is the cost of this fix, and the reason to make title changes
  rarely and in one batch. Merchants who had set read-only tools to "always
  allow" will need to set them again once.

### Notes
- None of this guarantees tool selection, and nothing can. A request that names
  no shop ("find me a grey backpack") may still go to a general product search
  — reasonably so; one store should not win a question about the whole market.
  What these changes fix is the case where a model had a reason to use the
  connector and the server's own description talked it out of it.

## [1.2.0] - 2026-08-16

### Added
- **Generated `initialize` instructions.** The text a client reads before it
  decides whether to use this connector is now built from the store name and
  the tools actually registered, instead of a hard-coded string. The 1.0/1.1
  string claimed "Read-only commerce tools" — false the moment
  `angeo/module-mcp-checkout` was installed, and self-defeating: it told models
  the connector could not transact, which is the one thing a web search cannot
  do. Install or remove the checkout module and the text stays correct.
- **Store name anchoring.** `agent/store_label` (defaults to the store view
  name, falling back to the website name when the store view is still called
  something generic like "Default Store View") is interpolated into the
  instructions, appended to every tool description
  (`agent/anchor_descriptions`, on by default), and used to generate
  `title` annotations for the client's permission screen
  (`agent/tool_titles`, on by default). Without a name in the text, a model has
  nothing to match a shopper's "check Acme" against.
- **`agent/instructions`** — optional admin override for merchants who want to
  write their own. Empty means "generate", which is the recommended setting.
- Description anchoring and generated titles are applied centrally in
  `tools/list`, so third-party tools registered through the `ToolInterface` SPI
  get them without any change.

### Changed
- Read-tool descriptions rewritten to name the store explicitly and to say when
  to prefer them over a web search. 1.1.0 had weakened `search_products` from
  "Search this store's live product catalog" to "Search the store catalog",
  removing the anchoring that makes a model treat the tool as a specific shop
  rather than a generic product lookup.

### Fixed
- **`Config` is now wired into `McpServer` and `ToolsCommand` explicitly in
  `di.xml`.** Magento does not auto-wire optional constructor arguments: a
  nullable parameter with a default receives that default unless named in
  `di.xml`. Without the entry every agent-presentation setting silently fell
  back to pre-1.2.0 behaviour — the connector still worked, it was just harder
  to find, which is the worst kind of bug to ship.
- Generic Magento names ("Default Store View", "Main Website", "Main Website
  Store", …) are rejected as store labels outright rather than only names
  containing the word "default". When nothing usable remains the label is empty
  and the instructions say "this store", which reads better than advertising a
  misconfiguration to every agent that connects.
- `bin/magento angeo:mcp:tools` now warns when no usable store name exists,
  instead of leaving the operator to notice it in a protocol dump.

## [1.1.0] - 2026-07-11

### Added
- `Angeo\McpServer\Api\ToolAnnotationsInterface` — optional, BC-safe companion
  to `ToolInterface`. Tools implementing it expose MCP `ToolAnnotations`
  (readOnlyHint / destructiveHint / idempotentHint / openWorldHint) in the
  `tools/list` response; tools without it behave exactly as before.
- All four built-in read tools (`search_products`, `get_product`,
  `list_categories`, `get_store_info`) now declare
  `readOnlyHint: true, openWorldHint: false`.

### Notes
- Annotations are honest behavioral descriptions per the MCP spec, not
  security guarantees. Third-party tools registered in the ToolRegistry are
  encouraged (not required) to implement the new interface — Connectors
  Directory review checks that annotations match actual tool behavior.

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
