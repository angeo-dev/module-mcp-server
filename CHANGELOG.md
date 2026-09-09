# Changelog

All notable changes to `angeo/module-mcp-server` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/) and the
project adheres to [Semantic Versioning](https://semver.org/).

---

## [2.2.1] - 2026-09-08

2.2.0 made category counts truthful for anchor categories and left a second
problem in plain sight: one response could carry two different kinds of
number under one key.

`Category\Collection::loadProductCount()` counts anchor categories from the
category-product index with a visibility filter, and non-anchor categories as
raw rows in `catalog_category_product` with no visibility filter at all. On
the demo store that put Gear at 48 beside sibling categories counted as 12 —
48 assignment rows including size and colour variants, against 12 products a
shopper can actually open. A reader comparing them sees four times the choice
and is wrong.

### Fixed

- **`product_count` has one definition.** Every category is now counted from
  the store's category-product index — which already holds the anchor rollup —
  under the same visibility set `search_products` filters on. A count now
  predicts what a search in that category returns, which is the call an agent
  makes next.

### Added

- `Angeo\McpServer\Model\Catalog\CategoryProductCounter`, resolving the
  per-store index table through `TableMaintainer` rather than the legacy
  un-dimensioned table name.
- `CategoryProductCounter::VISIBILITY_IDS`, now the single source for the
  visibility set used by both `list_categories` and `search_products`.

### Changed

- `list_categories` description states that a count is what `search_products`
  will return for that category.
- When the category-product index cannot be read at all, the tool falls back
  to Magento's rollup rather than reporting an empty catalog. The fallback is
  logged. An index that reads clean but returns nothing is a real zero and is
  reported as one.

### Upgrade notes

Counts will drop on stores whose non-anchor categories hold variant
assignments — the demo store's Gear went from 48 to the number of products a
shopper can open. Nothing changed in the catalog; the earlier number was
counting something else. Requires the category-product indexer to have run,
which any working storefront already needs.

---

## [2.2.0] - 2026-09-08

A category release. Testing the server against the demo store with an agent
rather than a script exposed a failure no functional test had caught: the
storefront and the MCP surface disagreed about what the store sells.

Browsing `list_categories` reported `product_count: 0` for Women, Men,
Training and Sale, and a real count only for Gear. `search_products` then
found seventeen jackets. An agent that starts by browsing — as a shopper who
does not know what they want will — concludes the store stocks bags, gym
equipment and watches, and nothing in the response says otherwise.

### Fixed

- **`list_categories` counts the subtree, not the assignment row.**
  `Category::getProductCount()` counts direct assignments in
  `catalog_category_product`. Magento assigns products to leaf categories and
  rolls them up with the `is_anchor` flag, so on any store with depth the
  direct count is zero for exactly the categories a shopper browses. Counts
  now come from `loadProductCount()` — the same rollup the storefront and the
  admin category grid use.

- **`search_products` honours anchor categories.** The search-criteria filter
  `category_id` resolves against the same direct-assignment table, so a
  parent category returned an empty result set while its storefront page
  listed hundreds of products. An anchor category is now expanded to its
  active subtree before filtering, via the new `AnchorCategoryResolver`.

- **Default depth is 3, not 2.** Two levels is the shape of the catalog with
  none of its stock in view: the sample data, and most real stores, put
  products on the third level. An agent calling `list_categories` with no
  arguments never saw them.

### Changed

- `list_categories` and `search_products` descriptions state that a count and
  a category filter cover subcategories, and that a zero count on a category
  with children means look inside rather than the store has nothing.

### Added

- `Angeo\McpServer\Model\Catalog\AnchorCategoryResolver` — expands a
  category id to the set of ids a shopper's own browse would cover. Reads the
  category path, so it does not depend on the category-product indexer having
  run.

### Upgrade notes

No schema or configuration changes. `search_products` results for a
`category_id` that names an anchor category will grow, which is the point.
Stores that deliberately run non-anchor categories are unaffected: those
still return only what is assigned to them.

---

## [2.1.1] - 2026-09-05

A truthfulness release. Live testing on the demo store produced a product
comparison in which the colour, material, size and laptop-pocket status of a
product were all stated confidently and none of them came from the catalog.
Every change here exists to make that impossible to repeat.

### Fixed

- **`search_products` now says what it leaves out.** A search record carries
  sku, name, type, price, stock and — where they exist — url and image. It
  never carried attributes, description or variants, and it never said so.
  Asked to compare two products, a reader with only search results has no
  signal that the missing fields are missing from the *result* rather than
  from the *product*, and fills them from whatever is at hand — a colour word
  in an image filename, a sibling product, prior knowledge of the sample
  catalog. Responses now declare `omitted_fields`, and the description says to
  call `get_product` before stating any characteristic.

- **`get_product` returns the attributes its description promised.** The
  description advertised attributes; the payload had no such key. Reviewers
  call every tool and compare the result against the description, and this
  contradiction would have been read as carelessness at best.

  The new `attributes` block is the storefront attributes the merchant marked
  visible and actually filled in, keyed by store label. It is deliberately not
  the full EAV set: a wall of empty attributes reads as a specification and is
  not one. The description states plainly that a missing attribute is
  unrecorded rather than absent, and that neither an image filename nor a
  similar product may be used to infer one.

- **Empty optional fields are omitted, not sent as `null` or `""`.** Both
  tools used to publish `"short_description": ""` and `"image": null` for
  products without them. An empty value is a claim about the product; an
  absent key is not. Applies to `url`, `image`, `short_description`,
  `description` and `attributes`.

- **An unset boolean attribute is skipped rather than published as "No".**
  Magento's frontend model renders a never-set boolean as "No", which asserts
  that a product lacks a feature when nothing was ever recorded about it. The
  stored value is checked before rendering.

### Added

- Descriptions on `search_products`' `price_min`, `price_max`, `page`,
  `page_size` and `sort`. `ToolContractTest`, added in 2.1.0, fails without
  them — as intended: a type says what shape a value has, never what it means.

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
