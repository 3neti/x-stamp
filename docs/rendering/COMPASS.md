# Configurable Stamp Rendering Compass

**Updated:** 2026-10-21.
**Status:** agreed design, not implemented or activated by this documentation.
Companion: [implementation plan](PLAN.md).

## North star

Render a trustworthy Stamp using the best available configured capability before
payment, then honor the frozen contract and preserve its issued image.

## Settled decisions

- Quality (`standard` / `hd`) is separate from provider (`gd` / `browsershot` /
  `cloudflare` / `auto`). These new names describe the target model.
- Standard is 1200×630; HD is native 2400×1260, never an enlarged standard PNG.
- `auto` prefers eligible Cloudflare, then local Browsershot, then GD. GD currently
  supports standard only. Actual results identify the provider, never `auto`.
- Best-effort selection may reduce quality **before payment**. Show the actual
  preview and freeze its effective provider/quality before charging. Explicit
  strict provider/quality requests may block instead.
- After payment, no silent provider switch or quality downgrade. Preserve held
  funds and idempotent recovery; rendering retries never require another payment.
- Issued PNGs are immutable. Provider outages do not affect stored-image reads.
- Cloudflare Free is a valid provider tier. Missing credentials, quota exhaustion,
  invalid credentials and temporary outages are different conditions.
- Optional external rendering is deployment-enabled; no remote content transfer
  occurs merely because an adapter is installed. Keep credentials separate from
  Turnstile and DNS administration, and use narrowly scoped browser permissions.

## Current position and limits

x-stamp v1.4.0 provides GD and optional local Browsershot. Its combined profiles
remain `standard`, `browser` and `browser-hd`; there is no Cloudflare adapter,
provider selector, automatic fallback or shared browser-time budget today.

The last verified Cloud release, **2026-10-20**, used x-change v1.0.255 and
x-stamp v1.4.0 on testing and payout, with standard-only rendering and comparison
tooling disabled. This is dated evidence, not a claim of current live settings.

The current browser integration supports system artwork and a single verified
image in HTML splash. URL artwork and plain/Markdown splash require compatibility
work or explicit eligibility restrictions before general HD activation.

Local HD capture and QR/identifier density are proven. Cloudflare font/emoji
parity, security controls, latency, quota behavior and cost remain unproven.

## Guardrails

x-stamp remains portable. x-change owns financial lifecycle decisions and trusted
projection. Hosts own secrets and commissioning; designs do not select providers.
Provider errors, health checks and audit evidence must not reveal Stamp HTML,
claim QR destinations, session tokens, secrets or raw financial/provider payloads.

Do not treat a successful draft preflight as a future availability guarantee.
Rollback affects new orders; retain the backend required by existing frozen
orders. Never relabel an old artifact's provider using current configuration.

## Next gates

Implement adapter contracts and legacy readers, prove Cloudflare capability and
content isolation, then add phase-aware selection and usage controls. Release in
package order; commission testing before payout. Documentation approval does not
activate paid usage, install credentials, change defaults or authorize transfers.

## Cost reference

Pricing checked **2026-10-21**: [Browser Run pricing](https://developers.cloudflare.com/browser-run/pricing/)
lists 10 browser minutes/day on Workers Free; Workers Paid includes 10 hours/month,
then $0.09/hour. Screenshot Quick Actions charge browser time only, unlike browser
sessions with separate concurrency billing. [Workers pricing](https://developers.cloudflare.com/workers/platform/pricing/)
lists a $5/month account minimum for Paid. Allowances are shared, prices may change,
and existing account usage reduces available headroom.

At an **assumed**, unmeasured two billable seconds per capture, 10 free minutes
would permit about 300 captures/day. Captures include draft edits and retries,
not only issued Pay Codes. Use Cloudflare-reported milliseconds and account usage
rather than treating that estimate as a contractual request allowance. Normal
image storage/delivery and implementation work are separate costs.
