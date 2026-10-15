# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 2.4.x | ✅ |
| 2.3.x | ✅ |
| < 2.3 | ❌ — upgrade: 2.3.0 fixes an integration-token check (see CHANGELOG) |

## Reporting a vulnerability

**Please do not open a public GitHub issue for security problems.**

Email <info@angeo.dev> with:

- the module (`angeo/module-mcp-server`) and version affected
- what an attacker can do, and what access they need to start
- reproduction steps or a proof of concept
- your assessment of impact

You will get an acknowledgement within 3 working days and an assessment within 10. If the report is valid you will be credited in the release notes unless you prefer otherwise.

If you would rather use GitHub's private channel, [report it there](../../security/advisories/new) instead.

## Scope

In scope:

- Authentication or authorisation bypass, including acting as a customer without a valid customer token
- Account linking: redeeming a link code twice or without its PKCE verifier, using a grant through a different integration, or getting a token for a revoked, locked or other-website account
- Any redirect from `/mcp/link` to a URI that is not on the configured list
- Using an `amc_` MCP token without the Integration token it is bound to, or after its grant was revoked
- Any way a connector obtains a Magento customer token through account linking
- Header/body confusion: a request whose `Mcp-Method` / `Mcp-Name` headers name one thing while the body runs another
- Any path by which an AI agent, or a client impersonating one, exceeds a configured server-side limit
- Exposure of data an anonymous storefront visitor could not otherwise see
- Injection, SSRF, or path traversal through tool arguments or request bodies
- Information disclosure in error responses or logs

Out of scope:

- Misconfiguration of the merchant's own store — guest checkout left on with an unlimited order cap is a configuration decision, not a module vulnerability
- Vulnerabilities in Magento core or third-party extensions, which belong with those vendors
- Denial of service through request volume against an endpoint with rate limiting disabled
- Findings that require admin access to exploit

## A note on agentic modules

Modules in this suite deliberately expose functionality to AI agents, and one of them can place orders. The security boundary is that **every constraint is enforced server-side, in PHP** — not in a prompt, a tool description, or client configuration.

A report showing that a model can be persuaded to *attempt* something forbidden is expected behaviour and not a vulnerability. A report showing that an attempt *succeeds* past a server-side guardrail is a vulnerability, and we want to hear about it.
