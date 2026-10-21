# Configurable Stamp Rendering Plan

**Status:** agreed design; implementation pending. Documentation only.
**Updated:** 2026-10-21.
Companion: [rendering compass](COMPASS.md). Current implementation:
[optional Chromium profiles](../../README.md#optional-chromium-profiles).

## Goal and ownership

Choose image quality independently of the service rendering it. Make free-tier
Cloudflare useful for testing, preserve operation when an optional provider is
unavailable, and retain the exact rendering contract after payment.

- **x-stamp:** portable rendering adapters, capabilities, deterministic document
  preparation, normalized results and typed rendering failures. No Laravel,
  voucher, payment or deployment-specific dependencies.
- **x-change:** phase-aware selection, trusted instruction projection, signed
  order snapshots, retries, immutable artifacts and sanitized audit evidence.
- **Hosts:** enabled providers, credentials, browser runtime, budgets and
  commissioning. **x-stamp-designs:** artwork and layout, not provider choice.

## Naming and compatibility

The following is a proposed public model, not an API available in v1.4.0:

| Dimension | Values | Meaning |
| --- | --- | --- |
| Quality | `standard`, `hd` | Native 1200×630 or 2400×1260 output |
| Provider | `gd`, `browsershot`, `cloudflare`, `auto` | Rendering backend or selection policy |
| Failure policy | strict, best-effort | Require the request or allow an explicit effective result |

The logical canvas stays 1200×630. GD advertises standard quality only. Browser
providers advertise only artwork and quality capabilities proven by their tests.
`auto` is a policy, never the provider recorded as having rendered an image.

Preserve existing public entry points and map legacy profiles when reading saved
contracts: `standard` → GD/standard, `browser` → Browsershot/standard,
`browser-hd` → Browsershot/HD. Existing signed snapshots and v1/v2 artifact
descriptors must remain verifiable under their original schema/signing rules.
Do not rewrite legacy snapshots using a new default or regenerate issued PNGs.

## Selection and lifecycle

1. Prepare the same sanitized, self-contained document for every compatible
   browser adapter; preserve provider identity, QR, requirements and copy rules.
2. Under `auto`, prefer enabled and compatible Cloudflare, then available local
   Browsershot, then GD. Omit disabled or unavailable candidates. Explicit
   provider selection stays on that provider; strict requests fail when the
   requested provider/quality is unavailable. No provider switch is implicit.
3. For best-effort drafts, choose the requested quality where available. If only
   standard output is possible, return the actual standard preview with a quiet,
   accessible reduced-quality indication and a recorded fallback reason.
4. **Chosen policy: use available quality before payment.** Immediately before
   funding, resolve and preflight the intended provider and effective quality.
   Ensure the displayed preview agrees with that result before accepting payment;
   if selection changed, refresh the review rather than charge against a stale
   preview. Freeze the effective contract with the order before payment starts.
5. A preflight proves current compatibility/availability, not future service
   availability. After payment, retry only the frozen provider and quality.
   Failure leaves verified funds held and issuance recoverable. Never downgrade,
   release funds casually, create a second issuance or request a second payment.
6. Reuse the immutable issued image for detail, downloads and OG sharing. Reading
   it must not require credentials, browser availability or another API call.

A draft preflight uses sample identifiers and a generic claim destination. It is
not the final issued artifact: the real code and canonical claim URL are supplied
later through the same composition path.

## Portable request/result boundary

Document and implement a portable adapter contract accepting the prepared
composition, requested quality and bounded trusted rendering options. Credentials
and executable paths are adapter/deployment configuration, never design fields
or untrusted request parameters. The result must identify actual provider,
effective quality, physical dimensions, MIME type, bytes and renderer version.

x-change adds selection/fallback reason and duration to sanitized evidence. Cache
keys include document/assets, provider, quality and rendering version. New frozen
contracts/artifacts need an explicit schema version recording actual provider and
quality; retain readers for old schemas and accurate physical OG dimensions.
Secrets and live account/billing state never enter signed client-visible metadata.

## Implementation phases

1. Add quality/provider types, adapter capabilities, normalized results and typed
   failures in x-stamp, retaining legacy profile compatibility.
2. Add a Cloudflare screenshot adapter using the existing prepared HTML, inline
   approved assets/fonts, native 2× density and the same script/network isolation
   requirements as local capture. Prove remote equivalence; block activation if
   the chosen API cannot enforce these requirements. Stamp content is sent to
   Cloudflare, but no app session, OTP, secret or raw provider evidence is sent.
3. Add x-change automatic selection, pre-funding preflight/review refresh, frozen
   contract handling, compatible artifact storage and issuance-safe retries.
   Close or explicitly gate unsupported URL/plain/Markdown artwork before making
   HD a public default; compatibility must fail before charging.
4. Add content-addressed preview caching, concurrent-request deduplication,
   bounded timeouts, circuit breaking and sanitized observability. Missing or
   invalid credentials, quota exhaustion, throttling and transient failures are
   separate categories. Eligibility does not require a paid Cloudflare plan.
5. Add configurable browser-time budgets, recording `X-Browser-Ms-Used`. Shared
   Cloudflare account allowances cover testing and payout together. Monitoring is
   not a hard cap: a hard account-wide limit needs shared reservation/accounting
   across environments and concurrent requests, with a documented safety margin.
   New-request budgets must not silently change already-paid contracts.
6. Release x-stamp first, then x-change, then adopt in testing. Promote payout
   only after acceptance; keep current production defaults until then.

## Acceptance gates

- Provider/quality capability matrix; legacy aliases, signatures and artifacts.
- Missing credentials, invalid credentials, quota/rate limits, timeout, outage,
  circuit opening/recovery and strict versus automatic selection.
- Standard fallback displayed before funding and frozen without another payment;
  paid retries preserve provider, quality, hold and idempotency.
- Cache separation/invalidation, concurrent deduplication, measured usage and
  account-wide budget behavior without hard-cap overclaims.
- Native PNG dimensions, fonts/emoji, QR decoding, provider/requirement placement,
  text rendered once, artwork variants and preview/issuance parity.
- Remote HTML isolation, secret/log redaction, immutable image reuse and accurate
  OG dimensions; mobile/desktop and light/dark browser checks.
- Testing commissioning must measure latency, cost/usage and failure recovery
  before payout activation. No live payment is part of automated acceptance.
